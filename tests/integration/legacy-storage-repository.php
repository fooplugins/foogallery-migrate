<?php
/** Disposable WP/MariaDB affected-tree checks. Never run on a live site. */
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\OptionMigratedStore;

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
	public $after_read;
	public $fail_at_flip = 0;
	private $flips = 0;
	public function get_results( $sql, $format ) {
		$this->reads[] = $sql; $result = parent::get_results( $sql, $format );
		if ( $this->after_read ) { call_user_func( $this->after_read, $sql ); }
		return $result;
	}
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
foreach ( array( 'LegacyOptionBackend' => 'legacy-option-backend', 'LegacyTableBackend' => 'legacy-table-backend', 'LegacyTableRepository' => 'legacy-table-repository', 'TableSnapshot' => 'table-snapshot', 'LegacyTransactionConnection' => 'legacy-transaction-connection', 'MigratorSettings' => 'migrator-settings' ) as $class => $file ) {
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
	$probe = new FooGMRepositoryProbe( $wpdb ); $snapshot = new TableSnapshot( $probe, 1 );
	$fixture = array( '09' => $root, 9 => false, 'untouched' => array( 'opaque' => str_repeat( 'x', 100000 ) ) );
	$snapshot->replace_many( array( array( 'key' => 'history', 'order' => 0, 'expected_revision' => null, 'kind' => 'map', 'value' => $fixture ),
		array( 'key' => 'checkpoint', 'order' => 1, 'expected_revision' => null, 'kind' => 'scalar', 'value' => false ) ) );
	( new TableSnapshot( $probe, 2 ) )->replace_many( array( array( 'key' => 'history', 'order' => 0, 'expected_revision' => null, 'kind' => 'map', 'value' => array( '09' => null ) ) ) );
	$repo = new LegacyTableRepository( $probe, 1 );
	// WP option writes reject invalid UTF-8; table-only binary parity is checked below.
	$common = $fixture; $common['09']['future'] = "valid\x00雪";
	( new TableSnapshot( $probe, 4 ) )->replace_many( array(
		array( 'key' => 'history', 'order' => 0, 'expected_revision' => null, 'kind' => 'map', 'value' => $common ),
		array( 'key' => 'checkpoint', 'order' => 1, 'expected_revision' => null, 'kind' => 'scalar', 'value' => false ) ) );
	$option_backend = new LegacyOptionBackend(); $table_backend = new LegacyTableBackend( $probe, 4 );
	$check( $option_backend->replace_all( array( 'history' => $common, 'checkpoint' => false ) ), 'Disposable raw option fixture committed' );
	$keys = array( 9, 'missing', '09' );
	$check( $option_backend->roots( 'history', $keys ) === $table_backend->roots( 'history', $keys ), 'Common option/table raw roots preserve request order, missing and false' );
	$check( $option_backend->read( 'checkpoint' ) === $table_backend->read( 'checkpoint' ), 'Common raw scalar false presence parity' );
	$check( $option_backend->read( 'absent' ) === $table_backend->read( 'absent' ), 'Common missing raw scalar presence parity without option fallback' );
	$expect_refusal( function () use ( $table_backend ) { $table_backend->read( 'history' ); }, 'Table backend refuses whole collection scalar facade' );
	$expect_refusal( function () use ( $probe ) { ( new LegacyTableRepository( $probe, 1, 2 ) )->roots( 'history', array( '09', 9 ) ); }, 'Multi-root read shares aggregate row budget across complete trees' );
	$legacy = new stdClass(); $legacy->unknown = array( '09' => null, 9 => false );
	$check( $option_backend->replace_all( array( 'legacy' => $legacy ) ), 'Real WP serialized historical-object write succeeds' );
	$settings = new MigratorSettings();
	$check( $settings->set_migrator_settings( array( 'checkpoint' => 9 ) ) && $settings->set_migrator_settings( array( 'checkpoint' => 9 ) ), 'Real WP changed and same-value writes succeed with rehydrated historical objects' );
	$check( serialize( $option_backend->read( 'legacy' )['value'] ) === serialize( $legacy ), 'Real WP historical object retains unknown typed fields across unrelated writes' );
	delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$check( ( new OptionMigratedStore() )->clear() && ! $option_backend->exists(), 'Real WP empty-history clear preserves absent option' );
	$check( $settings->clear_migrator_setting() && $option_backend->exists(), 'Explicit full settings clear still creates an empty option' );
	if ( $options[FOOGALLERY_MIGRATE_OPTION_DATA] === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
	else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $options[FOOGALLERY_MIGRATE_OPTION_DATA], false ); }
	$list = array( str_repeat( 'a', 1000 ), str_repeat( 'b', 1000 ) );
	( new TableSnapshot( $probe, 3 ) )->replace_many( array( array( 'key' => 'list', 'order' => 0, 'expected_revision' => null, 'kind' => 'list', 'value' => $list ) ) );
	$limited_list = new LegacyTableBackend( $probe, 3, 100, 2500 );
	$check( $limited_list->roots( 'list', array( 0 ) )[0]['value'] === $list[0] && $limited_list->roots( 'list', array( 1 ) )[0]['value'] === $list[1], 'Each list root fits individual byte budget' );
	$expect_refusal( function () use ( $limited_list ) { $limited_list->roots( 'list', array( 0, 1 ) ); }, 'Multi-root byte budget is aggregate, not multiplied by request length' );
	$race = new FooGMRepositoryProbe( $wpdb ); $race_backend = new LegacyTableBackend( $race, 3 );
	$race_writer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $race_writer->base_prefix = $wpdb->base_prefix;
	$race_snapshot = new TableSnapshot( new LegacyTransactionConnection( $race_writer ), 3 ); $race_committed = false;
	$race->after_read = function ( $sql ) use ( $race, $race_snapshot, $list, &$race_committed ) {
		if ( false === strpos( $sql, '`payload`' ) ) { return; }
		$race->after_read = null; $next = $list; $next[1] = 'new committed value';
		$race_snapshot->replace_many( array( array( 'key' => 'list', 'order' => 0, 'expected_revision' => 1, 'kind' => 'list', 'value' => $next ) ) );
		$race_committed = true;
	};
	$expect_refusal( function () use ( $race_backend ) { $race_backend->roots( 'list', array( 0, 1 ) ); }, 'Concurrent generation change refuses complete multi-root result' );
	$check( $race_committed && $race->after_read === null && $race_backend->roots( 'list', array( 1 ) )[0]['value'] === 'new committed value', 'Independent writer committed and fresh bounded retry sees one generation' );
	$race_writer->close();
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
	$payload_reads = array_filter( $limited->reads, function ( $sql ) { return false !== strpos( $sql, '`payload`' ) && false !== strpos( $sql, 'records' ); } );
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
	if ( $options[FOOGALLERY_MIGRATE_OPTION_DATA] === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
	else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $options[FOOGALLERY_MIGRATE_OPTION_DATA], false ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); }
}
foreach ( $tables as $table ) { $check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Read-back confirms fixture table removed: ' . $table ); }
foreach ( $options as $key => $value ) { $check( get_option( $key, $missing ) === $value, 'Read-back confirms option untouched: ' . $key ); }
print json_encode( array( 'status' => 'pass', 'scope' => 'raw option/table seam parity and inert bounded affected-tree repository only', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ), 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
