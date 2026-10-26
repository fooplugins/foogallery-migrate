<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

class ProductionLoaderTest extends TestCase {
	public function test_actual_entrypoint_uses_only_the_native_loader(): void {
		foreach ( array( 'nonadmin', 'admin', 'unsupported-wp' ) as $mode ) {
			$output = array();
			$exit = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/production-loader-regression.php' ) . ' ' . escapeshellarg( dirname( __DIR__ ) ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $output, $exit );
			$this->assertSame( 0, $exit, implode( "\n", $output ) );
			$result = json_decode( implode( "\n", $output ), true );
			$this->assertSame( 'pass', $result['status'] );
		}
	}

	public function test_zip_task_explicitly_excludes_development_vendor(): void {
		$this->assertStringContainsString( "'!./{vendor,vendor/**/*}'", file_get_contents( dirname( __DIR__ ) . '/gulpfile.babel.js' ) );
	}

	public function test_composer_has_no_production_dependencies(): void {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/composer.json' ), true );
		$this->assertEmpty( isset( $manifest['require'] ) ? $manifest['require'] : array(), 'New production dependencies need an explicit loader/package design.' );
		$this->assertArrayHasKey( 'phpunit/phpunit', $manifest['require-dev'] );
	}
}
