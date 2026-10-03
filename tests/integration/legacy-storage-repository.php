<?php
/** Disposable WP/MariaDB affected-tree checks. Never run on a live site. */
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;

if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Explicit disposable WP-CLI opt-in required.' );
}
$plugin = getenv( 'FOOGM_INTEGRATION_PLUGIN_FILE' );
if ( ! $plugin ) { $plugin = dirname( dirname( __DIR__ ) ) . '/migrate.php'; }
require_once $plugin;
if ( ! class_exists( 'FooPlugins\\FooGalleryMigrate\\LegacyTableRepository' ) ) { throw new RuntimeException( 'Affected-root repository is absent.' ); }

class FooGMRepositoryProbe extends LegacyTransactionConnection {
	public $reads = array();
	public $writes = array();
	public $before_commit;
	public $fail_at_flip = 0;
	private $flips = 0;
	public function get_results( $sql, $format ) { $this->reads[] = $sql; return parent::get_results( $sql, $format ); }
	public function query( $sql ) {
		$this->writes[] = $sql;
		if ( 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, 'digest_status' ) && ++$this->flips === $this->fail_at_flip ) {
			$this->last_error = 'Injected head publication failure.'; return false;
		}
		if ( 'COMMIT' === $sql && $this->before_commit ) { call_user_func( $this->before_commit ); }
		return parent::query( $sql );
	}
}

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array(); $checks = array();
$names = array_keys( $schema->definitions() ); $tables = array( 'state' => $names[0], 'records' => $names[1] );
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$expect_refusal = function ( $fn, $label ) use ( $check ) {
	try { $fn(); } catch ( RuntimeException $error ) { $check( true, $label ); return; }
	throw new RuntimeException( 'Expected refusal: ' . $label );
};
foreach ( array( 'LegacyTableRepository' => 'legacy-table-repository', 'LegacyTableSnapshot' => 'legacy-table-snapshot', 'LegacyTransactionConnection' => 'legacy-transaction-connection', 'MigratorSettings' => 'migrator-settings' ) as $class => $file ) {
	$reflection = new ReflectionClass( 'FooPlugins\\FooGalleryMigrate\\' . $class );
	$check( realpath( $reflection->getFileName() ) === realpath( dirname( $plugin ) . '/includes/class-' . $file . '.php' ), 'Actual bootstrap loaded expected source/package class: ' . $class );
}
foreach ( $tables as $table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Refuse existing owned table.' ); }
}
$owned_options = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, 'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority', 'foogallery_migrate_storage_schema_version' );
$options = array(); $missing = new stdClass();
foreach ( $owned_options as $key ) { $options[$key] = get_option( $key, $missing ); }
$dump = function () use ( $wpdb, $tables ) {
	return array( $wpdb->get_results( "SELECT * FROM `{$tables['state']}` ORDER BY id", ARRAY_A ), $wpdb->get_results( "SELECT * FROM `{$tables['records']}` ORDER BY id", ARRAY_A ) );
};
$root = array( 'object_type' => 'gallery', 'plugin_name' => 'Exact', 'ID' => '09', 'migrated' => false, 'migrated_id' => 0,
	'future' => "\xff\x00", 'children' => array( '09' => array( 'object_type' => 'image', 'plugin_name' => 'Exact', 'ID' => 9, 'migrated' => true, 'migrated_id' => 44 ) ) );
