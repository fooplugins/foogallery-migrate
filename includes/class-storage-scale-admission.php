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
	private $baseline;
	private $native_high_water = 0;
	private $work = 0;
	private $additional_bytes = 0;
	private $retaining_phases = array();
	private $retaining_pending = 0;
	private $retaining_serial = 0;
	private $initialized = false;
	private $snapshots = array();
	private $snapshot_rows = 0;
	private $snapshot_bytes = 0;
	private $snapshot_tokens = array();
	private $tokens = 1; // Complete logical snapshot's outer array token.
	private $snapshot_blog;
	private $snapshot_write = false;
	private $authenticated_snapshot_read;

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
	public function __construct( $limits, $policy, $deadline, $clock = null, $memory = null, $baseline = null, $capacity = null ) {
		if ( $this->initialized ) { self::fail(); }
		$this->limits = self::validate( $limits );
		if ( is_wp_error( StoragePreflight::decode_budget( 0, '-1', 0, $policy ) ) || $policy['max_raw_bytes'] > 16777216 ||
			( null !== $clock && ! is_callable( $clock ) ) || ( null !== $memory && ! is_callable( $memory ) ) ) { self::fail(); }
		if ( null !== $baseline && ! $baseline instanceof StorageMemoryBaseline ) { self::fail(); }
		$this->baseline = $baseline;
		if ( null !== $capacity && ! $capacity instanceof StorageCapacityBudget ) { self::fail(); }
		$this->capacity = $capacity;
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
	private $capacity;
	public function capacity() { $this->valid(); return $this->capacity; }
	/** Experimental native flatten only: no allocating dependency hooks or widened ceiling. */
	public function supports_repository_flatten() {
		$this->valid();
		return null === $this->capacity && get_class( $this ) === __CLASS__ && null === $this->clock && null === $this->memory &&
			$this->policy['reserve_bytes'] >= 33554432 && $this->limit( 'memory_ceiling_bytes' ) <= 134217728;
	}
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
		if ( null !== $this->capacity ) { $this->capacity->tick(); }
		if ( ++$this->work > 16777216 ) { StorageScalarObserver::milestone( 'scale-work-refused' ); StorageScalarObserver::refusal( 'scale-work', 'scale-tick' ); self::fail(); }
		if ( $this->now() > $this->deadline ) { StorageScalarObserver::milestone( 'scale-deadline-refused' ); StorageScalarObserver::refusal( 'scale-deadline', 'scale-tick' ); self::fail(); }
	}

	public function admit_memory( $bytes ) {
		$this->tick();
		if ( ! is_int( $bytes ) || $bytes < 0 ) { self::fail(); }
		// New phase forecasts REPLACE, never stack with, legacy amplification.
		// Scratch (including the full record workspace) is not persisted bytes.
		// Keep that complete reservation in the capacity memory check; physical
		// snapshot/census bounds remain independently enforced by their owners.
		if ( null !== $this->capacity ) { $this->capacity->checkpoint( $bytes ); return true; }
		if ( $bytes > $this->limit( 'max_physical_bytes' ) ) { self::fail(); }
		$sample = $this->memory_sample();
		if ( ! is_array( $sample ) || array_keys( $sample ) !== array( 0, 1 ) ) { self::fail(); }
		// Both the effective PHP limit AND a finite ceiling apply, including -1.
		$policy = $this->policy; $policy['max_raw_bytes'] = $this->limit( 'max_physical_bytes' );
		// Pending is NEVER credited against a later sample, including nested raw,
		// snapshot, rebuild and per-row checks. Preserve their original multiplier.
		$used = self::checked_add( $sample[1], self::checked_add( $this->additional_bytes, $this->retaining_pending ) );
		if ( null !== $this->authenticated_snapshot_read ) {
			$used = self::checked_add( max( $sample[1], memory_get_usage( true ), $this->native_high_water ), self::checked_add( $this->additional_bytes, $this->retaining_pending ) );
			$used = self::checked_add( $used, $this->authenticated_snapshot_read->workspace( 0 ) );
		}
		StorageScalarObserver::acquisition_guard( $bytes, self::checked_add( $this->additional_bytes, $this->retaining_pending ), $sample[1], $this->native_high_water, $policy['reserve_bytes'], $policy['amplification'], $this->limit( 'memory_ceiling_bytes' ) );
		foreach ( array( $sample[0], (string) $this->limit( 'memory_ceiling_bytes' ) ) as $limit ) {
			if ( is_wp_error( StoragePreflight::decode_budget( $bytes, $limit, $used, $policy ) ) ) { StorageScalarObserver::milestone( 'scale-memory-refused' ); StorageScalarObserver::refusal( 'scale-memory', 'scale-memory' ); self::fail(); }
		}
		return true;
	}

	/**
	 * Low-level arithmetic ONLY, not approval of a phase/runtime coefficient.
	 * Caller supplies a justified prospective delta, never retained input bytes.
	 * Accepted deltas remain pending for this operation: no phase reset/release
	 * or sample-based credit. This deliberately overcounts already-realized deltas
	 * rather than allowing nested, not-yet-sampled allocations to reuse headroom.
	 * Only the qualified native flatten phase supplies a measured replacement
	 * delta; generic snapshot/raw-decoder/rebuild estimates remain unchanged.
	 */
	public function admit_additional( $bytes ) {
		$this->tick();
		$total = self::checked_add( $this->additional_bytes, $bytes );
		$sample = $this->memory_sample();
		if ( ! is_array( $sample ) || array_keys( $sample ) !== array( 0, 1 ) ) { self::fail(); }
		$policy = array( 'max_raw_bytes' => PHP_INT_MAX, 'amplification' => 1, 'reserve_bytes' => $this->policy['reserve_bytes'] );
		$forecast = self::checked_add( $total, $this->retaining_pending );
		if ( null !== $this->authenticated_snapshot_read ) { $forecast = self::checked_add( $forecast, $this->authenticated_snapshot_read->workspace( 0 ) ); }
		foreach ( array( $sample[0], (string) $this->limit( 'memory_ceiling_bytes' ) ) as $limit ) {
			if ( is_wp_error( StoragePreflight::decode_budget( $forecast, $limit, $sample[1], $policy ) ) ) { StorageScalarObserver::milestone( 'scale-memory-refused' ); StorageScalarObserver::refusal( 'scale-memory', 'scale-memory' ); self::fail(); }
		}
		$this->additional_bytes = $total;
		return true;
	}

	/**
	 * Low-level finite producer ledger, NOT qualification of a callback/model.
	 * Opaque object capabilities identify scopes, never PHP arrays. Interrupted
	 * scopes stay charged; only the bound producer's completed event can consume
	 * its forecast. Actual constructed resources are NEVER subtracted from usage.
	 */
	public function supports_retaining_phase() {
		$this->valid();
		return null === $this->capacity && null === $this->memory && $this->policy['reserve_bytes'] >= 33554432;
	}

	/** Internal discard-only workspace; this predicate does NOT approve a host. */
	public function supports_snapshot_stats() {
		$this->valid();
		return null === $this->capacity && $this->policy['reserve_bytes'] >= 33554432;
	}

	/**
	 * Prospective private scratch in addition to ALL existing pending scopes.
	 * Unlike the trusted legacy sampling seam, this internal operation always
	 * checks actual usage/effective limit too. An observer can tighten/refuse,
	 * never substitute a lower usage or a larger PHP limit. No ledger release
	 * or sample-based realization occurs here. Qualification is producer-owned.
	 */
	public function admit_snapshot_stats_workspace( $bytes ) {
		$this->tick();
		if ( null !== $this->capacity ) { $this->capacity->checkpoint( $bytes ); return; }
		$sample = $this->memory_sample();
		if ( ! is_array( $sample ) || array_keys( $sample ) !== array( 0, 1 ) ) { self::fail(); }
		$total = self::checked_add( $bytes, self::checked_add( $this->additional_bytes, $this->retaining_pending ) );
		self::checked_add( $sample[1], 0 );
		$used = max( $sample[1], memory_get_usage( true ), $this->native_high_water );
		$policy = array( 'max_raw_bytes' => PHP_INT_MAX, 'amplification' => 1, 'reserve_bytes' => $this->policy['reserve_bytes'] );
		StorageScalarObserver::acquisition_guard( $bytes, self::checked_add( $this->additional_bytes, $this->retaining_pending ), $used, $this->native_high_water, $policy['reserve_bytes'], 1, $this->limit( 'memory_ceiling_bytes' ) );
		foreach ( array( ini_get( 'memory_limit' ), $sample[0], (string) $this->limit( 'memory_ceiling_bytes' ) ) as $limit ) {
			if ( is_wp_error( StoragePreflight::decode_budget( $total, $limit, $used, $policy ) ) ) { StorageScalarObserver::milestone( 'scale-memory-refused' ); StorageScalarObserver::refusal( 'scale-memory', 'scale-memory' ); self::fail(); }
		}
	}

	/** Private qualified scope. Interrupted scopes cannot be reset/released. */
	public function open_authenticated_snapshot_read( $phase ) {
		if ( null !== $this->authenticated_snapshot_read || ! $phase instanceof SnapshotReadPhase || ! $this->supports_snapshot_stats() ) { self::fail(); }
		$this->authenticated_snapshot_read = $phase;
		$this->admit_snapshot_stats_workspace( $phase->workspace( 0 ) );
	}
	public function close_authenticated_snapshot_read( $phase ) {
		if ( $phase !== $this->authenticated_snapshot_read || ! $phase->completed() ) { self::fail(); }
		$this->admit_snapshot_stats_workspace( $phase->workspace( 0 ) );
		$this->authenticated_snapshot_read = null;
	}

	private function check_pending( $pending ) {
		$this->tick();
		$total = self::checked_add( $this->additional_bytes, $pending );
		if ( null !== $this->authenticated_snapshot_read ) { $total = self::checked_add( $total, $this->authenticated_snapshot_read->workspace( 0 ) ); }
		$sample = $this->memory_sample();
		if ( ! is_array( $sample ) || array_keys( $sample ) !== array( 0, 1 ) ) { self::fail(); }
		$policy = array( 'max_raw_bytes' => PHP_INT_MAX, 'amplification' => 1, 'reserve_bytes' => $this->policy['reserve_bytes'] );
		foreach ( array( $sample[0], (string) $this->limit( 'memory_ceiling_bytes' ) ) as $limit ) {
			if ( is_wp_error( StoragePreflight::decode_budget( $total, $limit, $sample[1], $policy ) ) ) { StorageScalarObserver::milestone( 'scale-memory-refused' ); StorageScalarObserver::refusal( 'scale-memory', 'scale-memory' ); self::fail(); }
		}
	}

	public function open_retaining_phase( $producer, $descriptor_private ) {
		$this->tick();
		if ( ! is_object( $producer ) || count( $this->retaining_phases ) >= 64 || $this->retaining_serial >= 1024 ) { self::fail(); }
		$pending = self::checked_add( $this->retaining_pending, $descriptor_private );
		$this->check_pending( $pending );
		$token = new \stdClass();
		$id = spl_object_hash( $token );
		$this->retaining_phases[$id] = array( 'token' => $token, 'producer' => $producer, 'planned' => false,
			'future' => 0, 'private' => $descriptor_private, 'external' => 0, 'events' => 0, 'next' => 0, 'indexes' => 0, 'after_indexes' => 0 );
		++$this->retaining_serial; $this->retaining_pending = $pending;
		$this->admit_memory( 0 ); // Include the constructed ledger metadata before returning.
		return $token;
	}

	private function retaining_id( $token, $producer ) {
		$this->tick();
		if ( ! is_object( $token ) ) { self::fail(); }
		$id = spl_object_hash( $token );
		if ( ! isset( $this->retaining_phases[$id] ) || $this->retaining_phases[$id]['token'] !== $token ||
			$this->retaining_phases[$id]['producer'] !== $producer ) { self::fail(); }
		return $id;
	}

	/** Called only after the descriptor's row-private construction has unwound. */
	public function plan_retaining_phase( $token, $producer, $future, $private, $external, $events, $index_growth = 0, $after_indexes = 0 ) {
		$id = $this->retaining_id( $token, $producer ); $s = $this->retaining_phases[$id];
		if ( $s['planned'] || ! is_int( $events ) || $events < 0 || $events > $this->limit( 'max_rows' ) ||
			( 0 === $events && 0 !== $future ) ) { self::fail(); }
		self::checked_add( $index_growth, $after_indexes );
		if ( $index_growth > $private || ( 0 === $index_growth && 0 !== $after_indexes ) ) { self::fail(); }
		$own = self::checked_add( self::checked_add( $future, $private ), $external );
		$pending = self::checked_add( $this->retaining_pending - $s['private'], $own );
		$this->check_pending( $pending );
		$s['planned'] = true; $s['future'] = $future; $s['private'] = $private; $s['external'] = $external; $s['events'] = $events;
		$s['indexes'] = $index_growth; $s['after_indexes'] = $after_indexes;
		$this->retaining_phases[$id] = $s; $this->retaining_pending = $pending;
		$this->admit_memory( 0 );
	}

	/**
	 * Bound producer calls once AFTER both immutable indexes are complete, before
	 * any hydration. This is a producer event, not a usage/sample-delta credit.
	 * Workspace remains pending; live indexes remain in actual usage. Deferred
	 * visited/output/sort growth is admitted at the new sample BEFORE allocation.
	 * Like row events, this arithmetic API is not independent producer approval.
	 */
	public function complete_retaining_indexes( $token, $producer ) {
		$id = $this->retaining_id( $token, $producer ); $s = $this->retaining_phases[$id];
		if ( ! $s['planned'] || 0 === $s['indexes'] || 0 !== $s['next'] ) { self::fail(); }
		$pending = self::checked_add( $this->retaining_pending - $s['indexes'], $s['after_indexes'] );
		$this->check_pending( $pending );
		$s['private'] = self::checked_add( $s['private'] - $s['indexes'], $s['after_indexes'] );
		$s['indexes'] = 0; $s['after_indexes'] = 0;
		$this->retaining_phases[$id] = $s; $this->retaining_pending = $pending;
		$this->admit_memory( 0 );
	}

	/** Exact completed forecast, before external code; no memory-sample credits. */
	public function realize_retaining_event( $token, $producer, $sequence, $forecast ) {
		$id = $this->retaining_id( $token, $producer ); $s = $this->retaining_phases[$id];
		self::checked_add( $forecast, 0 );
		if ( ! $s['planned'] || 0 !== $s['indexes'] || ! is_int( $sequence ) || $sequence !== $s['next'] || $sequence >= $s['events'] ||
			$forecast > $s['future'] || ( $sequence + 1 === $s['events'] && $forecast !== $s['future'] ) ) { self::fail(); }
		$s['future'] -= $forecast; ++$s['next'];
		$this->retaining_phases[$id] = $s; $this->retaining_pending -= $forecast;
		$this->admit_memory( 0 );
	}

	/** Producer has unwound private frames. External list/COW liability persists. */
	public function close_retaining_phase( $token, $producer ) {
		$id = $this->retaining_id( $token, $producer ); $s = $this->retaining_phases[$id];
		if ( ! $s['planned'] || 0 !== $s['indexes'] || 0 !== $s['future'] || $s['next'] !== $s['events'] ) { self::fail(); }
		$additional = self::checked_add( $this->additional_bytes, $s['external'] );
		$this->retaining_pending -= self::checked_add( $s['private'], $s['external'] );
		$this->additional_bytes = $additional;
		unset( $this->retaining_phases[$id] );
		$this->admit_memory( 0 );
	}

	/** PHP 5 compatible: validate BEFORE an overflowing intermediate exists. */
	public static function checked_add( $a, $b ) {
		if ( ! is_int( $a ) || ! is_int( $b ) || $a < 0 || $b < 0 || $b > PHP_INT_MAX - $a ) { self::fail(); }
		return $a + $b;
	}

	public static function checked_multiply( $a, $b ) {
		self::checked_add( $a, 0 ); self::checked_add( $b, 0 );
		$result = 0;
		while ( $b > 0 ) {
			if ( $b & 1 ) { $result = self::checked_add( $result, $a ); }
			$b = $b >> 1;
			if ( $b > 0 ) { $a = self::checked_add( $a, $a ); }
		}
		return $result;
	}

	/** Native path retains sampled real backing; trusted test seams stay separate. */
	private function memory_sample() {
		if ( null !== $this->memory ) { return call_user_func( $this->memory ); }
		$this->native_high_water = max( $this->native_high_water, memory_get_usage( true ), null === $this->baseline ? 0 : $this->baseline->sample() );
		return array( ini_get( 'memory_limit' ), $this->native_high_water );
	}

	private function now() {
		$now = null === $this->clock ? microtime( true ) : call_user_func( $this->clock );
		if ( ! is_float( $now ) || ! is_finite( $now ) ) { self::fail(); } return $now;
	}
	private static function fail() { throw new \RuntimeException( 'Finite storage scale admission refused; retain authority and all evidence.' ); }
}
