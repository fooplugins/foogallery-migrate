<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageProductCallerTraceV3.php';

/** Current v3; original witness retained byte-for-byte. OFFLINE conditional reachable material; never admission or arena capacity. */
class StorageProductArgumentRetentionV3 {
	private $trace;
	private $local;
	public function __construct( $version ) {
		$this->trace = new StorageProductCallerTraceV3( $version );
		$this->local = new StorageScannerTraceCapacityV2( $version );
		StorageProductCallerTraceV3::assert_bindings( $this->bindings(), dirname( __DIR__, 2 ) );
	}
	private function fail() { throw new \InvalidArgumentException( 'product_argument_domain' ); }
	private function add( $a, $b ) {
		if ( $b > PHP_INT_MAX - $a ) { $this->fail(); }
		return $a + $b;
	}
	private function capacity( $upper ) {
		// Inherited executable arithmetic domain, NOT a product capacity cap.
		if ( ! is_int( $upper ) || $upper < 8 || $upper > 1000000 || ( $upper & ( $upper - 1 ) ) ) { $this->fail(); }
		return $this->local->hash_vector( $upper )['vector_nominal'];
	}
	/**
	 * Tree-expanded UPPER: aliases/equal values are never collapsed. Caller must
	 * establish capacity_upper for EVERY existing array, including empty/sparse
	 * ones; a modern value's element count cannot observe its PHP5 HT capacity.
	 */
	public function extent( $value, $capacity_upper, $string_extra_upper = 0 ) {
		$vector = $this->capacity( $capacity_upper );
		if ( ! is_int( $string_extra_upper ) || $string_extra_upper < 0 || $string_extra_upper > 1073741824 ) { $this->fail(); }
		return $this->walk( $value, $capacity_upper, $vector, $string_extra_upper, 0 );
	}
	private function walk( $value, $capacity, $vector, $string_extra, $depth ) {
		// Product codec permits logical depth64 per record; snapshot/value add
		// two outer levels. Cycles/deeper arbitrary graphs are outside this slice.
		if ( $depth > 66 ) { $this->fail(); }
		$n = $this->trace->nominal( 32 );
		if ( is_string( $value ) ) { return $this->add( $n, $this->trace->nominal( $this->add( strlen( $value ) + 1, $string_extra ) ) ); }
		if ( is_array( $value ) ) {
			if ( count( $value ) > $capacity ) { $this->fail(); }
			$n = $this->add( $n, $this->add( $this->trace->nominal( 72 ), $vector ) );
			foreach ( $value as $key => $child ) {
				$n = $this->add( $n, $this->trace->nominal( 72 + ( is_string( $key ) ? strlen( $key ) + 1 : 0 ) ) );
				$n = $this->add( $n, $this->walk( $child, $capacity, $vector, $string_extra, $depth + 1 ) );
			}
			return $n;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) { return $n; }
		$this->fail(); // No tiny object/resource grant; receiver objects excluded.
	}
	private function shape( $value, $keys ) {
		if ( ! is_array( $value ) ) { $this->fail(); }
		$a = array_keys( $value ); sort( $a ); sort( $keys );
		if ( $a !== $keys ) { $this->fail(); }
	}
	private function path( $id ) {
		$paths = $this->trace->paths();
		if ( ! is_int( $id ) || ! isset( $paths[$id] ) ) { $this->fail(); }
		return $paths[$id];
	}
	public function bindings() {
		$b = $this->trace->bindings();
		$b['product_files']['includes/class-legacy-state-codec.php'] = 'ff82db7eda0be9999675b94c1e7561ab009579b4f57540d2b6a47a0df331b474';
		return $b;
	}
	/** One unchanged request family across frames; record/source only in advance. */
	public function retention( $path_id, $arguments, $capacities ) {
		$path = $this->path( $path_id ); $shapes = $this->trace->shapes();
		if ( ! is_array( $arguments ) || ! is_array( $capacities ) || ! isset( $arguments['request'] ) ) { $this->fail(); }
		$this->shape( $arguments['request'], $shapes['request'] );
		foreach ( $arguments['request'] as $value ) { if ( is_array( $value ) ) { $this->fail(); } }
		$values = array( 'request' => $arguments['request'], 'operation' => $path['operation'] );
		if ( 'recover' !== $path['operation'] ) { $values['replacement'] = 'replace' === $path['operation']; }
		if ( 'direct' !== $path['phase'] ) {
			if ( ! isset( $arguments['record'] ) || ! is_array( $arguments['record'] ) || ! isset( $arguments['record']['version'] ) ||
				! in_array( $arguments['record']['version'], array( 1, 2 ), true ) || ! array_key_exists( 'source', $arguments ) ) { $this->fail(); }
			$keys = 1 === $arguments['record']['version'] ? array_slice( $shapes['record'], 0, 15 ) : $shapes['record'];
			$this->shape( $arguments['record'], $keys );
			foreach ( $arguments['record'] as $value ) { if ( is_array( $value ) ) { $this->fail(); } }
			if ( null === $arguments['source'] ) { if ( ! $path['source_may_be_null'] ) { $this->fail(); } }
			else {
				$this->shape( $arguments['source'], $shapes['source'] );
				if ( ! is_string( $arguments['source']['physical'] ) || ! is_array( $arguments['source']['value'] ) ) { $this->fail(); }
			}
			$values['record'] = $arguments['record']; $values['source'] = $arguments['source'];
		}
		$roots = array(); $total = 0;
		foreach ( $values as $kind => $value ) {
			if ( is_array( $value ) ) {
				if ( ! isset( $capacities[$kind] ) ) { $this->fail(); }
				$this->shape( $capacities[$kind], array( 'array_capacity_upper', 'string_extra_upper' ) );
				$roots[$kind] = $this->extent( $value, $capacities[$kind]['array_capacity_upper'], $capacities[$kind]['string_extra_upper'] );
			} else { $roots[$kind] = $this->extent( $value, 8 ); }
			$total = $this->add( $total, $roots[$kind] );
		}
		// Trace copies preserve children through addrefs. Keeping original outer
		// shells too is conservative even when unwind destroys them. No array
		// identity claim is needed for nested aliases: tree expansion overgrants.
		return array( 'roots' => $roots, 'captured_families' => array_keys( $values ),
			'existing_argument_graph_nominal_upper' => $total, 'catch_reachable_graph_nominal_upper' => $total,
			'original_outer_shells_conservatively_retained' => true, 'nested_aliases_tree_expanded' => true,
			'backing_upper_contracts' => $capacities, 'capacity_upper_is_external_contract' => true,
			'string_backing_extent_proven' => false, 'deallocation_or_arena_release_proven' => false );
	}
	/** ONE seam: add existing graph once to reviewed local+suffix trace material. */
	public function compose( $path_id, $raw, $depth, $file_bytes, $arguments, $capacities, $rejected = false ) {
		$r = $this->retention( $path_id, $arguments, $capacities );
		$t = $this->trace->envelope( $raw, $depth, $file_bytes, $rejected ); $cases = array(); $catch = 0; $construction = 0;
		foreach ( $t['cases'] as $case ) {
			if ( $case['path_id'] !== $path_id ) { continue; }
			$c = $this->add( $case['catch_nominal'], $r['existing_argument_graph_nominal_upper'] );
			$b = $this->add( $case['construction_nominal'], $r['existing_argument_graph_nominal_upper'] );
			$cases[] = array( 'trace' => $case, 'catch_argument_and_trace_nominal_upper' => $c, 'construction_argument_and_trace_nominal_upper' => $b );
			$catch = max( $catch, $c ); $construction = max( $construction, $b );
		}
		return array( 'status' => 'CONDITIONAL_PRODUCT_ARGUMENT_REACHABLE_MATERIAL_PREREQUISITE_ONLY',
			'abi' => $t['abi'], 'bindings' => $this->bindings(), 'domain' => $t['domain'], 'path_id' => $path_id,
			'filename_extent_proven' => false, 'historical_array_capacity_proven' => false,
			'retention' => $r, 'cases' => $cases, 'catch_argument_and_trace_nominal_upper' => $catch,
			'construction_argument_and_trace_nominal_upper' => $construction,
			'whole_operation_nominal_upper' => null, 'retained_arena_upper' => null,
			'external_receiver_trace_nominal_upper' => null, 'object_store_resize_nominal_upper' => null,
			'arena_retention_proven' => false, 'shared_gc_frontier_proven' => false, 'complete_trace_capacity_proven' => false,
			'fits_justified_helper_budget' => false, 'task_scope_completed' => false, 'children_released' => false );
	}
}
