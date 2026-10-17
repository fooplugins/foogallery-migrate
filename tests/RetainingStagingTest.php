<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Private-helper witnesses do not qualify a custom codec or injected sampler. */
class StagingDigestWitness extends LegacyStateCodec {
 public $digests = 0;
 public $refuse = false;
 public function snapshot_digest( $value ) {
  ++$this->digests;
  if ( $this->refuse && 'last' === $value ) { throw new \RuntimeException( 'Cache witness refusal.' ); }
  return parent::snapshot_digest( $value );
 }
}

class RetainingStagingTest extends TestCase {
 private function stats() { return array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 ); }
 private function property( $object, $name ) { return ( new \ReflectionProperty( get_class( $object ), $name ) )->getValue( $object ); }
 private function qualified( $budget ) {
  $codec = new LegacyRecordCodec( null, 24576, 16777216, $budget );
  $this->assertTrue( Model::supports( $codec, new LegacyStateCodec( 1048576, 64, 10000, $budget ), $budget ), 'Required native M1 host must qualify; no skip or guard override.' );
  return $codec;
 }
 private function preflight( $c, $state, $tree, &$stats, $phase, &$slots ) {
  $method = new \ReflectionMethod( LegacyRecordCodec::class, 'preflight_tree' );
  $method->invokeArgs( $c, array( $state, null, 'root', $tree, 0, 0, &$stats, $phase, &$slots ) );
 }
 private function stage( $c, $state, $tree, &$stats, $phase, &$slots ) {
  $method = new \ReflectionMethod( LegacyRecordCodec::class, 'stage_retaining_node' );
  $method->invokeArgs( $c, array( $state, null, 'root', $tree, 0, 0, &$stats, $phase, &$slots ) );
 }

 public function test_plan_keeps_existing_workspace_consumer_and_next_row_coefficients(): void {
  $tree = array( 'children' => array( 'a' => str_repeat( 'x', 4097 ), 'b' => null ) );
  $d = ( new LegacyRecordCodec() )->describe_tree( 'root', $tree, 'retaining-row' );
  $old = Model::flatten_plan( $d, 'galleries' ); $new = Model::staged_flatten_plan( $d, 'galleries' );
  $this->assertSame( Model::container( $d['records'] ), $new['future'] );
  $this->assertSame( 1, $new['events'] );
  $this->assertSame( $old['external'], $new['external'] );
  $this->assertSame( $old['private'] + Model::row_forecast( $d['max_row_bytes'], 5 ), $new['private'] );
  foreach ( $old['parts'] as $key => $value ) { $this->assertSame( $value, $new['parts'][$key] ); }
 }

 public function test_representation_preorder_hash_columns_stats_and_serialized_rebuild_parity(): void {
  $tree = array( 'title' => "binary\0\xff", 'object_type' => 'gallery', 'plugin_name' => 'plug', 'ID' => '007',
   'progress' => array( 'done' => 2 ), 'children' => array( '01' => false, 8 => array( 'object_type' => 'image',
    'plugin_name' => 'plug', 'ID' => 7, 'migration_status' => 'pending', 'children' => array( 'nested' => null ) ), 'empty' => array() ) );
  $reference = array(); $plain = new LegacyRecordCodec();
  $expected = $plain->flatten_tree( 'galleries', 'root', $tree, 3, function( $row ) use ( &$reference ) { $reference[] = $row; } );
  $budget = StorageScaleAdmissionTest::admission(); $c = $this->qualified( $budget ); $rows = array();
  $stats = $c->flatten_tree( 'galleries', 'root', $tree, 3, function( $row ) use ( &$rows ) { $rows[] = $row; } );
  $this->assertSame( $reference, $rows ); $this->assertSame( $expected, $stats );
  $this->assertSame( array( 'root', '01', '8', 'nested', 'empty' ), array_map( function( $r ) { return base64_decode( $r['key_value'] ); }, $rows ) );
  $this->assertSame( array( 0, 1, 1, 2, 1 ), array_column( $rows, 'depth' ) );
  $values = array( $tree, false, $tree['children'][8], null, array() ); $logical = new LegacyStateCodec();
  foreach ( $rows as $i => $row ) {
   $this->assertSame( array_keys( $reference[0] ), array_keys( $row ) );
   $this->assertSame( $logical->snapshot_digest( $values[$i] ), $row['logical_hash'] );
   $this->assertSame( hash( 'sha256', $row['payload'] ), $row['payload_hash'] );
  }
  $this->assertSame( serialize( $tree ), serialize( $c->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) ) );
 }

 public function test_all_rows_staged_and_recursive_frames_gone_before_first_callback(): void {
  $last = array( 'title' => 'original' );
  $tree = array( 'children' => array( 'first' => 'first', 'last' => &$last ) );
  $budget = StorageScaleAdmissionTest::admission(); $c = $this->qualified( $budget );
  $rows = array(); $d = $c->describe_tree( 'root', $tree, 'retaining-row' ); $plan = Model::staged_flatten_plan( $d, 'galleries' );
  $c->flatten_tree( 'galleries', 'root', $tree, 0, function( $row ) use ( &$rows, &$last, $budget, $plan ) {
   $functions = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' );
   $this->assertNotContains( 'stage_retaining_node', $functions );
   $this->assertNotContains( 'flatten_node', $functions );
   $phases = $this->property( $budget, 'retaining_phases' ); $phase = reset( $phases );
   $this->assertSame( 1, $phase['events'] ); $this->assertSame( 1, $phase['next'] );
   $this->assertSame( 0, $phase['future'] ); $this->assertSame( $plan['private'], $phase['private'] );
   $this->assertSame( $plan['external'], $phase['external'] );
   if ( ! count( $rows ) ) { $last['title'] = 'changed by callback'; }
   $rows[] = $row;
  } );
  $rebuilt = ( new LegacyRecordCodec() )->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows );
  $this->assertSame( 'original', $rebuilt['children']['last']['title'] );
  $this->assertSame( array(), $this->property( $budget, 'retaining_phases' ) );
  $this->assertSame( $plan['external'], $this->property( $budget, 'additional_bytes' ) );
 }

 public function test_private_slot_sequence_and_no_second_record_digest(): void {
  $tree = array( 'children' => array( 'first' => 'first', 'last' => 'last' ) );
  $codec = new StagingDigestWitness(); $c = new LegacyRecordCodec( $codec );
  $state = $codec->key_hash( 'galleries' ); $slots = array_fill( 0, 3, null ); $preflight = $this->stats();
  $this->assertSame( array( null, null, null ), $slots );
  $this->preflight( $c, $state, $tree, $preflight, new \stdClass(), $slots );
  $this->assertSame( 3, $codec->digests );
  $this->assertSame( array( $codec->snapshot_digest( $tree ), $codec->snapshot_digest( 'first' ), $codec->snapshot_digest( 'last' ) ), $slots );
  // The full-tree pass runs while every slot is still a hash, not a row.
  $codec->snapshot_digest( $tree ); $before = $codec->digests; $stats = $this->stats();
  $this->stage( $c, $state, $tree, $stats, new \stdClass(), $slots );
  $this->assertSame( $before, $codec->digests ); $this->assertSame( $preflight, $stats );
  $this->assertSame( 3, count( array_filter( $slots, 'is_array' ) ) );
  // The production transfer is lexical, not an exported/foreach table alias.
  $method = new \ReflectionMethod( LegacyRecordCodec::class, 'flatten_tree' );
  $source = implode( '', array_slice( file( $method->getFileName() ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
  $this->assertStringContainsString( '$row = $slots[$i]; unset( $slots[$i] );', $source );
  $take = strpos( $source, '$row = $slots[$i]; unset( $slots[$i] );' );
  $this->assertGreaterThan( $take, strpos( $source, 'call_user_func( $emit, $row )', $take ) );
  $this->assertStringNotContainsString( 'foreach ( $slots', $source );
 }

 public function test_cache_refusal_has_no_emission_or_retained_row_graph(): void {
  $codec = new StagingDigestWitness(); $codec->refuse = true; $c = new LegacyRecordCodec( $codec );
  $slots = array_fill( 0, 3, null ); $stats = $this->stats(); $emitted = 0;
  try {
   $this->preflight( $c, $codec->key_hash( 'galleries' ), array( 'children' => array( 'first' => 'first', 'last' => 'last' ) ), $stats, new \stdClass(), $slots );
   ++$emitted; $this->fail( 'Expected cache refusal.' );
  } catch ( \RuntimeException $e ) { $this->assertSame( 'Cache witness refusal.', $e->getMessage() ); }
  $this->assertSame( 0, $emitted ); $this->assertSame( 0, count( array_filter( $slots, 'is_array' ) ) );
  $this->assertIsString( $slots[0] ); $this->assertIsString( $slots[1] ); $this->assertNull( $slots[2] );
 }

 public function test_last_cached_slot_refusal_cannot_emit_a_prepared_prefix(): void {
  $codec = new LegacyStateCodec(); $c = new LegacyRecordCodec( $codec );
  $tree = array( 'children' => array( 'first' => 'first', 'last' => 'last' ) );
  $slots = array_fill( 0, 3, null ); $preflight = $this->stats(); $state = $codec->key_hash( 'galleries' );
  $this->preflight( $c, $state, $tree, $preflight, new \stdClass(), $slots );
  $slots[2] = null; $stats = $this->stats(); $emitted = 0;
  try { $this->stage( $c, $state, $tree, $stats, new \stdClass(), $slots ); ++$emitted; $this->fail( 'Expected stage refusal.' ); }
  catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'Invalid cached logical hash slot', $e->getMessage() ); }
  $this->assertSame( 0, $emitted ); $this->assertSame( 2, $stats['record_count'] );
  $this->assertIsArray( $slots[0] ); $this->assertIsArray( $slots[1] ); $this->assertNull( $slots[2] );
 }

 public function test_public_cache_and_staging_interruptions_emit_no_prefix(): void {
  foreach ( array( 'preflight_tree', 'stage_retaining_node' ) as $target ) {
   $armed = false; $reached = false; $emitted = 0;
   // Supported clock seam, not a sampler override or production qualification bypass.
   $clock = function() use ( &$armed, &$reached, $target ) {
    if ( $armed ) {
     $functions = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' );
     $counts = array_count_values( $functions );
     if ( isset( $counts[$target] ) && $counts[$target] > 1 &&
      ( 'stage_retaining_node' === $target || ( in_array( 'snapshot_digest', $functions, true ) && ! in_array( 'projections', $functions, true ) ) ) ) {
      $reached = true; throw new \RuntimeException( 'M1 preparation interruption: ' . $target );
     }
    }
    return microtime( true );
   };
   $budget = StorageScaleAdmissionTest::admission( null, $clock ); $c = $this->qualified( $budget ); $armed = true;
   try {
    $c->flatten_tree( 'galleries', 'root', array( 'children' => array( 'first' => 'first', 'last' => 'last' ) ), 0,
     function() use ( &$emitted ) { ++$emitted; } );
    $this->fail( 'Required preparation seam was not exercised: ' . $target );
   } catch ( \RuntimeException $e ) { $this->assertSame( 'M1 preparation interruption: ' . $target, $e->getMessage() ); }
   $this->assertTrue( $reached ); $this->assertSame( 0, $emitted );
   $this->assertNotEmpty( $this->property( $budget, 'retaining_phases' ) );
   $this->assertGreaterThan( 0, $this->property( $budget, 'retaining_pending' ) );
  }
 }

 public function test_original_input_flatten_uses_one_completed_slot_event_not_aggregate_future(): void {
  // Original data factory and resources, not the historical test or its order.
  // Flatten-only M1 witness: NOT original roundtrip/deadline/package acceptance.
  $value = StorageA7Fixture::value( 20000 ); $tree = $value['galleries']['items']['gallery-009'];
  $budget = StorageScaleAdmissionTest::admission(); $c = $this->qualified( $budget ); $rows = 0;
  $stats = $c->flatten_tree( 'galleries', 'gallery-009', $tree, 0, function( $row ) use ( $budget, &$rows ) {
   if ( 0 === $rows ) {
    $phases = $this->property( $budget, 'retaining_phases' ); $phase = reset( $phases );
    $this->assertSame( 1, $phase['events'], 'Original input must use the one preallocated slot event.' );
    $this->assertSame( 1, $phase['next'] ); $this->assertSame( 0, $phase['future'] );
   }
   ++$rows;
  } );
  $this->assertSame( 20208, $rows ); $this->assertSame( 20208, $stats['record_count'] );
  $this->assertSame( 15070354, $stats['encoded_bytes'] );
 }
}
