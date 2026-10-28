<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\OptionMigratedStore;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageAuthorityTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EngineExceptionBoundaryTest extends TestCase {
	/** @dataProvider engine_paths */
	public function test_wp_error_exceptions_stay_plain_data_until_rendered( $path ): void {
		require __DIR__ . '/fixtures/release-hardening-translations.php';
		$GLOBALS['wpdb'] = new AuthorityTestWpdb(); $GLOBALS['wpdb']->fail_probe = true;
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		try {
			if ( 'construct' === $path ) { new MigratorEngine(); }
			else {
				$engine = new MigratorEngine( new OptionMigratedStore() );
				$property = new \ReflectionProperty( $engine, 'storage_guarded' ); $property->setAccessible( true ); $property->setValue( $engine, true );
				$property = new \ReflectionProperty( $engine, 'storage_gate' ); $property->setAccessible( true ); $property->setValue( $engine, StorageMaintenanceGate::current() );
				$engine->$path();
			}
			$this->fail( 'Expected refused storage operation.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( '<em data-domain=', $error->getMessage(), 'Do not HTML-escape exception data prematurely.' );
			$status = array( 'backend' => 'unavailable', 'phase' => 'unavailable', 'target' => 'option', 'retained' => false, 'discardable' => false,
				'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => $error->getMessage(), 'calibrated' => true );
			ob_start(); try { StorageAdmin::render_troubleshooting( $status ); $html = ob_get_clean(); }
			catch ( \Throwable $failure ) { ob_end_clean(); throw $failure; }
			$this->assertStringContainsString( esc_html( $error->getMessage() ), $html );
			$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
			$this->assertSame( 0, $xpath->query( '//em[@data-domain]' )->length );
			$this->assertStringNotContainsString( '&amp;lt;em', $html );
		}
	}
	public static function engine_paths(): array { return array( array( 'construct' ), array( 'assert_storage_writer' ), array( 'clear_migrator_setting' ) ); }
}
