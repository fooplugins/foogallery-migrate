<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageMaintenanceGateTest.php';
require_once __DIR__ . '/MigrationFlowTest.php';

class StorageOutputDrainTest extends TestCase {
	private $engine;
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		foreach ( array( 'options', 'posts', 'post_meta', 'post_meta_updates', 'plugins' ) as $name ) {
			$GLOBALS['foogallery_migrate_test_' . $name] = array();
		}
		$this->engine = new MigratorEngine();
		$GLOBALS['foogallery_migrate_engine_instance'] = $this->engine;
	}
	protected function tearDown(): void {
		StorageMaintenanceGate::current()->shutdown_writer();
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_engine_instance'], $GLOBALS['foogallery_migrate_test_post_insert_callback'], $GLOBALS['foogallery_migrate_test_meta_update_callback'], $GLOBALS['foogallery_migrate_test_option_update_callback'] );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
	}
	private function object( $class ) {
		$object = new $class( new FakeSourcePlugin() );
		$object->ID = 710;
		$object->title = 'Output drain fixture';
		$child = new Image( $object->plugin );
		$child->ID = 711;
		$child->migrated = true;
		$child->migrated_id = 712;
		$object->children = array( $child );
		return $object;
	}
	public static function output_classes(): array { return array( array( Gallery::class ), array( Album::class ) ); }
	/** @dataProvider output_classes */
	public function test_post_callback_remains_active_after_nested_batch_flush( $class ): void {
		$GLOBALS['foogallery_migrate_test_post_insert_callback'] = function () {
			$this->engine->begin_migrated_object_batch();
			$this->assertTrue( $this->engine->flush_migrated_object_batch() );
			$this->assertFalse( $this->engine->storage_is_quiescent() );
			$this->assertFalse( MigratorEngine::storage_maintenance_is_quiescent() );
		};
		$this->object( $class )->migrate();
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	/** @dataProvider output_classes */
	public function test_post_callback_pin_loss_refuses_metadata_and_history( $class ): void {
		$GLOBALS['foogallery_migrate_test_post_insert_callback'] = function () { $GLOBALS['wpdb']->current = false; };
		$object = $this->object( $class );
		$caught = null;
		try { $object->migrate(); }
		catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_posts'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
		$this->assertFalse( $object->migrated );
		$this->assertArrayNotHasKey( FOOGALLERY_MIGRATE_OPTION_DATA, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertFalse( $this->engine->storage_is_quiescent() );
	}
	public function test_gallery_first_metadata_pin_loss_refuses_next_metadata_and_history(): void {
		$GLOBALS['foogallery_migrate_test_meta_update_callback'] = function () { $GLOBALS['wpdb']->current = false; };
		$caught = null;
		try { $this->object( Gallery::class )->migrate(); }
		catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
		$this->assertArrayNotHasKey( FOOGALLERY_MIGRATE_OPTION_DATA, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_gallery_source_settings_pin_loss_refuses_settings_metadata(): void {
		$object = $this->object( Gallery::class );
		$object->plugin = new OutputPinLosingSourcePlugin();
		$caught = null;
		try { $object->migrate(); }
		catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_posts'] );
		$this->assertCount( 2, $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
		$this->assertFalse( $object->migrated );
	}
	public function test_changed_blog_in_post_callback_refuses_metadata(): void {
		$GLOBALS['foogallery_migrate_test_post_insert_callback'] = function () { $GLOBALS['foogallery_migrate_test_blog_id'] = 2; };
		$caught = null;
		try { $this->object( Album::class )->migrate(); }
		catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
	}
	public static function callback_errors(): array { return array( array( new \RuntimeException( 'output exception' ) ), array( new \Error( 'output error' ) ) ); }
	/** @dataProvider callback_errors */
	public function test_output_throw_unwinds_depth_without_history( $error ): void {
		$GLOBALS['foogallery_migrate_test_post_insert_callback'] = function () use ( $error ) { throw $error; };
		$caught = null;
		try { $this->object( Gallery::class )->migrate(); }
		catch ( \Throwable $exception ) { $caught = $exception; }
		$this->assertSame( $error, $caught );
		$this->assertTrue( $this->engine->storage_is_quiescent() );
		$this->assertArrayNotHasKey( FOOGALLERY_MIGRATE_OPTION_DATA, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_ordinary_option_write_callback_cannot_affirm_drain(): void {
		$GLOBALS['foogallery_migrate_test_option_update_callback'] = function ( $name ) {
			if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) {
				$this->assertFalse( $this->engine->storage_is_quiescent() );
			}
		};
		$this->assertTrue( $this->engine->set_migrator_setting( 'output-fixture', 1 ) );
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	public function test_ordinary_option_write_pin_loss_cannot_return_success(): void {
		$GLOBALS['foogallery_migrate_test_option_update_callback'] = function ( $name ) {
			if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $GLOBALS['wpdb']->current = false; }
		};
		$caught = null;
		try { $this->engine->set_migrator_setting( 'output-fixture', 1 ); }
		catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertFalse( $this->engine->storage_is_quiescent() );
	}
}

class OutputPinLosingSourcePlugin extends FakeSourcePlugin {
	public function get_gallery_settings( $gallery, $default_settings ) {
		$GLOBALS['wpdb']->current = false;
		return $default_settings;
	}
}
