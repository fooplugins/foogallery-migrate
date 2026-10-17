<?php
/**
 * HOST/offline retaining-output boundary witness, NOT calibration or acceptance.
 * All product guards, original policy and frozen fixture cells remain unchanged.
 * Usage: php -d memory_limit=128M -d opcache.enable_cli=0 <this> <runtime-root> <fixture.php> <cell> [ballast-bytes] [copies]
 * Exit 0 means this finite diagnostic roundtrip passed; exit 1 preserves refusal.
 */
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;

$root = realpath( $argv[1] );
$fixture = realpath( $argv[2] );
$count = (int) $argv[3];
$ballast_bytes = isset( $argv[4] ) ? (int) $argv[4] : 0;
$copies = isset( $argv[5] ) ? (int) $argv[5] : 1;
if ( ! $root || ! $fixture || ! in_array( $count, array( 1000, 2000, 10000, 20000 ), true ) ||
	! in_array( $ballast_bytes, array( 0, 16777216, 33554432 ), true ) || ! in_array( $copies, array( 1, 2 ), true ) ||
	'128M' !== ini_get( 'memory_limit' ) || ini_get( 'opcache.enable_cli' ) ) { throw new RuntimeException( 'Explicit finite host witness arguments required.' ); }
define( 'ABSPATH', $root . '/' );
class WP_Error { public function __construct( $code, $message ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
foreach ( array( 'storage-preflight', 'storage-scale-admission', 'legacy-state-codec', 'legacy-record-codec', 'legacy-table-reader', 'generic-retaining-model' ) as $file ) { require $root . '/includes/class-' . $file . '.php'; }
require $fixture;
function sample() { return array( 'true' => memory_get_usage( true ), 'live' => memory_get_usage( false ), 'peak_true' => memory_get_peak_usage( true ), 'peak_live' => memory_get_peak_usage( false ) ); }
$value = FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture::value( $count );
$tree = $value['galleries']['items']['gallery-009'];
$input_hash = hash( 'sha256', serialize( $tree ) );
$ballast = $ballast_bytes ? str_repeat( 'B', $ballast_bytes ) : '';
$limits = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216, 'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432, 'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 );
$policy = array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 );
$rows = array(); $duplicates = array(); $phases = array(); $stats = null; $failure = null; $stage = 'construct';
$started = microtime( true );
try {
	$budget = new Admission( $limits, $policy, microtime( true ) + 10.0 );
	$codec = new Records( null, 24576, 16777216, $budget );
	$stage = 'flatten'; memory_reset_peak_usage(); $phases['before_flatten'] = sample();
	$stats = $codec->flatten_tree( 'galleries', 'gallery-009', $tree, 0, function ( $row ) use ( &$rows, &$duplicates, $copies ) {
		$rows[] = $row;
		// A second genuinely distinct shell/string set, not a COW-only alias.
		if ( 2 === $copies ) { $copy = array(); foreach ( $row as $key => $value ) { $copy[$key] = is_string( $value ) ? substr( 'x' . $value, 1 ) : $value; } $duplicates[] = $copy; }
	} );
	$phases['after_flatten'] = sample();
	$stage = 'rebuild'; memory_reset_peak_usage(); $phases['before_rebuild'] = sample();
	$rebuilt = $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows );
	$phases['after_rebuild'] = sample();
	$stage = 'parity';
	if ( serialize( $tree ) !== serialize( $rebuilt ) || $input_hash !== hash( 'sha256', serialize( $tree ) ) ) { throw new RuntimeException( 'Exact retaining parity/input immutability failed.' ); }
	$phases['after_parity'] = sample(); $stage = 'complete';
} catch ( RuntimeException $error ) {
	$phases['failure'] = sample(); $trace = array();
	foreach ( $error->getTrace() as $frame ) { $trace[] = array( 'method' => ( isset( $frame['class'] ) ? $frame['class'] . '::' : '' ) . $frame['function'], 'file' => isset( $frame['file'] ) ? $frame['file'] : null, 'line' => isset( $frame['line'] ) ? $frame['line'] : null ); }
	$failure = array( 'message' => $error->getMessage(), 'trace' => $trace );
}
$loaded = array();
foreach ( get_declared_classes() as $class ) { if ( 0 !== strpos( $class, 'FooPlugins\\FooGalleryMigrate\\' ) ) { continue; } $file = ( new ReflectionClass( $class ) )->getFileName(); $loaded[$class] = array( 'path' => realpath( $file ), 'sha256' => hash_file( 'sha256', $file ) ); }
$extensions = array(); foreach ( get_loaded_extensions() as $extension ) { $extensions[$extension] = phpversion( $extension ); } ksort( $extensions );
echo json_encode( array( 'status' => null === $failure ? 'FINITE_DIAGNOSTIC_PASS_NOT_ORIGINAL_ACCEPTANCE' : 'REFUSED_NOT_WAIVED', 'stage' => $stage,
	'cell' => $count, 'ballast_bytes' => strlen( $ballast ), 'retained_copies' => $copies, 'rows_retained' => count( $rows ), 'duplicate_rows_retained' => count( $duplicates ),
	'stats' => $stats, 'phases' => $phases, 'failure' => $failure, 'duration_seconds' => microtime( true ) - $started,
	'runtime' => array( 'binary' => PHP_BINARY, 'binary_sha256' => hash_file( 'sha256', PHP_BINARY ), 'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS, 'architecture' => php_uname( 'm' ), 'memory_limit' => ini_get( 'memory_limit' ), 'opcache.enable_cli' => ini_get( 'opcache.enable_cli' ), 'extensions' => $extensions, 'native_json' => ! function_exists( 'wp_json_encode' ), 'pid' => getmypid() ),
	'loaded' => $loaded, 'fixture' => array( 'path' => $fixture, 'sha256' => hash_file( 'sha256', $fixture ), 'tree_sha256' => $input_hash ), 'limits' => $limits, 'policy' => $policy ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure ? 0 : 1 );
