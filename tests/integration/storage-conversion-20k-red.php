<?php
/** Explicit opt-in G3 RED fixture. Synthetic trusted TEST policy, NOT G1 calibration. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Owned disposable WordPress required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
global $wpdb;
$blog = (int) get_current_blog_id();
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing pre-existing shared schema.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) ) ) { throw new RuntimeException( 'Refusing pre-existing storage evidence.' ); }
}
$created = array(); $receipt = array(); $passed = false;
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$schema->verify();
	// Each collection fits 10K individually; together exactly 20,000 flat records.
	$initial = array( 'synthetic-flat-a' => array_fill( 0, 10000, null ), 'synthetic-flat-b' => array_fill( 0, 10000, null ) );
	if ( ! update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ) ) { throw new RuntimeException( 'Synthetic source write failed.' ); }
	$raw_sql = $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA );
	$raw = $wpdb->get_var( $raw_sql ); $digest = ( new LegacyStateCodec() )->snapshot_digest( $initial );
	// Constructor injection exists only in this disposable TEST process. Not a
	// server profile, filter, measured calibration, or production approval.
	$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
	$coordinator = new StorageConversion( $policy );
	$request = array( 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) );
	if ( function_exists( 'memory_reset_peak_usage' ) ) { memory_reset_peak_usage(); }
	$base = memory_get_usage( true ); $start = microtime( true );
	$result = $coordinator->switch_backend( $request );
	$elapsed = microtime( true ) - $start; $peak = memory_get_peak_usage( true );
	$control_raw = get_option( StorageAuthority::CONTROL_OPTION, null );
	$control = is_string( $control_raw ) ? StorageControl::decode( $control_raw, $blog ) : null;
	$counts = array(); foreach ( $tables as $table ) { $counts[] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id = %d', $blog ) ); }
	$receipt = array( 'policy' => 'SYNTHETIC_TRUSTED_TEST_ONLY_NOT_G1', 'flat_records' => 20000, 'collections' => 2, 'records_per_collection' => 10000, 'raw_bytes' => strlen( $raw ), 'seconds' => $elapsed, 'allocated_base_bytes' => $base, 'allocated_peak_bytes' => $peak, 'peak_delta_bytes' => max( 0, $peak - $base ), 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : null, 'phase' => is_array( $control ) ? $control['phase'] : null, 'backend' => is_array( $control ) ? $control['backend'] : null, 'table_counts' => $counts, 'source_raw_unchanged' => $raw === $wpdb->get_var( $raw_sql ), 'authority_present' => false !== get_option( StorageAuthority::AUTHORITY_OPTION, false ), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ) );
	// Diagnostic only: replay the actual snapshot's non-writing preflight with
	// the same changes. This does NOT substitute for the real coordinator above.
	if ( is_wp_error( $result ) ) {
		// A source decoder gate can mask the later aggregate snapshot gate.
		// Report both executable diagnostics without claiming the coordinator
		// reached copying or bypassing either production safety bound.
		try { ( new \FooPlugins\FooGalleryMigrate\LegacyOptionDecoder( $policy['max_raw_bytes'] ) )->decode( $raw ); $receipt['source_decoder_admitted'] = true; }
		catch ( Exception $error ) { $receipt['source_decoder_admitted'] = false; $receipt['source_decoder_error'] = $error->getMessage(); }
		$property = new ReflectionProperty( $coordinator, 'snapshot' ); $snapshot = $property->getValue( $coordinator );
		if ( is_object( $snapshot ) ) {
			$changes = array(); $order = 0;
			foreach ( $initial as $key => $value ) { $changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => 'list', 'value' => $value, 'expected_revision' => null ); }
			$method = new ReflectionMethod( $snapshot, 'preflight' );
			try { $method->invoke( $snapshot, $changes ); }
			catch ( Exception $error ) { $receipt['snapshot_preflight_error'] = $error->getMessage(); }
		}
	}
	$passed = ! is_wp_error( $result ) && is_array( $result ) && 'stable' === $result['phase'] && 'table' === $result['backend'] && array_sum( $counts ) >= 20000;
	if ( $passed ) { $receipt['expected_logical_digest'] = $digest; }
} finally {
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); } }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
foreach ( $names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) ) ) { throw new RuntimeException( 'Owned option cleanup failed.' ); }
}
$receipt['owned_fixture_sql_cleanup'] = true;
echo wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES ) . "\n";
if ( ! $passed ) { throw new RuntimeException( 'G3 RED: expected stable table authority for 20,000 flat records; actual ' . $receipt['error_code'] ); }
