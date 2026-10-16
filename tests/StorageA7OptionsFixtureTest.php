<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageA7Fixture.php';

/** Host only: no WP bootstrap, SQL, provider, profile or native acceptance. */
class StorageA7OptionsFixtureTest extends TestCase {
	private function fixture() {
		require_once __DIR__ . '/support/StorageA7OptionsFixture.php';
		return StorageA7OptionsFixture::value();
	}

	public function test_frozen_identity_and_manifest(): void {
		$value = $this->fixture(); $raw = serialize( $value );
		$this->assertSame( 2998293, strlen( $raw ) );
		$this->assertSame( '60392c9d095f334fed1475ccd5cd1e3db5ed1142922627980f5d0bff64072297', hash( 'sha256', $raw ) );
		$this->assertSame( '0b77be8fa38731750a46d6ba9afb96fd6d656738025b02a193acb44aa180ee6c', ( new LegacyStateCodec() )->snapshot_digest( $value ) );
		$this->assertSame( StorageA7OptionsFixture::manifest(), json_decode( file_get_contents( __DIR__ . '/integration/storage-a7-options-manifest.json' ), true ) );
		StorageA7OptionsFixture::verify( $value );
		$this->assertSame( $raw, serialize( unserialize( $raw, array( 'allowed_classes' => false ) ) ) );
	}

	public function test_only_declared_208_bytes_and_leaves_change(): void {
		$original = StorageA7Fixture::value( 20000 ); $before = serialize( $original ); $value = $this->fixture(); $after = serialize( $value );
		$this->assertSame( '6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be', hash( 'sha256', $before ) );
		$this->assertSame( 'a3d122b4a3219a31ffefd28d1063f753caeb289eefc3597b38582ab1090ff695', ( new LegacyStateCodec() )->snapshot_digest( $original ) );
		$leaves = 0; $tokens = 0; $arrays = 0; $depth = 0;
		$walk = function ( $a, $b, $level ) use ( &$walk, &$leaves, &$tokens, &$arrays, &$depth ) {
			++$tokens; $depth = max( $depth, $level );
			$this->assertSame( gettype( $a ), gettype( $b ) );
			if ( is_array( $a ) ) {
				++$arrays; $this->assertSame( array_keys( $a ), array_keys( $b ) );
				foreach ( $a as $key => $child ) { ++$tokens; $walk( $child, $b[$key], $level + 1 ); }
			} elseif ( is_string( $a ) && false !== strpos( $a, "\xff" ) ) {
				++$leaves; $this->assertSame( str_replace( "\xff", 'x', $a ), $b );
			} else { $this->assertSame( serialize( $a ), serialize( $b ) ); }
		};
		$walk( $original, $value, 0 );
		$this->assertSame( array( 208, 204995, 20834, 8 ), array( $leaves, $tokens, $arrays, $depth ) );
		$diff = 0; $first = null;
		$this->assertSame( strlen( $before ), strlen( $after ) );
		for ( $i = 0, $length = strlen( $before ); $i < $length; ++$i ) {
			if ( $before[$i] !== $after[$i] ) { ++$diff; if ( null === $first ) { $first = $i; } $this->assertSame( "\xff", $before[$i] ); $this->assertSame( 'x', $after[$i] ); }
		}
		$this->assertSame( array( 208, 440 ), array( $diff, $first ) );
		$this->assertSame( 208, substr_count( $after, "\x00" ) );
		$this->assertSame( 1, preg_match( '//u', $after ) );
		$this->assertSame( $before, serialize( $original ) );
		$this->assertSame( $before, serialize( StorageA7Fixture::value( 20000 ) ) );
	}

	/** @dataProvider mutation_cases */
	public function test_identity_rejects_mutation_without_repair( $mutation ): void {
		$value = $this->fixture(); $gallery =& $value['galleries']['items']['gallery-009']; $image =& $gallery['children']['image-000'];
		switch ( $mutation ) {
			case 'key': $gallery['children'] = array_values( $gallery['children'] ); break;
			case 'order': $gallery['children'] = array_reverse( $gallery['children'], true ); break;
			case 'unicode': $image['unknown']['unicode'] = 'snow'; break;
			case 'nul': $image['unknown']['binary'] = 'xx'; break;
			case 'zero': $image['unknown']['zero'] = 0.0; break;
			case 'unknown': unset( $image['unknown']['09'] ); break;
			case 'nesting': unset( $image['children']['boundary'] ); break;
			case 'url': $image['source_url'] .= '?changed'; break;
			case 'id': $image['ID'] = 0; break;
			case 'target': $image['unknown']['binary'] = "?\x00"; break;
		}
		$raw = serialize( $value );
		try { StorageA7OptionsFixture::verify( $value ); $this->fail( 'Mutation accepted: ' . $mutation ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Positive corpus identity mismatch.', $error->getMessage() ); }
		$this->assertSame( $raw, serialize( $value ) );
	}
	public static function mutation_cases(): array {
		return array_map( function ( $key ) { return array( $key ); }, array( 'key', 'order', 'unicode', 'nul', 'zero', 'unknown', 'nesting', 'url', 'id', 'target' ) );
	}

	/** @dataProvider decoder_cases */
	public function test_both_default_20k_decoders_still_refuse( $positive ): void {
		$value = $positive ? $this->fixture() : StorageA7Fixture::value( 20000 );
		$this->expectException( \RuntimeException::class ); ( new LegacyOptionDecoder() )->decode( serialize( $value ) );
	}
	public static function decoder_cases(): array { return array( array( false ), array( true ) ); }
}
