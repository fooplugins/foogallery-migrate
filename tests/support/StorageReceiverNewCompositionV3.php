<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageProductArgumentRetentionV3.php';

/**
 * Current v3; original witness retained byte-for-byte.
 * OFFLINE selected pre-unslash NEW-copy ledger, conditional LP64/NTS only.
 * Not a graph census, policy, complete traversal/VM/arena bound or admission.
 * See docs/storage-receiver-new-composition-checkpoint.md.
 */
class StorageReceiverNewCompositionV3 {
	private $arguments;
	private $trace;
	private $local;
	public function __construct( $version ) {
		$this->arguments = new StorageProductArgumentRetentionV3( $version );
		$this->trace = new StorageProductCallerTraceV3( $version );
		$this->local = new StorageScannerTraceCapacityV2( $version );
	}
	public function bindings() {
		return array( 'retained_helpers' => $this->arguments->bindings(),
			'receiver_observer_sha256' => '3c9f49d5d9bccf2c47eee9fda84e920d76847f2b9e41266b30957804dae4eae9',
			'stripslashes_sha256' => array( '5.4.0' => 'e14702dccbbf63d669303604f0255b3f28ef4523e782e55b1935a6e498191b9c',
				'5.6.20' => '471dfa81c9b58223dd16addca27a77018f18359a9889bf696f127442c5114daa' ),
			'stripslashes_sites' => array( '5.4.0' => 'ext/standard/string.c:3028-3065,3132-3142',
				'5.6.20' => 'ext/standard/string.c:3289-3326,3393-3404' ) );
	}
	private function add( $a, $b ) {
		if ( $b > PHP_INT_MAX - $a ) { throw new \InvalidArgumentException( 'receiver_composition_overflow' ); }
		return $a + $b;
	}
	/**
	 * Consume the ACTUAL receiver's internal projection without retaining input
	 * values. Conditional PHP5 nominal copy families reuse the existing helpers;
	 * semantic VM sites below are counts, NOT certified box/stack/arena bytes.
	 */
	public function receiver_events( $observed ) {
		if ( ! is_array( $observed ) || ! isset( $observed['status'], $observed['leaf_visits'], $observed['string_lengths_upper'], $observed['key_request_bytes'] ) ||
			'SCALAR_RECEIVER_EVENTS_ONLY' !== $observed['status'] || 7 !== $observed['leaf_visits'] ||
			! is_array( $observed['string_lengths_upper'] ) || ! is_array( $observed['key_request_bytes'] ) ||
			7 !== count( $observed['string_lengths_upper'] ) || 7 !== count( $observed['key_request_bytes'] ) ) {
			throw new \InvalidArgumentException( 'receiver_scalar_events_unresolved' );
		}
		$strings = 0; $copies = 0;
		foreach ( $observed['string_lengths_upper'] as $length ) {
			if ( null === $length ) { continue; }
			if ( ! is_int( $length ) || $length < 0 || $length > PHP_INT_MAX - 1 ) { throw new \InvalidArgumentException( 'receiver_scalar_length' ); }
			++$strings; $copies = $this->add( $copies, $this->trace->nominal( $length + 1 ) );
		}
		$v = $this->local->hash_vector( 7 );
		$root = $this->add( $this->add( $this->trace->nominal( 32 ), $this->trace->nominal( 72 ) ), $v['vector_nominal'] );
		foreach ( $observed['key_request_bytes'] as $bytes ) {
			if ( ! is_int( $bytes ) || $bytes < 0 || $bytes > PHP_INT_MAX - 72 ) { throw new \InvalidArgumentException( 'receiver_scalar_key' ); }
			$root = $this->add( $root, $this->trace->nominal( 72 + $bytes ) );
		}
		return array( 'status' => 'ACTUAL_SCALAR_RECEIVER_SELECTED_NEW_EVENTS_ONLY',
			'events' => array( 'wp_unslash_calls' => 1, 'stripslashes_deep_calls' => 1, 'map_deep_calls' => 8,
				'callback_calls' => 7, 'string_callback_calls' => 7, 'stripslashes_calls' => $strings,
				'wp_unslash_return_sites' => 1, 'stripslashes_deep_return_sites' => 1, 'callback_result_sites' => 7,
				'foreach_resets' => 1, 'foreach_fetch_attempts' => 8, 'root_assignments' => 7,
				'map_deep_return_sites' => 8, 'string_callback_return_sites' => 7,
				'root_cow_copy_selected_upper' => 1, 'reference_visits_deduplicated' => false ),
			'input_duplicate_nominal_upper' => $copies, 'selected_root_copy_nominal' => $root,
			'selected_copy_family_nominal_sum' => $this->add( $copies, $root ),
			'original_backing_extent' => null, 'originals_retained_without_free_credit' => true,
			'vm_allocation_bytes' => null, 'observer_allocation_bytes' => null, 'failure_cleanup_overlap_bytes' => null,
			'rejected_graph_upper' => null, 'whole_operation_new_upper' => null, 'safe_admission_approved' => false );
	}
	/** INPUT duplicate survives shortening: do not charge output strlen instead. */
	public function string_copy( $input, $normalized ) {
		if ( ! is_string( $input ) || ! is_string( $normalized ) || stripslashes( $input ) !== $normalized ) {
			throw new \InvalidArgumentException( 'receiver_transform_observation' );
		}
		$request = strlen( $input ) + 1;
		return array( 'input_bytes' => strlen( $input ), 'output_bytes' => strlen( $normalized ),
			'input_copy_request_from_output_upper' => 2 * strlen( $normalized ) + 2,
			'duplicate_request_bytes' => $request, 'duplicate_nominal' => $this->trace->nominal( $request ),
			'original_backing_extent' => null, 'original_freed' => false );
	}
	/** One possible NEW COW shell; existing children's storage is only addref'd. */
	public function shallow_copy( $keys ) {
		$v = $this->local->hash_vector( count( $keys ) );
		$bytes = $this->trace->nominal( 32 ) + $this->trace->nominal( 72 ) + $v['vector_nominal'];
		foreach ( $keys as $key ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) { throw new \InvalidArgumentException( 'receiver_copy_keys' ); }
			$bytes = $this->add( $bytes, $this->trace->nominal( 72 + ( is_string( $key ) ? strlen( $key ) + 1 : 0 ) ) );
		}
		return array( 'copy_nominal' => $bytes, 'child_addrefs' => count( $keys ),
			'new_vector_capacity' => $v['capacity'], 'original_capacity' => null, 'deep_child_copy' => false );
	}
	/**
	 * Caller supplies visits, NOT inferred root capacities. Sum all selected
	 * copies conservatively across siblings; this does not prove simultaneous
	 * peak, completeness, hidden boxing, VM packing or retained allocator pages.
	 */
	public function compose( $string_pairs, $array_key_visits ) {
		$total = 0;
		foreach ( $string_pairs as $pair ) { $total = $this->add( $total, $this->string_copy( $pair[0], $pair[1] )['duplicate_nominal'] ); }
		foreach ( $array_key_visits as $keys ) { $total = $this->add( $total, $this->shallow_copy( $keys )['copy_nominal'] ); }
		return array( 'status' => 'SELECTED_RECEIVER_NEW_COPY_FAMILIES_ONLY',
			'string_visits' => count( $string_pairs ), 'array_visits' => count( $array_key_visits ),
			'selected_copy_family_nominal_sum' => $total, 'originals_retained_without_free_credit' => true,
			'callback_vm_bytes' => null, 'failure_cleanup_overlap_bytes' => null,
			'whole_operation_new_upper' => null, 'retained_arena_upper' => null, 'rejected_graph_upper' => null,
			'complete_traversal_proven' => false, 'safe_admission_approved' => false );
	}
}
