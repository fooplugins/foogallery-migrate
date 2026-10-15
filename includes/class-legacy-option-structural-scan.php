<?php
/**
 * Non-materializing continuation companion to LegacyOptionDecoder (PHP 5.4).
 *
 * Construction retains the exact raw string and hashes it once: O(source bytes),
 * capped at 16 MiB, NOT part of step's cooperative parsing deadline. The caller
 * must separately admit source acquisition/setup. No graph or native decoder is
 * used. Each step consumes finite bytes/nodes with an at-most-65-frame stack.
 *
 * Cursors are live-instance checkpoints, not trusted offsets or portable parser
 * snapshots. They must equal the last issued source-bound parser state exactly;
 * stale, edited, foreign and terminal resumptions fail. There is deliberately no
 * deserialization/restoration API. A later durable adapter needs its own trusted
 * checkpoint/fencing contract; do not persist this object or treat scan success
 * as permission to materialize or publish a snapshot.
 *
 * This proves canonical TOKENS and array structure, not unique array keys or
 * whole-graph roundtrip equivalence. Those remain decode()/conversion obligations.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyOptionStructuralScan {
	private static $next_id = 0;
	private $raw;
	private $length;
	private $digest;
	private $max_depth;
	private $id;
	private $sequence = 0;
	private $checkpoint = null;
	private $failed = false;
	private $done = false;
	private $state;

	public function __construct( $raw, $max_bytes = 16777216, $max_depth = 64 ) {
		if ( ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > 16777216 ||
			! is_int( $max_depth ) || $max_depth < 1 || $max_depth > 64 ||
			! is_string( $raw ) || strlen( $raw ) > $max_bytes || 'a:' !== substr( $raw, 0, 2 ) ) { $this->fail(); }
		$this->raw = $raw;
		$this->length = strlen( $raw );
		$hash_span = StorageScalarObserver::begin( 5, $this->length );
		try { $this->digest = hash( 'sha256', $raw ); }
		catch ( \Exception $error ) { StorageScalarObserver::end( $hash_span, 2 ); throw $error; }
		catch ( \Throwable $error ) { StorageScalarObserver::end( $hash_span, 2 ); throw $error; }
		StorageScalarObserver::end( $hash_span, 1, $this->length );
		$this->max_depth = $max_depth;
		$this->id = ++self::$next_id;
		$this->state = array( 'offset' => 0, 'nodes' => 0, 'started' => false,
			'phase' => 'node', 'frames' => array(), 'type' => '', 'key' => false,
			'token' => '', 'remaining' => 0, 'key_prefix' => '' );
	}

	/** Only the final server-owned adapter can admit portable parser state. */
	public static function owned( $raw, LegacyOptionDurableScan $owner ) {
		$scanner = new self( $raw );
		$state = $owner->take_state( $scanner->digest, $scanner->length );
		if ( null !== $state ) {
			$scanner->state = $state['parser'];
			$scanner->sequence = $state['generation'];
		}
		return $scanner;
	}

	private function __clone() {}
	public function __sleep() { $this->fail(); }
	public function __wakeup() { $this->fail(); }

	/** Limits include partial tokens/string bodies; never retry a whole token. */
	public function step( $cursor, $max_bytes = 4096, $max_nodes = 256, $max_seconds = 0.01 ) {
		if ( $this->failed || $this->done || $cursor !== $this->checkpoint ||
			! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > 65536 ||
			! is_int( $max_nodes ) || $max_nodes < 1 || $max_nodes > 30000 ||
			( ! is_int( $max_seconds ) && ! is_float( $max_seconds ) ) ||
			! is_finite( (float) $max_seconds ) || $max_seconds <= 0 || $max_seconds > 1 ) { $this->fail(); }
		$start = $this->state['offset']; $nodes = $this->state['nodes'];
		$deadline = microtime( true ) + $max_seconds;
		try {
			while ( $this->state['offset'] - $start < $max_bytes && microtime( true ) < $deadline ) {
				if ( 'node' === $this->state['phase'] ) {
					$depth = count( $this->state['frames'] );
					if ( $depth && 0 === $this->state['frames'][$depth - 1] ) {
						$this->state['phase'] = 'close';
					} else if ( ! $depth && $this->state['started'] ) {
						$this->finish(); break;
					} else {
						if ( $this->state['nodes'] - $nodes >= $max_nodes ) { break; }
						$this->begin_node( $depth );
					}
				}
				if ( $this->state['offset'] >= $this->length ) { $this->fail(); }
				$byte = $this->raw[$this->state['offset']++];
				$this->consume( $byte );
			}
			// Completion is constant work even at an exact byte boundary.
			if ( 'node' === $this->state['phase'] && ! count( $this->state['frames'] ) && $this->state['started'] ) { $this->finish(); }
		} catch ( \RuntimeException $error ) { $this->failed = true; throw $error; }
		$this->checkpoint = array( 'scan_id' => $this->id, 'sequence' => ++$this->sequence,
			'source_digest' => $this->digest, 'source_length' => $this->length, 'max_depth' => $this->max_depth,
			'parser' => $this->state );
		return array( 'cursor' => $this->checkpoint, 'done' => $this->done,
			'bytes' => $this->state['offset'] - $start, 'nodes' => $this->state['nodes'] - $nodes,
			'stack_depth' => count( $this->state['frames'] ) );
	}

	private function finish() {
		if ( $this->state['offset'] !== $this->length ) { $this->fail(); }
		$this->done = true;
	}

	private function begin_node( $depth ) {
		if ( $depth > $this->max_depth ) { $this->fail(); }
		$this->state['key'] = $depth && 0 === $this->state['frames'][$depth - 1] % 2;
		if ( $depth ) { --$this->state['frames'][$depth - 1]; }
		$this->state['started'] = true;
		++$this->state['nodes'];
		$this->state['phase'] = 'type';
	}

	private function consume( $byte ) {
		switch ( $this->state['phase'] ) {
			case 'type':
				if ( false === strpos( 'Nbidsa', $byte ) ||
					( $this->state['key'] && 'i' !== $byte && 's' !== $byte ) ||
					( 1 === $this->state['nodes'] && 'a' !== $byte ) ) { $this->fail(); }
				$this->state['type'] = $byte;
				$this->state['phase'] = 'N' === $byte ? 'null_end' : 'colon'; return;
			case 'null_end':
				if ( ';' !== $byte ) { $this->fail(); }
				$this->state['phase'] = 'node'; return;
			case 'colon':
				if ( ':' !== $byte ) { $this->fail(); }
				$this->state['token'] = ''; $this->state['phase'] = 'token'; return;
			case 'token':
				$type = $this->state['type'];
				$end = ( 'a' === $type || 's' === $type ) ? ':' : ';';
				if ( $end === $byte ) { $this->end_token(); return; }
				$cap = 'd' === $type ? 128 : ( 'b' === $type ? 1 : ( 'i' === $type ? 21 : 20 ) );
				if ( strlen( $this->state['token'] ) >= $cap ) { $this->fail(); }
				$this->state['token'] .= $byte; return;
			case 'open':
				if ( '{' !== $byte ) { $this->fail(); }
				$this->state['frames'][] = 2 * $this->state['remaining'];
				$this->state['phase'] = 'node'; return;
			case 'close':
				if ( '}' !== $byte ) { $this->fail(); }
				array_pop( $this->state['frames'] ); $this->state['phase'] = 'node'; return;
			case 'quote':
				if ( '"' !== $byte || $this->state['remaining'] > $this->length - $this->state['offset'] - 2 ) { $this->fail(); }
				$this->state['key_prefix'] = '';
				$this->state['phase'] = $this->state['remaining'] ? 'body' : 'end_quote'; return;
			case 'body':
				if ( $this->state['key'] && strlen( $this->state['key_prefix'] ) < 22 ) { $this->state['key_prefix'] .= $byte; }
				if ( 0 === --$this->state['remaining'] ) { $this->state['phase'] = 'end_quote'; }
				return;
			case 'end_quote':
				if ( '"' !== $byte || ( $this->state['key'] && (string) (int) $this->state['key_prefix'] === $this->state['key_prefix'] ) ) { $this->fail(); }
				$this->state['phase'] = 'string_end'; return;
			case 'string_end':
				if ( ';' !== $byte ) { $this->fail(); }
				$this->state['phase'] = 'node'; return;
		}
		$this->fail();
	}

	private function end_token() {
		$value = $this->state['token']; $type = $this->state['type'];
		$this->state['phase'] = 'node';
		if ( 'b' === $type ) {
			if ( '0' !== $value && '1' !== $value ) { $this->fail(); }
		} else if ( 'i' === $type ) {
			if ( ! preg_match( '/^(0|-?[1-9][0-9]*)$/D', $value ) || (string) (int) $value !== $value ) { $this->fail(); }
		} else if ( 'd' === $type ) {
			if ( ! preg_match( '/^-?[0-9]+(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?$/D', $value ) ||
				! is_finite( (float) $value ) || serialize( (float) $value ) !== 'd:' . $value . ';' ) { $this->fail(); }
		} else {
			if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || (string) (int) $value !== $value || (int) $value < 0 ) { $this->fail(); }
			$count = (int) $value;
			if ( 'a' === $type && ( ( 1 === $this->state['nodes'] && $count > 100 ) || $count > ( $this->length - $this->state['offset'] ) / 4 ) ) { $this->fail(); }
			$this->state['remaining'] = $count;
			$this->state['phase'] = 'a' === $type ? 'open' : 'quote';
		}
	}

	private function fail() {
		throw new \RuntimeException( 'Invalid or unbounded structural scan continuation. Retain source authority.' );
	}
}
