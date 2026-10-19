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
		// Reconstruct the compiler's exact component algebra, not merely its sum.
		// Even larger/redistributed parts cannot support the actual compiled plan:
		// supports() deliberately requires exact producer-plan identity.
		$f = $p['features'];
		foreach ( array( 'logical_depth', 'max_string', 'max_scalar_encoding', 'containers', 'max_resize' ) as $k ) { if ( ! isset( $f[$k] ) ) { self::fail( 'overlap floors' ); } self::number( $f[$k] ); }
		$expected = array(
			'legacy_private_floor' => self::number( $p['own']['private'] ),
			'witness_construction_lifetime_lookup' => SnapshotPageWitness::workspace( self::number( $p['own']['records'] ) ),
			'whole_logical_digest_frames' => StorageScaleAdmission::checked_multiply( 4096, self::add( $f['logical_depth'], 1 ) ),
			'whole_logical_scalar_hash_scratch' => StorageScaleAdmission::checked_multiply( 16, max( $f['max_string'], $f['max_scalar_encoding'] ) ),
			'whole_children_wrapper_cow' => self::add( $f['containers'], $f['max_resize'] ),
			'retained_two_outputs_cow' => StorageScaleAdmission::checked_multiply( 2, $f['containers'] ),
		);
		if ( $p['version'] !== SnapshotReadPlan::VERSION || $p['parts'] !== $expected ) { self::fail( 'overlap floors' ); }
		$private = 0; foreach ( $p['parts'] as $n ) { $private = self::add( $private, $n ); }
		if ( $private !== $p['full']['private'] ) { self::fail( 'private sum' ); }
		$full = $p['own']; $full['private'] = $private;
		if ( $p['full'] !== $full ) { self::fail( 'overlap floors' ); }
		$pids = array(); $max_residual = 0; $residuals = array(); $quantum = 0; $samples = array();
		foreach ( $raw_traces as $i => $raw ) {
			if ( ! is_string( $raw ) || strlen( $raw ) > 16777216 || hash( 'sha256', $raw ) !== $cell['trace_sha256'][$i] ) { self::fail( 'raw trace hash/bound' ); }
			$t = json_decode( $raw, true );
			foreach ( array( 'runtime', 'pid', 'identity', 'features', 'events', 'variants' ) as $k ) { if ( ! isset( $t[$k] ) ) { self::fail( 'trace ' . $k ); } }
			if ( $t['runtime'] !== $cell['runtime'] || $t['identity'] !== $cell['identity'] || $t['features'] !== $p['features'] || isset( $pids[$t['pid']] ) || self::number( $t['pid'] ) < 1 || $t['variants'] !== array( 'original', 'retained-two-outputs', 'deadline-interrupted', 'fragmented-backing' ) ) { self::fail( 'trace/child/variant binding' ); }
			$pids[$t['pid']] = true; $seen = array(); $last_remaining = array(); $before = array(); $stack = array(); $read_index = -1;
			$lifecycle = array( 'stage' => 'entry', 'attached' => 0 );
			foreach ( $t['events'] as $seq => $e ) {
				foreach ( array( 'seq', 'read', 'seam', 'edge', 'row', 'remaining', 'private', 'page', 'pending', 'allocated', 'observer_backing', 'native_high_water', 'peak', 'limit', 'observer_limit', 'ceiling', 'reserve', 'residual', 'allocator_growth', 'retained_outputs' ) as $k ) { if ( ! array_key_exists( $k, $e ) ) { self::fail( 'sample ' . $k ); } }
				if ( $e['seq'] !== $seq || ! in_array( $e['read'], array( 'staged', 'read1', 'read2' ), true ) || ! in_array( $e['edge'], array( 'before', 'after' ), true ) ) { self::fail( 'seam ordering' ); }
				if ( ! in_array( $e['seam'], array( 'root-native', 'page-chain', 'whole-record-digest', 'cow-resize', 'envelope', 'final-digest', 'exit', 'key-native', 'payload-native', 'attach', 'unwind' ), true ) ) { self::fail( 'unknown seam' ); }
				self::number( $e['row'] ); self::number( $e['peak'] ); self::number( $e['retained_outputs'] );
				$ri = array_search( $e['read'], array( 'staged', 'read1', 'read2' ), true );
				if ( $ri < $read_index || $ri > $read_index + 1 ) { self::fail( 'read ordering' ); }
				if ( $ri !== $read_index ) {
					if ( $read_index >= 0 && ( $lifecycle['stage'] !== 'complete' || count( $stack ) ) ) { self::fail( 'unfinished previous read' ); }
					$lifecycle = array( 'stage' => 'entry', 'attached' => 0 ); $read_index = $ri;
				}
				self::lifecycle( $e, $p, $lifecycle, $seen, $stack );
				$id = $e['read'] . ':' . $e['seam'] . ':' . $e['row']; $key = $id . ':' . $e['edge'];
				if ( $e['edge'] === 'before' ) { $stack[] = $id; } else if ( array_pop( $stack ) !== $id ) { self::fail( 'unwound seam ordering' ); }
				if ( isset( $seen[$key] ) || ( $e['edge'] === 'after' && ! isset( $seen[$id . ':before'] ) ) ) { self::fail( 'duplicate/missing seam' ); } $seen[$key] = true;
				self::number( $e['remaining'] );
				if ( $e['remaining'] > $p['own']['future'] || ( isset( $last_remaining[$e['read']] ) && $e['remaining'] > $last_remaining[$e['read']] ) || $e['private'] !== $private || $e['reserve'] < 33554432 ) { self::fail( 'remaining/private/reserve' ); }
				if ( ! isset( $last_remaining[$e['read']] ) && $e['remaining'] !== $p['own']['future'] ) { self::fail( 'new owner debt' ); }
				if ( isset( $last_remaining[$e['read']] ) && $e['remaining'] !== $last_remaining[$e['read']] && ( $e['seam'] !== 'attach' || $e['edge'] !== 'after' ) ) { self::fail( 'premature own debt discharge' ); }
				if ( $e['seam'] === 'attach' && $e['edge'] === 'after' ) {
					++$lifecycle['attached'];
					// Every not-yet-attached record has positive own bytes. No per-row
					// allocation estimate is inferred from this aggregate trace.
					if ( $e['remaining'] >= $last_remaining[$e['read']] || $e['remaining'] < $p['own']['records'] - $lifecycle['attached'] || ( $lifecycle['attached'] === $p['own']['records'] && $e['remaining'] !== 0 ) ) { self::fail( 'attachment debt' ); }
				}
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
	/** Finite successful-read grammar, not a claim about arbitrary hook traces.
	 * attach:after denotes assigned, validated output after the callee returned
	 * (SnapshotOwnedEventPlan::attached). Only that event may consume own debt.
	 * unwind samples may bracket that event or follow it; they grant no credit. */
	private static function lifecycle( $e, $p, &$state, $seen, $stack ) {
		$row_seam = in_array( $e['seam'], array( 'key-native', 'payload-native', 'attach', 'unwind' ), true );
		if ( $row_seam ? ( $e['row'] < 1 || $e['row'] > $p['own']['records'] ) : $e['row'] !== 0 ) { self::fail( 'lifecycle row' ); }
		$prefix = $e['read'] . ':'; $seam = $e['seam']; $after = $e['edge'] === 'after';
		if ( in_array( $seam, array( 'root-native', 'page-chain', 'envelope', 'final-digest', 'exit' ), true ) ) {
			$previous = array( 'root-native' => 'entry', 'page-chain' => 'root-native', 'envelope' => 'page-chain', 'final-digest' => 'envelope', 'exit' => 'final-digest' );
			if ( $state['stage'] !== ( $after ? $seam . '-active' : $previous[$seam] ) || ( ! $after && count( $stack ) ) ) { self::fail( 'lifecycle completion ordering' ); }
			if ( $seam === 'page-chain' && $after ) {
				foreach ( array( 'whole-record-digest', 'cow-resize' ) as $required ) {
					if ( ! isset( $seen[$prefix . $required . ':0:after'] ) ) { self::fail( 'unfinished overlap' ); }
				}
				for ( $row = 1; $row <= $p['own']['records']; ++$row ) {
					foreach ( array( 'key-native', 'payload-native', 'attach', 'unwind' ) as $required ) {
						if ( ! isset( $seen[$prefix . $required . ':' . $row . ':after'] ) ) { self::fail( 'unfinished row' ); }
					}
				}
				if ( $state['attached'] !== $p['own']['records'] || $e['remaining'] !== 0 ) { self::fail( 'unfinished own debt' ); }
			}
			$state['stage'] = $after ? ( $seam === 'exit' ? 'complete' : $seam ) : $seam . '-active';
		} else {
			if ( $state['stage'] !== 'page-chain-active' ) { self::fail( 'work outside reconstruction' ); }
			if ( $row_seam && $seam !== 'key-native' ) {
				$required = $seam === 'payload-native' ? 'key-native' : 'payload-native';
				if ( ! isset( $seen[$prefix . $required . ':' . $e['row'] . ':after'] ) ) { self::fail( 'premature row work' ); }
			}
		}
	}
	public function supports( $plan, $identity ) {
		return $this->pid === getmypid() && $this->cell['runtime'] === SnapshotReadPlan::runtime_binding() && $this->cell['plan'] === $plan && $this->cell['identity'] === $identity;
	}
	public function margin() { return $this->margin; }
	private static function fail( $reason ) { throw new \RuntimeException( 'Snapshot finite host cell refused: ' . $reason ); }
}
