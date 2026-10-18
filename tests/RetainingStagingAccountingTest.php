<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Finite M2 accounting witnesses, not native coefficient calibration. */
class RetainingStagingAccountingTest extends TestCase {
 private function property( $object, $name ) {
  return ( new \ReflectionProperty( get_class( $object ), $name ) )->getValue( $object );
 }
 private function ledger( $b ) {
  return array( 'pending' => $this->property( $b, 'retaining_pending' ),
   'additional' => $this->property( $b, 'additional_bytes' ), 'phases' => $this->property( $b, 'retaining_phases' ) );
 }
 private function refused( $call, $message = null ) {
  try { $call(); } catch ( \RuntimeException $e ) {
   if ( null !== $message ) { $this->assertSame( $message, $e->getMessage() ); }
   else { $this->assertNotEmpty( $e->getMessage() ); }
   return;
  }
  $this->fail( 'Expected finite interruption/refusal.' );
 }
 private function qualified( $b ) {
  $c = new LegacyRecordCodec( null, 24576, 16777216, $b );
  $this->assertTrue( Model::supports( $c, new LegacyStateCodec( 1048576, 64, 10000, $b ), $b ), 'Native M2 qualification required; no skip or sampler override.' );
  return $c;
 }
 private function tree( $count, $bytes = 17 ) {
  $tree = array( 'children' => array() );
  for ( $i = 0; $i < $count; ++$i ) { $tree['children']['child-' . $i] = str_repeat( chr( 65 + $i % 26 ), $bytes ) . $i; }
  return $tree;
 }
 private function foreign( $b ) {
  $owner = new \stdClass(); $token = $b->open_retaining_phase( $owner, 1 );
  $b->plan_retaining_phase( $token, $owner, 1024, 2048, 512, 1 );
  return $this->ledger( $b );
 }
 private function assert_staged_pending( $b, $c, $plan, $foreign, $future ) {
  $ledger = $this->ledger( $b );
  $this->assertSame( $foreign['additional'], $ledger['additional'] );
  foreach ( $foreign['phases'] as $id => $phase ) { $this->assertSame( $phase, $ledger['phases'][$id] ); }
  $own = array_values( array_filter( $ledger['phases'], function( $s ) use ( $c ) { return $s['producer'] === $c; } ) );
  $this->assertCount( 1, $own ); $s = $own[0];
  $this->assertTrue( $s['planned'] ); $this->assertSame( 1, $s['events'] );
  $this->assertSame( $future, $s['future'] ); $this->assertSame( 0 === $future ? 1 : 0, $s['next'] );
  $this->assertSame( $plan['private'], $s['private'] ); $this->assertSame( $plan['external'], $s['external'] );
  $this->assertSame( $foreign['pending'] + $future + $plan['private'] + $plan['external'], $ledger['pending'] );
 }
 private function record( $name, $start, $b, $extra = array() ) {
  $ledger = $this->ledger( $b ); $phases = array();
  foreach ( $ledger['phases'] as $s ) { unset( $s['token'], $s['producer'] ); $phases[] = $s; }
  $data = array_merge( array( 'case' => $name, 'seconds' => microtime( true ) - $start,
   'used_live' => memory_get_usage( false ), 'used_true' => memory_get_usage( true ),
   'peak_live' => memory_get_peak_usage( false ), 'peak_true' => memory_get_peak_usage( true ),
   'pending' => $ledger['pending'], 'additional' => $ledger['additional'], 'phases' => $phases ), $extra );
  $path = getenv( 'M2_METRICS_REPORT' );
  if ( false !== $path && '' !== $path ) { file_put_contents( $path, json_encode( $data, JSON_THROW_ON_ERROR ) . "\n", FILE_APPEND | LOCK_EX ); }
 }

