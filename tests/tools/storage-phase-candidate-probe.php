<?php
/** Isolated offline probe: native decode/serialize calls in either class namespace fail. */
namespace FooPlugins\FooGalleryMigrate {
	function json_decode() { ++$GLOBALS['phase_native_calls']; throw new \LogicException( 'Unexpected native JSON hydration.' ); }
	function unserialize() { ++$GLOBALS['phase_native_calls']; throw new \LogicException( 'Unexpected native option hydration.' ); }
	function serialize() { ++$GLOBALS['phase_native_calls']; throw new \LogicException( 'Unexpected native scalar/graph serialization.' ); }
}
namespace FooPlugins\FooGalleryMigrate\Tests {
	function json_decode() { ++$GLOBALS['phase_native_calls']; throw new \LogicException( 'Unexpected candidate JSON hydration.' ); }
	function unserialize() { ++$GLOBALS['phase_native_calls']; throw new \LogicException( 'Unexpected candidate option hydration.' ); }
	function serialize() { ++$GLOBALS['phase_native_calls']; throw new \LogicException( 'Unexpected candidate serialization.' ); }
}
namespace {
	$root = dirname( dirname( __DIR__ ) );
	define( 'ABSPATH', $root . '/' );
	require_once $root . '/includes/class-legacy-state-codec.php';
	require_once $root . '/includes/class-storage-calibration.php';
	require_once $root . '/tests/support/StoragePhasePeakCandidate.php';
	$GLOBALS['phase_native_calls'] = 0;
	$GLOBALS['wp_version'] = 'offline-identity-only';
	$domain = array( 'source_version' => '5.6.20', 'abi' => 'lp64-nts-nondebug', 'allocator' => 'zend-mm',
		'segment_bytes' => 262144, 'driver' => 'none', 'precision' => 14, 'serialize_precision' => 17 );
	$budget = array( 'integer_max' => PHP_INT_MAX, 'limit_bytes' => 134217728, 'baseline_bytes' => 16777216, 'reserve_bytes' => 8388608 );
	$option = \serialize( array( 'records' => range( 1, 4000 ) ) ); // fixture generation, NOT candidate execution.
	$json = ( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->encode( array( 'items' => range( 1, 1000 ), 'float' => -0.0 ) );
	$cases = array( 'dense_option' => array( 'option', $option ), 'dense_table' => array( 'table_json', $json ),
		'duplicate_option' => array( 'option', 'a:2:{i:0;s:3:"old";i:0;s:3:"new";}' ),
		'duplicate_json' => array( 'table_json', '{"version":1,"version":1,"value":["null"]}' ),
		'decimal_option' => array( 'option', 'a:1:{i:0;d:1.25;}' ),
		'decimal_flood_json' => array( 'table_json', str_repeat( '9', 1000000 ) ),
		'deep_json' => array( 'table_json', str_repeat( '[', 196 ) . str_repeat( ']', 196 ) ) );
	$results = array();
	foreach ( $cases as $name => $case ) {
		$before = $GLOBALS['phase_native_calls'];
		try {
			$r = call_user_func( array( '\FooPlugins\FooGalleryMigrate\Tests\StoragePhasePeakCandidate', $case[0] ), $case[1], $domain, $budget );
			if ( false !== $r['admitted'] || null !== $r['real_usage_peak_bytes'] ) { throw new \LogicException( 'Unjustified approval.' ); }
			$results[$name] = $r;
		} catch ( \InvalidArgumentException $error ) { $results[$name] = array( 'admitted' => false, 'refusal' => $error->getMessage() ); }
		$results[$name]['native_calls'] = $GLOBALS['phase_native_calls'] - $before;
	}
	if ( 0 !== $GLOBALS['phase_native_calls'] ) { throw new \LogicException( 'Candidate crossed native boundary.' ); }
	$origins = array();
	foreach ( array( '\FooPlugins\FooGalleryMigrate\LegacyStateCodec', '\FooPlugins\FooGalleryMigrate\StorageCalibration', '\FooPlugins\FooGalleryMigrate\Tests\StoragePhasePeakCandidate' ) as $class ) {
		$path = ( new \ReflectionClass( $class ) )->getFileName();
		$origins[$class] = array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) );
	}
	print json_encode( array( 'status' => 'candidate_probe_only_not_admission', 'cases' => $results, 'origins' => $origins,
		'actual_runtime' => array( 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'integer_size' => PHP_INT_SIZE,
			'debug' => PHP_DEBUG, 'zts' => PHP_ZTS, 'precision' => ini_get( 'precision' ), 'serialize_precision' => ini_get( 'serialize_precision' ),
			'fingerprint' => \FooPlugins\FooGalleryMigrate\StorageCalibration::fingerprint() ),
		'measurement_comparison' => 'none: modern calculator runtime differs from retained legacy source model' ), JSON_PRETTY_PRINT ) . "\n";
}
