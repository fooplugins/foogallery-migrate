<?php
/**
 * Internal per-operation sampled Zend-MM baseline, not an allocation envelope.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageMemoryBaseline {
	private $bytes = 0;
	private $receiver;

	public function __construct() { $this->sample(); }

	/**
	 * Internal pre-unslash projection, NOT validation or a memory bound. Only
	 * inspect the seven root slots; never traverse invalid arrays/objects, retain
	 * payloads, deduplicate references, or alter the original WP transformation.
	 * Aliased strings can shorten on successive visits, so these initial lengths
	 * are per-visit UPPER observations, not exact later duplicate sizes.
	 */
	public function observe_receiver( $request ) {
		$this->receiver = array( 'status' => 'UNRESOLVED_RECEIVER_GRAPH', 'whole_operation_new_upper' => null );
		if ( ! is_array( $request ) || 7 !== count( $request ) ) { return; }
		$lengths = array(); $keys = array();
		foreach ( $request as $key => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) { return; }
			$lengths[] = is_string( $value ) ? strlen( $value ) : null;
			// Zero denotes an integer key; a string key includes its terminator.
			if ( is_string( $key ) && PHP_INT_MAX === strlen( $key ) ) { return; }
			$keys[] = is_string( $key ) ? strlen( $key ) + 1 : 0;
		}
		$this->receiver = array( 'status' => 'SCALAR_RECEIVER_EVENTS_ONLY', 'leaf_visits' => 7,
			'string_lengths_upper' => $lengths, 'key_request_bytes' => $keys, 'whole_operation_new_upper' => null );
		// Include the retained projection in later baseline samples; this does NOT
		// measure/close the observer's own intervening VM/allocation/cleanup cost.
		$this->sample();
	}

	/** Internal evidence only; never put this projection in a public response. */
	public function receiver_events() { return $this->receiver; }

	/**
	 * Keep historical backing charged even if a later sample is smaller.
	 * This is sampled live real_size, NOT the process peak, RSS, a bound on
	 * intervening NEW allocations, or proof that any policy is sufficient.
	 */
	public function sample() {
		$this->bytes = max( $this->bytes, memory_get_usage( true ) );
		return $this->bytes;
	}
}
