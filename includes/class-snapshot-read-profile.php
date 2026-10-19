<?php
/** Internal offline finite-cell validator. No shipped cells or request input. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotReadProfile {
	private $cell;
	private $margin;
	private $pid;
	private function __construct( $cell, $margin ) { $this->cell = $cell; $this->margin = $margin; $this->pid = getmypid(); }
	public function __clone() { self::fail( 'profile cloning' ); }
	public function __sleep() { self::fail( 'profile serialization' ); }
	public function __wakeup() { self::fail( 'profile serialization' ); }
	private static function number( $v ) { if ( ! is_int( $v ) || $v < 0 ) { self::fail( 'invalid integer' ); } return $v; }
	private static function add( $a, $b ) { return StorageScaleAdmission::checked_add( self::number( $a ), self::number( $b ) ); }
	/** Each trace is an authenticated raw JSON receipt, never a peak summary.
	 * The author supplies files offline, not via options/SQL/HTTP. The reviewer
	 * must separately verify observer provenance; this is finite arithmetic and
	 * coverage validation, not an independent authenticity oracle. */
	public static function qualify( $cell, $raw_traces ) {
		foreach ( array( 'runtime', 'plan', 'identity', 'trace_sha256', 'observer', 'domain' ) as $k ) { if ( ! isset( $cell[$k] ) ) { self::fail( 'missing ' . $k ); } }
		if ( $cell['domain'] !== 'host-double-exact-cell' || $cell['runtime'] !== SnapshotReadPlan::runtime_binding() || count( $raw_traces ) < 3 || count( $raw_traces ) !== count( $cell['trace_sha256'] ) ) { self::fail( 'runtime/domain/repeats' ); }
		foreach ( array( 'independent', 'bounded_bytes', 'allocator_growth_quantum' ) as $k ) { if ( ! isset( $cell['observer'][$k] ) ) { self::fail( 'observer binding' ); } }
		if ( true !== $cell['observer']['independent'] || self::number( $cell['observer']['bounded_bytes'] ) < 1 ) { self::fail( 'observer binding' ); }
		$p = $cell['plan'];
		foreach ( array( 'own', 'full', 'parts', 'features', 'version' ) as $k ) { if ( ! isset( $p[$k] ) ) { self::fail( 'missing overlap' ); } }
		$required = array( 'legacy_private_floor', 'witness_construction_lifetime_lookup', 'whole_logical_digest_frames', 'whole_logical_scalar_hash_scratch', 'whole_children_wrapper_cow', 'retained_two_outputs_cow' );
		if ( array_keys( $p['parts'] ) !== $required || $p['version'] !== SnapshotReadPlan::VERSION || $p['parts']['legacy_private_floor'] < $p['own']['private'] || $p['parts']['witness_construction_lifetime_lookup'] < SnapshotPageWitness::workspace( $p['own']['records'] ) ) { self::fail( 'overlap floors' ); }
		$private = 0; foreach ( $p['parts'] as $n ) { $private = self::add( $private, $n ); }
		if ( $private !== $p['full']['private'] ) { self::fail( 'private sum' ); }
		$pids = array(); $max_residual = 0; $residuals = array(); $quantum = 0; $samples = array();
		foreach ( $raw_traces as $i => $raw ) {
			if ( ! is_string( $raw ) || strlen( $raw ) > 16777216 || hash( 'sha256', $raw ) !== $cell['trace_sha256'][$i] ) { self::fail( 'raw trace hash/bound' ); }
			$t = json_decode( $raw, true );
			foreach ( array( 'runtime', 'pid', 'identity', 'features', 'events', 'variants' ) as $k ) { if ( ! isset( $t[$k] ) ) { self::fail( 'trace ' . $k ); } }
			if ( $t['runtime'] !== $cell['runtime'] || $t['identity'] !== $cell['identity'] || $t['features'] !== $p['features'] || isset( $pids[$t['pid']] ) || self::number( $t['pid'] ) < 1 || $t['variants'] !== array( 'original', 'retained-two-outputs', 'deadline-interrupted', 'fragmented-backing' ) ) { self::fail( 'trace/child/variant binding' ); }
			$pids[$t['pid']] = true; $seen = array(); $last_remaining = array(); $before = array(); $stack = array(); $read_index = -1;
			foreach ( $t['events'] as $seq => $e ) {
				foreach ( array( 'seq', 'read', 'seam', 'edge', 'row', 'remaining', 'private', 'page', 'pending', 'allocated', 'observer_backing', 'native_high_water', 'peak', 'limit', 'observer_limit', 'ceiling', 'reserve', 'residual', 'allocator_growth', 'retained_outputs' ) as $k ) { if ( ! array_key_exists( $k, $e ) ) { self::fail( 'sample ' . $k ); } }
				if ( $e['seq'] !== $seq || ! in_array( $e['read'], array( 'staged', 'read1', 'read2' ), true ) || ! in_array( $e['edge'], array( 'before', 'after' ), true ) ) { self::fail( 'seam ordering' ); }
				if ( ! in_array( $e['seam'], array( 'root-native', 'page-chain', 'whole-record-digest', 'cow-resize', 'envelope', 'final-digest', 'exit', 'key-native', 'payload-native', 'attach', 'unwind' ), true ) ) { self::fail( 'unknown seam' ); }
				self::number( $e['row'] ); self::number( $e['peak'] ); self::number( $e['retained_outputs'] );
				$ri = array_search( $e['read'], array( 'staged', 'read1', 'read2' ), true );
				if ( $ri < $read_index || $ri > $read_index + 1 ) { self::fail( 'read ordering' ); } $read_index = $ri;
				$id = $e['read'] . ':' . $e['seam'] . ':' . $e['row']; $key = $id . ':' . $e['edge'];
				if ( $e['edge'] === 'before' ) { $stack[] = $id; } else if ( array_pop( $stack ) !== $id ) { self::fail( 'unwound seam ordering' ); }
				if ( isset( $seen[$key] ) || ( $e['edge'] === 'after' && ! isset( $seen[$id . ':before'] ) ) ) { self::fail( 'duplicate/missing seam' ); } $seen[$key] = true;
				self::number( $e['remaining'] );
				if ( $e['remaining'] > $p['own']['future'] || ( isset( $last_remaining[$e['read']] ) && $e['remaining'] > $last_remaining[$e['read']] ) || $e['private'] !== $private || $e['reserve'] < 33554432 ) { self::fail( 'remaining/private/reserve' ); }
				if ( ! isset( $last_remaining[$e['read']] ) && $e['remaining'] !== $p['own']['future'] ) { self::fail( 'new owner debt' ); }
				$last_remaining[$e['read']] = $e['remaining'];
				if ( $e['seam'] === 'exit' && ( $e['remaining'] !== 0 || $e['retained_outputs'] !== ( $e['read'] === 'read2' ? 2 : ( $e['read'] === 'read1' ? 1 : 0 ) ) ) ) { self::fail( 'retained output overlap' ); }
				// Before AND after samples retain all debt. Peak must be covered by
				// the preceding guard, not excused by a later larger backing sample.
				$backing = max( self::number( $e['allocated'] ), self::number( $e['observer_backing'] ), self::number( $e['native_high_water'] ) );
				$base = self::add( self::add( $e['remaining'], $private ), self::add( $e['page'], $e['pending'] ) );
				$limit = min( self::number( $e['limit'] ), self::number( $e['observer_limit'] ), self::number( $e['ceiling'] ) );
				$forecast = self::add( $backing, $base );
				$residual = 0; $growth = 0;
				if ( $e['edge'] === 'before' ) { $before[$id] = array( $forecast, $backing, $e['allocated'] ); }
				else {
					if ( $e['peak'] > $before[$id][0] ) { self::fail( 'peak exceeded prospective guard' ); }
					$residual = max( 0, $e['peak'] - $before[$id][1] );
					$growth = max( 0, $e['allocated'] - $before[$id][2] );
					$residuals[$id][] = $residual; unset( $before[$id] );
				}
				if ( $e['residual'] !== $residual || $e['allocator_growth'] !== $growth ) { self::fail( 'unmeasured margin arithmetic' ); }
				$max_residual = max( $max_residual, $residual ); $quantum = max( $quantum, $growth );
				$samples[] = array( $forecast, $limit, $e['reserve'] );
			}
			if ( count( $stack ) || count( $before ) ) { self::fail( 'incomplete before/after seams' ); }
			foreach ( array( 'staged', 'read1', 'read2' ) as $read ) {
				foreach ( array( 'root-native', 'page-chain', 'whole-record-digest', 'cow-resize', 'envelope', 'final-digest', 'exit' ) as $seam ) {
					foreach ( array( 'before', 'after' ) as $edge ) { if ( ! isset( $seen[$read . ':' . $seam . ':0:' . $edge] ) ) { self::fail( 'missing overlap seam ' . $seam ); } }
				}
				for ( $row = 1; $row <= $p['own']['records']; ++$row ) {
					foreach ( array( 'key-native', 'payload-native', 'attach', 'unwind' ) as $seam ) { foreach ( array( 'before', 'after' ) as $edge ) {
						if ( ! isset( $seen[$read . ':' . $seam . ':' . $row . ':' . $edge] ) ) { self::fail( 'incomplete row seam' ); }
					} }
				}
			}
		}
		if ( $quantum !== $cell['observer']['allocator_growth_quantum'] ) { self::fail( 'allocator margin' ); }
		$spread = 0; foreach ( $residuals as $values ) { if ( count( $values ) !== count( $raw_traces ) ) { self::fail( 'repeat seam mismatch' ); } $spread = max( $spread, max( $values ) - min( $values ) ); }
		$margin = self::add( self::add( $max_residual, $spread ), self::add( $quantum, $cell['observer']['bounded_bytes'] ) );
		foreach ( $samples as $s ) { if ( self::add( self::add( $s[0], $margin ), $s[2] ) > $s[1] ) { self::fail( 'finite measured cell exceeds capacity with complete margin' ); } }
		return new self( $cell, $margin );
	}
	public function supports( $plan, $identity ) {
		return $this->pid === getmypid() && $this->cell['runtime'] === SnapshotReadPlan::runtime_binding() && $this->cell['plan'] === $plan && $this->cell['identity'] === $identity;
	}
	public function margin() { return $this->margin; }
	private static function fail( $reason ) { throw new \RuntimeException( 'Snapshot finite host cell refused: ' . $reason ); }
}
