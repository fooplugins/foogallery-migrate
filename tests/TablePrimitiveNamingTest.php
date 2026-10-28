<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\TableReader;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use PHPUnit\Framework\TestCase;

class TablePrimitiveNamingTest extends TestCase {
	/** @dataProvider names */
	public function test_current_names_have_matching_files_without_legacy_aliases( $name, $file, $previous ): void {
		$class = new \ReflectionClass( $name );
		$this->assertSame( $name, $class->getName() );
		$this->assertSame( realpath( FOOGM_DIR . '/includes/class-' . $file . '.php' ), $class->getFileName() );
		$this->assertFalse( class_exists( $previous, false ) );
		$this->assertFileDoesNotExist( FOOGM_DIR . '/includes/class-legacy-' . $file . '.php' );
	}

	public static function names(): array {
		return array(
			array( TableReader::class, 'table-reader', 'FooPlugins\\FooGalleryMigrate\\LegacyTableReader' ),
			array( TableSnapshot::class, 'table-snapshot', 'FooPlugins\\FooGalleryMigrate\\LegacyTableSnapshot' ),
		);
	}

	public function test_runtime_fingerprint_uses_the_renamed_files(): void {
		$had_version = array_key_exists( 'wp_version', $GLOBALS );
		$previous = $had_version ? $GLOBALS['wp_version'] : null;
		$GLOBALS['wp_version'] = '6.2';
		try {
			$identity = StorageCalibration::identity();
			$this->assertIsArray( $identity );
			foreach ( array( 'table-reader', 'table-snapshot' ) as $file ) {
				$this->assertSame( hash_file( 'sha256', FOOGM_DIR . '/includes/class-' . $file . '.php' ), $identity[$file] );
				$this->assertArrayNotHasKey( 'legacy-' . $file, $identity );
			}
		} finally {
			if ( $had_version ) { $GLOBALS['wp_version'] = $previous; }
			else { unset( $GLOBALS['wp_version'] ); }
		}
	}
}
