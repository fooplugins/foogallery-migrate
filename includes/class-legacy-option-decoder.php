<?php
/**
 * Object-free, bounded admission to PHP's native serialized-option decoder.
 * This validates structure only: it neither builds a graph nor streams/copies
 * records. Callers still need calibrated memory admission for the ENTIRE option.
 * Unsupported historical objects/references must retain authority, not narrow it.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyOptionDecoder {
	private $max_bytes;
	private $max_depth;
	private $max_nodes;

	public function __construct( $max_bytes = 16777216, $max_depth = 64, $max_nodes = 30000 ) {
		foreach ( array( $max_bytes, $max_depth, $max_nodes ) as $limit ) {
			if ( ! is_int( $limit ) || $limit < 1 ) { $this->fail(); }
		}
		if ( $max_depth > 64 || $max_nodes > 30000 ) { $this->fail(); }
		$this->max_bytes = $max_bytes;
		$this->max_depth = $max_depth;
		$this->max_nodes = $max_nodes;
	}

	public function decode( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > $this->max_bytes || 'a:' !== substr( $raw, 0, 2 ) ) { $this->fail(); }
		$offset = 0; $nodes = 0;
		$this->scan( $raw, $offset, 0, $nodes, false );
		if ( $offset !== strlen( $raw ) ) { $this->fail(); }
		// ALL tokens have now been proven scalar/array, including keys. No object,
		// custom serialization, enum or reference token can reach native decoding.
		// PHP5.4 lacks allowed_classes; on modern PHP keep it as defense in depth.
		$value = PHP_VERSION_ID >= 70000 ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : @unserialize( $raw );
		if ( ! is_array( $value ) || serialize( $value ) !== $raw ) { $this->fail(); }
		return $value;
	}

	private function scan( $raw, &$offset, $depth, &$nodes, $key ) {
		if ( $depth > $this->max_depth || ++$nodes > $this->max_nodes || $offset >= strlen( $raw ) ) { $this->fail(); }
		$type = $raw[$offset];
		if ( $key && 'i' !== $type && 's' !== $type ) { $this->fail(); }
		if ( 'N' === $type ) { $this->literal( $raw, $offset, 'N;' ); return; }
		$this->literal( $raw, $offset, $type . ':' );
		switch ( $type ) {
			case 'b':
				$value = $this->token( $raw, $offset, ';', 1 );
				if ( '0' !== $value && '1' !== $value ) { $this->fail(); }
				return;
			case 'i':
				$value = $this->token( $raw, $offset, ';', 21 );
				if ( ! preg_match( '/^(0|-?[1-9][0-9]*)$/D', $value ) || (string) (int) $value !== $value ) { $this->fail(); }
				return;
			case 'd':
				$value = $this->token( $raw, $offset, ';', 128 );
				if ( ! preg_match( '/^-?[0-9]+(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?$/D', $value ) || ! is_finite( (float) $value ) ) { $this->fail(); }
				return;
			case 's':
				$bytes = $this->unsigned( $raw, $offset, ':' );
				$this->literal( $raw, $offset, '"' );
				// Skip exact bytes, never look for forbidden tokens INSIDE a string.
				if ( $bytes > strlen( $raw ) - $offset ) { $this->fail(); }
				$offset += $bytes;
				$this->literal( $raw, $offset, '";' );
				return;
			case 'a':
				$count = $this->unsigned( $raw, $offset, ':' );
				// Each entry requires a key and value node. Check before the loop,
				// even when a malicious count has no actual serialized entries.
				if ( ( 0 === $depth && $count > 100 ) || $count > ( $this->max_nodes - $nodes ) / 2 ) { $this->fail(); }
				$this->literal( $raw, $offset, '{' );
				for ( $i = 0; $i < $count; ++$i ) {
					$this->scan( $raw, $offset, $depth + 1, $nodes, true );
					$this->scan( $raw, $offset, $depth + 1, $nodes, false );
				}
				$this->literal( $raw, $offset, '}' );
				return;
		}
		$this->fail();
	}

	private function unsigned( $raw, &$offset, $terminator ) {
		$value = $this->token( $raw, $offset, $terminator, 20 );
		if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || (string) (int) $value !== $value || (int) $value < 0 ) { $this->fail(); }
		return (int) $value;
	}

	private function token( $raw, &$offset, $terminator, $max_bytes ) {
		$start = $offset;
		while ( $offset < strlen( $raw ) && $offset - $start <= $max_bytes ) {
			if ( $raw[$offset] === $terminator ) {
				$value = substr( $raw, $start, $offset - $start ); ++$offset;
				return $value;
			}
			++$offset;
		}
		$this->fail();
	}

	private function literal( $raw, &$offset, $literal ) {
		if ( substr( $raw, $offset, strlen( $literal ) ) !== $literal ) { $this->fail(); }
		$offset += strlen( $literal );
	}

	private function fail() {
		throw new \RuntimeException( 'Unsupported or unbounded serialized option. Retain recorded authority and all storage evidence.' );
	}
}
