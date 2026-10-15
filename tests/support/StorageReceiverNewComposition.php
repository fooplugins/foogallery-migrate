<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageProductArgumentRetention.php';

/**
 * OFFLINE selected pre-unslash NEW-copy ledger, conditional LP64/NTS only.
 * Not a graph census, policy, complete traversal/VM/arena bound or admission.
 * See docs/storage-receiver-new-composition-checkpoint.md.
 */
class StorageReceiverNewComposition {
	private $arguments;
	private $trace;
	private $local;
	public function __construct( $version ) {
		$this->arguments = new StorageProductArgumentRetention( $version );
		$this->trace = new StorageProductCallerTrace( $version );
		$this->local = new StorageScannerTraceCapacity( $version );
	}
	public function bindings() {
		return array( 'retained_helpers' => $this->arguments->bindings(),
			'stripslashes_sha256' => array( '5.4.0' => 'e14702dccbbf63d669303604f0255b3f28ef4523e782e55b1935a6e498191b9c',
				'5.6.20' => '471dfa81c9b58223dd16addca27a77018f18359a9889bf696f127442c5114daa' ),
			'stripslashes_sites' => array( '5.4.0' => 'ext/standard/string.c:3028-3065,3132-3142',
				'5.6.20' => 'ext/standard/string.c:3289-3326,3393-3404' ) );
	}
	private function add( $a, $b ) {
		if ( $b > PHP_INT_MAX - $a ) { throw new \InvalidArgumentException( 'receiver_composition_overflow' ); }
		return $a + $b;
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
