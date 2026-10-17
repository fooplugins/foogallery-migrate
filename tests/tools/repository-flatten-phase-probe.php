<?php
/** Exact native-only, integrated bounded digest-sink experiment; never WP/SQL approval. */
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\RepositoryFlattenModel as Model;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec as Values;
$root = realpath( $argv[1] ); $fixture = realpath( $argv[2] );
define( 'ABSPATH', $root . '/' );
class WP_Error { public function __construct( $c, $m ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v, $d ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
foreach ( array( 'storage-preflight', 'storage-scale-admission', 'legacy-state-codec', 'legacy-record-codec', 'legacy-table-reader', 'repository-flatten-model', 'generic-retaining-model' ) as $file ) { require $root . '/includes/class-' . $file . '.php'; }
require $fixture;
function limits() { return array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216, 'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432, 'memory_ceiling_bytes' => 134217728, 'max_seconds' => 10 ); }
function budget( $seconds = 10.0, $p = null ) { return new Admission( null === $p ? limits() : $p, array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ), microtime( true ) + $seconds ); }
function must( $v, $message ) { if ( ! $v ) { throw new RuntimeException( $message ); } }
function refused( $call ) { try { $call(); } catch ( RuntimeException $e ) { return $e->getMessage(); } throw new RuntimeException( 'Expected refusal did not occur.' ); }
function measure( $tree, $key, $b = null, $sink = null ) {
	$b = null === $b ? budget() : $b; $sink = null === $sink ? new Model( Model::PROFILE ) : $sink;
	$codec = new Records( null, $b->limit( 'max_rows' ), $b->limit( 'max_bytes' ), $b );
	$before = array( 'true' => memory_get_usage( true ), 'live' => memory_get_usage( false ) );
	memory_reset_peak_usage(); $start = microtime( true );
	$stats = $codec->flatten_tree( 'galleries', $key, $tree, 0, $sink );
	$receipt = $sink->receipt();
	foreach ( array( true, false ) as $real ) {
		$kind = $real ? 'true' : 'live';
		must( memory_get_peak_usage( $real ) - $before[$kind] <= $receipt['plan']['additional_allocation_bytes'], 'Integrated peak exceeds phase reservation.' );
	}
	must( memory_get_peak_usage( true ) + 33554432 <= 134217728, 'Integrated peak erodes finite128M reserve.' );
	return array( 'stats' => $stats, 'sink' => $sink->receipt(), 'seconds' => microtime( true ) - $start,
		'before' => $before, 'after' => array( 'true' => memory_get_usage( true ), 'live' => memory_get_usage( false ) ),
		'peak' => array( 'true' => memory_get_peak_usage( true ), 'live' => memory_get_peak_usage( false ) ),
		'incremental_peak' => array( 'true' => memory_get_peak_usage( true ) - $before['true'], 'live' => memory_get_peak_usage( false ) - $before['live'] ) );
}
$result = array( 'status' => 'RED', 'failure' => null );
try {
	$store = FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture::value( 20000 );
	$raw_hash = hash( 'sha256', serialize( $store ) ); $tree = $store['galleries']['items']['gallery-009'];
	$result['original'] = measure( $tree, 'gallery-009' );
	must( $result['original']['stats']['record_count'] === 20208 && $result['original']['stats']['encoded_bytes'] === 15070354, 'Original factory mismatch.' );
	must( $result['original']['sink']['rows'] === 20208, 'Sink lost rows.' );
	$retained = array();
	// Supersedes the historical generic-flatten refusal, NOT the still-open
	// original rebuild/parity/deadline gate in StorageScaleAdmissionTest.
	$result['retaining_original_flatten'] = ( new Records( null, 24576, 16777216, budget() ) )->flatten_tree( 'galleries', 'gallery-009', $tree, 0, function ( $row ) use ( &$retained ) { $retained[] = $row; } );
	must( count( $retained ) === 20208 && $result['retaining_original_flatten']['encoded_bytes'] === 15070354, 'Retaining original flatten mismatch.' );
	unset( $retained );
	$result['corpus'] = array();
	$cases = array( 'binary' => array( 'blob' => str_repeat( "\xff\0/", 4097 ), 'progress' => str_repeat( 'z', 8193 ) ),
		'large-binary' => array( 'blob' => str_repeat( "\xff", 170001 ), 'progress' => str_repeat( "\xff", 170007 ) ),
		'wide' => array_fill( 0, 1025, 'x' ), 'deep' => 'leaf', 'ancestors' => array( 'blob' => str_repeat( '/', 8193 ) ) );
	for ( $i = 0; $i < 24; ++$i ) { $cases['deep'] = array( 'n' => $cases['deep'] ); }
	for ( $i = 0; $i < 12; ++$i ) { $cases['ancestors'] = array( 'blob' => str_repeat( 'a', 8193 ), 'children' => array( $i => $cases['ancestors'] ) ); }
	foreach ( $cases as $name => $case ) { $result['corpus'][$name] = measure( $case, 'k' ); }
	// Independent callback path on a small held-out tree must emit identical columns.
	$legacy = new Model( Model::PROFILE ); $b = budget();
	$legacy->qualify( new Values( 1048576, 64, 10000, $b ), $b );
	$stats = ( new Records( null, 24576, 16777216, $b ) )->flatten_tree( 'galleries', 'k', $cases['ancestors'], 0,
		function ( $row ) use ( $legacy ) { return $legacy( $row ); } );
	must( $stats === $result['corpus']['ancestors']['stats'] && $legacy->receipt()['sha256'] === $result['corpus']['ancestors']['sink']['sha256'], 'Qualified/callback column parity differs.' );
	$result['callback_parity'] = 'PASS';
	$sink = new Model( Model::PROFILE );
	$result['physical_row_refusal'] = refused( function () use ( $sink ) { measure( array( 'blob' => str_repeat( "\xff", 300001 ), 'progress' => str_repeat( "\xff", 300007 ) ), 'k', budget(), $sink ); } );
	must( $sink->receipt()['rows'] === 0, 'Oversized physical row emitted.' );
	foreach ( array( 'max_tokens', 'max_depth', 'max_rows', 'max_physical_rows_per_table', 'max_physical_rows' ) as $limit ) {
		$p = limits(); $p[$limit] = 1; $sink = new Model( Model::PROFILE );
		$result['limit_refusals'][$limit] = refused( function () use ( $p, $sink ) { measure( array( 'children' => array( 'c' => 'x' ) ), 'k', budget( 10.0, $p ), $sink ); } );
		must( $sink->receipt()['rows'] === 0, 'Logical/physical limit emitted.' );
	}
	// Repeat calls share one pending ledger. No phase or refusal releases it.
	$b = budget(); $sink = new Model( Model::PROFILE ); $calls = 0;
	$result['repeated_refusal'] = refused( function () use ( $b, $sink, &$calls ) { for ( $i = 0; $i < 32; ++$i ) { measure( array( 'v' => 'x' ), 'k', $b, $sink ); ++$calls; } } );
	must( $calls > 1 && $calls < 32, 'No monotonic repeated-call refusal.' ); $result['repeated_completed'] = $calls;
	$sink = new Model( Model::PROFILE, null, 1 );
	$result['sink_refusal'] = refused( function () use ( $sink ) { measure( array( 'children' => array( 'c' => 'x' ) ), 'k', budget(), $sink ); } );
	must( $sink->receipt()['rows'] === 1, 'Staged refusal count mismatch.' );
	$sink = new Model( Model::PROFILE ); $b = budget( 0.10 ); $expired = null;
	try { measure( $tree, 'k', $b, $sink ); }
	catch ( RuntimeException $e ) {
		$frames = array(); foreach ( $e->getTrace() as $frame ) { $frames[] = $frame['function']; }
		$expired = array( 'message' => $e->getMessage(), 'functions' => array_values( array_unique( $frames ) ) );
	}
	must( null !== $expired && count( array_intersect( $expired['functions'], array( 'footprint_logical', 'footprint_tree', 'preflight_tree', 'digest_value' ) ) ) > 0, 'Deadline did not expire inside traversal.' );
	$result['traversal_expiry'] = $expired;
	must( $sink->receipt()['rows'] === 0, 'Expired traversal emitted.' );
	$p = limits(); $p['memory_ceiling_bytes'] = memory_get_usage( true ) + 33554432 + 1;
	$b = budget( 10.0, $p ); $sink = new Model( Model::PROFILE );
	$result['low_memory'] = refused( function () use ( $b, $sink ) { measure( array( 'x' => 'y' ), 'k', $b, $sink ); } );
	must( $sink->receipt()['rows'] === 0, 'Low memory emitted.' );
	$b = budget(); $custom = new class( 1048576, 64, 10000, $b ) extends Values {};
	$sink = new Model( Model::PROFILE );
	$result['custom_codec'] = refused( function () use ( $b, $custom, $sink ) { ( new Records( $custom, 24576, 16777216, $b ) )->flatten_tree( 'galleries', 'k', 'x', 0, $sink ); } );
	$custom = new Values( 1024, 64, 10000, $b );
	$result['altered_builtin_codec'] = refused( function () use ( $b, $custom, $sink ) { ( new Records( $custom, 24576, 16777216, $b ) )->flatten_tree( 'galleries', 'k', 'x', 0, $sink ); } );
	$records = new class( null, 24576, 16777216, $b ) extends Records {};
	$result['custom_records'] = refused( function () use ( $records, $sink ) { $records->flatten_tree( 'galleries', 'k', 'x', 0, $sink ); } );
	$result['model_custom_codec'] = refused( function () use ( $custom ) { new Model( Model::PROFILE, $custom ); } );
	$result['constructor_reset'] = refused( function () use ( $sink ) { $sink->__construct( Model::PROFILE ); } );
	$result['domain_replacement'] = refused( function () use ( $legacy ) { measure( 'x', 'k', budget(), $legacy ); } );
	$result['unknown_profile'] = refused( function () { new Model( 'unsupported-binary-profile' ); } );
	// Real effective PHP limit, separately from the finite128M ceiling.
	$previous = ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 33554432 + 1 ) );
	$sink = new Model( Model::PROFILE );
	$result['effective_low_memory'] = refused( function () use ( $sink ) { measure( 'x', 'k', budget(), $sink ); } );
	must( $sink->receipt()['rows'] === 0, 'Effective low memory emitted.' ); ini_set( 'memory_limit', $previous );
	// Encoder check is last: defining the WP shim permanently disqualifies this process.
	$old_sink = new Model( Model::PROFILE );
	eval( 'function wp_json_encode($v) { return json_encode($v); }' );
	$result['unsupported_encoder'] = refused( function () { new Model( Model::PROFILE ); } );
	$result['encoder_after_construction'] = refused( function () use ( $old_sink ) { measure( 'x', 'k', budget(), $old_sink ); } );
	$result['status'] = 'PASS';
} catch ( Throwable $e ) { $result['failure'] = array( 'class' => get_class( $e ), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine() ); }
$result['loaded'] = array();
foreach ( array( Admission::class, Model::class, Records::class, Values::class, 'FooPlugins\\FooGalleryMigrate\\StoragePreflight', 'FooPlugins\\FooGalleryMigrate\\LegacyTableReader' ) as $class ) { $file = ( new ReflectionClass( $class ) )->getFileName(); $result['loaded'][$class] = array( 'path' => realpath( $file ), 'sha256' => hash_file( 'sha256', $file ) ); }
$result['runtime'] = array( 'version' => PHP_VERSION, 'binary' => PHP_BINARY, 'binary_sha256' => hash_file( 'sha256', PHP_BINARY ), 'memory_limit' => ini_get( 'memory_limit' ), 'opcache_cli' => ini_get( 'opcache.enable_cli' ), 'profile' => Model::PROFILE );
$result['fixture'] = array( 'path' => $fixture, 'sha256' => hash_file( 'sha256', $fixture ), 'raw_sha256' => isset( $raw_hash ) ? $raw_hash : null );
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $result['status'] === 'PASS' ? 0 : 1 );
