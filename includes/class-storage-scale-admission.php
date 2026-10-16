<?php
/**
 * Explicit storage-only finite domain. No provider, approval or request defaults.
 * Limits are immutable after construction; work counters belong to ONE operation.
 * This is refusal arithmetic, NOT a calibrated allocator or arbitrary-hook proof.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StorageScaleAdmission {
	private $limits;
	private $policy;
	private $deadline;
	private $clock;
	private $memory;
	private $work = 0;

	public static function validate( $limits ) {
		$ceilings = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
			'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
			'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
			'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 );
		if ( ! is_array( $limits ) || count( $limits ) !== count( $ceilings ) || array_diff_key( $limits, $ceilings ) ) { self::fail(); }
		$copy = array();
		foreach ( $ceilings as $key => $ceiling ) {
			if ( ! isset( $limits[$key] ) || ! is_int( $limits[$key] ) || $limits[$key] < 1 || $limits[$key] > $ceiling ) { self::fail(); }
			$copy[$key] = $limits[$key];
		}
		return $copy;
	}

	/** Clock/memory seams are trusted PHP dependencies, never request fields. */
	public function __construct( $limits, $policy, $deadline, $clock = null, $memory = null ) {
		$this->limits = self::validate( $limits );
		if ( is_wp_error( StoragePreflight::decode_budget( 0, '-1', 0, $policy ) ) || $policy['max_raw_bytes'] > 16777216 ||
			( null !== $clock && ! is_callable( $clock ) ) || ( null !== $memory && ! is_callable( $memory ) ) ) { self::fail(); }
		$this->policy = array();
		foreach ( $policy as $key => $value ) { $this->policy[$key] = $value; }
		$this->clock = $clock; $this->memory = $memory;
		$now = $this->now();
		if ( ! is_float( $deadline ) || ! is_finite( $deadline ) || $deadline <= $now || $deadline - $now > $this->limit( 'max_seconds' ) ) { self::fail(); }
		$this->deadline = $deadline;
		$this->admit_memory( 0 );
	}

	public function limit( $name ) { if ( ! array_key_exists( $name, $this->limits ) ) { self::fail(); } return $this->limits[$name]; }
	public function deadline() { return $this->deadline; }
	public function raw_bytes() { return $this->policy['max_raw_bytes']; }
	public function decoder( $bytes ) { return new LegacyOptionDecoder( $bytes, $this->limit( 'max_depth' ), $this->limit( 'max_tokens' ), $this ); }

	/** Fixed finite repeated-pass work cap, never reset by phases or pages. */
	public function tick() {
		if ( ++$this->work > 16777216 || $this->now() > $this->deadline ) { self::fail(); }
	}

	public function admit_memory( $bytes ) {
		$this->tick();
		if ( ! is_int( $bytes ) || $bytes < 0 || $bytes > $this->limit( 'max_physical_bytes' ) ) { self::fail(); }
		$sample = null === $this->memory ? array( ini_get( 'memory_limit' ), memory_get_usage( true ) ) : call_user_func( $this->memory );
		if ( ! is_array( $sample ) || array_keys( $sample ) !== array( 0, 1 ) ) { self::fail(); }
		// Both the effective PHP limit AND a finite ceiling apply, including -1.
		$policy = $this->policy; $policy['max_raw_bytes'] = $this->limit( 'max_physical_bytes' );
		foreach ( array( $sample[0], (string) $this->limit( 'memory_ceiling_bytes' ) ) as $limit ) {
			if ( is_wp_error( StoragePreflight::decode_budget( $bytes, $limit, $sample[1], $policy ) ) ) { self::fail(); }
		}
		return true;
	}

	private function now() {
		$now = null === $this->clock ? microtime( true ) : call_user_func( $this->clock );
		if ( ! is_float( $now ) || ! is_finite( $now ) ) { self::fail(); } return $now;
	}
	private static function fail() { throw new \RuntimeException( 'Finite storage scale admission refused; retain authority and all evidence.' ); }
}