try {
	foreach ( $schema->statements() as $sql ) { if ( false === $wpdb->query( $sql ) ) { throw new RuntimeException( 'Fixture DDL failed.' ); } }
	$schema->verify();
	$probe = new FooGMRepositoryProbe( $wpdb ); $snapshot = new LegacyTableSnapshot( $probe, 1 );
	$fixture = array( '09' => $root, 9 => false, 'untouched' => array( 'opaque' => str_repeat( 'x', 100000 ) ) );
	$snapshot->replace_many( array( array( 'key' => 'history', 'order' => 0, 'expected_revision' => null, 'kind' => 'map', 'value' => $fixture ),
		array( 'key' => 'checkpoint', 'order' => 1, 'expected_revision' => null, 'kind' => 'scalar', 'value' => false ) ) );
	( new LegacyTableSnapshot( $probe, 2 ) )->replace_many( array( array( 'key' => 'history', 'order' => 0, 'expected_revision' => null, 'kind' => 'map', 'value' => array( '09' => null ) ) ) );
	$repo = new LegacyTableRepository( $probe, 1 );
	$check( $repo->root( 'history', '09' ) === array( 'found' => true, 'key' => '09', 'value' => $root ), 'Exact compact tree, typed keys and opaque binary fields survive' );
	$check( $repo->root( 'history', 9 )['value'] === false && ! $repo->root( 'history', 'missing' )['found'], 'Explicit false differs from missing and string key' );
	$check( ( new LegacyTableRepository( $probe, 2 ) )->root( 'history', '09' )['value'] === null, 'Shared table operations are blog-scoped' );
	$p = $repo->page( 'history', 1 ); $p2 = $repo->page( 'history', 1, $p['cursor'] );
	$check( $p['rows'][0]['key'] === '09' && $p2['rows'][0]['key'] === 9, 'Keyset root pages preserve legacy map order and key type' );
	$expect_refusal( function () use ( $probe, $p ) { ( new LegacyTableRepository( $probe, 2 ) )->page( 'history', 1, $p['cursor'] ); }, 'Foreign blog cursor is refused' );
	$check( $repo->count_roots( 'history' ) === 3, 'Metadata-only exact root count' );
	$before = $dump(); $changed = $root; $changed['migrated'] = true; $changed['migrated_id'] = 87; $changed['error'] = false;
	$heads = $repo->patch_many( array( array( 'key' => 'history', 'expected_revision' => 1, 'patches' => array( array( 'key' => '09', 'value' => $changed ) ) ),
		array( 'key' => 'checkpoint', 'expected_revision' => 1, 'value' => null ) ) );
	$check( $repo->root( 'history', '09' )['value'] === $changed && $repo->scalar( 'checkpoint' ) === null, 'Affected root and companion checkpoint commit together' );
	$head = $wpdb->get_row( "SELECT revision, active_generation, digest_status FROM `{$tables['state']}` WHERE blog_id=1 AND state_order=0", ARRAY_A );
	$check( $head['revision'] === '2' && $head['active_generation'] === '1' && $head['digest_status'] === 'dirty', 'Patch increments revision, retains generation and dirties complete digest' );
	$after = $dump(); $unchanged_before = array(); $unchanged_after = array();
	foreach ( $before[1] as $row ) { if ( null === $row['parent_node_hash'] && $row['item_order'] !== '0' || $row['blog_id'] === '2' ) { $unchanged_before[] = $row; } }
	foreach ( $after[1] as $row ) { if ( null === $row['parent_node_hash'] && $row['item_order'] !== '0' || $row['blog_id'] === '2' ) { $unchanged_after[] = $row; } }
	$check( $unchanged_before === $unchanged_after, 'Unrelated large root and foreign-blog rows remain byte-identical with same physical IDs' );
	$expect_refusal( function () use ( $repo, $p ) { $repo->page( 'history', 1, $p['cursor'] ); }, 'Stale revision cursor is refused' );
	$expect_refusal( function () use ( $snapshot ) { $snapshot->read_bounded( 'history' ); }, 'Dirty full snapshot is not misrepresented as digest-verified' );
	$stable = $dump();
	$expect_refusal( function () use ( $repo ) { $repo->patch_many( array( array( 'key' => 'history', 'expected_revision' => 1, 'patches' => array( array( 'key' => '09', 'delete' => true ) ) ) ) ); }, 'Stale patch CAS is refused' );
	$check( $stable === $dump(), 'Stale patch leaves all rows and heads unchanged' );
	$fault = new FooGMRepositoryProbe( $wpdb ); $fault->fail_at_flip = 2;
	$expect_refusal( function () use ( $fault ) { ( new LegacyTableRepository( $fault, 1 ) )->patch_many( array(
		array( 'key' => 'history', 'expected_revision' => 2, 'patches' => array( array( 'key' => '09', 'delete' => true ) ) ),
		array( 'key' => 'checkpoint', 'expected_revision' => 2, 'value' => 99 ) ) ); }, 'Injected second-head failure is refused' );
	$check( $stable === $dump(), 'Real rollback restores affected rows and first companion head' );
	$repo->patch_many( array( array( 'key' => 'history', 'expected_revision' => 2, 'patches' => array( array( 'key' => 9, 'delete' => true ), array( 'key' => 'new', 'value' => false ) ) ) ) );
	$all = $repo->page( 'history', 10 );
	$check( array_column( $all['rows'], 'key' ) === array( '09', 'untouched', 'new' ) && $repo->count_roots( 'history' ) === 3, 'Delete and append retain logical order despite physical gaps' );
	$check( ! $repo->root( 'history', 9 )['found'] && $repo->root( 'history', 'new' )['value'] === false, 'Missing-after-delete and appended explicit false remain distinct' );
	$observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$atomic = new FooGMRepositoryProbe( $wpdb );
	$atomic->before_commit = function () use ( $observer, $tables, $check, $expect_refusal, &$atomic_repo ) {
		$seen = $observer->get_results( "SELECT revision FROM `{$tables['state']}` WHERE blog_id=1 ORDER BY state_order", ARRAY_A );
		$check( array_column( $seen, 'revision' ) === array( '3', '2' ), 'External autocommit reader sees both old heads before COMMIT' );
		$expect_refusal( function () use ( &$atomic_repo ) { $atomic_repo->root( 'history', 'new' ); }, 'Reentrant repository read is refused before SQL' );
	};
	$atomic_repo = new LegacyTableRepository( $atomic, 1 );
	$atomic_repo->patch_many( array( array( 'key' => 'history', 'expected_revision' => 3, 'patches' => array( array( 'key' => 'new', 'value' => null ) ) ),
		array( 'key' => 'checkpoint', 'expected_revision' => 2, 'value' => 99 ) ) );
	$seen = $observer->get_results( "SELECT revision FROM `{$tables['state']}` WHERE blog_id=1 ORDER BY state_order", ARRAY_A );
	$check( array_column( $seen, 'revision' ) === array( '4', '3' ) && $snapshot->read_bounded( 'checkpoint' ) === 99, 'External reader sees both new heads and current verified scalar digest after COMMIT' );
	$wpdb->query( 'START TRANSACTION' );
	try {
		$expect_refusal( function () use ( $repo ) { $repo->patch_many( array( array( 'key' => 'checkpoint', 'expected_revision' => 3, 'value' => 98 ) ) ); }, 'Caller-owned ambient transaction is refused' );
		$check( $wpdb->get_var( 'SELECT @@SESSION.in_transaction' ) === '1', 'Ambient refusal does not implicitly commit or roll back caller transaction' );
	} finally { $wpdb->query( 'ROLLBACK' ); }
	$stable = $dump();
	$client = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$client->set_prefix( $wpdb->base_prefix );
	$lost = new FooGMRepositoryProbe( $client );
	$lost->before_commit = function () use ( $wpdb, $client ) { $wpdb->query( 'KILL CONNECTION ' . (int) $client->get_var( 'SELECT CONNECTION_ID()' ) ); };
	$lost_repo = new LegacyTableRepository( $lost, 1 );
	$expect_refusal( function () use ( $lost_repo ) { $lost_repo->patch_many( array( array( 'key' => 'checkpoint', 'expected_revision' => 3, 'value' => 98 ) ) ); }, 'Server-disconnected COMMIT is refused without automatic replay' );
	$check( $stable === $dump(), 'Server-disconnected transaction leaves every committed row and head unchanged' );
	$query_count = count( $lost->reads ) + count( $lost->writes );
	$expect_refusal( function () use ( $lost_repo ) { $lost_repo->scalar( 'checkpoint' ); }, 'Uncertain repository refuses reuse' );
	$check( $query_count === count( $lost->reads ) + count( $lost->writes ), 'Tainted repository sends no subsequent SQL' );
	$limited = new FooGMRepositoryProbe( $wpdb );
	$expect_refusal( function () use ( $limited ) { ( new LegacyTableRepository( $limited, 1, 100, 5000 ) )->root( 'history', 'untouched' ); }, 'Oversized root is refused from byte metadata' );
	$payload_reads = array_filter( $limited->reads, function ( $sql ) { return false !== strpos( $sql, '`payload`' ) && false !== strpos( $sql, 'legacy_records' ); } );
	$check( ! $payload_reads, 'Oversized root refusal occurs before any LONGTEXT record fetch' );
	$checkpoint = $wpdb->get_row( "SELECT id, HEX(snapshot_digest) AS digest FROM `{$tables['state']}` WHERE blog_id=1 AND state_order=1", ARRAY_A );
	$wpdb->query( "UPDATE `{$tables['state']}` SET snapshot_digest=UNHEX('" . str_repeat( '0', 64 ) . "') WHERE id=" . (int) $checkpoint['id'] );
	$expect_refusal( function () use ( $repo ) { $repo->scalar( 'checkpoint' ); }, 'Scalar checkpoint digest corruption is refused' );
	$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['state']}` SET snapshot_digest=UNHEX(%s) WHERE id=%d", $checkpoint['digest'], $checkpoint['id'] ) );
	$record = $wpdb->get_row( "SELECT * FROM `{$tables['records']}` WHERE blog_id=1 AND parent_node_hash IS NULL AND item_order=0", ARRAY_A );
	$wpdb->query( "UPDATE `{$tables['records']}` SET is_migrated=0 WHERE id=" . (int) $record['id'] );
	$expect_refusal( function () use ( $repo ) { $repo->root( 'history', '09' ); }, 'Projection corruption is refused instead of hydrating plausible state' );
	$wpdb->query( "UPDATE `{$tables['records']}` SET is_migrated=1 WHERE id=" . (int) $record['id'] );
	$schema->verify();
} finally {
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); }
}
foreach ( $tables as $table ) { $check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Read-back confirms fixture table removed: ' . $table ); }
foreach ( $options as $key => $value ) { $check( get_option( $key, $missing ) === $value, 'Read-back confirms option untouched: ' . $key ); }
print json_encode( array( 'status' => 'pass', 'scope' => 'inert bounded affected-tree repository only', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ), 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
