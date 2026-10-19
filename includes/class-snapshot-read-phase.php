<?php
/** One authenticated phase policy, including pre-root entry and return seams. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class SnapshotReadPhase {
	private $plan;
	private $witness;
	private $binding;
	private $owner;
	private $margin;
	private $runtime;
	public function __construct( $plan, $witness, $binding, $profile ) {
		if ( ! $witness instanceof SnapshotPageWitness || ! $profile instanceof SnapshotReadProfile ||
			! $witness->matches( $binding, $plan ) || ! $profile->supports( $plan, self::identity( $binding ) ) ) { self::fail(); }
		$this->plan = $plan; $this->witness = $witness; $this->binding = $binding; $this->margin = $profile->margin();
		$this->runtime = SnapshotReadPlan::runtime_state();
	}
	public function __clone() { self::fail(); }
	public function __sleep() { self::fail(); }
	public function __wakeup() { self::fail(); }
	public static function identity( $binding ) {
		// Exact finite source shape/value; no interpolation to other sources.
		return array( 'key' => $binding['key'], 'root_sha256' => hash( 'sha256', $binding['root'] ),
			'digest' => $binding['digest'], 'stats' => $binding['stats'], 'revision' => $binding['revision'], 'connection' => $binding['connection'] );
	}
	public function matches( $binding ) { return $binding === $this->binding && $this->witness->matches( $binding, $this->plan ); }
	public function attach_owner( $owner, $source ) {
		if ( null !== $this->owner || ! $owner instanceof SnapshotOwnedEventPlan || ! $owner->owns_source( $source ) ||
			! $source->uses_witness( $this->witness ) ) { self::fail(); }
		$this->owner = $owner;
	}
	public function completed() { return null !== $this->owner && $this->owner->is_complete(); }
	public function workspace( $page ) {
		// A stale ACTIVE operation refuses; it cannot fall back and discard debt.
		if ( SnapshotReadPlan::runtime_state() !== $this->runtime ) { self::fail(); }
		$remaining = null === $this->owner ? $this->plan['own']['future'] : $this->owner->remaining_own_output();
		return StorageScaleAdmission::checked_add( StorageScaleAdmission::checked_add( $remaining, $this->plan['full']['private'] ), StorageScaleAdmission::checked_add( $this->margin, $page ) );
	}
	private static function fail() { throw new \RuntimeException( 'Authenticated snapshot read phase refused; debt retained.' ); }
}
