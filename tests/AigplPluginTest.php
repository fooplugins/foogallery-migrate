<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use PHPUnit\Framework\TestCase;

class AigplPluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_posts'] = array();
		$GLOBALS['foogallery_migrate_test_post_meta'] = array();
		$GLOBALS['foogallery_migrate_engine_instance'] = new MigratorEngine( new MigratedStore( false, new MigratorSettings() ) );
		$GLOBALS['wpdb'] = new FakeAigplWpdb();
	}

	public function test_aigpl_albums_are_created_from_category_terms_and_gallery_relationships(): void {
		$plugin = new Aigpl();
		$wpdb = $GLOBALS['wpdb'];

		$wpdb->gallery_posts = array(
			$this->post( 101, 'Wedding One', '2026-05-20 10:00:00' ),
			$this->post( 102, 'Wedding Two', '2026-05-19 10:00:00' ),
			$this->post( 103, 'Empty Import', '2026-05-18 10:00:00' ),
		);
		$wpdb->attachments = array(
			1001 => $this->attachment( 1001, 'one.jpg' ),
			1002 => $this->attachment( 1002, 'two.jpg' ),
			1003 => $this->attachment( 1003, 'three.jpg' ),
		);
		$wpdb->category_gallery_rows = array(
			(object) array(
				'term_id'    => 7,
				'name'       => 'Weddings',
				'slug'       => 'weddings',
				'gallery_id' => 101,
			),
			(object) array(
				'term_id'    => 7,
				'name'       => 'Weddings',
				'slug'       => 'weddings',
				'gallery_id' => 102,
			),
			(object) array(
				'term_id'    => 8,
				'name'       => 'Portraits',
				'slug'       => 'portraits',
				'gallery_id' => 102,
			),
			(object) array(
				'term_id'    => 9,
				'name'       => 'Skipped',
				'slug'       => 'skipped',
				'gallery_id' => 103,
			),
		);

		$GLOBALS['foogallery_migrate_test_post_meta'][101] = array(
			Aigpl::META_GALLERY_IMAGES => array( 1001, 1002 ),
		);
		$GLOBALS['foogallery_migrate_test_post_meta'][102] = array(
			Aigpl::META_GALLERY_IMAGES => array( 1003 ),
		);
		$GLOBALS['foogallery_migrate_test_post_meta'][103] = array(
			Aigpl::META_GALLERY_IMAGES => array( 9999 ),
		);
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$galleries = $plugin->find_galleries();
		$this->assertCount( 2, $galleries );
		$this->assertSame( array( 101, 102 ), array_map( array( $this, 'object_id' ), $galleries ) );

		$albums = $plugin->find_albums();
		$albums_by_id = array();
		foreach ( $albums as $album ) {
			$albums_by_id[ $album->ID ] = $album;
		}

		$this->assertCount( 2, $albums );
		$this->assertArrayHasKey( 7, $albums_by_id );
		$this->assertArrayHasKey( 8, $albums_by_id );
		$this->assertArrayNotHasKey( 9, $albums_by_id );

		$this->assertInstanceOf( Album::class, $albums_by_id[7] );
		$this->assertSame( 'Weddings', $albums_by_id[7]->title );
		$this->assertSame( 'album_AIGPL_7', $albums_by_id[7]->unique_identifier() );
		$this->assertSame( 2, $albums_by_id[7]->children_count );
		$this->assertSame( array( 101, 102 ), array_map( array( $this, 'object_id' ), $albums_by_id[7]->children ) );
		$this->assertContainsOnlyInstancesOf( Gallery::class, $albums_by_id[7]->children );

		$this->assertSame( 'Portraits', $albums_by_id[8]->title );
		$this->assertSame( array( 102 ), array_map( array( $this, 'object_id' ), $albums_by_id[8]->children ) );
	}

	public function test_aigpl_content_mapping_uses_categories_for_albums_and_ids_for_galleries(): void {
		$plugin = new Aigpl();

		$this->assertSame( 'gallery', $plugin->get_content_object_type( '[aigpl-gallery id="101"]' ) );
		$this->assertSame( 'gallery', $plugin->get_content_object_type( '[aigpl-gallery-album id="101"]' ) );
		$this->assertSame( 'album', $plugin->get_content_object_type( '[aigpl-gallery-album category="7"]' ) );

		$matches = $this->find_aigpl_shortcode_matches( '[aigpl-gallery id="101"] [aigpl-gallery-album id="102"] [aigpl-gallery-album category="7"]', $plugin );

		$this->assertSame(
			array(
				array( 'gallery', 101 ),
				array( 'gallery', 102 ),
				array( 'album', 7 ),
			),
			$matches
		);
	}

	public function test_204_image_turn_stays_bounded_with_thousands_of_migrated_records(): void {
		$plugin = new Aigpl();
		$store = new MigratedStore( false, new MigratorSettings() );
		$engine = new MigratorEngine( $store );
		$GLOBALS['foogallery_migrate_engine_instance'] = $engine;
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );

		$history = array();
		for ( $i = 1; $i <= 5000; $i++ ) {
			$image = new Image( $plugin );
			$image->source_url = 'https://history.example.test/' . $i . '.jpg';
			$image->migrated = true;
			$image->migrated_id = 10000 + $i;
			$image->migration_status = Migratable::PROGRESS_COMPLETED;
			$history[] = array( 'object' => $image, 'parent_key' => 'gallery_AIGPL_history' );
		}
		$this->assertSame( 5000, $store->upsert_batch( $history ) );

		$this->configure_large_gallery( 204, 204 );
		$engine->run_detection();
		$migrator = $engine->get_gallery_migrator();
		$galleries = $migrator->get_objects_to_migrate( true );
		$gallery_key = $galleries[0]->unique_identifier();
		$migrator->queue_objects_for_migration(
			array(
				$gallery_key => array( 'title' => 'AIGPL 204' ),
			)
		);

		$raw = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		$this->assertArrayNotHasKey( 'children', $raw[ MigratorEngine::KEY_GALLERIES ]['items'][0] );
		$this->assertSame( 204, $raw[ MigratorEngine::KEY_GALLERIES ]['items'][0]['children_count'] );

		$store->reset_diagnostics();
		$migrator->migrate();
		$diagnostics = $store->diagnostics();

		$this->assertSame( 1, $diagnostics['write_queries'] );
		$this->assertSame( 5, $diagnostics['rows_written'] );
		// One bulk parent lookup plus at most one indexed lookup per five-item turn.
		$this->assertLessThanOrEqual( 8, $diagnostics['read_queries'] );
		$this->assertSame( 0, $diagnostics['full_history_hydrations'] );
		$this->assertCount( 5, $store->get_by_parent( $gallery_key ) );
		$this->assertSame( 5005, $store->count() );
	}

	public function test_retry_targets_one_204_image_gallery_and_only_its_error_records(): void {
		$plugin = new Aigpl();
		$store = new MigratedStore( false, new MigratorSettings() );
		$engine = new MigratorEngine( $store );
		$GLOBALS['foogallery_migrate_engine_instance'] = $engine;
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );
		$this->configure_large_gallery( 305, 204 );

		$engine->run_detection();
		$migrator = $engine->get_gallery_migrator();
		$gallery = $migrator->get_objects_to_migrate( true )[0];
		$gallery_key = $gallery->unique_identifier();
		$children = $plugin->load_object_children( $gallery );
		$records = array();
		foreach ( $children as $index => $child ) {
			$child->migrated = true;
			$child->migrated_id = 20000 + $index;
			$child->migration_status = Migratable::PROGRESS_COMPLETED;
			if ( 203 === $index ) {
				$child->migration_status = Migratable::PROGRESS_ERROR;
				$child->error = new \WP_Error( 'forced', 'Retry this image.' );
			}
			$records[] = array( 'object' => $child, 'parent_key' => $gallery_key );
		}

		$gallery->children = array();
		$gallery->children_count = 204;
		$gallery->migrated = true;
		$gallery->migrated_id = 3305;
		$gallery->migrated_child_count = 204;
		$gallery->progress = 100;
		$gallery->migration_status = Migratable::PROGRESS_ERROR;
		$gallery->error = new \WP_Error( 'child_error', 'One image failed.' );
		$records[] = array( 'object' => $gallery, 'parent_key' => null );
		$this->assertSame( 205, $store->upsert_batch( $records ) );
		$engine->set_migrator_setting( MigratorEngine::KEY_GALLERIES, array( $gallery ) );

		$store->reset_diagnostics();
		$this->assertTrue( $engine->retry_gallery_migration( $gallery_key ) );
		$diagnostics = $store->diagnostics();

		$this->assertLessThanOrEqual( 4, $diagnostics['read_queries'] );
		$this->assertSame( 2, $diagnostics['write_queries'] );
		$this->assertSame( 4, $diagnostics['rows_written'] );
		$this->assertSame( 0, $diagnostics['full_history_hydrations'] );
		$this->assertCount( 204, $store->get_by_parent( $gallery_key ) );
		$this->assertSame( Migratable::PROGRESS_COMPLETED, $store->get( $gallery_key )->migration_status );
	}

	public function test_error_check_reads_and_updates_only_one_204_image_gallery(): void {
		$plugin = new Aigpl();
		$store = new MigratedStore( false, new MigratorSettings() );
		$engine = new MigratorEngine( $store );
		$GLOBALS['foogallery_migrate_engine_instance'] = $engine;
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );
		$this->configure_large_gallery( 406, 204 );

		$engine->run_detection();
		$migrator = $engine->get_gallery_migrator();
		$gallery = $migrator->get_objects_to_migrate( true )[0];
		$gallery_key = $gallery->unique_identifier();
		$children = $plugin->load_object_children( $gallery );
		$records = array();
		foreach ( $children as $index => $child ) {
			$child->migrated = true;
			$child->migrated_id = 30000 + $index;
			$child->migration_status = Migratable::PROGRESS_COMPLETED;
			$records[] = array( 'object' => $child, 'parent_key' => $gallery_key );
		}
		$gallery->children = array();
		$gallery->children_count = 204;
		$gallery->migrated = true;
		$gallery->migrated_id = 4406;
		$gallery->migrated_child_count = 204;
		$gallery->progress = 100;
		$gallery->migration_status = Migratable::PROGRESS_COMPLETED;
		$records[] = array( 'object' => $gallery, 'parent_key' => null );
		$this->assertSame( 205, $store->upsert_batch( $records ) );
		$engine->set_migrator_setting( MigratorEngine::KEY_GALLERIES, array( $gallery ) );

		$store->reset_diagnostics();
		$result = $engine->check_gallery_migration_errors( $gallery_key );
		$diagnostics = $store->diagnostics();

		$this->assertSame( array( 'checked' => 204, 'errors' => 204 ), $result );
		$this->assertLessThanOrEqual( 2, $diagnostics['read_queries'] );
		$this->assertSame( 1, $diagnostics['write_queries'] );
		$this->assertSame( 205, $diagnostics['rows_written'] );
		$this->assertSame( 0, $diagnostics['full_history_hydrations'] );
		$this->assertSame( Migratable::PROGRESS_ERROR, $store->get( $gallery_key )->migration_status );
		$this->assertSame( Migratable::PROGRESS_ERROR, $store->get_by_parent( $gallery_key )[0]->migration_status );
	}

	public function object_id( $object ): int {
		return (int) $object->ID;
	}

	private function post( int $id, string $title, string $date ) {
		$post = (object) array(
			'ID'           => $id,
			'post_type'    => Aigpl::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => strtolower( str_replace( ' ', '-', $title ) ),
			'post_excerpt' => '',
			'post_content' => '',
			'post_date'    => $date,
		);

		$GLOBALS['foogallery_migrate_test_posts'][ $id ] = $post;

		return $post;
	}

	private function attachment( int $id, string $file ): array {
		return array(
			'ID'            => $id,
			'guid'          => 'https://example.test/wp-content/uploads/' . $file,
			'post_name'     => pathinfo( $file, PATHINFO_FILENAME ),
			'post_title'    => $file,
			'post_excerpt'  => '',
			'post_content'  => '',
			'post_date'     => '2026-05-21 10:00:00',
			'attached_file' => '2026/05/' . $file,
			'alt'           => '',
		);
	}

	private function configure_large_gallery( int $gallery_id, int $image_count ): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->gallery_posts = array( $this->post( $gallery_id, 'AIGPL Large Gallery', '2026-05-20 10:00:00' ) );
		$image_ids = array();
		for ( $i = 1; $i <= $image_count; $i++ ) {
			$attachment_id = $gallery_id * 1000 + $i;
			$image_ids[] = $attachment_id;
			$wpdb->attachments[ $attachment_id ] = $this->attachment( $attachment_id, 'large-' . $i . '.jpg' );
		}
		$GLOBALS['foogallery_migrate_test_post_meta'][ $gallery_id ] = array(
			Aigpl::META_GALLERY_IMAGES => $image_ids,
		);
	}

	private function find_aigpl_shortcode_matches( string $content, Aigpl $plugin ): array {
		$matches = array();

		foreach ( $plugin->get_shortcode_patterns() as $pattern ) {
			$pattern_matches = array();
			if ( ! preg_match_all( $pattern, $content, $pattern_matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $pattern_matches as $match ) {
				$matches[] = array(
					$plugin->get_content_object_type( $match[0] ),
					$this->first_numeric_match( $match ),
				);
			}
		}

		return $matches;
	}

	private function first_numeric_match( array $matches ): int {
		foreach ( $matches as $index => $match ) {
			if ( 0 === $index ) {
				continue;
			}

			if ( is_numeric( $match ) ) {
				return (int) $match;
			}
		}

		return 0;
	}
}

class FakeAigplWpdb {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $gallery_posts = array();
	public $attachments = array();
	public $category_gallery_rows = array();
	private $last_prepare_args = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$this->last_prepare_args = $args;

		return $query;
	}

	public function get_var( $query ) {
		if ( false !== strpos( $query, "p.post_type = %s" ) ) {
			return ! empty( $this->gallery_posts ) ? '1' : null;
		}

		return null;
	}

	public function get_results( $query, $output = null ) {
		if ( false !== strpos( $query, "p.post_type = 'attachment'" ) ) {
			return $this->get_attachment_results();
		}

		if ( false !== strpos( $query, 'FROM ' . $this->terms . ' t' ) ) {
			return $this->category_gallery_rows;
		}

		if ( false !== strpos( $query, 'FROM ' . $this->posts . ' p' ) ) {
			return $this->gallery_posts;
		}

		return array();
	}

	private function get_attachment_results(): array {
		$ids = array();
		foreach ( $this->last_prepare_args as $arg ) {
			if ( is_numeric( $arg ) ) {
				$ids[] = (int) $arg;
			}
		}

		$attachments = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->attachments[ $id ] ) ) {
				$attachments[] = $this->attachments[ $id ];
			}
		}

		return $attachments;
	}
}
