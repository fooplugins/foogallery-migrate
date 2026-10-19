<?php
/** Closed fixed-size conversion protocol; never record keys/payload or a continuation stack. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class StorageCopyCursor {
	public static function initial( $r ) {
		return array( 'state_order' => 0, 'state_id' => 0, 'staged_generation' => 1, 'expected_revision' => 0, 'next_root_ordinal' => 0, 'next_node_ordinal' => 0,
			'committed_rows' => 0, 'committed_bytes' => 0, 'committed_tokens' => 0, 'completed_heads_digest' => hash( 'sha256', "conversion-heads-v1\0" ) );
	}
	public static function validate( $c, $r ) {
		$keys = array( 'state_order', 'state_id', 'staged_generation', 'expected_revision', 'next_root_ordinal', 'next_node_ordinal',
			'committed_rows', 'committed_bytes', 'committed_tokens', 'completed_heads_digest' );
		if ( ! is_array( $c ) || array_keys( $c ) !== $keys || json_encode( $c ) === false || strlen( json_encode( $c ) ) > 1024 ||
			1 !== $c['staged_generation'] || 0 !== $c['expected_revision'] ) { self::fail(); }
		foreach ( array( 'state_order', 'state_id', 'next_root_ordinal', 'next_node_ordinal', 'committed_rows', 'committed_bytes', 'committed_tokens' ) as $key ) {
			if ( ! is_int( $c[$key] ) || $c[$key] < 0 ) { self::fail(); }
		}
		if ( $c['state_order'] > 100 || $c['next_root_ordinal'] > 24576 || $c['next_node_ordinal'] > 24576 ||
			$c['committed_rows'] > 24576 || $c['committed_bytes'] > 16777216 || $c['committed_tokens'] > 262144 ||
			( 0 === $c['state_id'] && ( 0 !== $c['next_root_ordinal'] || 0 !== $c['next_node_ordinal'] ) ) ||
			! is_string( $c['completed_heads_digest'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $c['completed_heads_digest'] ) ) { self::fail(); }
		return $c;
	}
	private static function fail() { throw new \RuntimeException( 'Invalid conversion-owned fixed copy cursor; preserve authority and durable prefix.' ); }
}
