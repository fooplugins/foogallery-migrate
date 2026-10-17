<?php
/** Offline finite producer measurements. Ordinary retaining callback; no guard bypass. */
use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec as Codec;

$root = realpath( $argv[1] );
define( 'ABSPATH', $root . '/' );
class WP_Error { public function __construct( $code, $message ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v, $domain ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
foreach ( array( 'storage-preflight', 'storage-scale-admission', 'legacy-state-codec', 'legacy-record-codec', 'legacy-table-reader', 'generic-retaining-model', 'legacy-option-decoder' ) as $file ) {
 require $root . '/includes/class-' . $file . '.php';
}
function sample() {
 return array( 'live' => memory_get_usage( false ), 'true' => memory_get_usage( true ),
  'peak_live' => memory_get_peak_usage( false ), 'peak_true' => memory_get_peak_usage( true ) );
}
$mode = $argv[2]; $n = (int) $argv[3];
$ballast_bytes = isset( $argv[4] ) ? (int) $argv[4] : 0;
$copies = isset( $argv[5] ) ? (int) $argv[5] : 1;
if ( '128M' !== ini_get( 'memory_limit' ) || ini_get( 'opcache.enable_cli' ) || ! in_array( $copies, array( 1, 2 ), true ) ||
 $ballast_bytes < 0 || $ballast_bytes > 33554432 || $n < 0 || $n > 1048576 ) { throw new RuntimeException( 'Unsupported probe arguments.' ); }
if ( 'original' === $mode ) {
 require dirname( __DIR__ ) . '/support/StorageA7Fixture.php';
 $store = FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture::value( $n ); $v = $store['galleries']['items']['gallery-009'];
} else if ( 'rows' === $mode || 'sibling-cow' === $mode ) { $v = array( 'children' => array_fill( 0, $n, array( 'title' => 'row', 'ID' => 7 ) ) ); }
else if ( 'wide' === $mode || 'fields' === $mode ) { $v = array(); for ( $i = 0; $i < $n; ++$i ) { $v['field-' . $i] = $i; } }
else if ( 'deep' === $mode ) { $v = 'leaf'; for ( $i = 0; $i < $n; ++$i ) { $v = array( 'title' => 'ancestor', 'children' => array( 'kid' => $v ) ); } }
else if ( 'binary' === $mode ) { $v = array( 'data' => str_repeat( "\xff", $n ) ); }
else if ( 'scalar' === $mode ) { $v = str_repeat( 'x', $n ); }
else { throw new RuntimeException( 'Unknown shape.' ); }
$ballast = $ballast_bytes ? str_repeat( 'B', $ballast_bytes ) : '';
$limits = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
 'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152,
 'max_physical_bytes' => 33554432, 'memory_ceiling_bytes' => 134217728, 'max_seconds' => 10 );
$budget = new Admission( $limits, array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ), microtime( true ) + 10.0 );
$c = new Records( null, 24576, 16777216, $budget ); $state = new Codec( 1048576, 64, 10000, $budget );
$report = array( 'profile' => Model::PROFILE, 'supported' => Model::supports( $c, $state, $budget ),
 'shape' => $mode, 'size' => $n, 'ballast_bytes' => strlen( $ballast ), 'copies' => $copies,
 'limit' => ini_get( 'memory_limit' ), 'reserve' => 33554432 );
$stage = 'flatten'; $rows = array(); $duplicates = array();
try {
 $d = $c->describe_tree( 'gallery-009', $v, 'retaining-row' );
 $plan = Model::flatten_plan( $d, 'galleries' );
 $report['flatten_plan'] = $plan;
 if ( 2 === $copies ) {
  // Distinct caller-owned copy is an independent legacy liability, not credited
  // when a producer completes its own rows or closes its scope.
  $budget->admit_additional( Admission::checked_add( $plan['future'], $plan['external'] ) );
  $report['external_distinct_copy_pending'] = Admission::checked_add( $plan['future'], $plan['external'] );
 }
 memory_reset_peak_usage(); $report['flatten_before'] = sample();
 $stats = $c->flatten_tree( 'galleries', 'gallery-009', $v, 0, function ( $row ) use ( &$rows, &$duplicates, $copies ) {
  $rows[] = $row;
  if ( 2 === $copies ) { $copy = array(); foreach ( $row as $key => $value ) { $copy[$key] = is_string( $value ) ? substr( 'x' . $value, 1 ) : $value; } $duplicates[] = $copy; }
 } );
 $report['stats'] = $stats; $report['flatten_after'] = sample();
 $stage = 'rebuild'; $d = $c->describe_rows( $rows, 'caller-rows-rebuild' );
 $scope = $budget->open_retaining_phase( $c, Model::rebuild_descriptor_bytes( $d ) );
 memory_reset_peak_usage(); $report['rebuild_descriptor_before'] = sample();
 $plan = Model::rebuild_plan( $rows, $d, $state, $budget );
 $report['rebuild_descriptor_after'] = sample();
 $budget->plan_retaining_phase( $scope, $c, 0, 0, 0, 0 ); $budget->close_retaining_phase( $scope, $c );
 $report['rebuild_plan'] = $plan;
 memory_reset_peak_usage(); $report['rebuild_before'] = sample();
 $result = $c->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); $report['rebuild_after'] = sample();
 $stage = 'parity';
 if ( serialize( $v ) !== serialize( $result ) ) { throw new RuntimeException( 'Exact serialized parity failed.' ); }
 $report['parity_after'] = sample(); $stage = 'complete';
} catch ( RuntimeException $e ) { $report['failure'] = $e->getMessage(); $report['failure_sample'] = sample(); }
$report['stage'] = $stage; $report['retained_rows'] = count( $rows ); $report['distinct_rows'] = count( $duplicates );
$loaded = array(); foreach ( get_declared_classes() as $class ) {
 if ( 0 !== strpos( $class, 'FooPlugins\\FooGalleryMigrate\\' ) || false !== strpos( $class, '\\Tests\\' ) ) { continue; }
 $file = realpath( ( new ReflectionClass( $class ) )->getFileName() );
 if ( 0 !== strpos( $file, $root . '/includes/' ) ) { throw new RuntimeException( 'Mixed product origin.' ); }
 $loaded[$class] = array( 'path' => $file, 'sha256' => hash_file( 'sha256', $file ) );
}
$report['loaded'] = $loaded;
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 'complete' === $stage ? 0 : 1 );
