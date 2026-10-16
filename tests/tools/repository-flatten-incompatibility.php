<?php
/**
 * Original20K128M incompatibility witness. Exit 1 preserves acceptance RED.
 * Usage: php -d opcache.enable_cli=0 -d memory_limit=128M <this> <root> <fixture.php> original|planned
 * Planned only adds prospective reservations; existing gates remain unchanged
 * and pending-blind. It is NOT a proposed integration or a pending-safety proof.
 * No WP encoder, SQL, retained-row output or source/fixture mutation.
 */
use FooPlugins\FooGalleryMigrate\RepositoryFlattenModel as Model;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;
$root = realpath( $argv[1] );
$fixture = realpath( $argv[2] );
if ( ! isset( $argv[3] ) || ! in_array( $argv[3], array( 'original', 'planned' ), true ) ) {
	throw new InvalidArgumentException( 'Explicit original or planned mode required.' );
}
$planned = 'planned' === $argv[3];
define( 'ABSPATH', $root . '/' );
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v, $domain ) { return $v; }
// Projection helper only; NOT WordPress runtime or encoder coverage.
function absint( $value ) { return abs( (int) $value ); }
foreach ( array( 'storage-preflight', 'storage-scale-admission', 'legacy-state-codec', 'legacy-record-codec', 'legacy-table-reader', 'repository-flatten-model' ) as $file ) { require $root . '/includes/class-' . $file . '.php'; }
require $fixture;
$model = new Model( Model::PROFILE );
$store = FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture::value( 20000 );
$tree = $store['galleries']['items']['gallery-009'];
$limits = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216, 'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432, 'memory_ceiling_bytes' => 134217728, 'max_seconds' => 10 );
$policy = array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 );
$budget = new Admission( $limits, $policy, microtime( true ) + 10.0 );
$codec = new Records( null, 24576, 16777216, $budget );
$sample = memory_get_usage( true );
$budget->admit_additional( $planned ? $model->descriptor_bytes( 64 ) : 0 );
$d = $codec->describe_tree( 'gallery-009', $tree, 'repository-sink' );
$plan = $model->project( $d, 'galleries' );
if ( $planned ) { $budget->admit_additional( $plan['after_descriptor_bytes'] ); }
$emitted = 0; $failure = null; $stats = null;
try { $stats = $codec->flatten_tree( 'galleries', 'gallery-009', $tree, 0, function ( $row ) use ( &$emitted ) { ++$emitted; } ); }
catch ( RuntimeException $e ) {
 $trace = array(); foreach ( $e->getTrace() as $frame ) { $trace[] = array( 'function' => ( isset( $frame['class'] ) ? $frame['class'] . '::' : '' ) . $frame['function'], 'file' => isset( $frame['file'] ) ? $frame['file'] : null, 'line' => isset( $frame['line'] ) ? $frame['line'] : null ); }
 $failure = array( 'message' => $e->getMessage(), 'trace' => $trace );
}
$bindings = array(); foreach ( get_declared_classes() as $class ) { if ( 0 !== strpos( $class, 'FooPlugins\\FooGalleryMigrate\\' ) ) { continue; } $file = ( new ReflectionClass( $class ) )->getFileName(); $bindings[$class] = array( 'path' => realpath( $file ), 'sha256' => hash_file( 'sha256', $file ) ); }
$result = array( 'status' => null === $failure ? 'flatten_returned_not_conversion_approval' : 'BLOCKED_ORIGINAL20K_FLATTEN', 'mode' => $planned ? 'model_pending_plus_unchanged_legacy' : 'unchanged_legacy', 'runtime' => array( 'version' => PHP_VERSION, 'binary' => PHP_BINARY, 'binary_sha256' => hash_file( 'sha256', PHP_BINARY ), 'memory_limit' => ini_get( 'memory_limit' ), 'native_json' => ! function_exists( 'wp_json_encode' ), 'profile' => Model::PROFILE ), 'loaded' => $bindings, 'fixture' => array( 'path' => $fixture, 'sha256' => hash_file( 'sha256', $fixture ), 'raw_sha256' => hash( 'sha256', serialize( $store ) ) ), 'sample_including_source' => $sample, 'emitted' => $emitted, 'descriptor' => $d, 'plan' => $plan, 'failure' => $failure, 'stats' => $stats, 'original_policy' => $policy, 'limits' => $limits, 'minimum_legacy_aggregate_requirement_even_with_zero_usage' => $d['encoded_bytes'] * $policy['amplification'] + $policy['reserve_bytes'] );
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure ? 0 : 1 );
