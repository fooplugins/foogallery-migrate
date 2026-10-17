<?php
/**
 * Internal scalar measurement seam, NOT a policy or a public observer API.
 * Disabled by default; no request/filter/constant/callback activation. An external
 * authorized harness may arm private state by reflection in its own process.
 * Read private records only after the operation. No SQL, values or objects held.
 * Times are wall-clock endpoints; peaks are PROCESS CUMULATIVE, not phase maxima.
 * Invalid/overflow/backward-clock evidence never changes storage control flow.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageScalarObserver {
	// Separate, finite full-conversion milestones. Privileged test reflection only;
	// no callback, SQL/payload retention, request activation, or approval semantics.
	private static $milestones_enabled = false;
	private static $milestones_valid = true;
	private static $milestones = array();

	public static function milestone( $name ) {
		if ( ! self::$milestones_enabled || ! self::$milestones_valid ) { return; }
		$allowed = array( 'receiver-entry', 'authorized', 'policy', 'source-read', 'source-decoded',
			'source-verified', 'snapshot-preflight', 'snapshot-staged', 'snapshot-readback', 'snapshot-committed',
			'reverse-serialized', 'reverse-written', 'retained-backup', 'control-copying', 'control-verified',
			'control-flipped', 'control-stable', 'released', 'response', 'scale-work-refused', 'scale-deadline-refused', 'scale-memory-refused' );
		$now = microtime( true ); $count = count( self::$milestones );
		if ( ! in_array( $name, $allowed, true ) || $count >= 128 || ! is_finite( $now ) ||
			( $count && $now < self::$milestones[$count - 1]['time'] ) ) { self::$milestones_valid = false; return; }
		self::$milestones[] = array( 'name' => $name, 'time' => $now, 'live' => memory_get_usage( false ),
			'allocated' => memory_get_usage( true ), 'peak_live_cumulative' => memory_get_peak_usage( false ),
			'peak_allocated_cumulative' => memory_get_peak_usage( true ) );
	}

	private static $enabled = false;
	private static $valid = true;
	private static $records = array();
	private static $stack = array();
	private static $sequence = 0;
	private static $last_time = 0.0;

	// Closed phases: start, advance, LENGTH/admit, CONCAT/substr, existing hash,
	// parser, source-fenced CAS (through query, NOT readback), read, mysqli, release,
	// non-source CAS. Events 1=begin/2=end; outcomes 0=open/1=return/2=exception/3=false.
	public static function begin( $phase, $bytes = 0 ) {
		if ( ! self::$enabled || ! self::$valid ) { return 0; }
		if ( ! is_int( $phase ) || $phase < 1 || $phase > 11 || ! self::bytes( $bytes ) ||
			self::$sequence >= PHP_INT_MAX || count( self::$stack ) >= 128 ) { self::$valid = false; return 0; }
		$id = ++self::$sequence;
		$parent = count( self::$stack ) ? self::$stack[count( self::$stack ) - 1]['id'] : 0;
		if ( ! self::record( $id, $parent, $phase, 1, $bytes, 0 ) ) { return 0; }
		self::$stack[] = array( 'id' => $id, 'parent' => $parent, 'phase' => $phase );
		return $id;
	}

	public static function end( $id, $outcome = 1, $bytes = 0 ) {
		if ( ! self::$enabled || ! self::$valid || 0 === $id ) { return; }
		if ( ! is_int( $id ) || ! is_int( $outcome ) || $outcome < 1 || $outcome > 3 || ! self::bytes( $bytes ) ) { self::$valid = false; return; }
		$found = false;
		foreach ( self::$stack as $span ) { if ( $id === $span['id'] ) { $found = true; break; } }
		if ( ! $found ) { self::$valid = false; return; }
		// Only exception unwind may close descendants. No finally dependency (5.4).
		do {
			$span = array_pop( self::$stack );
			if ( $span['id'] !== $id && 2 !== $outcome ) { self::$valid = false; return; }
			if ( ! self::record( $span['id'], $span['parent'], $span['phase'], 2, $bytes, $outcome ) ) { return; }
		} while ( $span['id'] !== $id );
	}

	private static function bytes( $bytes ) { return is_int( $bytes ) && $bytes >= 0; }
	private static function record( $id, $parent, $phase, $event, $bytes, $outcome ) {
		if ( count( self::$records ) >= 256 ) { self::$valid = false; return false; }
		$now = \microtime( true );
		if ( ! is_finite( $now ) || $now < self::$last_time ) { self::$valid = false; return false; }
		self::$last_time = $now;
		self::$records[] = array( 'id' => $id, 'parent' => $parent, 'phase' => $phase, 'event' => $event,
			'time' => $now, 'live' => memory_get_usage( false ), 'allocated' => memory_get_usage( true ),
			'peak_live_cumulative' => memory_get_peak_usage( false ), 'peak_allocated_cumulative' => memory_get_peak_usage( true ),
			'bytes' => $bytes, 'outcome' => $outcome );
		return true;
	}
}
