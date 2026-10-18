<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageControl;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageProductCallerTraceV3.php';

/** Modern executable source-bound prerequisite, not executed production endpoints/PHP5. */
class StorageCurrentProductCallerTraceTest extends TestCase {
	public function test_exact_product_bindings_formal_arities_and_callsite_edges() {
		$m = new StorageProductCallerTraceV3( '5.4.0' );
		$root = dirname( __DIR__ );
		foreach ( $m->bindings()['product_files'] as $file => $sha ) { $this->assertSame( $sha, hash_file( 'sha256', $root . '/' . $file ) ); }
		$paths = $m->paths(); $this->assertCount( 18, $paths );
		foreach ( $paths as $path ) {
			foreach ( $path['frames'] as $frame ) {
				$r = new \ReflectionMethod( $frame['class'], $frame['method'] );
				$this->assertSame( count( $frame['arguments'] ), $r->getNumberOfParameters() );
				$this->assertFalse( $r->returnsReference() );
				foreach ( $r->getParameters() as $p ) { $this->assertFalse( $p->isPassedByReference() ); }
			}
			foreach ( $path['edges'] as $edge ) {
				$lines = file( $root . '/' . $edge['file'] );
				$this->assertStringContainsString( $edge['expression'], $lines[$edge['line'] - 1] );
			}
		}
	}

	public function test_shapes_are_existing_closed_product_arrays_not_deep_copies() {
		$m = new StorageProductCallerTraceV3( '5.6.20' ); $s = $m->shapes();
		$this->assertSame( array( 'blog_id', 'scope', 'target', 'confirmation', 'nonce' ), $s['request'] );
		$this->assertSame( array( 'physical', 'value' ), $s['source'] );
		$this->assertCount( 16, $s['record'] );
		$this->assertSame( 'retained_digest', $s['record'][15] );
		$this->assertGreaterThan( $m->shallow_array( $s['source'] ), $m->shallow_array( $s['record'] ) );
		$large = array( 'physical' => str_repeat( 'a', 64 ), 'value' => array( str_repeat( 'x', 60000 ), array_fill( 0, 4000, null ) ) );
		$this->assertSame( $m->shallow_array( $s['source'] ), $m->shallow_array( array_keys( $large ) ) );
		// Only the OUTER copied HT/buckets/vector are new. Graph reachability
		// extends through nested strings/arrays; its existing extent is NOT zero.
		$r = $m->envelope( 60000, 16, 1024 );
		$this->assertNull( $r['existing_product_argument_graph_nominal_upper'] );
		$this->assertNull( $r['external_receiver_trace_nominal_upper'] );
		$this->assertNull( $r['whole_operation_nominal_upper'] );
	}

	public function test_mutable_slots_not_zval_alias_or_repeated_visit_determine_separation() {
		$m = new StorageProductCallerTraceV3( '5.4.0' );
		$a = array( 'slot' => 'dispatch:1', 'identity' => 'request', 'kind' => 'request', 'refcount' => 4, 'is_ref' => false );
		$b = $a; $b['slot'] = 'switch_backend:0';
		$r = $m->visit( array( $a, $b, $a, $b ) );
		$this->assertSame( 2, $r['new_boxes'] ); $this->assertSame( 2, $r['new_shallow_arrays'] );
		$this->assertSame( 2 * $m->shallow_array( $m->shapes()['request'] ), $r['backing_nominal'] );
		$a['is_ref'] = true; $b['refcount'] = 1;
		$this->assertSame( 0, $m->visit( array( $a, $b ) )['backing_nominal'] );
	}

