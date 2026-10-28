<?php
/** Native-text corpus on literal PHP5. NOT byte/semantic equivalence to the PHP8 corpus. */
namespace FooPlugins\FooGalleryMigrate\Tests;
require_once __DIR__ . '/StorageA7Fixture.php';

class StorageReleaseNativeFixture {
	const NAME = 'a7-options-native-php5-20k-v1';
	const RAW_BYTES = 2998086;
	const RAW_SHA256 = 'b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677';

	public static function value() {
		if ( ! in_array( PHP_VERSION, array( '5.4.0', '5.6.20' ), true ) ) {
			throw new \RuntimeException( 'Use the separately pinned modern corpus on other runtimes.' );
		}
		$value = StorageA7Fixture::value( 20000 );
		// Literal PHP5 serializes the factory's -0.0 as d:0;, unlike PHP8 d:-0;.
		// This was observed independently, not repaired in the original fixture.
		if ( 'd:0;' !== serialize( -0.0 ) || hash( 'sha256', serialize( $value ) ) !==
			'049c6beb6ee9064fa33561b98050016b5aa9e0db5211c827c3cf3612119feca5' ) {
			throw new \RuntimeException( 'Native PHP5 corpus identity mismatch.' );
		}
		$gallery =& $value['galleries']['items']['gallery-009']; $changes = 0;
		foreach ( $gallery['children'] as &$image ) {
			if ( isset( $image['unknown'] ) ) {
				if ( "\xff\x00" !== $image['unknown']['binary'] ) { throw new \RuntimeException( 'Unexpected native leaf.' ); }
				$image['unknown']['binary'] = "x\x00"; ++$changes;
			}
		}
		unset( $image );
		if ( "\x00\xff" !== $gallery['unknown'][2] ) { throw new \RuntimeException( 'Unexpected native gallery leaf.' ); }
		$gallery['unknown'][2] = "\x00x"; ++$changes; unset( $gallery );
		$raw = serialize( $value );
		if ( 208 !== $changes || strlen( $raw ) !== self::RAW_BYTES || hash( 'sha256', $raw ) !== self::RAW_SHA256 ) {
			throw new \RuntimeException( 'Native-text fixture drift.' );
		}
		return $value;
	}
}