 public function test_failed_native_allocation_cannot_realize_K_or_clear_existing_liabilities(): void {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 );
  $b = StorageScaleAdmissionTest::admission(); $c = $this->qualified( $b );
  $b->admit_additional( 8192 ); $foreign = $this->foreign( $b ); $tree = $this->tree( 16 );
  $plan = Model::staged_flatten_plan( $c->describe_tree( 'root', $tree, 'retaining-row' ), 'galleries' );
  $token = $b->open_retaining_phase( $c, Model::descriptor_bytes() );
  $b->plan_retaining_phase( $token, $c, $plan['future'], $plan['private'], $plan['external'], 1 );
  $before = $this->ledger( $b ); $failed = false;
  // An impossible slot count is rejected by the native allocator BEFORE memory
  // allocation. This is an allocation-failure witness, NOT a catchable OOM claim
  // or a qualified descriptor. Production count/caps are never overridden.
  try {
   $slots = array_fill( 0, PHP_INT_MAX, null );
   $b->realize_retaining_event( $token, $c, 0, $plan['future'] );
  } catch ( \ValueError $e ) { $failed = true; $this->assertNotEmpty( $e->getMessage() ); }
  $this->assertTrue( $failed ); $this->assertFalse( isset( $slots ) );
  $this->assertSame( $before, $this->ledger( $b ) );
  $this->assert_staged_pending( $b, $c, $plan, $foreign, $plan['future'] );
  $this->refused( function() use ( $b, $token, $c ) { $b->close_retaining_phase( $token, $c ); } );
  $this->assertSame( $before, $this->ledger( $b ) );
  // Bind this public-ledger failure witness to the actual private orchestration.
  // No production allocator hook or public bypass is introduced for fault injection.
  $m = new \ReflectionMethod( LegacyRecordCodec::class, 'flatten_tree' );
  $source = implode( '', array_slice( file( $m->getFileName() ), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
  $allocate = strpos( $source, '$slots = array_fill( 0, $d[\'records\'], null );' );
  $realize = strpos( $source, '$this->scale->realize_retaining_event( $phase, $this, 0, $plan[\'future\'] );' );
  $this->assertNotFalse( $allocate ); $this->assertNotFalse( $realize ); $this->assertGreaterThan( $allocate, $realize );
  $this->record( __FUNCTION__, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'allocation_failure' => 'native count rejection; not OOM',
   'K' => $plan['future'], 'private_W_plus_F' => $plan['private'], 'E' => $plan['external'] ) );
 }

