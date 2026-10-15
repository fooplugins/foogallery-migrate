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

	public function __construct() { $this->sample(); }

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
