<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageScannerTraceWitnessV2.php';

/**
 * OFFLINE LP64/nondebug/alignment8 nominal material envelope, NOT retained
 * allocator capacity, a complete caller trace, native evidence or admission.
 * Source derivation and exclusions: docs/storage-scanner-trace-capacity.md.
 */
class StorageScannerTraceCapacityV2 {
	private $version;
	private $witness;
	private $argc = array( 'decode' => 1, 'scan' => 5, 'unsigned' => 3, 'token' => 4, 'literal' => 3, 'fail' => 0, 'tick' => 0 );

	public function __construct( $version ) {
		$this->witness = new StorageScannerTraceWitnessV2( $version );
		$this->version = $version;
	}
	private function integer( $value, $max ) {
		if ( ! is_int( $value ) || $value < 0 || $value > $max ) { throw new \InvalidArgumentException( 'scanner_trace_capacity_domain' ); }
	}
	public function nominal( $request ) {
		$this->integer( $request, 1073741824 );
		return max( 32, (int) ( ceil( ( $request + 16 ) / 8 ) * 8 ) );
	}
	/** A growing insertion-built HashTable, including old/new vector overlap. */
	public function hash_vector( $elements ) {
		$this->integer( $elements, 1000000 ); $c = 8;
		while ( $c < $elements ) { $c *= 2; }
		return array( 'capacity' => $c, 'vector_nominal' => $elements ? $this->nominal( 8 * $c ) : 0,
			'old_vector_overlap' => $elements > 8 ? $this->nominal( 8 * (int) ( $c / 2 ) ) : 0 );
	}
	/** Top-to-bottom visitation order; no sibling/historical invocation sum. */
	public function paths( $depth, $include_rejected_child = false ) {
		$this->integer( $depth, 64 );
		if ( ! is_bool( $include_rejected_child ) ) { throw new \InvalidArgumentException( 'scanner_trace_capacity_domain' ); }
		$out = array( array( 'fail', 'decode' ) );
		for ( $n = 1; $n <= $depth + 1; ++$n ) {
			foreach ( array( array(), array( 'token' ), array( 'unsigned' ), array( 'token', 'unsigned' ), array( 'literal' ) ) as $tail ) {
				$out[] = array_merge( array( 'fail' ), $tail, array_fill( 0, $n, 'scan' ), array( 'decode' ) );
			}
		}
		// scan checks depth BEFORE dispatch/unsigned/token: the rejecting child
		// cannot also have a token/literal frame. Include it only diagnostically.
		if ( $include_rejected_child ) { $out[] = array_merge( array( 'fail' ), array_fill( 0, $depth + 2, 'scan' ), array( 'decode' ) ); }
		return $out;
	}
	/** Distinct mutable slots, not distinct zval identities or visits. */
	public function visit( $slots ) {
		$seen = array(); $boxes = 0; $backing = 0;
		foreach ( $slots as $slot ) {
			if ( ! isset( $slot['slot'] ) || ! is_string( $slot['slot'] ) ) { throw new \InvalidArgumentException( 'scanner_trace_capacity_slot' ); }
			if ( isset( $seen[$slot['slot']] ) ) { continue; }
			$seen[$slot['slot']] = true;
			$r = $this->witness->argument( $slot['raw_bytes'], $slot['refcount'], $slot['is_ref'], $slot['interned'] );
			$boxes += $r['new_box'] ? 1 : 0; $backing += $r['nominal_block_bytes'];
		}
		return array( 'new_boxes' => $boxes, 'backing_nominal' => $backing );
	}
	private function array_shell( $elements ) {
		$v = $this->hash_vector( $elements );
		// allocated zval_gc_info32, HashTable72; vector separately allocated.
		return $this->nominal( 32 ) + $this->nominal( 72 ) + $v['vector_nominal'];
	}
	public function path_material( $path, $raw, $file ) {
		$frames = count( $path ); $raw_slots = 0; $arguments = 0;
		// Trace array has numeric buckets, whose zval values are frame shells.
		$material = $this->array_shell( $frames ) + $frames * $this->nominal( 72 );
		$overlap = $this->hash_vector( $frames )['old_vector_overlap'];
		foreach ( $path as $identity ) {
			$parts = explode( '::', $identity );
			$class = count( $parts ) === 2 ? $parts[0] : 'LegacyOptionDecoder';
			$method = end( $parts );
			if ( 'LegacyOptionDecoder' === $class && isset( $this->argc[$method] ) ) { $argc = $this->argc[$method]; }
			elseif ( 'StorageScaleAdmission' === $class && in_array( $method, array( 'tick', 'fail', 'admit_memory', 'checked_add' ), true ) ) {
				$argc = 'admit_memory' === $method ? 1 : ( 'checked_add' === $method ? 2 : 0 );
			} else { throw new \InvalidArgumentException( 'scanner_trace_capacity_domain' ); }
			$arguments += $argc;
			$is_raw = 'LegacyOptionDecoder' === $class && in_array( $method, array( 'decode', 'scan', 'unsigned', 'token', 'literal' ), true ); $raw_slots += $is_raw ? 1 : 0;
			// Six fields, object omitted by options=0: file,line,function,class,
			// type,args. args has its own zval/HashTable. Grant all fields even
			// when backtrace skips one. Constant keys need not be interned.
			$material += $this->array_shell( 6 );
			foreach ( array( 'file', 'line', 'function', 'class', 'type', 'args' ) as $key ) {
				$material += $this->nominal( 72 + strlen( $key ) + 1 );
			}
			$material += 5 * $this->nominal( 32 );
			$material += $this->nominal( $file + 1 ) + $this->nominal( strlen( $method ) + 1 ) +
				$this->nominal( strlen( 'FooPlugins\\FooGalleryMigrate\\' . $class ) + 1 ) + $this->nominal( 3 );
			$material += $this->array_shell( $argc ) + $argc * $this->nominal( 72 );
			// Upper grant: one GC box per argument, even existing refs and sole
			// owners. Nonraw scanner args are integers/bools or literal <=2 bytes.
			$material += $argc * $this->nominal( 32 ) + ( $argc - ( $is_raw ? 1 : 0 ) ) * $this->nominal( 3 );
		}
		$large = $raw_slots * $this->nominal( $raw + 1 );
		// Standard RuntimeException: object32, seven pointer properties56;
		// grant every property its own GC box (shared defaults need none), one
		// object box and one catch box; file/message backing. Trace's box is
		// already included, so this deliberately double-grants that property.
		$message = isset( $path[0] ) && 'StorageScaleAdmission::fail' === $path[0] ?
			'Finite storage scale admission refused; retain authority and all evidence.' :
			'Unsupported or unbounded serialized option. Retain recorded authority and all storage evidence.';
		$exception = $this->nominal( 32 ) + $this->nominal( 7 * 8 ) + 9 * $this->nominal( 32 ) +
			$this->nominal( $file + 1 ) + $this->nominal( strlen( $message ) + 1 );
		return array( 'frames' => $frames, 'raw_argument_slots' => $raw_slots, 'argument_slots' => $arguments,
			'large_backing_nominal' => $large, 'trace_containers_strings_and_boxes_nominal' => $material,
			'exception_nominal' => $exception, 'vector_resize_overlap_nominal' => $overlap,
			'catch_nominal' => $large + $material + $exception,
			'construction_nominal' => $large + $material + $exception + $overlap );
	}
	/**
	 * Optional suffix must include its frames/args AND the shared trace-vector
	 * capacity/growth delta (rounding at the local/suffix join is not additive);
	 * object-store capacity is a separate shared-runtime frontier parameter.
	 * Null means unresolved, NEVER zero. No policy consumes these results.
	 */
	public function envelope( $raw, $depth, $source_file_bytes, $rejected = false, $caller_suffix_nominal = null, $object_store_old_capacity = null ) {
		$this->integer( $raw, 60000 ); $this->integer( $depth, 64 ); $this->integer( $source_file_bytes, 1048576 );
		if ( null !== $caller_suffix_nominal ) { $this->integer( $caller_suffix_nominal, 1073741824 ); }
		if ( null !== $object_store_old_capacity ) { $this->integer( $object_store_old_capacity, 1000000 ); }
		$max = array( 'frames' => 0, 'raw_argument_slots' => 0, 'large_backing_nominal' => 0, 'catch_nominal' => 0, 'construction_nominal' => 0 );
		$cases = array();
		foreach ( $this->paths( $depth, $rejected ) as $path ) {
			$r = $this->path_material( $path, $raw, $source_file_bytes );
			foreach ( $max as $k => $unused ) { $max[$k] = max( $max[$k], $r[$k] ); }
			$cases[] = array( 'methods_in_visitation_order' => $path, 'material' => $r );
		}
		// store_put doubles the shared vector. A conservative parameterized
		// old+new overlap includes its pre-existing half; bucket LP64 size64.
		$store = null === $object_store_old_capacity ? null :
			$this->nominal( 64 * $object_store_old_capacity ) + $this->nominal( 128 * $object_store_old_capacity );
		return array( 'status' => 'LOCAL_NOMINAL_MATERIAL_PREREQUISITE_ONLY', 'source_version' => $this->version,
			'bindings' => $this->witness->bindings(),
			'material_source_bindings_manifest_sha256' => '2b304920b0de417aa34d8263629a24bec279052919b0fabb98da207686fa826a',
			'domain' => array( 'raw_bytes_upper' => $raw, 'depth_upper' => $depth,
				'source_file_bytes_upper' => $source_file_bytes, 'nodes_upper' => 8003, 'arrays_upper' => 16,
				'depth_rejection_diagnostic_included' => $rejected, 'ordinary_domain' => $depth <= 16 && ! $rejected, 'decode_scanner_only' => true ),
			'max_frames' => $max['frames'], 'max_raw_argument_slots' => $max['raw_argument_slots'],
			'large_backing_nominal_upper' => $max['large_backing_nominal'], 'catch_nominal_upper' => $max['catch_nominal'],
			'construction_nominal_upper' => $max['construction_nominal'], 'depth_guard_frames' => $rejected ? $depth + 4 : null,
			'caller_suffix_nominal_upper' => $caller_suffix_nominal, 'object_store_resize_nominal_upper' => $store,
			'parameterized_construction_nominal_upper' => null === $caller_suffix_nominal || null === $store ? null : $max['construction_nominal'] + $caller_suffix_nominal + $store,
			'cases' => $cases, 'retained_arena_upper' => null, 'arena_retention_proven' => false,
			'shared_gc_frontier_proven' => false,
			'complete_trace_capacity_proven' => false, 'fits_justified_helper_budget' => false,
			'retention_obligations' => array( 'large_block_split_and_free_adjacency', 'cache_and_rest_frontiers',
				'PHP54_argument_transfer_extra_page', 'PHP56_reservation_R_not_retained_C', 'caller_suffix', 'object_store_frontier', 'shared_gc_frontier' ) );
	}
}
