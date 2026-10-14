<?php
/**
 * Lossless envelope for already-compact legacy scalar/array values.
 * No runtime objects are serialized. Unsupported historical objects must stop
 * conversion, not be silently narrowed to the compact-v1 whitelist.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyStateCodec {
	const VERSION = 1;
	private $max_bytes;
	private $max_depth;
	private $max_nodes;

	/** Limits apply to one record, not an entire collection or migration. */
	public function __construct( $max_bytes = 1048576, $max_depth = 64, $max_nodes = 10000 ) {
		foreach ( array( $max_bytes, $max_depth, $max_nodes ) as $limit ) {
			if ( ! is_int( $limit ) || $limit < 1 ) {
				$this->fail( 'Invalid codec budget.' );
			}
		}
		if ( $max_depth > 64 ) {
			$this->fail( 'Codec nesting budget exceeds the supported envelope.' );
		}
		$this->max_bytes = $max_bytes;
		$this->max_depth = $max_depth;
		$this->max_nodes = $max_nodes;
	}

	public function encode( $value ) {
		$nodes = 0;
		$bytes = 0;
		$envelope = array( 'version' => self::VERSION, 'value' => $this->tag( $value, 0, $nodes, $bytes ) );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $envelope ) : json_encode( $envelope );
		if ( ! is_string( $json ) || strlen( $json ) > $this->max_bytes ) {
			$this->fail( 'Record exceeds the encoded byte budget.' );
		}
		return $json;
	}

	public function decode( $json ) {
		$this->inspect_json( $json );
		$envelope = json_decode( $json, true, 512 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $envelope ) ||
			array_keys( $envelope ) !== array( 'version', 'value' ) || self::VERSION !== $envelope['version'] ) {
			$this->fail( 'Unsupported or malformed record envelope.' );
		}
		$nodes = 0;
		return $this->untag( $envelope['value'], 0, $nodes );
	}

	/**
	 * Bound native JSON work BEFORE hydration, including rejected prefixes and
	 * duplicate keys. This constant-space lexical pass is NOT a JSON validator
	 * or memory admission: native syntax and logical checks still follow it.
	 * Each logical tag contributes at most three tokens; each entry contributes
	 * one pair container. Four envelope tokens + 4N is a conservative ceiling.
	 * A logical nesting edge adds three JSON containers; an empty array adds one.
	 */
	public function inspect_json( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > $this->max_bytes || strlen( $json ) > 2147483646 ) {
			$this->fail( 'Record exceeds the encoded byte budget.' );
		}
		// Avoid native integer overflow even for caller-supplied PHP_INT_MAX.
		$limit = $this->max_nodes > ( ( PHP_INT_MAX - 4 ) >> 2 ) ? PHP_INT_MAX : 4 * $this->max_nodes + 4;
		$depth_limit = 3 * $this->max_depth + 3;
		$length = strlen( $json ); $tokens = 0; $depth = 0; $peak = 0;
		for ( $offset = 0; $offset < $length; ++$offset ) {
			$char = $json[$offset];
			if ( ' ' === $char || "	" === $char || "\r" === $char || "\n" === $char || ',' === $char || ':' === $char ) { continue; }
			if ( ']' === $char || '}' === $char ) {
				if ( $depth < 1 ) { $this->fail( 'Malformed JSON prefix.' ); }
				--$depth; continue;
			}
			if ( $tokens >= $limit ) { $this->fail( 'Record exceeds the native JSON token budget.' ); }
			++$tokens;
			if ( '[' === $char || '{' === $char ) {
				if ( ++$depth > $depth_limit ) { $this->fail( 'Record exceeds the native JSON depth budget.' ); }
				$peak = max( $peak, $depth ); continue;
			}
			if ( '"' === $char ) {
				for ( ++$offset; $offset < $length && '"' !== $json[$offset]; ++$offset ) {
					if ( '\\' === $json[$offset] ) { ++$offset; }
				}
				if ( $offset >= $length ) { $this->fail( 'Malformed JSON string prefix.' ); }
				continue;
			}
			// The v1 envelope only uses native integer 1 and boolean scalars.
			// Integers, floats, null and binary strings in logical state are TAGGED.
			// Do not let a malformed huge decimal reach malloc-backed strtod.
			$start = $offset;
			for ( ; $offset < $length; ++$offset ) {
				$char = $json[$offset];
				if ( ' ' === $char || "	" === $char || "\r" === $char || "\n" === $char || ',' === $char || ':' === $char || '[' === $char || ']' === $char || '{' === $char || '}' === $char || '"' === $char ) { break; }
				if ( $offset - $start >= 5 ) { $this->fail( 'Unsupported native JSON scalar.' ); }
			}
			$scalar = substr( $json, $start, $offset - $start );
			if ( '1' !== $scalar && 'true' !== $scalar && 'false' !== $scalar ) { $this->fail( 'Unsupported native JSON scalar.' ); }
			--$offset;
		}
		if ( 0 !== $depth ) { $this->fail( 'Malformed JSON container prefix.' ); }
		return array( 'bytes_scanned' => $length, 'tokens' => $tokens, 'max_depth' => $peak );
	}

	/** Hex hashes are passed to SQL via prepared strings and fixed UNHEX(). */
	public function logical_hash( $value ) {
		return hash( 'sha256', $this->encode( $value ) );
	}

	/**
	 * Snapshot-only canonical digest. Traverse logical PHP state in its existing
	 * order; do not encode a whole snapshot or include physical backend metadata.
	 * No aggregate byte/node cap: snapshots intentionally span many records.
	 * Depth and individual scalar byte limits still fail closed. Callers must
	 * preflight serialized options BEFORE obtaining/decoding their logical value.
	 * This domain is distinct from the physical envelope hash used above.
	 */
	public function snapshot_digest( $value ) {
		$context = hash_init( 'sha256' );
		hash_update( $context, "legacy-logical-v1\0" );
		$this->digest_value( $context, $value, 0 );
		return hash_final( $context );
	}

	private function digest_value( $context, $value, $depth ) {
		if ( $depth > $this->max_depth ) {
			$this->fail( 'Snapshot exceeds the depth budget.' );
		}
		if ( is_array( $value ) ) {
			hash_update( $context, 'array:' . count( $value ) . ':' );
			foreach ( $value as $key => $item ) {
				$this->digest_value( $context, $key, $depth + 1 );
				$this->digest_value( $context, $item, $depth + 1 );
			}
		} else {
			$encoded = $this->encode( $value );
			hash_update( $context, 'value:' . strlen( $encoded ) . ':' . $encoded );
		}
	}

	public function key_value( $key ) {
		if ( ! is_int( $key ) && ! is_string( $key ) ) {
			$this->fail( 'Array keys must be exact integers or strings.' );
		}
		return $this->encode( $key );
	}

	public function key_hash( $key ) {
		return hash( 'sha256', "legacy-key-v1\0" . $this->key_value( $key ) );
	}

	/** Stable across generations; includes state, parent and exact typed key. */
	public function node_hash( $state_hash, $parent_hash, $key ) {
		if ( ! $this->valid_hash( $state_hash ) || ( null !== $parent_hash && ! $this->valid_hash( $parent_hash ) ) ) {
			$this->fail( 'Invalid node ancestry hash.' );
		}
		$value = $this->key_value( $key );
		return hash( 'sha256', "legacy-node-v1\0" . $state_hash . ( null === $parent_hash ? 'root' : $parent_hash ) . strlen( $value ) . ':' . $value );
	}

	private function tag( $value, $depth, &$nodes, &$bytes ) {
		$this->budget( $depth, $nodes );
		if ( null === $value ) {
			return array( 'null' );
		}
		if ( is_bool( $value ) ) {
			return array( 'boolean', $value );
		}
		if ( is_int( $value ) ) {
			return array( 'integer', (string) $value );
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				$this->fail( 'Non-finite floats are unsupported.' );
			}
			// Canonical big-endian IEEE double, including negative zero; PHP 5.4
			// lacks pack("E"). Never rely on locale or JSON float precision.
			$packed = pack( 'd', $value );
			return array( 'float', base64_encode( $this->little_endian() ? strrev( $packed ) : $packed ) );
		}
		if ( is_string( $value ) ) {
			$bytes += strlen( $value );
			if ( $bytes > $this->max_bytes ) {
				$this->fail( 'Record exceeds the byte budget.' );
			}
			// All bytes are encoded, so invalid UTF-8 never gets repaired by WP.
			return array( 'string', base64_encode( $value ) );
		}
		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $key => $item ) {
				$items[] = array( $this->tag( $key, $depth + 1, $nodes, $bytes ), $this->tag( $item, $depth + 1, $nodes, $bytes ) );
			}
			return array( 'array', $items );
		}
		$this->fail( 'Unsupported non-compact object/resource. Retain option authority; a lossless legacy encoder is required.' );
	}

	private function untag( $tag, $depth, &$nodes ) {
		$this->budget( $depth, $nodes );
		if ( ! is_array( $tag ) || array_keys( $tag ) !== range( 0, count( $tag ) - 1 ) || ! isset( $tag[0] ) || ! is_string( $tag[0] ) ) {
			$this->fail( 'Malformed value tag.' );
		}
		if ( array( 'null' ) === $tag ) {
			return null;
		}
		if ( count( $tag ) !== 2 ) {
			$this->fail( 'Malformed tagged value.' );
		}
		switch ( $tag[0] ) {
			case 'boolean':
				if ( is_bool( $tag[1] ) ) {
					return $tag[1];
				}
				break;
			case 'integer':
				if ( is_string( $tag[1] ) && (string) (int) $tag[1] === $tag[1] ) {
					return (int) $tag[1];
				}
				break;
			case 'string':
				return $this->base64( $tag[1] );
			case 'float':
				$packed = $this->base64( $tag[1] );
				if ( strlen( $packed ) === 8 ) {
					$values = unpack( 'dvalue', $this->little_endian() ? strrev( $packed ) : $packed );
					if ( is_finite( $values['value'] ) ) {
						return $values['value'];
					}
				}
				break;
			case 'array':
				if ( ! is_array( $tag[1] ) || ( count( $tag[1] ) && array_keys( $tag[1] ) !== range( 0, count( $tag[1] ) - 1 ) ) ) {
					break;
				}
				$result = array();
				foreach ( $tag[1] as $item ) {
					if ( ! is_array( $item ) || array_keys( $item ) !== array( 0, 1 ) ) {
						$this->fail( 'Malformed array entry.' );
					}
					$key = $this->untag( $item[0], $depth + 1, $nodes );
					if ( ! is_int( $key ) && ! is_string( $key ) ) {
						$this->fail( 'Invalid array key type.' );
					}
					$probe = array( $key => true );
					$keys = array_keys( $probe );
					if ( $keys[0] !== $key || array_key_exists( $key, $result ) ) {
						$this->fail( 'Duplicate or coercible typed array key.' );
					}
					$result[ $key ] = $this->untag( $item[1], $depth + 1, $nodes );
				}
				return $result;
		}
		$this->fail( 'Unknown or invalid value tag.' );
	}

	private function base64( $value ) {
		if ( ! is_string( $value ) ) {
			$this->fail( 'Invalid encoded bytes.' );
		}
		$bytes = base64_decode( $value, true );
		if ( false === $bytes || base64_encode( $bytes ) !== $value ) {
			$this->fail( 'Invalid encoded bytes.' );
		}
		return $bytes;
	}

	private function budget( $depth, &$nodes ) {
		$nodes++;
		if ( $depth > $this->max_depth || $nodes > $this->max_nodes ) {
			$this->fail( 'Record exceeds the depth/node budget.' );
		}
	}

	private function little_endian() {
		return "\x01\x00\x00\x00" === pack( 'L', 1 );
	}

	private function valid_hash( $hash ) {
		return is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash );
	}

	private function fail( $message ) {
		throw new \RuntimeException( 'Legacy storage codec: ' . $message );
	}
}
