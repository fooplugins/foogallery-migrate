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
	private $initialized = false;
	private $snapshots = array();
	private $snapshot_rows = 0;
	private $snapshot_bytes = 0;
	private $snapshot_tokens = array();
	private $tokens = 1; // Complete logical snapshot's outer array token.
	private $snapshot_blog;
	private $snapshot_write = false;

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
		if ( $this->initialized ) { self::fail(); }
		$this->limits = self::validate( $limits );
		if ( is_wp_error( StoragePreflight::decode_budget( 0, '-1', 0, $policy ) ) || $policy['max_raw_bytes'] > 16777216 ||
			( null !== $clock && ! is_callable( $clock ) ) || ( null !== $memory && ! is_callable( $memory ) ) ) { self::fail(); }
		$this->policy = array();
		foreach ( $policy as $key => $value ) { $this->policy[$key] = $value; }
		$this->clock = $clock; $this->memory = $memory;
		$now = $this->now();
		if ( ! is_float( $deadline ) || ! is_finite( $deadline ) || $deadline <= $now || $deadline - $now > $this->limits['max_seconds'] ) { self::fail(); }
		$this->deadline = $deadline;
		$this->initialized = true;
		$this->admit_memory( 0 );
	}

	// A constructor-validated, operation-local domain, never a serialized policy object.
	public function __sleep() { self::fail(); }
	public function __wakeup() { self::fail(); }
	public function __clone() { self::fail(); }
	private function valid() { if ( ! $this->initialized ) { self::fail(); } }
	public function limit( $name ) { $this->valid(); if ( ! array_key_exists( $name, $this->limits ) ) { self::fail(); } return $this->limits[$name]; }
	public function deadline() { $this->valid(); return $this->deadline; }
	public function raw_bytes() { $this->valid(); return $this->policy['max_raw_bytes']; }
	public function decoder( $bytes ) { return new LegacyOptionDecoder( $bytes, $this->limit( 'max_depth' ), $this->limit( 'max_tokens' ), $this ); }

	public function bind_snapshot_blog( $blog_id ) {
		$this->tick();
		if ( ! is_int( $blog_id ) || $blog_id < 1 || ( null !== $this->snapshot_blog && $this->snapshot_blog !== $blog_id ) ) { self::fail(); }
		$this->snapshot_blog = $blog_id;
	}

	/** One write attempt; readback may recheck it but cannot stage another generation. */
	public function begin_snapshot_write() {
		$this->tick();
		if ( null === $this->snapshot_blog || $this->snapshot_write ) { self::fail(); }
		$this->snapshot_write = true;
	}

	/** Affected snapshot footprint, NOT an all-generation physical census.
	 * Charge each head exactly once across preflight/stage/readback. Rechecking
	 * the SAME immutable footprint is allowed; neither phases nor readers reset
	 * the aggregate allowance. A new logical operation needs a new domain.
	 */
	public function admit_snapshot( $hash, $rows, $bytes ) {
		$this->tick();
		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ||
			! is_int( $rows ) || $rows < 0 || ! is_int( $bytes ) || $bytes < 1 ) { self::fail(); }
		if ( isset( $this->snapshots[$hash] ) ) {
			if ( $this->snapshots[$hash] !== array( $rows, $bytes ) ) { self::fail(); }
			return;
		}
		$heads = count( $this->snapshots ) + 1;
		if ( $heads > $this->limit( 'max_heads' ) || $heads > $this->limit( 'max_physical_rows_per_table' ) ||
			$rows > $this->limit( 'max_rows' ) - $this->snapshot_rows ||
			$rows > $this->limit( 'max_physical_rows_per_table' ) - $this->snapshot_rows ||
			$heads > $this->limit( 'max_physical_rows' ) - $this->snapshot_rows ||
			$rows > $this->limit( 'max_physical_rows' ) - $this->snapshot_rows - $heads ||
			$bytes > $this->limit( 'max_bytes' ) - $this->snapshot_bytes ||
			$bytes > $this->limit( 'max_physical_bytes' ) - $this->snapshot_bytes ) { self::fail(); }
		$this->snapshots[$hash] = array( $rows, $bytes );
		$this->snapshot_rows += $rows; $this->snapshot_bytes += $bytes;
		$this->admit_memory( $this->snapshot_bytes );
	}

	/** Count logical values AND their state keys once, not once per digest pass. */
	public function admit_snapshot_tokens( $hash, $tokens ) {
		$this->tick();
		if ( ! is_string( $hash ) || ! isset( $this->snapshots[$hash] ) || ! is_int( $tokens ) || $tokens < 1 ) { self::fail(); }
		if ( isset( $this->snapshot_tokens[$hash] ) ) {
			if ( $this->snapshot_tokens[$hash] !== $tokens ) { self::fail(); } return;
		}
		if ( $tokens >= $this->limit( 'max_tokens' ) - $this->tokens ) { self::fail(); }
		$this->snapshot_tokens[$hash] = $tokens; $this->tokens += $tokens + 1;
	}

	/** Fixed finite repeated-pass work cap, never reset by phases or pages. */
	public function tick() {
		$this->valid();
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
