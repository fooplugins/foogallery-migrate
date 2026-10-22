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
	private $scale;
	private $max_heads;
	private $statistics;
	private $decoding = false;

	public function __construct( $max_bytes = 16777216, $max_depth = 64, $max_nodes = 30000, $scale = null ) {
		foreach ( array( $max_bytes, $max_depth, $max_nodes ) as $limit ) {
			if ( ! is_int( $limit ) || $limit < 1 ) { $this->fail(); }
		}
		if ( null !== $scale && ! $scale instanceof StorageScaleAdmission ) { $this->fail(); }
		if ( $max_depth > 64 || ( null === $scale && $max_nodes > 30000 ) ||
			( null !== $scale && ( $max_nodes > $scale->limit( 'max_tokens' ) || $max_depth > $scale->limit( 'max_depth' ) || $max_bytes > $scale->raw_bytes() ) ) ) { $this->fail(); }
		$this->scale = $scale;
		$this->max_heads = null === $scale ? 100 : $scale->limit( 'max_heads' );
		$this->max_bytes = $max_bytes;
		$this->max_depth = $max_depth;
		$this->max_nodes = $max_nodes;
	}

	public function decode( $raw ) {
		if ( $this->decoding ) { $this->fail(); } $this->decoding = true;
		try { $value = $this->decode_owned( $raw ); $this->decoding = false; return $value; }
		catch ( \Exception $error ) { $this->decode_failed(); throw $error; }
		catch ( \Throwable $error ) { $this->decode_failed(); throw $error; }
	}
	private function decode_failed() {
		$this->decoding = false;
		if ( null !== $this->scale && null !== $this->scale->capacity() ) { $this->scale->capacity()->abort(); }
	}
	private function decode_owned( $raw ) {
		$capacity = null === $this->scale ? null : $this->scale->capacity();
		// The release's fixed phase headroom covers the structural scan as well
		// as native hydration. Older diagnostic classes still use parsed dimensions.
		$release = null !== $capacity && $capacity->release_policy();
		$token = $release ? $capacity->open( $this, 'option_decode', array( 'R' => is_string( $raw ) ? strlen( $raw ) : 0 ) ) : null;
		$statistics = $this->structural_statistics( $raw );
		if ( null !== $capacity ) {
			if ( $release ) { $capacity->dimensions( $statistics ); }
			else { $token = $capacity->open( $this, 'option_decode', $statistics ); }
		}
		$value = $this->decode_native( $raw );
		if ( null !== $capacity ) { $capacity->close( $this, $token ); }
		return $value;
	}

	/** Nonmaterializing dimensions, complete before native hydration. */
	public function structural_statistics( $raw ) {
		$this->tick();
		if ( ! is_string( $raw ) || strlen( $raw ) > $this->max_bytes || 'a:' !== substr( $raw, 0, 2 ) ) { $this->fail(); }
		$this->statistics = array( 'R' => strlen( $raw ), 'T' => 0, 'S' => 0, 'H' => 0, 'D' => 0 );
		$offset = 0; $nodes = 0;
		$this->scan( $raw, $offset, 0, $nodes, false );
		if ( $offset !== strlen( $raw ) ) { $this->fail(); }
		$this->statistics['T'] = $nodes;
		return $this->statistics;
	}

	private function decode_native( $raw ) {
		if ( null !== $this->scale ) { $this->scale->admit_memory( strlen( $raw ) ); }
		// ALL tokens have now been proven scalar/array, including keys. No object,
		// custom serialization, enum or reference token can reach native decoding.
		// PHP5.4 lacks allowed_classes; on modern PHP keep it as defense in depth.
		$value = PHP_VERSION_ID >= 70000 ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : @unserialize( $raw );
		if ( null !== $this->scale ) { $this->scale->admit_memory( strlen( $raw ) ); }
		if ( ! is_array( $value ) || serialize( $value ) !== $raw ) { $this->fail(); }
		$this->tick();
		return $value;
	}

	/**
	 * Reverse conversion: prove the same structural/byte domain and admit memory
	 * BEFORE serializing the complete table-origin graph. This is not an allocator
	 * proof: measuring one finite float still uses native scalar serialization and
	 * its configured serialize_precision workspace must be covered by calibration.
	 */
	public function encode( $value, $admit ) {
		if ( null !== $this->scale ) { $this->scale->admit_memory( 0 ); }
		if ( ! is_array( $value ) || ! is_callable( $admit ) ) { $this->fail(); }
		$nodes = 0; $bytes = 0;
		$this->measure( $value, 0, $nodes, $bytes );
		call_user_func( $admit, $bytes );
		if ( null !== $this->scale ) { $this->scale->admit_memory( $bytes ); }
		$raw = serialize( $value );
		$this->tick();
		if ( ! is_string( $raw ) || strlen( $raw ) !== $bytes ) { $this->fail(); }
		// Aliased references can serialize differently from the measured logical
		// tree. Refuse those tokens without hydrating a second full graph.
		$offset = 0; $nodes = 0;
		$this->scan( $raw, $offset, 0, $nodes, false );
		if ( $offset !== $bytes ) { $this->fail(); }
		return $raw;
	}

	private function measure( $value, $depth, &$nodes, &$bytes ) {
		$this->tick();
		if ( $depth > $this->max_depth || ++$nodes > $this->max_nodes ) { $this->fail(); }
		if ( is_array( $value ) ) {
			$count = count( $value );
			if ( ( 0 === $depth && $count > $this->max_heads ) || $count > ( $this->max_nodes - $nodes ) / 2 ) { $this->fail(); }
			$this->add_bytes( 5 + strlen( (string) $count ), $bytes ); // a:<count>:{...}
			foreach ( $value as $key => $item ) {
				$this->measure( $key, $depth + 1, $nodes, $bytes );
				$this->measure( $item, $depth + 1, $nodes, $bytes );
			}
		} else if ( is_string( $value ) ) {
			$length = strlen( $value );
			$this->add_bytes( $length, $bytes );
			$this->add_bytes( 6 + strlen( (string) $length ), $bytes ); // s:<bytes>:"...";
		} else if ( is_int( $value ) ) {
			$this->add_bytes( 3 + strlen( (string) $value ), $bytes );
		} else if ( is_bool( $value ) || null === $value ) {
			$this->add_bytes( null === $value ? 2 : 4, $bytes );
		} else if ( is_float( $value ) && is_finite( $value ) ) {
			$scalar = serialize( $value );
			if ( strlen( $scalar ) > 131 ) { $this->fail(); } // same 128-byte float token cap as scan().
			$this->add_bytes( strlen( $scalar ), $bytes );
		} else { $this->fail(); }
	}

	private function add_bytes( $amount, &$bytes ) {
		if ( $amount > $this->max_bytes - $bytes ) { $this->fail(); }
		$bytes += $amount;
	}

	private function scan( $raw, &$offset, $depth, &$nodes, $key ) {
		$this->tick();
		if ( $depth > $this->max_depth || ++$nodes > $this->max_nodes || $offset >= strlen( $raw ) ) { $this->fail(); }
		if ( is_array( $this->statistics ) ) { $this->statistics['D'] = max( $this->statistics['D'], $depth ); }
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
				if ( is_array( $this->statistics ) ) { $this->statistics['S'] = max( $this->statistics['S'], $bytes ); }
				$this->literal( $raw, $offset, '"' );
				// Skip exact bytes, never look for forbidden tokens INSIDE a string.
				if ( $bytes > strlen( $raw ) - $offset ) { $this->fail(); }
				$offset += $bytes;
				$this->literal( $raw, $offset, '";' );
				return;
			case 'a':
				$count = $this->unsigned( $raw, $offset, ':' );
				if ( 0 === $depth && is_array( $this->statistics ) ) { $this->statistics['H'] = $count; }
				// Each entry requires a key and value node. Check before the loop,
				// even when a malicious count has no actual serialized entries.
				if ( ( 0 === $depth && $count > $this->max_heads ) || $count > ( $this->max_nodes - $nodes ) / 2 ) { $this->fail(); }
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

	private function tick() { if ( null !== $this->scale ) { $this->scale->tick(); } }

	private function fail() {
		throw new \RuntimeException( 'Unsupported or unbounded serialized option. Retain recorded authority and all storage evidence.' );
	}
}
