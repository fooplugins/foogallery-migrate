<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

/** Offline source envelopes are not PHP5 runtime or arena measurements. */
class StorageScannerTraceCapacityTest extends TestCase {
	public function test_every_fail_edge_and_failing_child_are_enumerated() {
		foreach ( array( '5.4.0', '5.6.20' ) as $v ) {
			$m = new StorageScannerTraceCapacity( $v );
			$paths = $m->paths( 16 );
			$this->assertCount( 86, $paths );
			$max = $m->envelope( 60000, 16, 256 );
			$this->assertSame( 21, $max['max_frames'] );
			$this->assertSame( 20, $max['max_raw_argument_slots'] );
			$this->assertSame( 20 * 60024, $max['large_backing_nominal_upper'] );
			$this->assertSame( 20, $m->envelope( 60000, 16, 256, true )['depth_guard_frames'] );
			$this->assertSame( 68, $m->envelope( 60000, 64, 256, true )['depth_guard_frames'] );
			$this->assertFalse( $max['arena_retention_proven'] );
			$this->assertFalse( $max['complete_trace_capacity_proven'] );
			$this->assertNull( $max['caller_suffix_nominal_upper'] );
			$this->assertNull( $max['retained_arena_upper'] );
			$this->assertGreaterThan( $max['large_backing_nominal_upper'], $max['construction_nominal_upper'] );
			$this->assertGreaterThanOrEqual( $max['catch_nominal_upper'], $max['construction_nominal_upper'] );
		}
	}

	public function test_growth_and_alignment_equal_and_just_over_boundaries() {
		$m = new StorageScannerTraceCapacity( '5.4.0' );
		$this->assertSame( 32, $m->nominal( 16 ) );
		$this->assertSame( 40, $m->nominal( 17 ) );
		$this->assertSame( 60024, $m->nominal( 60001 ) );
		$this->assertSame( array( 'capacity' => 8, 'vector_nominal' => 80, 'old_vector_overlap' => 0 ), $m->hash_vector( 8 ) );
		$this->assertSame( array( 'capacity' => 16, 'vector_nominal' => 144, 'old_vector_overlap' => 80 ), $m->hash_vector( 9 ) );
		$this->assertSame( 272, $m->hash_vector( 17 )['vector_nominal'] );
		$this->assertSame( 144, $m->hash_vector( 17 )['old_vector_overlap'] );
		$this->assertSame( 0, $m->hash_vector( 0 )['vector_nominal'] );
		$a = $m->envelope( 60000, 16, 255 ); $b = $m->envelope( 60000, 16, 256 );
		$this->assertGreaterThan( $a['construction_nominal_upper'], $b['construction_nominal_upper'] );
	}

	public function test_separation_is_per_distinct_slot_not_alias_or_historical_visit() {
		$m = new StorageScannerTraceCapacity( '5.6.20' );
		$slots = array(
			array( 'slot' => 'inner:0', 'raw_bytes' => 60000, 'refcount' => 3, 'is_ref' => false, 'interned' => false ),
			array( 'slot' => 'outer:0', 'raw_bytes' => 60000, 'refcount' => 2, 'is_ref' => false, 'interned' => false ),
		);
		$r = $m->visit( array_merge( $slots, $slots ) );
		$this->assertSame( 2, $r['new_boxes'] );
		$this->assertSame( 120048, $r['backing_nominal'] );
		$slots[0]['is_ref'] = true; $slots[1]['refcount'] = 1;
		$this->assertSame( 0, $m->visit( $slots )['new_boxes'] );
		$slots[0]['is_ref'] = false; $slots[0]['interned'] = true;
		$this->assertSame( 1, $m->visit( $slots )['new_boxes'] );
		$this->assertSame( 0, $m->visit( $slots )['backing_nominal'] );
	}

	/** @dataProvider scanner_cases */
	public function test_modern_control_flow_trace_catch_unwind_and_reuse( $raw, $valid, $depth ) {
		$m = new StorageScannerTraceCapacity( '5.4.0' );
		$decoder = new LegacyOptionDecoder( 60000, $depth, 8003 );
		$caught = null;
		try { $value = $decoder->decode( $raw ); $this->assertTrue( $valid ); $this->assertIsArray( $value ); }
		catch ( \RuntimeException $e ) {
			$this->assertFalse( $valid ); $caught = $e;
			$local = array();
			foreach ( $e->getTrace() as $frame ) {
				if ( isset( $frame['class'] ) && $frame['class'] === LegacyOptionDecoder::class ) { $local[] = $frame['function']; }
			}
			$allowed = $m->paths( $depth, true );
			$this->assertContains( $local, $allowed );
			$this->assertSame( 'Unsupported or unbounded serialized option. Retain recorded authority and all storage evidence.', $caught->getMessage() );
		}
		$this->assertSame( array(), $decoder->decode( 'a:0:{}' ) );
		if ( null !== $caught ) { $this->assertNotEmpty( $caught->getTrace() ); }
	}
	public static function scanner_cases() {
		$nested = 'i:1;'; for ( $i = 0; $i < 16; ++$i ) { $nested = 'a:1:{i:0;' . $nested . '}'; }
		$deep = 'a:1:{i:0;' . $nested . '}';
		$dense = serialize( array( array_fill( 0, 4000, null ) ) );
		return array(
			'ordinary' => array( 'a:1:{i:0;s:1:"x";}', true, 16 ),
			'dense' => array( $dense, true, 16 ),
			'nested admitted' => array( $nested, true, 16 ),
			'failed child at boundary' => array( $deep, false, 16 ),
			'sibling' => array( 'a:2:{i:0;a:0:{}i:1;a:0:{}}', true, 16 ),
			'bad unsigned token' => array( 'a:x:{}', false, 16 ),
			'bad token terminator' => array( 'a:1:{i:0;i:1}', false, 16 ),
			'bad literal' => array( 'a:1:{i:0;N!}', false, 16 ),
			'trailing' => array( 'a:0:{}x', false, 16 ),
			'unknown type' => array( 'a:1:{i:0;R:1;}', false, 16 ),
			'rejected depth64' => array( str_repeat( 'a:1:{i:0;', 65 ) . 'i:1;' . str_repeat( '}', 65 ), false, 64 ),
		);
	}
	public function test_explicit_suffix_and_object_store_terms_are_not_zeroed() {
		$m = new StorageScannerTraceCapacity( '5.6.20' );
		$a = $m->envelope( 60000, 16, 256 );
		$b = $m->envelope( 60000, 16, 256, false, 1234, 128 );
		$this->assertSame( 1234, $b['caller_suffix_nominal_upper'] );
		$this->assertSame( 24608, $b['object_store_resize_nominal_upper'] );
		$this->assertSame( $a['construction_nominal_upper'] + 1234 + 24608, $b['parameterized_construction_nominal_upper'] );
		$this->assertFalse( $b['complete_trace_capacity_proven'] );
	}
	public function test_each_path_covers_the_exact_bound_method_argument_counts() {
		$m = new StorageScannerTraceCapacity( '5.4.0' );
		foreach ( $m->envelope( 60000, 16, 256 )['cases'] as $case ) {
			$argc = 0;
			foreach ( $case['methods_in_visitation_order'] as $method ) {
				$argc += ( new \ReflectionMethod( LegacyOptionDecoder::class, $method ) )->getNumberOfParameters();
			}
			$this->assertSame( $argc, $case['material']['argument_slots'] );
		}
	}
}
