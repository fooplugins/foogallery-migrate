<?php
/** Isolated calculator probe. Real helper execution belongs to the PHPUnit fixture. */
namespace FooPlugins\FooGalleryMigrate {
	function unserialize() { ++$GLOBALS['headroom_native_calls']; throw new \LogicException( 'Unexpected hydration.' ); }
	function serialize() { ++$GLOBALS['headroom_native_calls']; throw new \LogicException( 'Unexpected parity.' ); }
	function json_decode() { ++$GLOBALS['headroom_native_calls']; throw new \LogicException( 'Unexpected JSON hydration.' ); }
}
namespace FooPlugins\FooGalleryMigrate\Tests {
	function unserialize() { ++$GLOBALS['headroom_native_calls']; throw new \LogicException( 'Unexpected hydration.' ); }
	function serialize() { ++$GLOBALS['headroom_native_calls']; throw new \LogicException( 'Unexpected parity.' ); }
	function json_decode() { ++$GLOBALS['headroom_native_calls']; throw new \LogicException( 'Unexpected JSON hydration.' ); }
}
namespace {
	$root = dirname( dirname( __DIR__ ) );
	require $root . '/tests/support/StoragePhasePeakCandidate.php';
	$GLOBALS['headroom_native_calls'] = 0;
	$d = array( 'source_version' => '5.6.20', 'abi' => 'lp64-nts-nondebug', 'allocator' => 'zend-mm',
		'segment_bytes' => 262144, 'driver' => 'none', 'precision' => 14, 'serialize_precision' => 17 );
	$b = array( 'integer_max' => PHP_INT_MAX, 'limit_bytes' => 134217728, 'baseline_bytes' => 16777216, 'reserve_bytes' => 8388608 );
	$c = array( 'caller_frames' => 32, 'caller_arguments' => 8, 'metadata_bytes' => 1024, 'argument_string_bytes' => 1024,
		'primitive_caller_arguments' => true, 'hooks_disabled' => true, 'helpers_loaded' => true,
		'pcre_patterns_prepared' => true, 'object_store_free_slots' => 1 );
	$nested = null; for ( $i = 0; $i < 16; ++$i ) { $nested = array( $nested ); }
	$exact = array( 'records' => array_fill( 0, 3500, 'a' ) );
	// Build a genuine canonical exact-60000-byte fixture, not padding/trailing bytes.
	for ( $i = 0; strlen( \serialize( $exact ) ) < 60000; ++$i ) {
		$remaining = 60000 - strlen( \serialize( $exact ) );
		$exact['records'][$i] .= str_repeat( 'x', min( 8, $remaining ) );
	}
	if ( 60000 !== strlen( \serialize( $exact ) ) ) { throw new \LogicException( 'Exact-length fixture construction failed.' ); }
	$cases = array( 'ordinary' => \serialize( array( 'records' => range( 1, 4000 ) ) ),
		'dense_short' => \serialize( array( 'records' => array_fill( 0, 2000, 'ab' ) ) ),
		'depth16' => \serialize( $nested ), 'exact_raw60000' => \serialize( $exact ),
		'key64_string128' => \serialize( array( str_repeat( 'k', 64 ) => str_repeat( 'x', 128 ) ) ),
		'arrays16' => \serialize( array_fill( 0, 15, array() ) ), 'duplicate' => 'a:2:{i:0;s:3:"old";i:0;s:3:"new";}',
		'coercing' => 'a:1:{s:1:"0";N;}', 'binary' => \serialize( array( "\0\xff" ) ),
		'malformed' => 'a:1:{i:0;s:3:"ab";}', 'nodes8005' => \serialize( array( 'records' => range( 1, 4001 ) ) ),
		'raw60001' => str_repeat( 'x', 60001 ), 'decimal' => 'a:1:{i:0;d:1.25;}' );
	$out = array();
	foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
		$d['source_version'] = $version;
		foreach ( array( array( 134217728, 16777216 ), array( 268435456, 67108864 ) ) as $pair ) {
			$b['limit_bytes'] = $pair[0]; $b['baseline_bytes'] = $pair[1];
			foreach ( $cases as $name => $raw ) {
				try {
					$r = \FooPlugins\FooGalleryMigrate\Tests\StoragePhasePeakCandidate::option_headroom( $raw, $d, $b, $c );
					if ( $r['admitted'] || $r['headroom_proof_complete'] || $r['fits_justified_helper_budget'] || null !== $r['real_usage_peak_bytes'] ) {
						throw new \LogicException( 'Unproved headroom was credited.' );
					}
				} catch ( \InvalidArgumentException $e ) { $r = array( 'admitted' => false, 'refusal' => $e->getMessage() ); }
				$out[$version][$pair[0]][$name] = $r;
			}
		}
	}
	if ( 0 !== $GLOBALS['headroom_native_calls'] ) { throw new \LogicException( 'Native boundary crossed.' ); }
	$origin = ( new \ReflectionClass( '\FooPlugins\FooGalleryMigrate\Tests\StoragePhasePeakCandidate' ) )->getFileName();
	print json_encode( array( 'status' => 'unproved_prototype_not_justified_fit', 'native_calls' => $GLOBALS['headroom_native_calls'],
		'origin' => array( 'path' => $origin, 'sha256' => hash_file( 'sha256', $origin ) ), 'cases' => $out,
		'actual_runtime' => PHP_VERSION, 'legacy_runtime_execution' => false ), JSON_PRETTY_PRINT ) . "\n";
}
