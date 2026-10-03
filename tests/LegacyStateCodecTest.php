<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;

class LegacyStateCodecTest extends TestCase {
	public function test_compact_values_round_trip_without_normalizing_keys_bytes_or_order(): void {
		$codec = new LegacyStateCodec();
		$value = array( 9 => false, '009' => null, -2 => "\xff\x00雪", '' => array(),
			'unknown' => array( 'children' => array( '03' => array( 'extra' => true ), 3 => 'last' ) ),
			'integer' => PHP_INT_MAX, 'negative' => PHP_INT_MIN, 'float' => 0.1, 'zero' => -0.0 );
		$encoded = $codec->encode( $value );
		$this->assertSame( $value, $codec->decode( $encoded ) );
		$this->assertSame( pack( 'd', -0.0 ), pack( 'd', $codec->decode( $encoded )['zero'] ) );
		$this->assertSame( $encoded, $codec->encode( $codec->decode( $encoded ) ) );
	}

	public function test_hashes_are_exact_typed_ordered_and_parent_scoped(): void {
		$codec = new LegacyStateCodec();
		$this->assertNotSame( $codec->key_hash( 7 ), $codec->key_hash( '007' ) );
		$this->assertNotSame( $codec->key_hash( 'MixedCase' ), $codec->key_hash( 'mixedcase' ) );
		$this->assertNotSame( $codec->logical_hash( array( 'a' => 1, 'b' => 2 ) ), $codec->logical_hash( array( 'b' => 2, 'a' => 1 ) ) );
		$state = $codec->key_hash( 'migrated' );
		$root = $codec->node_hash( $state, null, 9 );
		$this->assertSame( 64, strlen( $root ) );
		$this->assertNotSame( $root, $codec->node_hash( $state, $root, 9 ) );
	}

	/** @dataProvider unsupportedValues */
	public function test_unsupported_values_fail_closed( $value ): void {
		$this->expectException( \RuntimeException::class );
		( new LegacyStateCodec() )->encode( $value );
	}

	public static function unsupportedValues(): array {
		return array( array( new \stdClass() ), array( NAN ), array( INF ) );
	}

	/** @dataProvider corruptEnvelopes */
	public function test_corrupt_envelopes_fail_closed( $json ): void {
		$this->expectException( \RuntimeException::class );
		( new LegacyStateCodec() )->decode( $json );
	}

	public static function corruptEnvelopes(): array {
		return array(
			array( '{"version":1,"value":[true,true]}' ),
			array( '{"version":1,"value":[0,false]}' ),
			array( '{"version":999,"value":["null"]}' ),
			array( '{"version":1,"value":["string","@@"]}' ),
			array( '{"version":1,"value":["integer","01"]}' ),
			array( '{"version":1,"value":["array",[[["integer","1"],["null"]],[["integer","1"],["boolean",false]]]]}' ),
			array( '{"version":1,"value":["array",[[["string","MQ=="],["null"]]]]}' ),
			array( '{"version":1,"value":["null"],"unknown":true}' ),
			array( 'not JSON' )
		);
	}

	public function test_encoded_bytes_are_measured_before_decode(): void {
		$codec = new LegacyStateCodec( 100 );
		$this->expectException( \RuntimeException::class );
		$codec->decode( str_repeat( ' ', 101 ) );
	}

	public function test_encoding_has_byte_depth_and_node_limits(): void {
		foreach ( array( array( new LegacyStateCodec( 100 ), str_repeat( 'x', 100 ) ),
			array( new LegacyStateCodec( 1000, 2 ), array( array( array( true ) ) ) ),
			array( new LegacyStateCodec( 1000, 64, 2 ), array( true, false ) ) ) as $case ) {
			try {
				$case[0]->encode( $case[1] );
				$this->fail( 'Codec budget must refuse this input.' );
			} catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'storage', $error->getMessage() );
			}
		}
	}
}
