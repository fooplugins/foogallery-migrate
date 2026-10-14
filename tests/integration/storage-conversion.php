<?php
/** Native disposable conversion/phase receipt, not release or literal-floor proof. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Disposable WordPress fixture required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;

 global $wpdb;
$blog = (int) get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing table evidence.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) ) ) { throw new RuntimeException( 'Refusing existing option evidence.' ); }
}
$checks = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$request = function ( $target, $recover = false ) use ( $blog ) {
	$purpose = $recover ? 'recover' : ( 'table' === $target ? 'option_import' : 'table_export' );
	return array( 'blog_id' => $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => $recover ? 'recover-storage' : 'switch-to-' . $target,
		'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
};
// Deliberately synthetic fixture calibration, NEVER production acceptance.
$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
$codec = new LegacyStateCodec();
$initial = array( 'checkpoint' => array( 'cursor' => '009', 'started' => true ), 'migrated-revision' => 7,
	'migrated' => array( '009' => array( 'ID' => '009', 'object_type' => 'gallery', 'plugin_name' => 'Case', 'migrated_id' => 37,
		'children' => array( 7 => false, '007' => null ), 'unknown' => array( 'binary' => "\0雪", 'negative' => -0.0 ) ), 9 => null ),
	9 => false, '09' => null, 'empty' => array() );
$control = new StorageControl( $wpdb, $blog );
$reset = function () use ( $wpdb, $tables, $names, $blog ) {
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', $blog ) ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
$created = array(); $hook = null;
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'verified existing InnoDB schema; coordinator performs no provisioning' );
	$db = new LegacyTransactionConnection( $wpdb );
	$other = $blog + 1000;
	( new LegacyTableSnapshot( $db, $other ) )->replace_many( array( array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => 'other-blog-retained', 'expected_revision' => null ) ) );
	$other_raw = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A );

	$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ), 'native fixture source option actually persisted' );
	$raw_before = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$r = $request( 'table' ); $r['nonce'] = 'wrong';
	$check( is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $r ) ), 'native wrong-purpose/invalid nonce refused' );
	$check( null === $control->read( StorageAuthority::CONTROL_OPTION ) && null === $control->read( StorageAuthority::AUTHORITY_OPTION ), 'nonce denial creates no control or sentinel' );
	$check( is_wp_error( ( new StorageConversion() )->switch_backend( $request( 'table' ) ) ), 'no calibrated trusted policy refuses source decode' );
	$check( $raw_before === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ) && null === $control->read( StorageAuthority::CONTROL_OPTION ), 'missing-policy denial retains exact source and no pending control' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) ), 'owned maintenance marker released after refusal' );

	$result = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) );
	$check( ! is_wp_error( $result ), 'authorized native forward switch succeeds' );
	$check( 'table' === $result['backend'] && 'stable' === $result['phase'] && 1 === $result['epoch'], 'forward control publishes table epoch1 only after verification' );
	$check( StorageControl::runtime_ready( $result, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'table sentinel matches exact blog/epoch/switch' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'live stale option name removed after table flip' );
	$backup = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ) );
	$check( $raw_before === $backup, 'distinct retained backup contains exact original physical option bytes' );
	$reader = new LegacyTableSnapshot( $db, $blog );
	$actual = array(); foreach ( $initial as $key => $value ) { $actual[$key] = $reader->read_bounded( $key ); }
	$check( $codec->snapshot_digest( $initial ) === $codec->snapshot_digest( $actual ), 'full typed-key/order/revision/unknown/binary/negative-zero parity' );
	$check( $other_raw === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A ), 'forward switch retains other SQL blog scope byte-identically' );

	// Direct admitted-backend primitive fixture mutation, not public UI acceptance.
	$backend = new LegacyTableBackend( $db, $blog );
	$backend->mutate_scalars( array( 9 => true ) );
	$reader->replace_many( array( array( 'key' => 'migrated-revision', 'order' => 1, 'kind' => 'scalar', 'value' => 8, 'expected_revision' => 1 ) ) );
	$expected = $initial; $expected[9] = true; $expected['migrated-revision'] = 8;
	$result = ( new StorageConversion( $policy ) )->switch_backend( $request( 'option' ) );
	$check( ! is_wp_error( $result ), 'authorized reverse switch succeeds from current authoritative table state' );
	$check( 'option' === $result['backend'] && 2 === $result['epoch'] && 'stable' === $result['phase'], 'reverse control publishes option epoch2' );
	$check( $codec->snapshot_digest( $expected ) === $codec->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'reverse exports new table mutation and logical revision, not stale retained backup' );
	$check( $backup === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', StorageAuthority::STORAGE_BACKUP_OPTION ) ) && null === $control->read( StorageAuthority::AUTHORITY_OPTION ), 'reverse retains backup and removes sentinel only after option flip' );
	$check( 'no' === $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'fresh reverse option is non-autoloaded' );

	foreach ( array( 'verified', 'flipped', 'delete-source', 'stable' ) as $fault ) {
		$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
		$fired = false;
		$hook = function ( $sql ) use ( $fault, &$fired ) {
			$matches = 'delete-source' === $fault ? ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'foogallery-migrate-data'" ) ) :
				( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( $sql, '\\"phase\\":\\"' . $fault . '\\"' ) );
			// WordPress SQL escaping does not necessarily escape double quotes.
			if ( 'delete-source' !== $fault ) { $matches = 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && ( false !== strpos( $sql, '"phase":"' . $fault . '"' ) || $matches ); }
			if ( ! $fired && $matches ) { $fired = true; throw new RuntimeException( 'Fixture interrupt before ' . $fault ); }
			return $sql;
		};
		add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
		$check( $fired && is_wp_error( $failed ), 'forward interruption fired and did not report success: ' . $fault );
		$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
		$check( 'stable' !== $pending['phase'] && ! StorageControl::runtime_ready( $pending, $control->read( StorageAuthority::AUTHORITY_OPTION ) ), 'interrupted phase never admits normal runtime: ' . $fault );
		$result = ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) );
		$check( ! is_wp_error( $result ) && 'table' === $result['backend'] && 'stable' === $result['phase'], 'authoritative phase recovery succeeds without merging: ' . $fault );
	}

	// Atomic source dependency in final authority CAS, not only a prior SELECT.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ); $fired = false;
	$hook = function ( $sql ) use ( &$fired, $initial ) {
		if ( ! $fired && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'storage_control' ) && false !== strpos( stripslashes( $sql ), '"phase":"flipped"' ) ) {
			$fired = true; $changed = $initial; $changed['migrated-revision'] = 999; update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $changed, false );
		}
		return $sql;
	};
	add_filter( 'query', $hook ); $failed = ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ); remove_filter( 'query', $hook ); $hook = null;
	$pending = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), $blog );
	$check( $fired && is_wp_error( $failed ) && 'option' === $pending['backend'] && 'verified' === $pending['phase'], 'atomic option source hash dependency denies racing source change at flip SQL' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'recovery refuses changed source instead of merging staged destination' );
	update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
	$check( ! is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'explicit fixture restoration permits recovery from exact immutable source' );

	// Unsupported historical objects never invoke wakeup and remain untouched.
	$reset(); update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'old' => (object) array( 'unknown' => true ) ), false );
	$before = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request( 'table' ) ) ), 'unsupported noncompact object blocks conversion rather than narrowing properties' );
	$check( $before === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) ) && null === $control->read( StorageAuthority::CONTROL_OPTION ), 'unsupported object refusal retains original raw source with no pending control' );

	$reset();
	$gate = StorageMaintenanceGate::current(); $check( true === $gate->acquire_maintenance(), 'fixture owner acquires maintenance' );
	$check( is_wp_error( ( new StorageConversion( $policy ) )->recover( $request( 'table', true ) ) ), 'live foreign maintenance owner is not silently replaced' );
	$check( true === $gate->release_maintenance(), 'original fixture owner still owns and releases exact marker' );
	$check( $other_raw === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[0] . '` WHERE blog_id = %d', $other ), ARRAY_A ), 'all conversions/failures retain other scoped heads' );
} finally {
	if ( is_callable( $hook ) ) { remove_filter( 'query', $hook ); }
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE `' . $table . '`' ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'check_count' => count( $checks ), 'blog_id' => $blog,
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ), 'calibration' => 'synthetic fixture policy; NOT production/floor acceptance' ), JSON_PRETTY_PRINT ) . PHP_EOL;
