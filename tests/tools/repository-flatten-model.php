<?php
/** Standalone native-JSON host producer. No WP bootstrap, DB or guard bypass. */
use FooPlugins\FooGalleryMigrate\RepositoryFlattenModel as Model;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec as Codec;

$root = realpath( $argv[1] );
define( 'ABSPATH', $root . '/' );
class WP_Error { public function __construct( $code, $message ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v, $domain ) { return $v; }
foreach ( array( 'storage-preflight', 'storage-scale-admission', 'legacy-state-codec', 'legacy-record-codec', 'table-reader', 'repository-flatten-model', 'generic-retaining-model' ) as $file ) {
	require $root . '/includes/class-' . $file . '.php';
}
function limits( $ceiling = 134217728 ) {
	return array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
		'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
		'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
		'memory_ceiling_bytes' => $ceiling, 'max_seconds' => 10 );
}
function budget( $ceiling = 134217728, $limit = '128M', $used = 0, $clock = null ) {
	return new Admission( limits( $ceiling ), array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ),
		( null === $clock ? microtime( true ) : $clock() ) + 10.0, $clock, function () use ( $limit, $used ) { return array( $limit, $used ); } );
}
$checks = 0;
function check( $ok, $name ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $name ); } }
function refuses( $call, $name ) { try { $call(); } catch ( RuntimeException $e ) { check( true, $name ); return; } check( false, $name ); }
function binding() {
	$out = array(); foreach ( get_declared_classes() as $class ) {
		if ( strpos( $class, 'FooPlugins\\FooGalleryMigrate\\' ) !== 0 ) { continue; }
		$file = ( new ReflectionClass( $class ) )->getFileName();
		$out[$class] = array( 'file' => realpath( $file ), 'sha256' => hash_file( 'sha256', $file ) );
	} return $out;
}
$model = new Model( Model::PROFILE );
$kind = $argv[2]; $n = isset( $argv[3] ) ? (int) $argv[3] : 0;
if ( 'regression' === $kind ) {
	$c = new Records(); $d = $c->describe_tree( 'root', array( 'x' => '1234567' ), 'repository-sink' ); $p = $model->project( $d, 'galleries' );
	check( 7 === $d['logical_max_string_bytes'], 'scalar max includes values' );
	check( 0 === $p['source_charge_bytes'], 'source not charged twice' );
	check( $p['additional_allocation_bytes'] === $p['descriptor_bytes'] + array_sum( $p['parts'] ), 'parts exact sum' );
	refuses( function () { new Model( 'unknown' ); }, 'unknown runtime/profile' );
	refuses( function () { new Model( Model::PROFILE, new Codec() ); }, 'custom codec' );
	foreach ( array( null, -1, 1.0, '1', PHP_INT_MAX ) as $bad ) {
		$wrong = $d; $wrong['max_row_bytes'] = $bad;
		refuses( function () use ( $model, $wrong ) { $model->project( $wrong, 'galleries' ); }, 'unknown/overflow descriptor' );
	}
	$wrong = $d; $wrong['ownership'] = 'retaining-row'; refuses( function () use ( $model, $wrong ) { $model->project( $wrong, 'x' ); }, 'retaining rows' );
	$wrong = $d; $wrong['max_row_tagged_arrays'] = PHP_INT_MAX; refuses( function () use ( $model, $wrong ) { $model->project( $wrong, 'x' ); }, 'checked model multiply' );
	check( PHP_INT_MAX === Admission::checked_add( PHP_INT_MAX, 0 ), 'checked add boundary' );
	check( PHP_INT_MAX === Admission::checked_multiply( PHP_INT_MAX, 1 ), 'checked multiply boundary' );
	refuses( function () { Admission::checked_add( PHP_INT_MAX, 1 ); }, 'add overflow' );
	refuses( function () { Admission::checked_multiply( PHP_INT_MAX, 2 ); }, 'multiply overflow' );
	$delta = $p['additional_allocation_bytes'];
	foreach ( array( array( '-1', 33554432 + $delta + 100 ), array( (string) ( 33554432 + $delta + 100 ), 134217728 ), array( '128M', 33554432 + $delta + 100 ) ) as $args ) {
		$b = budget( $args[1], $args[0], 100 );
		check( $b->admit_additional( $p['descriptor_bytes'] ), 'descriptor reserved first' );
		check( $b->admit_additional( $p['after_descriptor_bytes'] ), 'exact ceiling accepted' );
		refuses( function () use ( $b ) { $b->admit_additional( 1 ); }, 'one over refused' );
		check( $b->admit_additional( 0 ), 'refusal does not spend' );
	}
	$used = 0; $ceiling = 33554452;
	$b = new Admission( limits( $ceiling ), array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ), microtime( true ) + 10.0, null,
		function () use ( &$used ) { return array( '-1', $used ); } );
	$b->admit_additional( 10 ); $used = 10;
	refuses( function () use ( $b ) { $b->admit_additional( 1 ); }, 'no realized sample credit' );
	check( $b->admit_additional( 0 ), 'pending monotonic boundary' );
	// Existing legacy arithmetic is intentionally untouched and pending-blind.
	$b = budget( 33554452, '-1' ); $b->admit_additional( 19 );
	check( $b->admit_memory( 1 ), 'legacy guard remains its original independent check' );
	refuses( function () use ( $model, $b ) { $model->reserve_legacy( $b, 1, 8 ); }, 'bridge includes pending' );
	$b = budget( 33554452, '-1' ); $b->admit_additional( 10 );
	check( $model->reserve_legacy( $b, 1, 8 ), 'bridge useful below boundary' );
	refuses( function () use ( $model, $b ) { $model->reserve_legacy( $b, 1, 8 ); }, 'bridge cumulative no credits' );
	refuses( function () use ( $model ) { $model->reserve_legacy( budget(), 33554433, 1 ); }, 'bridge preserves raw cap' );
	$now = 100.0; $clock = function () use ( &$now ) { return $now; }; $b = budget( 134217728, '128M', 0, $clock );
	$now = 110.0; check( $b->admit_additional( $delta ), 'exact deadline' );
	$now = 110.000001; refuses( function () use ( $b ) { $b->admit_additional( 0 ); }, 'expired deadline' );
	// The real WP encoder is outside this profile, including the test shim.
	eval( 'function wp_json_encode($v) { return json_encode($v); }' );
	refuses( function () { new Model( Model::PROFILE ); }, 'unknown/WP JSON refuses' );
	$result = array( 'checks' => $checks, 'plan' => $p );
} else if ( 'allocator' === $kind ) {
	$observations = array();
	foreach ( array( 0, 1, 7, 8, 9, 15, 16, 17, 31, 32, 33, 63, 64, 65, 127, 128, 129, 257, 1025, 4097, 16385 ) as $size ) {
		foreach ( array( 'packed', 'mixed' ) as $type ) {
			memory_reset_peak_usage(); $before = memory_get_usage( false ); $array = array();
			for ( $i = 0; $i < $size; ++$i ) { if ( 'packed' === $type ) { $array[] = $i; } else { $array['key-' . $i] = $i; } }
			$delta = memory_get_peak_usage( false ) - $before;
			$bound = 768 + 160 * $size + ( 'mixed' === $type ? 64 * $size : 0 );
			check( $delta <= $bound, 'container allocation bound' );
			$observations[] = array( 'type' => $type, 'size' => $size, 'live_peak_delta' => $delta, 'bound' => $bound ); unset( $array );
		}
	}
	foreach ( array( 0, 1, 7, 8, 23, 24, 25, 255, 256, 257, 3071, 3072, 3073, 4095, 4096, 4097, 65535, 65536, 65537, 131071, 262143 ) as $size ) {
		$v = str_repeat( "\xff", $size ); memory_reset_peak_usage(); $before = memory_get_usage( false );
		$json = ( new Codec() )->encode( $v ); $delta = memory_get_peak_usage( false ) - $before;
		$bound = 16384 + 8 * strlen( $json ); check( $delta <= $bound, 'native tagged/base64/JSON buffer bound' );
		$observations[] = array( 'type' => 'binary-scalar-encode', 'size' => $size, 'json_bytes' => strlen( $json ), 'live_peak_delta' => $delta, 'bound' => $bound ); unset( $v, $json );
	}
	$result = array( 'checks' => $checks, 'observations' => $observations );
} else {
	$v = null;
	if ( 'tiny' === $kind ) { $v = array( 'children' => array_fill( 0, $n, null ) ); }
	else if ( 'wide' === $kind ) { $v = array(); for ( $i = 0; $i < $n; ++$i ) { $v['field-' . $i] = $i; } }
	else if ( 'deep' === $kind ) { $v = 'leaf'; for ( $i = 0; $i < $n; ++$i ) { $v = array( 'title' => 'ancestor', 'children' => array( 'kid' => $v ) ); } }
	else if ( 'binary' === $kind ) { $v = array( 'bytes' => str_repeat( "\xff\0/", $n ), 'plugin_name' => "\xff-plugin", 'ID' => '0007' ); }
	else if ( 'mutable' === $kind ) { $v = array( 'title' => 'root', 'progress' => array_fill( 0, $n, array( 'error' => "\xff\0/", 'fraction' => -0.0 ) ) ); }
	else if ( 'logical-deep' === $kind ) { $v = 'leaf'; for ( $i = 0; $i < $n; ++$i ) { $v = array( $v ); } $v = array( 'x' => $v ); }
	else if ( 'original20k' === $kind ) {
		require $root . '/tests/support/StorageA7Fixture.php';
		$store = FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture::value( 20000 );
		$v = $store['galleries']['items']['gallery-009'];
	} else { throw new RuntimeException( 'Unknown shape' ); }
	$b = new Admission( limits(), array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ), microtime( true ) + 10.0 );
	$c = 'original20k' === $kind ? new Records( null, 24576, 16777216, $b ) : new Records();
	$sample = memory_get_usage( true ); $live = memory_get_usage( false );
	$b->admit_additional( $model->descriptor_bytes( 64 ) );
	memory_reset_peak_usage();
	$d = $c->describe_tree( 'gallery-009', $v, 'repository-sink' );
	$descriptor_peak = array( memory_get_peak_usage( true ), memory_get_peak_usage( false ) );
	$p = $model->project( $d, 'galleries' );
	$b->admit_additional( $p['after_descriptor_bytes'] );
	check( $descriptor_peak[0] - $sample <= $p['descriptor_bytes'], 'descriptor true peak within plan' );
	check( $descriptor_peak[1] - $live <= $p['descriptor_bytes'], 'descriptor live peak within plan' );
	$measurements = array();
	if ( 'original20k' !== $kind ) {
		$h = ( new Codec() )->key_hash( 'galleries' );
		// Execute the exact private preflight/make_row path separately, then
		// the public two-pass flatten with an empty non-retaining sink.
		$method = new ReflectionMethod( Records::class, 'preflight_tree' );
		foreach ( array( 'preflight', 'flatten' ) as $phase ) {
			gc_collect_cycles(); memory_reset_peak_usage(); $before = array( memory_get_usage( true ), memory_get_usage( false ) );
			if ( 'preflight' === $phase ) {
				$stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
				$method->invokeArgs( $c, array( $h, null, 'gallery-009', $v, 0, 0, &$stats ) );
			} else { $stats = $c->flatten_tree( 'galleries', 'gallery-009', $v, 0, function ( $row ) {} ); }
			$peak = array( memory_get_peak_usage( true ), memory_get_peak_usage( false ) );
			check( $stats['encoded_bytes'] === $d['encoded_bytes'] && $stats['record_count'] === $d['records'], 'actual rows match descriptor' );
			check( $peak[0] - $before[0] <= $p['after_descriptor_bytes'], 'true phase peak within plan' );
			check( $peak[1] - $before[1] <= $p['after_descriptor_bytes'], 'live phase peak within plan' );
			$measurements[$phase] = array( 'before' => $before, 'peak' => $peak, 'delta' => array( $peak[0] - $before[0], $peak[1] - $before[1] ) );
		}
	}
	$result = array( 'shape' => $kind, 'size' => $n, 'checks' => $checks, 'sampled_retained_source_included' => $sample,
		'descriptor_peak' => $descriptor_peak, 'descriptor' => $d, 'plan' => $p, 'phases' => $measurements,
		'projected_total_with_unchanged_reserve' => $sample + $p['additional_allocation_bytes'] + 33554432,
		'original20k_status' => 'prospective-only; no end-to-end conversion or persistence attempted' );
}
$result['runtime'] = array( 'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'binary_sha256' => hash_file( 'sha256', PHP_BINARY ), 'memory_limit' => ini_get( 'memory_limit' ), 'classes' => binding() );
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
