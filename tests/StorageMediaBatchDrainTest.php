<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageMaintenanceGateTest.php';
require_once __DIR__ . '/MigrationFlowTest.php';

class StorageMediaBatchDrainTest extends TestCase {
	private $engine;
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		foreach ( array( 'options', 'posts', 'post_meta', 'post_meta_updates', 'plugins', 'attachment_url_to_postid', 'imported_attachments', 'set_object_terms', 'object_terms' ) as $name ) { $GLOBALS['foogallery_migrate_test_' . $name] = array(); }
		$GLOBALS['foogallery_migrate_test_taxonomies'] = array( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG );
		$this->engine = new MigratorEngine();
		$GLOBALS['foogallery_migrate_engine_instance'] = $this->engine;
	}
	protected function tearDown(): void {
		StorageMaintenanceGate::current()->shutdown_writer();
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_engine_instance'], $GLOBALS['foogallery_migrate_test_terms_callback'], $GLOBALS['foogallery_migrate_test_meta_update_callback'] );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_taxonomies'] = array();
	}
	private function image( $source = null ) {
		$image = new Image( $source ? $source : new MediaDrainSource() );
		$image->source_url = 'https://example.invalid/media-drain.jpg';
		$image->date = '2026-01-01 00:00:00';
		return $image;
	}
	public function test_direct_import_callback_nested_flush_does_not_drain_output(): void {
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () {
			$this->engine->begin_migrated_object_batch();
			$this->assertTrue( $this->engine->flush_migrated_object_batch() );
			$this->assertFalse( $this->engine->storage_is_quiescent() );
		};
		$this->image()->create_new_migrated_object();
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	public function test_import_pin_loss_refuses_following_metadata(): void {
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () { $GLOBALS['wpdb']->current = false; };
		$caught = null;
		try { $this->image()->create_new_migrated_object(); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_post_meta_updates'] );
	}
	public function test_import_metadata_loss_refuses_date_and_second_term_output(): void {
		$GLOBALS['foogallery_migrate_test_meta_update_callback'] = function () { $GLOBALS['wpdb']->current = false; };
		$image = $this->image(); $caught = null;
		try { $image->create_new_migrated_object(); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_set_object_terms'] );
		$this->assertArrayNotHasKey( 'post_date', (array) $GLOBALS['foogallery_migrate_test_posts'][$image->migrated_id] );
	}
	public function test_reused_attachment_term_loss_does_not_mark_completion(): void {
		$GLOBALS['foogallery_migrate_test_attachment_url_to_postid']['https://example.invalid/media-drain.jpg'] = 456;
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () { $GLOBALS['foogallery_migrate_test_blog_id'] = 2; };
		$image = $this->image(); $caught = null;
		try { $image->create_new_migrated_object(); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertFalse( $image->migrated );
	}
	public function test_source_tag_pin_loss_refuses_external_import(): void {
		$caught = null;
		try { $this->image( new MediaDrainSource( function () { $GLOBALS['wpdb']->current = false; } ) )->create_new_migrated_object(); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_imported_attachments'] );
	}
	public function test_direct_discovery_callback_nested_flush_does_not_drain_batch_path(): void {
		$source = new MediaDrainSource( null, function () {
			$this->engine->begin_migrated_object_batch();
			$this->assertTrue( $this->engine->flush_migrated_object_batch() );
			$this->assertFalse( $this->engine->storage_is_quiescent() );
		} );
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $source );
		$this->engine->run_detection();
		$this->engine->get_gallery_migrator()->get_objects_to_migrate( true );
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	public function test_discovery_pin_loss_refuses_next_source_callback(): void {
		$calls = 0;
		$GLOBALS['foogallery_migrate_test_plugins'] = array( new MediaDrainSource( null, function () { $GLOBALS['wpdb']->current = false; } ), new MediaDrainSource( null, function () use ( &$calls ) { $calls++; } ) );
		$this->engine->run_detection(); $caught = null;
		try { $this->engine->get_gallery_migrator()->get_objects_to_migrate( true ); } catch ( \RuntimeException $error ) { $caught = $error; }
		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertSame( 0, $calls );
	}
	public static function callback_errors(): array { return array( array( new \RuntimeException( 'media failure' ) ), array( new \Error( 'media error' ) ) ); }
	/** @dataProvider callback_errors */
	public function test_direct_media_throw_unwinds_depth( $error ): void {
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () use ( $error ) { throw $error; };
		$caught = null;
		try { $this->image()->create_new_migrated_object(); } catch ( \Throwable $exception ) { $caught = $exception; }
		$this->assertSame( $error, $caught );
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
}

class MediaDrainSource extends FakeSourcePlugin {
	private $tag_callback;
	private $discovery_callback;
	public function __construct( $tag_callback = null, $discovery_callback = null ) { $this->tag_callback = $tag_callback; $this->discovery_callback = $discovery_callback; }
	public function get_image_tags( $image ) { if ( $this->tag_callback ) { call_user_func( $this->tag_callback ); } return array( 'native-fixture-tag' ); }
	public function find_galleries() { if ( $this->discovery_callback ) { call_user_func( $this->discovery_callback ); } return array(); }
}
