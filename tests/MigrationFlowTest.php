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
		$GLOBALS['foogallery_migrate_test_posts']             = array();
		$GLOBALS['foogallery_migrate_test_post_meta']         = array();
		$GLOBALS['foogallery_migrate_test_post_meta_updates'] = array();
		$GLOBALS['foogallery_migrate_test_attached_files']    = array();
		$GLOBALS['foogallery_migrate_test_attachment_urls']   = array();
		$GLOBALS['foogallery_migrate_test_attachment_url_to_postid'] = array();
		$GLOBALS['foogallery_migrate_test_remote_head']       = array();
		$GLOBALS['foogallery_migrate_test_gallery_templates'] = array();
		$GLOBALS['foogallery_migrate_engine_instance']        = new MigratorEngine();
	}

	public function test_gallery_migration_can_resume_and_tracks_migrated_objects(): void {
		$plugin  = new FakeSourcePlugin();
		$gallery = $this->create_gallery(
			$plugin,
			10,
			'Source Gallery',
			array(
				$this->create_image( 'https://example.test/one.jpg' ),
				$this->create_image( 'https://example.test/two.jpg' ),
			)
		);
		$this->create_test_post( 800, FOOGALLERY_CPT_GALLERY, 'Gallery Settings Source' );
		$GLOBALS['foogallery_migrate_test_post_meta'][800] = array(
			FOOGALLERY_META_SETTINGS => array( 'source_setting' => 'gallery-source' ),
			FOOGALLERY_META_CUSTOM_CSS => '.gallery-source{}',
		);
		$plugin->galleries = array( $gallery );

		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$engine->save_settings(
			array(
				'images_per_turn' => 1,
				'override_gallery_settings' => 800,
			)
		);

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
		$this->assertSame(
			array(
				'source_setting' => 'gallery-source',
				'fake_setting' => 'yes',
			),
			$this->get_meta_update_value( $completed[0]->migrated_id, FOOGALLERY_META_SETTINGS )
		);
		$this->assertSame( '.gallery-source{}', $this->get_meta_update_value( $completed[0]->migrated_id, FOOGALLERY_META_CUSTOM_CSS ) );
		$this->assertSame( array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ), $migrator->get_state() );
		$this->assertTrue( $engine->has_object_been_migrated( $uid ) );

		$summary = $engine->get_migrated_objects_summary();
		$this->assertSame( 1, $summary['gallery']['count'] );
		$this->assertSame( 2, $summary['image']['count'] );
	}

	public function test_gallery_migration_imports_configured_images_per_turn(): void {
		$plugin = new FakeSourcePlugin();
		$images = array();

		for ( $i = 1; $i <= 6; $i++ ) {
			$images[] = $this->create_image( 'https://example.test/batch-' . $i . '.jpg' );
		}

		$gallery = $this->create_gallery( $plugin, 15, 'Batch Gallery', $images );
		$plugin->galleries = array( $gallery );

		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$this->assertSame( 5, $engine->get_images_per_turn() );
		$engine->run_detection();

		$migrator = $engine->get_gallery_migrator();
		$objects = $migrator->get_objects_to_migrate( true );
		$uid = $objects[0]->unique_identifier();

		$migrator->queue_objects_for_migration(
			array(
				$uid => array(
					'title' => 'Migrated Batch Gallery',
				),
			)
		);

		$migrator->migrate();

		$started = $migrator->get_objects_to_migrate();
		$this->assertFalse( $started[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_STARTED, $started[0]->migration_status );
		$this->assertSame( 5, $started[0]->migrated_child_count );

		$migrator->migrate();

		$completed = $migrator->get_objects_to_migrate();
		$this->assertTrue( $completed[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_COMPLETED, $completed[0]->migration_status );
		$this->assertSame( 6, $completed[0]->migrated_child_count );
	}

	public function test_album_migration_migrates_nested_gallery_flow(): void {
		$plugin  = new FakeSourcePlugin();
		$image   = $this->create_image( 'https://example.test/album-image.jpg' );
		$gallery = $this->create_gallery( $plugin, 20, 'Nested Gallery', array( $image ) );
		$album   = $this->create_album( $plugin, 30, 'Source Album', array( $gallery ) );
		$this->create_test_post( 900, FOOGALLERY_CPT_ALBUM, 'Album Settings Source' );
		$GLOBALS['foogallery_migrate_test_post_meta'][900] = array(
			FOOGALLERY_ALBUM_META_TEMPLATE => 'stack',
			FOOGALLERY_META_SETTINGS_OLD => array( 'album_setting' => 'source' ),
			FOOGALLERY_ALBUM_META_SORT => 'date_desc',
			FOOGALLERY_META_CUSTOM_CSS => '.album-source{}',
		);

		$plugin->albums = array( $album );
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$engine->save_settings(
			array(
				'override_album_settings' => 900,
			)
		);
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
		$this->assertSame( 'Migrated Album', $GLOBALS['foogallery_migrate_test_posts'][ $completed[0]->migrated_id ]->post_title );
		$this->assertSame( 'stack', $this->get_meta_update_value( $completed[0]->migrated_id, FOOGALLERY_ALBUM_META_TEMPLATE ) );
		$this->assertSame( array( 'album_setting' => 'source' ), $this->get_meta_update_value( $completed[0]->migrated_id, FOOGALLERY_META_SETTINGS_OLD ) );
		$this->assertSame( 'date_desc', $this->get_meta_update_value( $completed[0]->migrated_id, FOOGALLERY_ALBUM_META_SORT ) );
		$this->assertSame( '.album-source{}', $this->get_meta_update_value( $completed[0]->migrated_id, FOOGALLERY_META_CUSTOM_CSS ) );
		$this->assertSame( 1, $completed[0]->migrated_child_count );
		$this->assertSame( 1, $completed[0]->get_total_migrated_images() );
		$this->assertSame( array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ), $migrator->get_state() );
		$this->assertTrue( $engine->has_object_been_migrated( $uid ) );
	}

	public function test_child_errors_are_recorded_and_retry_can_resume_gallery(): void {
		$plugin  = new FakeSourcePlugin();
		$first             = $this->create_image( 'https://example.test/good.jpg' );
		$second            = $this->create_image( 'https://example.test/bad.jpg' );
		$second->migrated         = true;
		$second->migration_status = Migratable::PROGRESS_ERROR;
		$second->error            = new \WP_Error( 'forced_image_failure', 'Forced image failure.' );
		$gallery           = $this->create_gallery( $plugin, 40, 'Retry Gallery', array( $first, $second ) );
		$plugin->galleries = array( $gallery );

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

		$errored = $migrator->get_objects_to_migrate();
		$this->assertTrue( $errored[0]->migrated );
		$this->assertSame( Migratable::PROGRESS_ERROR, $errored[0]->migration_status );
		$this->assertCount( 1, $errored[0]->get_children_errors() );
		$this->assertSame( 'Forced image failure.', $errored[0]->get_children_errors()[0] );

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

	public function test_newly_written_state_is_compact_and_hydrates_runtime_objects(): void {
		$plugin  = new FakeSourcePlugin();
		$image   = $this->create_image( 'https://example.test/compact.jpg' );
		$image->data = (object) array(
			'large_source_blob' => str_repeat( 'x', 1000 ),
		);
		$gallery = $this->create_gallery( $plugin, 70, 'Compact Gallery', array( $image ) );
		$gallery->data = (object) array(
			'post_content' => str_repeat( 'y', 2000 ),
		);
		$gallery->foogallery_title = 'Duplicate Compact Gallery';
		$plugin->galleries = array( $gallery );

		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$legacy_bytes = strlen( serialize( array( $gallery ) ) );
		$engine       = $GLOBALS['foogallery_migrate_engine_instance'];
		$engine->run_detection();
		$migrator = $engine->get_gallery_migrator();
		$migrator->get_objects_to_migrate( true );

		$raw = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		$this->assertCompactPayload( $raw['plugins'] );
		$this->assertCompactPayload( $raw['galleries'] );
		$this->assertFalse( $this->contains_object( $raw['plugins'] ) );
		$this->assertFalse( $this->contains_object( $raw['galleries'] ) );
		$this->assertLessThan( $legacy_bytes * 0.5, strlen( serialize( $raw['galleries'] ) ) );
		$this->assertArrayNotHasKey( 'data', $raw['galleries']['items'][0] );
		$this->assertArrayNotHasKey( 'plugin', $raw['galleries']['items'][0] );
		$this->assertArrayNotHasKey( 'foogallery_title', $raw['galleries']['items'][0] );

		$hydrated = $migrator->get_objects_to_migrate();
		$this->assertInstanceOf( Gallery::class, $hydrated[0] );
		$this->assertInstanceOf( FakeSourcePlugin::class, $hydrated[0]->plugin );
		$this->assertInstanceOf( Image::class, $hydrated[0]->children[0] );
		$this->assertSame( 'Compact Gallery', $hydrated[0]->title );
		$this->assertNull( $hydrated[0]->data );
		$this->assertSame( 'https://example.test/compact.jpg', $hydrated[0]->children[0]->source_url );
	}

	public function test_legacy_object_state_is_read_without_load_time_upgrade(): void {
		$plugin  = new FakeSourcePlugin();
		$gallery = $this->create_gallery(
			$plugin,
			80,
			'Legacy Gallery',
			array(
				$this->create_image( 'https://example.test/legacy.jpg' ),
			)
		);
		$plugin->galleries = array( $gallery );
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );
		update_option(
			FOOGALLERY_MIGRATE_OPTION_DATA,
			array(
				'galleries' => array( $gallery ),
			),
			false
		);

		$migrator = $GLOBALS['foogallery_migrate_engine_instance']->get_gallery_migrator();
		$objects  = $migrator->get_objects_to_migrate();
		$raw      = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

		$this->assertSame( $gallery, $objects[0] );
		$this->assertSame( $gallery, $raw['galleries'][0] );
		$this->assertTrue( $this->contains_object( $raw['galleries'] ) );
	}

	public function test_user_settings_are_saved_sanitized_and_read_through_engine(): void {
		$GLOBALS['foogallery_migrate_test_gallery_templates'] = array(
			array(
				'slug' => 'default',
				'name' => 'Default',
			),
			array(
				'slug' => 'masonry',
				'name' => 'Masonry',
			),
		);
		$this->create_test_post( 600, FOOGALLERY_CPT_GALLERY, 'Gallery Settings Source' );
		$this->create_test_post( 700, FOOGALLERY_CPT_ALBUM, 'Album Settings Source' );

		$engine = $GLOBALS['foogallery_migrate_engine_instance'];

		$this->assertSame( 5, $engine->get_images_per_turn() );
		$this->assertSame(
			array(
				'default' => 'Default',
				'masonry' => 'Masonry',
			),
			$engine->get_available_gallery_templates()
		);
		$this->assertSame(
			array(
				600 => 'Gallery Settings Source',
			),
			$engine->get_available_gallery_settings_sources()
		);
		$this->assertSame(
			array(
				700 => 'Album Settings Source',
			),
			$engine->get_available_album_settings_sources()
		);

		$engine->save_settings(
			array(
				'override_gallery_layout' => 'masonry',
				'override_gallery_settings' => 600,
				'override_album_settings' => 700,
				'page_size' => '50',
				'images_per_turn' => '12',
				'debug_enabled' => true,
			)
		);

		$this->assertSame( 'masonry', $engine->get_override_gallery_template() );
		$this->assertSame( 600, $engine->get_override_gallery_settings() );
		$this->assertSame( 700, $engine->get_override_album_settings() );
		$this->assertSame( 50, $engine->get_page_size() );
		$this->assertSame( 12, $engine->get_images_per_turn() );
		$this->assertTrue( $engine->is_debug_enabled() );

		$engine->save_settings(
			array(
				'override_gallery_layout' => 'missing-template',
				'override_gallery_settings' => 999,
				'override_album_settings' => 999,
				'page_size' => array(),
				'images_per_turn' => array(),
				'debug_enabled' => false,
			)
		);

		$this->assertSame( '', $engine->get_override_gallery_template() );
		$this->assertSame( 0, $engine->get_override_gallery_settings() );
		$this->assertSame( 0, $engine->get_override_album_settings() );
		$this->assertSame( 50, $engine->get_page_size() );
		$this->assertSame( 12, $engine->get_images_per_turn() );
		$this->assertFalse( $engine->is_debug_enabled() );

		$engine->save_settings(
			array(
				'images_per_turn' => 0,
			)
		);

		$this->assertSame( 1, $engine->get_images_per_turn() );
	}

	private function create_test_post( int $id, string $post_type, string $title ): void {
		$GLOBALS['foogallery_migrate_test_posts'][ $id ] = (object) array(
			'ID' => $id,
			'post_type' => $post_type,
			'post_status' => 'publish',
			'post_title' => $title,
			'post_name' => sanitize_key( $title ),
			'post_author' => 1,
		);
	}

	private function get_meta_update_value( int $post_id, string $meta_key ) {
		foreach ( array_reverse( $GLOBALS['foogallery_migrate_test_post_meta_updates'] ) as $update ) {
			if ( absint( $update['post_id'] ) === $post_id && $meta_key === $update['meta_key'] ) {
				return $update['meta_value'];
			}
		}

		return null;
	}

	private function create_gallery( FakeSourcePlugin $plugin, int $id, string $title, array $children ): Gallery {
		$gallery = $plugin->get_gallery(
			array(
				'ID'             => $id,
				'title'          => $title,
				'data'           => null,
				'children'       => $children,
				'children_count' => count( $children ),
				'settings'       => array(),
			)
		);

		return $gallery;
	}

	private function create_album( FakeSourcePlugin $plugin, int $id, string $title, array $children ): Album {
		$album = $plugin->get_album(
			array(
				'ID'             => $id,
				'title'          => $title,
				'data'           => null,
				'fooalbum_title' => $title,
			)
		);
		$album->children = $children;
		$album->children_count = count( $children );

		return $album;
	}

	private function create_image( string $source_url ): Image {
		$image = new Image();
		$image->source_url   = $source_url;
		$image->title        = basename( $source_url );
		$image->alt          = '';
		$image->date         = '2026-05-21 12:00:00';

		return $image;
	}

	private function assertCompactPayload( $payload ): void {
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( '_foogallery_migrate_compact', $payload );
		$this->assertSame( 1, $payload['_foogallery_migrate_compact'] );
		$this->assertArrayHasKey( 'items', $payload );
	}

	private function contains_object( $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( $this->contains_object( $item ) ) {
				return true;
			}
		}

		return false;
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
