<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

class ProductionLoaderTest extends TestCase {
	public function test_actual_entrypoint_uses_only_the_native_loader(): void {
		foreach ( array(
			array( 'nonadmin', '6.2' ), array( 'admin', '6.2' ),
			array( 'nonadmin', '7.1.3' ), array( 'admin', '7.1.3' ),
			array( 'unsupported-wp', '6.1.9' ), array( 'unsupported-wp', '6.1' ),
			array( 'unsupported-wp', '6.0' ), array( 'unsupported-wp', '5.0' ),
		) as $case ) {
			list( $mode, $version ) = $case;
			$output = array();
			$exit = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/production-loader-regression.php' ) . ' ' . escapeshellarg( dirname( __DIR__ ) ) . ' ' . escapeshellarg( $mode ) . ' ' . escapeshellarg( $version ) . ' 2>&1', $output, $exit );
			$this->assertSame( 0, $exit, implode( "\n", $output ) );
			$result = json_decode( implode( "\n", $output ), true );
			$this->assertSame( 'pass', $result['status'] );
			$this->assertSame( $version, $result['wp'] );
		}
	}

	public function test_plugin_header_and_readme_declare_the_same_minimum_wordpress(): void {
		foreach ( array( 'migrate.php', 'readme.txt' ) as $file ) {
			$source = file_get_contents( dirname( __DIR__ ) . '/' . $file );
			$this->assertSame( 1, preg_match( '/^Requires at least:[\t ]*([^\r\n]+)/m', $source, $match ), $file );
			$this->assertSame( '6.2', trim( $match[1] ), $file );
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
