<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageMaintenanceGateTest.php';
require_once __DIR__ . '/MigrationFlowTest.php';

class StorageSettingsCallbackDrainTest extends TestCase {
	private $engine;
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		foreach ( array( 'options', 'posts', 'post_meta', 'post_meta_updates', 'plugins' ) as $name ) { $GLOBALS['foogallery_migrate_test_' . $name] = array(); }
		$this->engine = new MigratorEngine();
		$GLOBALS['foogallery_migrate_engine_instance'] = $this->engine;
	}
	protected function tearDown(): void {
		StorageMaintenanceGate::current()->shutdown_writer();
		foreach ( array( 'default_template_callback', 'gallery_setting_callback', 'meta_read_callback' ) as $name ) { unset( $GLOBALS['foogallery_migrate_test_' . $name] ); }
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_engine_instance'] );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
	}
	public static function losses(): array { return array( array( 'pin' ), array( 'blog' ) ); }
	private function lose( $kind ) {
		if ( 'pin' === $kind ) { $GLOBALS['wpdb']->current = false; }
		else { $GLOBALS['foogallery_migrate_test_blog_id'] = 2; }
	}
	/** @dataProvider losses */
	public function test_template_loss_refuses_fallback_callback( $kind ): void {
		$calls = 0;
		$GLOBALS['foogallery_migrate_test_default_template_callback'] = function () use ( &$calls ) { $calls++; };
		$source = new SettingsDrainSource();
		$source->template_callback = function () use ( $kind ) { $this->lose( $kind ); return ''; };
		$object = new Gallery( $source ); $object->ID = 880; $object->title = 'Settings drain';
		$caught = null;
		try { $object->create_new_migrated_object(); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( 0, $calls, 'No following fallback callback after loss.' );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
		$this->assertFalse( $object->migrated );
	}
	/** @dataProvider losses */
	public function test_initial_settings_loss_refuses_adapter_callback( $kind ): void {
		$source = new SettingsDrainSource();
		$GLOBALS['foogallery_migrate_test_gallery_setting_callback'] = function () use ( $kind ) { $this->lose( $kind ); };
		$object = new Gallery( $source ); $object->ID = 881; $object->title = 'Settings drain';
		$caught = null;
		try { $object->create_new_migrated_object(); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( 0, $source->settings_calls, 'No following adapter callback after loss.' );
		$this->assertCount( 2, $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
	}
	/** @dataProvider losses */
	public function test_empty_album_setting_loss_refuses_next_read( $kind ): void {
		$calls = array();
		$GLOBALS['foogallery_migrate_test_meta_read_callback'] = function ( $id, $key ) use ( $kind, &$calls ) { $calls[] = $key; $this->lose( $kind ); };
		$object = new Album( new SettingsDrainSource() ); $object->migrated_id = 882;
		$caught = null;
		try { $object->inherit_album_settings_from( 883 ); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( array( FOOGALLERY_META_SETTINGS_OLD ), $calls, 'Empty values must not skip authority rechecks.' );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
	}
}

class SettingsDrainSource extends FakeSourcePlugin {
	public $template_callback;
	public $settings_calls = 0;
	public function get_gallery_template( $gallery ) { return $this->template_callback ? call_user_func( $this->template_callback ) : 'default'; }
	public function get_gallery_settings( $gallery, $settings ) { $this->settings_calls++; return $settings; }
}