	public function test_joined_trace_vector_is_one_growth_sequence_not_two_rounded_vectors() {
		$m = new StorageProductCallerTraceV3( '5.6.20' ); $local = new StorageScannerTraceCapacityV2( '5.6.20' );
		foreach ( array( array( 8, 1 ), array( 16, 1 ), array( 32, 1 ), array( 7, 1 ), array( 21, 6 ) ) as $pair ) {
			list( $a, $b ) = $pair; $join = $m->join_vector( $a, $b ); $v = $local->hash_vector( $a + $b ); $old = $local->hash_vector( $a );
			$this->assertSame( $v['vector_nominal'] - $old['vector_nominal'], $join['catch_delta'] );
			$this->assertSame( $v['old_vector_overlap'] - $old['old_vector_overlap'], $join['resize_overlap_delta'] );
			$this->assertSame( $v['vector_nominal'] + $v['old_vector_overlap'], $join['construction_nominal'] );
		}
		$this->assertSame( 0, $m->join_vector( 21, 6 )['catch_delta'] );
		$this->assertSame( 64, $m->join_vector( 8, 1 )['catch_delta'] );
	}

	public function test_every_local_case_is_joined_with_every_finite_suffix_and_no_proof_flags() {
		foreach ( array( '5.4.0', '5.6.20' ) as $v ) {
			$m = new StorageProductCallerTraceV3( $v ); $local = new StorageScannerTraceCapacityV2( $v );
			$r = $m->envelope( 60000, 16, 1024 );
			$this->assertCount( ( count( $local->paths( 16 ) ) + count( StorageNativeScaleTrace::paths( 16 ) ) ) * 18, $r['cases'] );
			$this->assertSame( 'LP64_NTS_NONDEBUG_ALIGNMENT8_ZEND_MM', $r['abi'] );
			foreach ( $r['cases'] as $case ) {
				$this->assertSame( $case['local']['frames'] + count( $m->paths()[$case['path_id']]['frames'] ), $case['joined_frames'] );
				$this->assertSame( $case['local']['catch_nominal'] + $case['suffix_material_nominal'] + $case['vector']['catch_delta'], $case['catch_nominal'] );
				$this->assertSame( $case['catch_nominal'] + $local->hash_vector( $case['joined_frames'] )['old_vector_overlap'], $case['construction_nominal'] );
			}
			foreach ( array( 'arena_retention_proven', 'shared_gc_frontier_proven', 'complete_trace_capacity_proven', 'fits_justified_helper_budget', 'task_scope_completed', 'children_released' ) as $flag ) { $this->assertFalse( $r[$flag] ); }
			$this->assertNull( $r['retained_arena_upper'] );
			$this->assertNull( $r['object_store_resize_nominal_upper'] );
		}
	}

	public function test_recovery_advance_and_replacement_keep_distinct_source_identities() {
		$m = new StorageProductCallerTraceV3( '5.4.0' );
		foreach ( $m->paths() as $path ) {
			if ( 'replace' === $path['operation'] ) { $this->assertSame( 'direct', $path['phase'] ); }
			$this->assertSame( 'option_snapshot', $path['frames'][0]['method'] );
			$this->assertSame( 'decode', $path['edges'][0]['callee'] );
			$slots = $m->slots( $path );
			$this->assertSame( count( $slots ), count( array_unique( array_column( $slots, 'slot' ) ) ) );
			if ( 'direct' !== $path['phase'] ) {
				$this->assertSame( 'advance', $path['frames'][1]['method'] );
				$this->assertSame( 'source', $path['frames'][1]['arguments'][1] );
				$this->assertSame( 'source', $slots[1]['identity'] );
			}
			$this->assertSame( 'unresolved_external_receiver', $path['outer_boundary'] );
		}
	}

	public function test_conditional_file_extent_is_not_a_shipped_256_byte_cap() {
		$m = new StorageProductCallerTraceV3( '5.6.20' );
		$a = $m->envelope( 60000, 16, 255 ); $b = $m->envelope( 60000, 16, 4096 );
		$this->assertGreaterThan( $a['construction_nominal_upper'], $b['construction_nominal_upper'] );
		$this->assertFalse( $b['filename_extent_proven'] );
	}

	public function test_array_copy_uses_number_of_elements_not_historical_capacity() {
		$m = new StorageProductCallerTraceV3( '5.4.0' );
		$keys = $m->shapes()['record'];
		$this->assertSame( $m->shallow_array( $keys ), $m->shallow_array( array_slice( $keys, 0, 15 ) ) + $m->nominal( 72 + strlen( $keys[15] ) + 1 ) );
	}
}
