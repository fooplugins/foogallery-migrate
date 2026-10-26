<?php
/**
 * Real WP/MariaDB atomic bounded snapshot primitive checkpoint.
 * Disposable site only; refuses ANY preexisting owned tables. No selection/UI.
 * wp --skip-plugins --skip-themes eval-file <runner defining DISPOSABLE=true>
 * Optional FOOGM_STORAGE_TEST_ROOT selects a safely extracted built package.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'An explicitly disposable WordPress site is required.' );
}
$plugin_root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $plugin_root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\TableReader;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

/** Failure/visibility injection; SQL still executes on the real WordPress DB. */
class FooGmSnapshotConnection extends LegacyTransactionConnection {
	public $queries = array();
	public $before_write;
	public $after_write;
	public function get_results( $sql, $format ) { $this->queries[] = $sql; return parent::get_results( $sql, $format ); }
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( is_callable( $this->before_write ) ) { call_user_func( $this->before_write, $sql ); }
		$result = parent::query( $sql );
		if ( is_callable( $this->after_write ) ) { call_user_func( $this->after_write, $sql ); }
		return $result;
	}
}

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) {
		throw new RuntimeException( 'Refusing existing shared tables or an unhealthy database.' );
	}
}
$codec = new LegacyStateCodec(); $missing = new stdClass(); $options = array();
foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION,
	StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $option ) {
	$options[ $option ] = get_option( $option, $missing );
}
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$refuses = function ( $callback, $label ) use ( $check ) {
	try { $callback(); } catch ( RuntimeException $error ) { $check( true, $label ); return; }
	$check( false, $label );
};
foreach ( array( TableSnapshot::class => 'class-table-snapshot.php', LegacyTransactionConnection::class => 'class-legacy-transaction-connection.php' ) as $class => $file ) {
	$check( ( new ReflectionClass( $class ) )->getFileName() === realpath( $plugin_root . '/includes/' . $file ), 'actual bootstrap loaded expected source/package primitive: ' . $file );
}
$change = function ( $key, $order, $kind, $value, $revision = null ) {
	return array( 'key' => $key, 'order' => $order, 'kind' => $kind, 'value' => $value, 'expected_revision' => $revision );
};
$raw_snapshot = function () use ( $wpdb, $tables ) {
	return array( $wpdb->get_results( 'SELECT * FROM `' . $tables[0] . '` ORDER BY id', ARRAY_A ),
		$wpdb->get_results( 'SELECT * FROM `' . $tables[1] . '` ORDER BY id', ARRAY_A ) );
};
$created = array(); $observer = null;
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[ $index ]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'exact shared InnoDB fixture schema is verified' );
	$blog = (int) get_current_blog_id();
	$connection = new FooGmSnapshotConnection( $wpdb );
	$repo = new TableSnapshot( $connection, $blog );
	$reader = new TableReader( $wpdb, $blog );
	$initial = array( '009' => array( 'object_type' => 'album', 'plugin_name' => 'Case', 'ID' => '009',
		'title' => "\xff\x00雪", 'migrated' => false, 'error' => false, 'unknown' => array( 'zero' => -0.0 ),
		'children' => array( 7 => array( 'object_type' => 'gallery', 'ID' => 7, 'plugin_name' => 'Case',
			'children' => array( '03' => array( 'object_type' => 'image', 'ID' => '03', 'migrated_id' => 0, 'error' => null ) ) ), '007' => false ) ),
		9 => null, 'empty' => array( 'children' => array() ) );
	$check( array() === $connection->queries, 'construction creates no SQL, options or tables' );
	$refuses( function () use ( $repo ) { $repo->read_bounded( 'missing' ); }, 'missing snapshot is refused without fallback' );
	$heads = $repo->replace_many( array( $change( 'migrated', 0, 'map', $initial ), $change( 'migrated-revision', 1, 'scalar', 7 ),
		$change( 'checkpoint', 2, 'scalar', false ), $change( 'empty', 3, 'list', array() ) ) );
	$check( count( $heads ) === 4, 'four coupled state heads commit together' );
	$check( $codec->encode( $repo->read_bounded( 'migrated' ) ) === $codec->encode( $initial ), 'real LONGTEXT round-trip retains nested trees, typed keys, binary bytes, negative zero and unknown fields' );
	$check( 7 === $repo->read_bounded( 'migrated-revision' ) && false === $repo->read_bounded( 'checkpoint' ) && array() === $repo->read_bounded( 'empty' ), 'revision, explicit false and empty list retain their exact presence and types' );
	$head = $reader->head( 'migrated' );
	$check( 1 === $head['active_generation'] && 1 === $head['revision'] && 6 === $head['record_count'], 'head publishes verified generation, revision and complete physical row count' );
	$other = new TableSnapshot( new LegacyTransactionConnection( $wpdb ), $blog + 10 );
	$other->replace_many( array( $change( 'migrated', 0, 'map', array( 'other' => false ) ) ) );
	$check( array( 'other' => false ) === $other->read_bounded( 'migrated' ) && $codec->encode( $initial ) === $codec->encode( $repo->read_bounded( 'migrated' ) ), 'same keys/orders are isolated across two blog IDs in shared tables' );

	// Separate autocommit connection must see ALL old keys even after one head
	// was internally flipped, then ALL new keys after commit.
	$observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$observer->base_prefix = $wpdb->base_prefix;
	$observer_repo = new TableSnapshot( new LegacyTransactionConnection( $observer ), $blog );
	$observations = 0;
	$connection->after_write = function ( $sql ) use ( $observer_repo, $codec, $initial, &$observations, $check ) {
		if ( false !== strpos( $sql, 'SET active_generation' ) ) {
			$check( $codec->encode( $initial ) === $codec->encode( $observer_repo->read_bounded( 'migrated' ) ) &&
				7 === $observer_repo->read_bounded( 'migrated-revision' ) && false === $observer_repo->read_bounded( 'checkpoint' ),
				'external autocommit reader sees complete old state before commit #' . ++$observations );
		}
	};
	$updated = $initial; $updated['009']['migration_status'] = 'complete'; $updated['009']['migrated'] = true;
	$repo->replace_many( array( $change( 'migrated', 0, 'map', $updated, 1 ), $change( 'migrated-revision', 1, 'scalar', 8, 1 ), $change( 'checkpoint', 2, 'scalar', array( 'cursor' => '009' ), 1 ) ) );
	$connection->after_write = null;
	$check( 3 === $observations && $codec->encode( $updated ) === $codec->encode( $observer_repo->read_bounded( 'migrated' ) ) &&
		8 === $observer_repo->read_bounded( 'migrated-revision' ) && array( 'cursor' => '009' ) === $observer_repo->read_bounded( 'checkpoint' ), 'external reader sees every new coupled value after one commit' );
	$check( 2 === $reader->head( 'migrated' )['active_generation'] && 2 === $reader->head( 'migrated' )['revision'], 'successful replacement increments generation and revision exactly once' );
	$frozen = $raw_snapshot();
	$refuses( function () use ( $repo, $change ) { $repo->replace_many( array( $change( 'checkpoint', 2, 'scalar', 'stale', 1 ) ) ); }, 'stale writer cannot overwrite newer checkpoint' );
	$check( $frozen === $raw_snapshot(), 'stale writer leaves every physical row and head unchanged' );
	$wpdb->query( 'START TRANSACTION' );
	$refuses( function () use ( $repo, $change ) { $repo->replace_many( array( $change( 'checkpoint', 2, 'scalar', 'ambient', 2 ) ) ); }, 'ambient caller transaction is refused before nested START' );
	$check( '1' === (string) $wpdb->get_var( 'SELECT @@session.in_transaction' ), 'refusal preserves active caller transaction without implicit commit or rollback' );
	$wpdb->query( 'ROLLBACK' );
	$wpdb->query( 'SET autocommit=0' );
	$refuses( function () use ( $repo, $change ) { $repo->replace_many( array( $change( 'checkpoint', 2, 'scalar', 'autocommit-disabled', 2 ) ) ); }, 'disabled autocommit is refused before START' );
	$wpdb->query( 'ROLLBACK' ); $wpdb->query( 'SET autocommit=1' );
	$check( $frozen === $raw_snapshot(), 'ambient and disabled-autocommit refusals preserve every committed row/head' );

	foreach ( array( 'record-insert', 'first-flip', 'second-flip' ) as $fault ) {
		$flips = 0;
		$connection->before_write = function ( $sql ) use ( $fault, &$flips ) {
			if ( false !== strpos( $sql, 'SET active_generation' ) ) { $flips++; }
			if ( ( 'record-insert' === $fault && false !== strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, '_records`' ) ) ||
				( 'first-flip' === $fault && 1 === $flips && false !== strpos( $sql, 'SET active_generation' ) ) ||
				( 'second-flip' === $fault && 2 === $flips && false !== strpos( $sql, 'SET active_generation' ) ) ) {
				throw new RuntimeException( 'Injected fixture failure: ' . $fault );
			}
		};
		$refuses( function () use ( $repo, $change, $updated ) {
			$repo->replace_many( array( $change( 'migrated', 0, 'map', $updated, 2 ), $change( 'checkpoint', 2, 'scalar', 'not-committed', 2 ) ) );
		}, 'real transaction refuses injected ' . $fault );
		$connection->before_write = null;
		$check( $frozen === $raw_snapshot() && $codec->encode( $updated ) === $codec->encode( $observer_repo->read_bounded( 'migrated' ) ), 'real rollback restores all old heads, root payloads and staged rows after ' . $fault );
	}
	$refuses( function () use ( $repo, $change ) { $repo->replace_many( array( $change( 'order-collision', 0, 'scalar', true ) ) ); }, 'real unique-order SQL constraint failure is refused' );
	$check( $frozen === $raw_snapshot(), 'SQL constraint failure rolls back inserted rows and leaves every head unchanged' );

	// Kill a real dedicated connection after a head UPDATE and before COMMIT.
	// Pinned mysqli must not reconnect/replay outside the lost transaction.
	foreach ( array( 'after-first-flip', 'before-commit' ) as $boundary ) {
		$victim = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $victim->base_prefix = $wpdb->base_prefix;
		$victim_id = (int) $victim->get_var( 'SELECT CONNECTION_ID()' );
		$loss = new FooGmSnapshotConnection( $victim );
		$loss_repo = new TableSnapshot( $loss, $blog ); $killed = false;
		$kill = function ( $sql ) use ( $boundary, $observer, $victim_id, &$killed, $check ) {
			$at_boundary = 'before-commit' === $boundary ? 'COMMIT' === $sql : false !== strpos( $sql, 'SET active_generation' );
			if ( ! $killed && $at_boundary ) {
				$check( false !== $observer->query( 'KILL CONNECTION ' . $victim_id ), 'real server connection is killed ' . $boundary );
				$killed = true;
			}
		};
		if ( 'before-commit' === $boundary ) { $loss->before_write = $kill; } else { $loss->after_write = $kill; }
		$refuses( function () use ( $loss_repo, $change, $updated ) {
			$loss_repo->replace_many( array( $change( 'migrated', 0, 'map', $updated, 2 ), $change( 'checkpoint', 2, 'scalar', 'lost', 2 ) ) );
		}, 'lost transaction is refused without automatic SQL replay ' . $boundary );
		$check( $killed && $frozen === $raw_snapshot(), 'server-disconnected transaction leaves every committed row/head unchanged ' . $boundary );
		$attempts = count( $loss->queries );
		$refuses( function () use ( $loss_repo ) { $loss_repo->read_bounded( 'migrated' ); }, 'uncertain repository refuses reuse ' . $boundary );
		$check( $attempts === count( $loss->queries ), 'tainted repository sends no further SQL ' . $boundary );
	}
	$before = count( $connection->queries );
	$refuses( function () use ( $repo, $change ) { $repo->replace_many( array( $change( 'checkpoint', 2, 'scalar', new stdClass(), 2 ) ) ); }, 'unsupported historical object is refused, not compacted or normalized' );
	$check( $before === count( $connection->queries ), 'historical-object refusal happens before any SQL' );

	$id = $reader->head( 'migrated' )['id'];
	$record_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $tables[1] . '` WHERE blog_id=%d AND state_id=%d AND generation=2 ORDER BY id LIMIT 1', $blog, $id ) );
	$original = $wpdb->get_row( $wpdb->prepare( 'SELECT payload, mutable_payload, is_migrated FROM `' . $tables[1] . '` WHERE id=%d', $record_id ), ARRAY_A );
	foreach ( array( 'payload' => $original['payload'] . ' ', 'mutable_payload' => $codec->encode( array( 'migrated' => false ) ), 'is_migrated' => 0 ) as $column => $bad ) {
		$wpdb->update( $tables[1], array( $column => $bad ), array( 'id' => $record_id ) );
		$refuses( function () use ( $repo ) { $repo->read_bounded( 'migrated' ); }, 'DB ' . $column . ' corruption fails closed' );
		$wpdb->update( $tables[1], array( $column => $original[ $column ] ), array( 'id' => $record_id ) );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET snapshot_digest=UNHEX(%s) WHERE id=%d', str_repeat( '0', 64 ), $id ) );
	$refuses( function () use ( $repo ) { $repo->read_bounded( 'migrated' ); }, 'complete snapshot digest corruption is refused' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET snapshot_digest=UNHEX(%s) WHERE id=%d', $codec->snapshot_digest( $updated ), $id ) );
	$small = new TableSnapshot( $connection, $blog, 2, 1000 );
	$before = count( $connection->queries );
	$refuses( function () use ( $small ) { $small->read_bounded( 'migrated' ); }, 'oversized complete snapshot is refused from head metadata' );
	$check( count( $connection->queries ) === $before + 1 && false !== strpos( end( $connection->queries ), 'OCTET_LENGTH(root_payload)' ), 'row-budget refusal does not fetch record/root payloads' );

	// Occupied next generation must not be overwritten or adopted implicitly.
	$columns = array_keys( $schema->definitions()[ $tables[1] ]['columns'] ); $columns = array_values( array_diff( $columns, array( 'id' ) ) );
	$source = array_map( function ( $column ) { return 'generation' === $column ? '3' : '`' . $column . '`'; }, $columns );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO `' . $tables[1] . '` (`' . implode( '`,`', $columns ) . '`) SELECT ' . implode( ',', $source ) . ' FROM `' . $tables[1] . '` WHERE id=%d', $record_id ) );
	$frozen_orphan = $raw_snapshot();
	$refuses( function () use ( $repo, $change, $updated ) { $repo->replace_many( array( $change( 'migrated', 0, 'map', $updated, 2 ) ) ); }, 'occupied inactive generation requires explicit recovery' );
	$check( $frozen_orphan === $raw_snapshot(), 'orphan refusal preserves inactive rows and authoritative heads' );
	$check( $schema->verify(), 'executed writes/refusals preserve exact schema health' );
} finally {
	if ( $observer ) { $observer->close(); }
	foreach ( array_reverse( $created ) as $table ) {
		if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); }
	}
}
foreach ( $tables as $table ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'read-back confirms removed fixture table: ' . $table );
}
foreach ( $options as $name => $value ) { $check( get_option( $name, $missing ) === $value, 'read-back confirms option untouched: ' . $name ); }
print wp_json_encode( array( 'status' => 'pass', 'scope' => 'inert atomic bounded snapshot primitive only; no manager, conversion, opt-in or authority flip',
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->db_version(), 'blog_id' => get_current_blog_id(),
	'checks' => $checks, 'peak_bytes' => memory_get_peak_usage( true ) ), JSON_PRETTY_PRINT ) . "\n";