 public function test_allocation_realization_interruption_keeps_unconsumed_K_and_all_liabilities(): void {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 );
  $armed = false; $before = null; $allocated = null;
  // Public clock seam interrupts the realization boundary before ledger mutation.
  // Native live usage distinguishes actual preallocation from an early event.
  $clock = function() use ( &$armed, &$before, &$allocated ) {
   if ( $armed ) {
    $functions = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' );
    if ( in_array( 'plan_retaining_phase', $functions, true ) && in_array( 'admit_memory', $functions, true ) ) { $before = memory_get_usage( false ); }
    if ( in_array( 'realize_retaining_event', $functions, true ) ) {
     $allocated = memory_get_usage( false ); throw new \RuntimeException( 'M2 realization interrupted.' );
    }
   }
   return microtime( true );
  };
  $b = StorageScaleAdmissionTest::admission( null, $clock ); $c = $this->qualified( $b );
  $b->admit_additional( 8192 ); $foreign = $this->foreign( $b ); $tree = $this->tree( 4096 );
  $plan = Model::staged_flatten_plan( $c->describe_tree( 'root', $tree, 'retaining-row' ), 'galleries' );
  $emitted = 0; $armed = true;
  $this->refused( function() use ( $c, $tree, &$emitted ) {
   $c->flatten_tree( 'galleries', 'root', $tree, 0, function() use ( &$emitted ) { ++$emitted; } );
  }, 'M2 realization interrupted.' );
  $armed = false;
  $this->assertSame( 0, $emitted ); $this->assertNotNull( $before ); $this->assertNotNull( $allocated );
  $this->assertGreaterThanOrEqual( 4097 * 16, $allocated - $before, 'K must be realized only AFTER all native slots exist.' );
  $this->assert_staged_pending( $b, $c, $plan, $foreign, $plan['future'] );
  $this->record( __FUNCTION__, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'slot_count' => 4097,
   'preallocation_live_delta' => $allocated - $before, 'K' => $plan['future'], 'private_W_plus_F' => $plan['private'], 'E' => $plan['external'] ) );
 }

 public function test_wrong_K_sequence_forecast_owner_and_domain_cannot_release_pending(): void {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 );
  $b = StorageScaleAdmissionTest::admission(); $this->qualified( $b ); $b->admit_additional( 8192 );
  $foreign = $this->foreign( $b ); $owner = new \stdClass(); $wrong = new \stdClass();
  $token = $b->open_retaining_phase( $owner, 1 ); $K = Model::container( 4097 );
  $b->plan_retaining_phase( $token, $owner, $K, 32768, 4096, 1 );
  $before = $this->ledger( $b ); $live = memory_get_usage( false ); $slots = array_fill( 0, 4097, null );
  $allocated = memory_get_usage( false );
  foreach ( array( array( $token, $wrong, 0, $K ), array( new \stdClass(), $owner, 0, $K ),
   array( $token, $owner, 1, $K ), array( $token, $owner, 0, $K - 1 ), array( $token, $owner, 0, $K + 1 ) ) as $args ) {
   $this->refused( function() use ( $b, $args ) { $b->realize_retaining_event( $args[0], $args[1], $args[2], $args[3] ); } );
   $this->assertSame( $before, $this->ledger( $b ) );
  }
  $this->refused( function() use ( $b, $token, $owner ) { $b->close_retaining_phase( $token, $owner ); } );
  $this->assertSame( $before, $this->ledger( $b ) );
  $b->realize_retaining_event( $token, $owner, 0, $K );
  $this->assertSame( $before['pending'] - $K, $this->ledger( $b )['pending'] );
  $this->assertSame( $before['additional'], $this->ledger( $b )['additional'] );
  $this->assertGreaterThanOrEqual( 4097 * 16, $allocated - $live ); $this->assertCount( 4097, $slots );
  $after = $this->ledger( $b );
  $this->refused( function() use ( $b, $token, $owner, $K ) { $b->realize_retaining_event( $token, $owner, 0, $K ); } );
  $this->assertSame( $after, $this->ledger( $b ) );
  foreach ( $foreign['phases'] as $id => $s ) { $this->assertSame( $s, $after['phases'][$id] ); }
  $this->record( __FUNCTION__, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'K' => $K, 'allocation_live_delta' => $allocated - $live ) );
 }

 public function test_native_slot_and_ballast_usage_is_never_forecast_credit(): void {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 );
  $b = StorageScaleAdmissionTest::admission(); $this->qualified( $b ); $owner = new \stdClass();
  $token = $b->open_retaining_phase( $owner, 1 ); $K = Model::container( 4097 );
  $b->plan_retaining_phase( $token, $owner, $K, 32768, 4096, 1 );
  $slots = array_fill( 0, 4097, null ); $b->realize_retaining_event( $token, $owner, 0, $K );
  $pending = $this->ledger( $b )['pending']; $sample = memory_get_usage( true );
  // Leave 1MiB finite headroom using the ACTUAL native sample, not a low sampler.
  $limit = \FooPlugins\FooGalleryMigrate\StoragePreflight::memory_bytes( ini_get( 'memory_limit' ) );
  $b->admit_additional( $limit - 33554432 - $sample - $pending - 1048576 );
  $this->assertTrue( $b->admit_memory( 0 ) ); $ledger = $this->ledger( $b );
  $more = str_repeat( 'x', 4194304 ); $grown = memory_get_usage( true );
  $this->assertGreaterThanOrEqual( 4194304, $grown - $sample );
  $this->refused( function() use ( $b ) { $b->admit_memory( 0 ); } );
  $this->assertSame( $ledger, $this->ledger( $b ) );
  $high_water = $this->property( $b, 'native_high_water' );
  $this->assertGreaterThanOrEqual( $grown, $high_water );
  unset( $more ); $released = memory_get_usage( true );
  $this->assertLessThan( $grown, $released, 'The real allocation was freed, not merely left live.' );
  // The operation-owned native high-water survives a lower current sample.
  // Freeing backing cannot restore forecast credit or erase any liability.
  $this->refused( function() use ( $b ) { $b->admit_memory( 0 ); } );
  $this->assertGreaterThanOrEqual( $high_water, $this->property( $b, 'native_high_water' ) );
  $this->assertSame( $ledger, $this->ledger( $b ) );
  $this->assertCount( 4097, $slots );
  $this->record( __FUNCTION__, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'extra_ballast_bytes' => 4194304,
   'sample_before' => $sample, 'sample_with_extra_ballast' => $grown, 'sample_after_release' => $released,
   'native_high_water' => $this->property( $b, 'native_high_water' ), 'K' => $K ) );
 }

 public function test_native_high_water_allows_positive_headroom_after_ballast_release(): void {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 );
  $b = StorageScaleAdmissionTest::admission(); $this->qualified( $b ); $owner = new \stdClass();
  $token = $b->open_retaining_phase( $owner, 1 ); $K = Model::container( 4097 );
  $b->plan_retaining_phase( $token, $owner, $K, 32768, 4096, 1 );
  $slots = array_fill( 0, 4097, null ); $b->realize_retaining_event( $token, $owner, 0, $K );
  $pending = $this->ledger( $b )['pending']; $sample = memory_get_usage( true );
  $limit = \FooPlugins\FooGalleryMigrate\StoragePreflight::memory_bytes( ini_get( 'memory_limit' ) );
  // Unlike the pressure case, this operation leaves room for the sampled peak.
  $b->admit_additional( $limit - 33554432 - $sample - $pending - 8388608 );
  $ledger = $this->ledger( $b ); $more = str_repeat( 'x', 4194304 ); $grown = memory_get_usage( true );
  $this->assertGreaterThanOrEqual( 4194304, $grown - $sample );
  $this->assertTrue( $b->admit_memory( 0 ) );
  unset( $more ); $released = memory_get_usage( true );
  $this->assertLessThan( $grown, $released );
  $headroom = $limit - 33554432 - $grown - $ledger['pending'] - $ledger['additional'];
  $bytes = intdiv( $headroom, 8 ); $this->assertGreaterThan( 0, $bytes );
  // Preserve a real post-free positive allocation, at the unchanged factor-8
  // boundary; one more raw byte must refuse, without spending or resetting it.
  $this->assertTrue( $b->admit_memory( $bytes ) );
  $this->refused( function() use ( $b, $bytes ) { $b->admit_memory( $bytes + 1 ); } );
  $this->assertTrue( $b->admit_memory( $bytes ) );
  $this->assertSame( $grown, $this->property( $b, 'native_high_water' ) );
  $this->assertSame( $ledger, $this->ledger( $b ) ); $this->assertCount( 4097, $slots );
  $this->record( __FUNCTION__, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'extra_ballast_bytes' => 4194304,
   'sample_before' => $sample, 'sample_with_extra_ballast' => $grown, 'sample_after_release' => $released,
   'native_high_water' => $this->property( $b, 'native_high_water' ), 'positive_raw_bytes' => $bytes,
   'high_water_headroom' => $headroom, 'K' => $K ) );
 }

 public function test_stage_interruption_preserves_W_F_E_foreign_and_legacy(): void {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 ); $armed = false; $reached = false;
  $clock = function() use ( &$armed, &$reached ) {
   if ( $armed ) {
    $counts = array_count_values( array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ) );
    if ( isset( $counts['stage_retaining_node'] ) && $counts['stage_retaining_node'] > 1 ) {
     $reached = true; throw new \RuntimeException( 'M2 stage interrupted.' );
    }
   }
   return microtime( true );
  };
  $b = StorageScaleAdmissionTest::admission( null, $clock ); $c = $this->qualified( $b );
  $b->admit_additional( 8192 ); $foreign = $this->foreign( $b ); $tree = $this->tree( 16, 4097 );
  $plan = Model::staged_flatten_plan( $c->describe_tree( 'root', $tree, 'retaining-row' ), 'galleries' );
  $emitted = 0; $armed = true;
  $this->refused( function() use ( $c, $tree, &$emitted ) {
   $c->flatten_tree( 'galleries', 'root', $tree, 0, function() use ( &$emitted ) { ++$emitted; } );
  }, 'M2 stage interrupted.' );
  $armed = false; $this->assertTrue( $reached ); $this->assertSame( 0, $emitted );
  // The root row completed; its child has not started. The independent row
  // scope must survive interruption alongside the original slot liabilities.
  $ledger = $this->ledger( $b );
  $own = array_values( array_filter( $ledger['phases'], function( $s ) use ( $c ) { return $s['producer'] === $c; } ) );
  $this->assertCount( 2, $own );
  $this->assertSame( 0, $own[0]['future'] ); $this->assertSame( 1, $own[0]['next'] );
  $this->assertSame( $plan['private'], $own[0]['private'] ); $this->assertSame( $plan['external'], $own[0]['external'] );
  $this->assertSame( $plan['rows_events'], $own[1]['events'] ); $this->assertSame( 1, $own[1]['next'] );
  $this->assertGreaterThan( 0, $own[1]['future'] ); $this->assertLessThan( $plan['rows_future'], $own[1]['future'] );
  $this->assertSame( $foreign['pending'] + $plan['private'] + $plan['external'] + $own[1]['future'], $ledger['pending'] );
  $this->assertSame( $foreign['additional'], $ledger['additional'] );
  foreach ( $foreign['phases'] as $id => $s ) { $this->assertSame( $s, $ledger['phases'][$id] ); }
  $before = $this->ledger( $b );
  $this->refused( function() use ( $b, $c, $own ) { $b->close_retaining_phase( $own[1]['token'], $c ); } );
  $this->assertSame( $before, $this->ledger( $b ) );
  $this->record( __FUNCTION__, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'private_W_plus_F' => $plan['private'], 'E' => $plan['external'] ) );
 }

 public function test_each_row_is_installed_before_exact_realization_and_closed_only_after_unwind(): void {
  $armed = false; $seen = array(); $closed = false; $prepared = false; $b = null; $c = null; $plan = null; $foreign = null;
  $clock = function() use ( &$armed, &$seen, &$closed, &$prepared, &$b, &$c, &$plan, &$foreign ) {
   if ( $armed ) {
    $trace = debug_backtrace(); $functions = array_column( $trace, 'function' );
    $ledger = $this->ledger( $b );
    $own = array_values( array_filter( $ledger['phases'], function( $s ) use ( $c ) { return $s['producer'] === $c; } ) );
    if ( ! $prepared && in_array( 'preflight_tree', $functions, true ) ) {
     $this->assertCount( 2, $own ); $this->assertSame( $plan['rows_future'], $own[1]['future'] );
     $this->assertSame( $plan['rows_events'], $own[1]['events'] ); $prepared = true;
    }
    foreach ( $trace as $frame ) {
     if ( 'realize_retaining_event' !== $frame['function'] || ! in_array( 'retaining_id', $functions, true ) ) { continue; }
     $args = $frame['args'];
     if ( count( $own ) !== 2 || $args[0] !== $own[1]['token'] ) { continue; }
     $sequence = $args[2]; $stage = $trace[array_search( 'stage_retaining_node', $functions, true )];
     $slots = $stage['args'][8];
     $this->assertIsArray( $slots[$sequence], 'Completed row must already be installed in its own slot.' );
     $bytes = 0;
     foreach ( array( 'payload', 'mutable_payload', 'key_value', 'source_plugin_value', 'source_id_value' ) as $field ) {
      if ( is_string( $slots[$sequence][$field] ) ) { $bytes += strlen( $slots[$sequence][$field] ); }
     }
     $forecast = Model::row_forecast( $bytes, Model::row_large_columns( $slots[$sequence] ) );
     $this->assertSame( $forecast, $args[3] ); $this->assertSame( count( $seen ), $sequence );
     $this->assertSame( $plan['rows_future'] - array_sum( $seen ), $own[1]['future'] );
     $this->assertSame( $foreign['pending'] + $plan['private'] + $plan['external'] + $own[1]['future'], $ledger['pending'] );
     $this->assertSame( $foreign['additional'], $ledger['additional'] );
     foreach ( $foreign['phases'] as $id => $s ) { $this->assertSame( $s, $ledger['phases'][$id] ); }
     $seen[] = $forecast;
    }
    if ( in_array( 'close_retaining_phase', $functions, true ) && in_array( 'retaining_id', $functions, true ) && count( $own ) === 2 ) {
     $this->assertNotContains( 'stage_retaining_node', $functions );
     $this->assertSame( $plan['rows_events'], $own[1]['next'] ); $this->assertSame( 0, $own[1]['future'] );
     $this->assertSame( $plan['rows_future'], array_sum( $seen ) ); $closed = true;
    }
   }
   return microtime( true );
  };
  $b = StorageScaleAdmissionTest::admission( null, $clock ); $c = $this->qualified( $b );
  $b->admit_additional( 8192 ); $foreign = $this->foreign( $b );
  $tree = $this->tree( 2, 4097 ); $tree['children']['small'] = null;
  $plan = Model::staged_flatten_plan( $c->describe_tree( 'root', $tree, 'retaining-row' ), 'galleries' );
  $emitted = 0; $armed = true;
  $c->flatten_tree( 'galleries', 'root', $tree, 0, function() use ( &$emitted, &$closed, $b, $c, $plan, $foreign ) {
   $this->assertTrue( $closed ); $this->assert_staged_pending( $b, $c, $plan, $foreign, 0 ); ++$emitted;
  } );
  $armed = false;
  $this->assertTrue( $prepared ); $this->assertTrue( $closed ); $this->assertSame( $plan['rows_events'], $emitted );
  $this->assertSame( $foreign['phases'], $this->ledger( $b )['phases'] );
  $this->assertSame( $foreign['pending'], $this->ledger( $b )['pending'] );
  $this->assertSame( $foreign['additional'] + $plan['external'], $this->ledger( $b )['additional'] );
 }

 public function test_aborted_row_event_wrong_owner_sequence_forecast_and_domain_preserve_pending(): void {
  $armed = false; $b = null; $c = null; $token = null; $next = null; $forecast = null;
  $clock = function() use ( &$armed, &$b, &$c, &$token, &$next, &$forecast ) {
   if ( $armed ) {
    $trace = debug_backtrace(); $functions = array_column( $trace, 'function' );
    if ( in_array( 'retaining_id', $functions, true ) ) {
     foreach ( $trace as $frame ) {
      if ( 'realize_retaining_event' !== $frame['function'] || 1 !== $frame['args'][2] ) { continue; }
      $token = $frame['args'][0]; $next = $frame['args'][2]; $forecast = $frame['args'][3];
      throw new \RuntimeException( 'Row realization interrupted.' );
     }
    }
   }
   return microtime( true );
  };
  $b = StorageScaleAdmissionTest::admission( null, $clock ); $c = $this->qualified( $b );
  $b->admit_additional( 8192 ); $foreign = $this->foreign( $b ); $tree = $this->tree( 1, 4097 );
  $plan = Model::staged_flatten_plan( $c->describe_tree( 'root', $tree, 'retaining-row' ), 'galleries' );
  $emitted = 0; $armed = true;
  $this->refused( function() use ( $c, $tree, &$emitted ) {
   $c->flatten_tree( 'galleries', 'root', $tree, 0, function() use ( &$emitted ) { ++$emitted; } );
  }, 'Row realization interrupted.' );
  $armed = false; $before = $this->ledger( $b );
  $this->assertSame( 0, $emitted ); $this->assertNotNull( $token );
  $s = $before['phases'][spl_object_hash( $token )];
  $this->assertSame( $c, $s['producer'] ); $this->assertSame( $next, $s['next'] );
  $this->assertSame( $forecast, $s['future'] ); $this->assertSame( $plan['rows_events'], $s['events'] );
  $this->assertSame( $foreign['pending'] + $plan['private'] + $plan['external'] + $forecast, $before['pending'] );
  $other = StorageScaleAdmissionTest::admission();
  foreach ( array( array( $token, new \stdClass(), $next, $forecast ), array( new \stdClass(), $c, $next, $forecast ),
   array( $token, $c, 0, $forecast ), array( $token, $c, 2, $forecast ),
   array( $token, $c, $next, $forecast - 1 ), array( $token, $c, $next, $forecast + 1 ) ) as $args ) {
   $this->refused( function() use ( $b, $args ) { $b->realize_retaining_event( $args[0], $args[1], $args[2], $args[3] ); } );
   $this->assertSame( $before, $this->ledger( $b ) );
  }
  $this->refused( function() use ( $b, $token, $c ) { $b->close_retaining_phase( $token, $c ); } );
  $this->refused( function() use ( $other, $token, $c, $next, $forecast ) { $other->realize_retaining_event( $token, $c, $next, $forecast ); } );
  $this->assertSame( $before, $this->ledger( $b ) );
  foreach ( $foreign['phases'] as $id => $s ) { $this->assertSame( $s, $before['phases'][$id] ); }
  $this->assertSame( $foreign['additional'], $before['additional'] );
  // Do not manually finish the interrupted producer: discarded slots are not
  // proof of a live completed graph. Its row scope deliberately remains open.
 }

 private function transfer( $mode ) {
  $start = microtime( true ); $ballast = str_repeat( 'b', 8388608 );
  $b = StorageScaleAdmissionTest::admission(); $c = $this->qualified( $b );
  $b->admit_additional( 8192 ); $foreign = $this->foreign( $b ); $tree = $this->tree( 16, 4097 );
  $plan = Model::staged_flatten_plan( $c->describe_tree( 'root', $tree, 'retaining-row' ), 'galleries' );
  $rows = array(); $copies = array(); $retained_bytes = 0; $copy_live_delta = 0;
  $emit = function( $row ) use ( $mode, $b, $c, $plan, $foreign, &$rows, &$copies, &$retained_bytes, &$copy_live_delta ) {
   $this->assert_staged_pending( $b, $c, $plan, $foreign, 0 );
   $rows[] = $row; $before = memory_get_usage( false );
   // Serialization/unserialization makes distinct arrays AND string allocations,
   // not another reference to the staged row or merely a shared scalar list.
   $copy = unserialize( serialize( $row ) );
   $this->assertSame( $row, $copy );
   $copy['payload'][0] = '!'; $this->assertNotSame( $row['payload'], $copy['payload'] );
   $copies[] = $copy; $copy_live_delta += memory_get_usage( false ) - $before;
   $retained_bytes += strlen( $row['payload'] ) + strlen( $copy['payload'] );
   if ( 'success' !== $mode && 2 === count( $rows ) ) {
    if ( 'throw' === $mode ) { throw new \RuntimeException( 'M2 callback interrupted.' ); }
    return false;
   }
   return true;
  };
  if ( 'success' === $mode ) {
   $stats = $c->flatten_tree( 'galleries', 'root', $tree, 0, $emit );
   $this->assertSame( 17, $stats['record_count'] ); $this->assertCount( 17, $rows );
   $ledger = $this->ledger( $b );
   $this->assertSame( $foreign['phases'], $ledger['phases'] );
   $this->assertSame( $foreign['pending'], $ledger['pending'] );
   $this->assertSame( $foreign['additional'] + $plan['external'], $ledger['additional'], 'E survives successful destruction/close; foreign and legacy remain.' );
  } else {
   $this->refused( function() use ( $c, $tree, $emit ) { $c->flatten_tree( 'galleries', 'root', $tree, 0, $emit ); },
    'throw' === $mode ? 'M2 callback interrupted.' : 'Legacy storage record codec: Staged consumer refused the write.' );
   $this->assertCount( 2, $rows ); $this->assert_staged_pending( $b, $c, $plan, $foreign, 0 );
  }
  $this->assertCount( count( $rows ), $copies ); $this->assertGreaterThan( 0, $copy_live_delta );
  $this->assertTrue( $b->admit_memory( 0 ) );
  $this->record( 'transfer_' . $mode, $start, $b, array( 'ballast_bytes' => strlen( $ballast ), 'retained_rows' => count( $rows ),
   'distinct_copies' => count( $copies ), 'retained_payload_bytes' => $retained_bytes, 'copy_live_delta' => $copy_live_delta,
   'private_W_plus_F' => $plan['private'], 'E' => $plan['external'] ) );
 }
 public function test_callback_exception_preserves_all_pending_with_distinct_retained_copies(): void { $this->transfer( 'throw' ); }
 public function test_callback_false_preserves_all_pending_with_distinct_retained_copies(): void { $this->transfer( 'false' ); }
 public function test_successful_close_preserves_E_foreign_and_legacy_with_distinct_retained_copies(): void { $this->transfer( 'success' ); }
}
