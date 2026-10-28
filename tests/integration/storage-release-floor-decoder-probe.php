<?php
/** Read-only diagnosis of the exact PHP 5.4 native option; never qualifies conversion. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) || ! is_file( '/fixture-owner.json' ) ||
	! isset( $args ) || ! is_array( $args ) || 1 !== count( $args ) ||
	! in_array( $args[0], array( 'native', 'structural', 'admitted' ), true ) ) {
	throw new RuntimeException( 'Owned floor probe and native|structural|admitted required.' );
}
$owner = json_decode( file_get_contents( '/fixture-owner.json' ), true );
if ( ! is_array( $owner ) || ! isset( $owner['kind'], $owner['database'], $owner['container'] ) ||
	'foogallery-migrate-floor-v1' !== $owner['kind'] || DB_NAME !== $owner['database'] ||
	DB_HOST !== $owner['container'] || PHP_VERSION !== '5.4.45' || $GLOBALS['wp_version'] !== '5.0' ||
	is_multisite() || ! current_user_can( 'manage_options' ) ||
	false === ini_set( 'memory_limit', '256M' ) || '256M' !== ini_get( 'memory_limit' ) ) {
	throw new RuntimeException( 'Floor probe ownership/runtime/memory guard refused.' );
}

use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\StorageCapacityBudget;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

global $wpdb;
$sql = $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', FOOGALLERY_MIGRATE_OPTION_DATA );
$raw = $wpdb->get_var( $sql );
$expected = 'b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677';
if ( $wpdb->get_var( 'SELECT DATABASE()' ) !== $owner['database'] || $wpdb->last_error ||
	! is_string( $raw ) || 2998086 !== strlen( $raw ) || $expected !== hash( 'sha256', $raw ) ||
	null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', StorageAuthority::CONTROL_OPTION ) ) ||
	$wpdb->last_error ) { throw new RuntimeException( 'Exact native option or option authority absent.' ); }

$mode = $args[0];
$start = microtime( true );
$result = array( 'mode' => $mode, 'diagnostic_only' => true, 'memory_limit' => ini_get( 'memory_limit' ),
	'raw_bytes' => strlen( $raw ), 'raw_sha256' => $expected );
try {
	if ( 'native' === $mode ) {
		$value = @unserialize( $raw );
		$encoded = serialize( $value );
		$result['native_array'] = is_array( $value );
		$result['roundtrip_equal'] = $encoded === $raw;
		$result['roundtrip_bytes'] = strlen( $encoded );
		$result['roundtrip_sha256'] = hash( 'sha256', $encoded );
	} else {
		$class = StorageCapacityPolicy::resolve();
		if ( is_wp_error( $class ) ) { throw new RuntimeException( 'Release capacity policy unavailable.' ); }
		$policy = array( 'max_raw_bytes' => $class['limits']['max_raw_bytes'],
			'amplification' => $class['amplification'], 'reserve_bytes' => $class['reserve_bytes'] );
		$capacity = new StorageCapacityBudget( $class );
		$limits = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
			'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
			'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
			'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 );
		foreach ( $limits as $key => $value ) { if ( 'version' !== $key ) { $limits[$key] = $capacity->limit( $key ); } }
		$scale = new StorageScaleAdmission( $limits, $policy, $capacity->deadline(), null, null, new StorageMemoryBaseline(), $capacity );
		$probe = new stdClass();
		$dimensions = array( 'R' => $capacity->limit( 'max_raw_bytes' ), 'T' => $capacity->limit( 'max_tokens' ),
			'N' => $capacity->limit( 'max_rows' ), 'E' => $capacity->limit( 'max_bytes' ),
			'S' => $capacity->limit( 'max_scalar_bytes' ), 'M' => $capacity->limit( 'max_row_bytes' ),
			'H' => $capacity->limit( 'max_heads' ), 'D' => $capacity->limit( 'max_depth' ),
			'W' => $capacity->limit( 'copy_batch_bytes' ) );
		$token = $capacity->open( $probe, 'option_verify', $dimensions );
		$decoder = $scale->decoder( $policy['max_raw_bytes'] );
		if ( 'structural' === $mode ) { $result['statistics'] = $decoder->structural_statistics( $raw ); }
		else {
			$value = $decoder->decode( $raw );
			$result['admitted_array'] = is_array( $value );
			$result['admitted_heads'] = is_array( $value ) ? count( $value ) : null;
		}
		$capacity->close( $probe, $token );
	}
} catch ( Exception $error ) {
	$result['exception_class'] = get_class( $error );
	$result['exception_message'] = $error->getMessage();
}
$after = $wpdb->get_var( $sql );
if ( $wpdb->last_error || $raw !== $after ) { throw new RuntimeException( 'Read-only decoder probe observed native option change.' ); }
$result['elapsed_seconds'] = round( microtime( true ) - $start, 3 );
$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
$result['source_unchanged'] = true;
echo json_encode( $result ) . "\n";
