<?php
/** Disposable-only lifecycle evidence; never enables table authority or runs uninstall. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$plugin_root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $plugin_root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once dirname( __DIR__ ) . '/fixtures/legacy-runtime-contract-fixtures.php';

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;

// Any SQL or blog enumeration/switch inside a lifecycle callback is a failure.
function foogm_lifecycle_forbid_query( $sql ) { throw new RuntimeException( 'Lifecycle attempted SQL: ' . $sql ); }
function foogm_lifecycle_forbid_sites( $sites, $query ) { throw new RuntimeException( 'Lifecycle enumerated sites.' ); }
function foogm_lifecycle_forbid_switch() { throw new RuntimeException( 'Lifecycle switched blog context.' ); }

global $wpdb;
$initial_blog = (int) get_current_blog_id();
$initial_prefix = $wpdb->prefix;
$blogs = array( $initial_blog );
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 20 ) ) as $id ) { if ( (int) $id !== $initial_blog ) { $blogs[] = (int) $id; break; } }
	if ( count( $blogs ) < 2 ) { throw new RuntimeException( 'Two registered disposable blogs required.' ); }
}
$keys = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, MigratedStore::SCHEMA_OPTION,
	MigratedStore::MIGRATION_OPTION, MigratedStore::LEGACY_BACKUP_OPTION, StorageAuthority::CONTROL_OPTION,
	StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, LegacyStorageSchema::VERSION_OPTION,
	LegacyStorageSchema::LOCK_OPTION, 'foogallery-migrate-data-storage-backup', 'foogallery_migrate_storage_writer_fixture' );
$schema = new LegacyStorageSchema( $wpdb );
$shared = array_keys( $schema->definitions() );
$tables = $shared;
foreach ( $blogs as $blog ) { $tables[] = $wpdb->get_blog_prefix( $blog ) . 'foogallery_migrate_objects'; }
foreach ( $tables as $table ) {
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Refusing existing fixture tables or unhealthy database.' );
	}
}
// Never overwrite existing migration state, even on a disposable database.
foreach ( $blogs as $blog ) {
	$option_table = $wpdb->get_blog_prefix( $blog ) . 'options';
	foreach ( $keys as $key ) {
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM `' . $option_table . '` WHERE option_name = %s', $key ) ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Refusing pre-existing option fixture.' );
		}
	}
}
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label;
};
$physical = function ( $existing ) use ( $wpdb, $blogs ) {
	$state = array();
	foreach ( $blogs as $blog ) {
		$table = $wpdb->get_blog_prefix( $blog ) . 'options';
		$state[$table] = $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `' . $table . '` ORDER BY option_name', ARRAY_A );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Option snapshot failed.' ); }
	}
	foreach ( $existing as $table ) {
		$state[$table] = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Table snapshot failed.' ); }
	}
	return serialize( $state );
};
$roundtrip = function ( $network, $label ) use ( $wpdb, $check ) {
	$blog = get_current_blog_id(); $prefix = $wpdb->prefix;
	add_filter( 'query', 'foogm_lifecycle_forbid_query', PHP_INT_MAX );
	add_filter( 'sites_pre_query', 'foogm_lifecycle_forbid_sites', PHP_INT_MAX, 2 );
	add_action( 'switch_blog', 'foogm_lifecycle_forbid_switch', PHP_INT_MAX );
	try {
		for ( $i = 0; $i < 2; $i++ ) {
			do_action( 'activate_' . plugin_basename( FOOGM_FILE ), $network );
			$check( foogallery_migrate_install_store_current_site(), $label . ' compatibility install no-op' );
			foogallery_migrate_upgrade_store();
			do_action( 'deactivate_' . plugin_basename( FOOGM_FILE ), $network );
			do_action( 'activate_' . plugin_basename( FOOGM_FILE ), $network );
			$check( ( new MigratedStore( $wpdb ) )->deactivate_schema(), $label . ' superseded API no-op' );
		}
	} finally {
		remove_filter( 'query', 'foogm_lifecycle_forbid_query', PHP_INT_MAX );
		remove_filter( 'sites_pre_query', 'foogm_lifecycle_forbid_sites', PHP_INT_MAX );
		remove_action( 'switch_blog', 'foogm_lifecycle_forbid_switch', PHP_INT_MAX );
	}
	$check( $blog === get_current_blog_id() && $prefix === $wpdb->prefix, $label . ' zero SQL/enumeration/context switch' );
};
$created = array(); $seeded = array(); $switched = false;
try {
	$check( false !== has_action( 'activate_' . plugin_basename( FOOGM_FILE ), 'foogallery_migrate_activate' ) &&
		false !== has_action( 'deactivate_' . plugin_basename( FOOGM_FILE ), 'foogallery_migrate_deactivate' ), 'bootstrap-scoped lifecycle hooks registered' );
	$check( false === has_action( 'admin_init', 'foogallery_migrate_upgrade_store' ), 'automatic admin upgrade hook absent' );
	$before = $physical( array() ); $roundtrip( false, 'virgin site' );
	if ( is_multisite() ) { $roundtrip( true, 'virgin network' ); }
	$check( $before === $physical( array() ), 'virgin lifecycle creates no option state' );
	foreach ( $tables as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'virgin lifecycle creates no table: ' . $table ); }
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $shared[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'explicit fixture shared schema verified' );
	$fixture = \FooPlugins\FooGalleryMigrate\Tests\Fixtures\legacy_runtime_contract_fixture( false );
	foreach ( $blogs as $blog ) {
		if ( $blog !== $initial_blog ) { switch_to_blog( $blog ); $switched = true; }
		$seeded[] = $blog;
		$data = $fixture['logical'];
		$data['queue'] = array( array( 'status' => 'started', 'cursor' => 27, 'selected' => true ) );
		$values = array( $data, array( 'template' => 'default', 'unknown' => null ), 1, array( 'phase' => 'interrupted' ),
			array( 'stale' => 'never restore automatically' ), array( 'backend' => 'table', 'phase' => 'failed', 'epoch' => 9 ),
			array( 'epoch' => 8 ), array( 'token' => 'maintenance-owner' ), 1, array( 'token' => 'schema-owner' ),
			array( 'immutable' => false ), array( 'token' => 'writer-owner' ) );
		foreach ( $keys as $i => $key ) { $check( update_option( $key, $values[$i], false ), 'seed option: ' . $blog . '/' . $key ); }
		$old = $wpdb->prefix . 'foogallery_migrate_objects'; $created[] = $old;
		$check( false !== $wpdb->query( 'CREATE TABLE `' . $old . '` (id bigint NOT NULL PRIMARY KEY, payload longtext NOT NULL) ENGINE=InnoDB' ), 'explicit partial prior table created' );
		$check( false !== $wpdb->insert( $old, array( 'id' => 1, 'payload' => 'retained-prior-history' ) ), 'prior table fixture seeded' );
		$db = new LegacyTransactionConnection( $wpdb ); $snapshot = new LegacyTableSnapshot( $db, $blog );
		$snapshot->replace_many( array(
			array( 'key' => 'migrated', 'order' => 0, 'kind' => 'map', 'expected_revision' => null, 'value' => array( 'same-identifier' => array( 'object_type' => 'image', 'plugin_name' => 'Case', 'ID' => '009', 'migrated' => true, 'migrated_id' => 7100 + $blog ) ) ),
			array( 'key' => 'queue', 'order' => 1, 'kind' => 'list', 'expected_revision' => null, 'value' => array( array( 'status' => 'started', 'cursor' => 27 ) ) ),
			array( 'key' => 'migrated-revision', 'order' => 2, 'kind' => 'scalar', 'expected_revision' => null, 'value' => 4 ) ) );
		$backend = new LegacyTableBackend( $db, $blog );
		$backend->patch_lists_and_scalars( array( array( 'key' => 'queue', 'expected_revision' => 1,
			'patches' => array( array( 'key' => 0, 'value' => array( 'status' => 'started', 'cursor' => 28 ) ) ) ) ) );
		$check( 'dirty' === $wpdb->get_var( $wpdb->prepare( 'SELECT digest_status FROM `' . $shared[0] . '` WHERE blog_id = %d AND state_order = 1', $blog ) ), 'dirty digest recovery fixture present: ' . $blog );
		if ( $switched ) { restore_current_blog(); $switched = false; }
	}
	$before = $physical( $created );
	foreach ( $blogs as $blog ) {
		if ( $blog !== $initial_blog ) { switch_to_blog( $blog ); $switched = true; }
		$roundtrip( false, 'seeded blog ' . $blog );
		if ( is_multisite() ) { $roundtrip( true, 'seeded network from blog ' . $blog ); }
		$settings = new MigratorSettings();
		foreach ( $data as $key => $value ) {
			$check( serialize( $value ) === serialize( $settings->get_migrator_setting( $key ) ), 'option history/queue/cursor readable: ' . $blog . '/' . $key );
		}
		$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog );
		$result = $backend->identifiers( 'migrated', array( 'same-identifier' ) );
		$check( 7100 + $blog === $result[0]['value']['migrated_id'], 'injected compact table mapping retained and blog isolated: ' . $blog );
		$check( 28 === $backend->roots( 'queue', array( 0 ) )[0]['value']['cursor'], 'injected compact queue cursor retained: ' . $blog );
		$revision = $backend->read( 'migrated-revision' );
		$check( 4 === $revision['value'], 'injected compact history revision retained: ' . $blog );
		if ( $switched ) { restore_current_blog(); $switched = false; }
	}
	$check( $before === $physical( $created ), 'all raw option/autoload and table row bytes unchanged across lifecycle' );
	// Simulate interruption with one shared table missing. Do not repair it on activation.
	$check( false !== $wpdb->query( 'DROP TABLE `' . $shared[1] . '`' ), 'explicit fixture partial shared schema' );
	$existing = array_values( array_diff( $created, array( $shared[1] ) ) );
	$before = $physical( $existing ); $roundtrip( is_multisite(), 'partial shared schema' );
	$check( $before === $physical( $existing ), 'partial schema keeps dirty heads/control/backup bytes' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $shared[1] ) ) ), 'missing shared table not recreated' );
} finally {
	if ( $switched ) { restore_current_blog(); }
	foreach ( array_reverse( $created ) as $table ) {
		$check( false !== $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ), 'fixture table cleanup: ' . $table );
		$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'fixture table cleanup read-back: ' . $table );
	}
	foreach ( $seeded as $blog ) {
		if ( $blog !== $initial_blog ) { switch_to_blog( $blog ); }
		foreach ( $keys as $key ) { delete_option( $key ); $check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM `' . $wpdb->options . '` WHERE option_name = %s', $key ) ), 'fixture option cleanup read-back: ' . $blog . '/' . $key ); }
		if ( $blog !== $initial_blog ) { restore_current_blog(); }
	}
	$check( $initial_blog === (int) get_current_blog_id() && $initial_prefix === $wpdb->prefix, 'original real blog context restored' );
}
print wp_json_encode( array( 'pass' => true, 'checks' => $checks, 'count' => count( $checks ), 'blogs' => $blogs,
	'actual_multisite' => is_multisite(), 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ), JSON_PRETTY_PRINT ) . "\n";
