<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageReleaseNativeFixture.php';
require_once __DIR__ . '/support/StorageA7OptionsFixture.php';

/** Corpus identities must not be silently repaired to get a different runtime green. */
class StorageReleaseNativeFixtureTest extends TestCase {
	public function test_native_and_modern_text_corpora_have_distinct_pinned_identities(): void {
		$this->assertNotSame( StorageA7OptionsFixture::NAME, StorageReleaseNativeFixture::NAME );
		$this->assertNotSame( StorageA7OptionsFixture::RAW_BYTES, StorageReleaseNativeFixture::RAW_BYTES );
		$this->assertNotSame( StorageA7OptionsFixture::RAW_SHA256, StorageReleaseNativeFixture::RAW_SHA256 );
		$this->assertSame( 2998086, StorageReleaseNativeFixture::RAW_BYTES );
		$this->assertSame( 'b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677', StorageReleaseNativeFixture::RAW_SHA256 );
	}

	public function test_non_native_runtime_cannot_receive_the_native_fixture(): void {
		if ( in_array( PHP_VERSION, array( '5.4.0', '5.6.20' ), true ) ) {
			$this->markTestSkipped( 'Exact legacy corpus creation/SQL assertions run in the literal-floor integration probe.' );
		}
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Use the separately pinned modern corpus on other runtimes.' );
		StorageReleaseNativeFixture::value();
	}

	public function test_original_binary_representative_size_and_leaves_are_unchanged(): void {
		$value = StorageA7Fixture::value( 20000 );
		$gallery = $value['galleries']['items']['gallery-009'];
		$this->assertCount( 20000, $gallery['children'] );
		$this->assertSame( "\x00\xff", $gallery['unknown'][2] );
		$this->assertSame( "\xff\x00", $gallery['children']['image-000']['unknown']['binary'] );
	}
}
