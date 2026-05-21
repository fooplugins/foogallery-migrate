<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;
use PHPUnit\Framework\TestCase;

class MigrationFlowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['foogallery_migrate_test_options']           = array();
		$GLOBALS['foogallery_migrate_test_plugins']           = array();
		$GLOBALS['foogallery_migrate_test_post_meta_updates'] = array();
		$GLOBALS['foogallery_migrate_test_attached_files']    = array();
		$GLOBALS['foogallery_migrate_test_attachment_urls']   = array();
		$GLOBALS['foogallery_migrate_test_remote_head']       = array();
		$GLOBALS['foogallery_migrate_engine_instance']        = new MigratorEngine();
	}

	public function test_gallery_migration_can_resume_and_tracks_migrated_objects(): void {
		$plugin  = new FakeSourcePlugin();
		$gallery = new FlowGallery( $plugin );
		$gallery->ID       = 10;
		$gallery->title    = 'Source Gallery';
		$gallery->settings = array();
		$gallery->children = array(
			$this->create_image( 'https://example.test/one.jpg' ),
			$this->create_image( 'https://example.test/two.jpg' ),
		);
		$gallery->children_count = count( $gallery->children );
		$plugin->galleries       = array( $gallery );

		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$this->assertSame( array( $plugin ), $engine->run_detection() );
		$this->assertTrue( $plugin->is_detected );

		$migrator = $engine->get_gallery_migrator();
		$objects  = $migrator->get_objects_to_migrate( true );
		$uid      = $objects[0]->unique_identifier();

		$migrator->queue_objects_for_migration(
			array(
				$uid => array(
					'title' => 'Migrated Gallery',
				),
			)
		);

		$queued = $migrator->get_objects_to_migrate();
		$this->assertSame( Migratable::PROGRESS_QUEUED, $queued[0]->migration_status );
		$this->assertSame( 'Migrated Gallery', $queued[0]->migrated_title );
		$this->assertSame( array( 'queued' => 1, 'completed' => 0, 'progress' => 0 ), $migrator->get_state() );

		$migrator->migrate();

		$started = $migrator->get_objects_to_migrate();
		$this->assertSame( Migratable::PROGRESS_STARTED, $started[0]->migration_status );
		$this->assertSame( 1, $started[0]->migrated_child_count );
		$this->assertSame( $uid, $migrator->get_current_object_being_migrated() );

		$migrator->migrate();

		$completed = $migrator->get_objects_to_migrate();
		$this->assertTrue( $completed[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_COMPLETED, $completed[0]->migration_status );
		$this->assertSame( 2, $completed[0]->migrated_child_count );
		$this->assertSame( array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ), $migrator->get_state() );
		$this->assertTrue( $engine->has_object_been_migrated( $uid ) );

		$summary = $engine->get_migrated_objects_summary();
		$this->assertSame( 1, $summary['gallery']['count'] );
		$this->assertSame( 2, $summary['image']['count'] );
	}

	public function test_album_migration_migrates_nested_gallery_flow(): void {
		$plugin  = new FakeSourcePlugin();
		$image   = $this->create_image( 'https://example.test/album-image.jpg' );
		$gallery = new FlowGallery( $plugin );
		$gallery->ID       = 20;
		$gallery->title    = 'Nested Gallery';
		$gallery->settings = array();
		$gallery->children = array( $image );
		$gallery->children_count = 1;

		$album = new FlowAlbum( $plugin );
		$album->ID       = 30;
		$album->title    = 'Source Album';
		$album->children = array( $gallery );
		$album->children_count = 1;

		$plugin->albums = array( $album );
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$engine->run_detection();

		$migrator = $engine->get_album_migrator();
		$objects  = $migrator->get_objects_to_migrate( true );
		$uid      = $objects[0]->unique_identifier();

		$migrator->queue_objects_for_migration(
			array(
				$uid => array(
					'title' => 'Migrated Album',
				),
			)
		);
		$migrator->migrate();

		$completed = $migrator->get_objects_to_migrate();
		$this->assertTrue( $completed[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_COMPLETED, $completed[0]->migration_status );
		$this->assertSame( 1, $completed[0]->migrated_child_count );
		$this->assertSame( 1, $completed[0]->get_total_migrated_images() );
		$this->assertSame( array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ), $migrator->get_state() );
		$this->assertTrue( $engine->has_object_been_migrated( $uid ) );
	}

	public function test_child_errors_are_recorded_and_retry_can_resume_gallery(): void {
		$plugin  = new FakeSourcePlugin();
		$gallery = new FlowGallery( $plugin );
		$gallery->ID       = 40;
		$gallery->title    = 'Retry Gallery';
		$gallery->settings = array();
		$first             = $this->create_image( 'https://example.test/good.jpg' );
		$second            = $this->create_image( 'https://example.test/bad.jpg', true );
		$gallery->children = array( $first, $second );
		$gallery->children_count = 2;
		$plugin->galleries       = array( $gallery );

		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$engine->run_detection();

		$migrator = $engine->get_gallery_migrator();
		$objects  = $migrator->get_objects_to_migrate( true );
		$uid      = $objects[0]->unique_identifier();

		$migrator->queue_objects_for_migration(
			array(
				$uid => array(
					'title' => 'Retry Gallery',
				),
			)
		);
		$migrator->migrate();
		$migrator->migrate();

		$errored = $migrator->get_objects_to_migrate();
		$this->assertTrue( $errored[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_ERROR, $errored[0]->migration_status );
		$this->assertCount( 1, $errored[0]->get_children_errors() );
		$this->assertSame( 'Forced image failure.', $errored[0]->get_children_errors()[0] );

		$second->should_error = false;
		$this->assertTrue( $engine->retry_gallery_migration( $uid ) );

		$retried = $migrator->get_objects_to_migrate();
		$this->assertTrue( $retried[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_COMPLETED, $retried[0]->migration_status );
		$this->assertCount( 0, $retried[0]->get_children_errors() );
	}

	public function test_plugin_factories_preserve_runtime_contract(): void {
		$plugin = new FakeSourcePlugin();
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$engine->run_detection();

		$image = $plugin->get_image(
			array(
				'source_url'  => 'https://example.test/factory.jpg',
				'slug'        => 'factory',
				'title'       => 'Factory Image',
				'caption'     => 'Caption',
				'description' => 'Description',
				'alt'         => 'Alt',
				'date'        => '2026-05-21 12:00:00',
				'data'        => (object) array( 'ignored' => true ),
			)
		);

		$gallery = $plugin->get_gallery(
			array(
				'ID'             => 50,
				'title'          => 'Factory Gallery',
				'data'           => (object) array( 'ignored' => true ),
				'children'       => array( $image ),
				'children_count' => 1,
				'settings'       => array( 'type' => 'default' ),
			)
		);

		$album = $plugin->get_album(
			array(
				'ID'             => 60,
				'title'          => 'Factory Album',
				'data'           => (object) array( 'ignored' => true ),
				'fooalbum_title' => 'Factory Album',
			)
		);

		$this->assertInstanceOf( Image::class, $image );
		$this->assertSame( 'https://example.test/factory.jpg', $image->unique_identifier() );
		$this->assertInstanceOf( Gallery::class, $gallery );
		$this->assertSame( 'gallery_FakeSource_50', $gallery->unique_identifier() );
		$this->assertSame( 1, $gallery->get_children_count() );
		$this->assertSame( array( 'type' => 'default' ), $gallery->settings );
		$this->assertInstanceOf( Album::class, $album );
		$this->assertSame( 'album_FakeSource_60', $album->unique_identifier() );
	}

	private function create_image( string $source_url, bool $should_error = false ): FlowImage {
		$image = new FlowImage();
		$image->source_url   = $source_url;
		$image->title        = basename( $source_url );
		$image->alt          = '';
		$image->date         = '2026-05-21 12:00:00';
		$image->should_error = $should_error;

		return $image;
	}
}

class FakeSourcePlugin extends Plugin {
	public $galleries = array();
	public $albums = array();

	public function name() {
		return 'FakeSource';
	}

	public function detect() {
		return true;
	}

	public function find_galleries() {
		return $this->galleries;
	}

	public function find_albums() {
		return $this->albums;
	}

	public function get_gallery_template( $gallery ) {
		return 'default';
	}

	public function get_gallery_settings( $gallery, $default_settings ) {
		$default_settings['fake_setting'] = 'yes';
		return $default_settings;
	}
}

class FlowGallery extends Gallery {
	public function create_new_migrated_object() {
		if ( 0 === (int) $this->migrated_id ) {
			$this->migrated_id = FlowIds::next();
		}
	}
}

class FlowAlbum extends Album {
	public function create_new_migrated_object() {
		if ( 0 === (int) $this->migrated_id ) {
			$this->migrated_id = FlowIds::next();
		}
	}
}

class FlowImage extends Image {
	public $should_error = false;

	public function create_new_migrated_object() {
		if ( $this->should_error ) {
			$this->error            = new \WP_Error( 'forced_image_failure', 'Forced image failure.' );
			$this->migration_status = self::PROGRESS_ERROR;
			$this->migrated         = true;
			return;
		}

		$this->migrated_id = FlowIds::next();
		$this->migrated    = true;
	}
}

class FlowIds {
	private static $next = 2000;

	public static function next() {
		self::$next++;
		return self::$next;
	}
}
