<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\Init;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Plugins\WpPhotoAlbumPlus;
use PHPUnit\Framework\TestCase;

class WpPhotoAlbumPlusPluginTest extends TestCase {

	private $created_files = array();

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_posts'] = array();
		$GLOBALS['foogallery_migrate_test_post_meta'] = array();
		$GLOBALS['foogallery_migrate_test_imported_attachments'] = array();
		$GLOBALS['foogallery_migrate_test_attachment_url_to_postid'] = array();
		$_POST = array();
		$GLOBALS['foogallery_migrate_engine_instance'] = new MigratorEngine();
		$GLOBALS['wpdb'] = new FakeWpPhotoAlbumPlusWpdb();
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->created_files ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		$this->created_files = array();

		parent::tearDown();
	}

	public function test_source_is_registered_with_exact_name(): void {
		$plugin = new WpPhotoAlbumPlus();
		$registry_source = file_get_contents( FOOGM_DIR . '/includes/functions.php' );

		$this->assertSame( 'WP Photo Album Plus', $plugin->name() );
		$this->assertStringContainsString( 'new \\FooPlugins\\FooGalleryMigrate\\Plugins\\WpPhotoAlbumPlus()', $registry_source );
	}

	public function test_ajax_title_lookup_accepts_php_normalized_wppa_field_names(): void {
		parse_str(
			http_build_query( array( 'foogallery-title-gallery_WP Photo Album Plus_4' => 'Renamed WPPA Gallery' ) ),
			$_POST
		);
		$init = ( new \ReflectionClass( Init::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( $init, 'get_migration_title_from_request' );

		$this->assertSame(
			'Renamed WPPA Gallery',
			$method->invoke( $init, 'gallery_WP Photo Album Plus_4' )
		);
	}

	public function test_ajax_album_title_lookup_accepts_php_normalized_wppa_field_names(): void {
		parse_str(
			http_build_query( array( 'foogallery-album-title-album_WP Photo Album Plus_3' => 'Renamed WPPA Album' ) ),
			$_POST
		);
		$init = ( new \ReflectionClass( Init::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( $init, 'get_migration_title_from_request' );

		$this->assertSame(
			'Renamed WPPA Album',
			$method->invoke( $init, 'album_WP Photo Album Plus_3', 'foogallery-album-title-' )
		);
	}

	public function test_detection_requires_both_exact_current_site_tables_when_inactive(): void {
		$plugin = new WpPhotoAlbumPlus();
		$wpdb = $GLOBALS['wpdb'];

		$wpdb->tables = array( 'wp_wppa_albums_backup', 'wp_wppa_photos_old' );
		$this->assertFalse( $plugin->detect(), 'LIKE wildcard lookalikes must not be detected.' );

		$wpdb->tables = array( 'wp_wppa_albums', 'wp_wppa_photos' );
		$wpdb->table_detection_arguments = array();
		$this->assertTrue( $plugin->detect() );
		$this->assertSame( array( 'wp_wppa_albums', 'wp_wppa_photos' ), $wpdb->table_detection_arguments );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detection_honors_active_wppa_table_constants(): void {
		define( 'WPPA_ALBUMS', 'network_wppa_albums' );
		define( 'WPPA_PHOTOS', 'network_wppa_photos' );

		$GLOBALS['wpdb'] = new FakeWpPhotoAlbumPlusWpdb();
		$GLOBALS['wpdb']->prefix = 'wp_9_';
		$GLOBALS['wpdb']->tables = array( 'network_wppa_albums', 'network_wppa_photos' );

		$plugin = new WpPhotoAlbumPlus();

		$this->assertTrue( $plugin->detect() );
		$this->assertSame( array( 'network_wppa_albums', 'network_wppa_photos' ), $GLOBALS['wpdb']->table_detection_arguments );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_wppa_upload_constants_are_used_for_local_sources(): void {
		$upload_path = $this->test_upload_dir() . '/wppa-active-upload-test';
		define( 'WPPA_ALBUMS', 'network_wppa_albums' );
		define( 'WPPA_PHOTOS', 'network_wppa_photos' );
		define( 'WPPA_UPLOAD_PATH', $upload_path );
		define( 'WPPA_UPLOAD_URL', 'https://media.example.test/wppa' );
		if ( ! is_dir( $upload_path ) ) {
			mkdir( $upload_path, 0777, true );
		}
		$this->create_photo_file( '601.jpg' );
		file_put_contents( $upload_path . '/601.jpg', file_get_contents( $this->test_upload_dir() . '/wppa/601.jpg' ) );

		$GLOBALS['foogallery_migrate_test_options'] = array( 'wppa_file_system' => 'flat' );
		$GLOBALS['foogallery_migrate_engine_instance'] = new MigratorEngine();
		$GLOBALS['wpdb'] = new FakeWpPhotoAlbumPlusWpdb();
		$GLOBALS['wpdb']->tables = array( 'network_wppa_albums', 'network_wppa_photos' );
		$GLOBALS['wpdb']->albums = array( $this->album( 60, 'Active', '', 0, 1, 1 ) );
		$GLOBALS['wpdb']->photos = array( $this->photo( 601, 60, 'jpg', 'Active photo', '', 1 ) );

		$plugin = new WpPhotoAlbumPlus();
		$gallery = $plugin->find_galleries()[0];
		$images = $plugin->load_object_children( $gallery );

		$this->assertSame( 'https://media.example.test/wppa/601.jpg', $images[0]->source_url );

		unlink( $upload_path . '/601.jpg' );
		rmdir( $upload_path );
	}

	public function test_flat_gallery_preserves_metadata_order_and_skips_non_public_or_invalid_payloads(): void {
		$plugin = new WpPhotoAlbumPlus();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->tables = array( 'wp_wppa_albums', 'wp_wppa_photos' );
		$wpdb->albums = array(
			$this->album( 7, 'Summer', 'Album description', 0, 1, 1 ),
			$this->album( 8, 'Restricted', '', 0, 2, 1 ),
			$this->album( 9, 'Unknown visibility', '', 0, 3, 1 ),
		);
		$wpdb->albums[1]->capability = 'edit_private_posts';
		$wpdb->albums[2]->status = 'mystery';
		$wpdb->photos = array(
			$this->photo( 102, 7, 'jpg', 'Second', 'Second caption', 2, 'publish', 'Second alt', '2026-06-02 11:00:00' ),
			$this->photo( 101, 7, 'png', 'First', 'First caption', 1, 'publish', 'First alt', '2026-06-01 10:00:00' ),
			$this->photo( 103, 7, 'gif', 'Tie', 'Tie caption', 1, 'publish', 'Tie alt', '2026-02-31 12:00:00' ),
			$this->photo( 104, 7, 'jpg', 'Pending', '', 0, 'pending' ),
			$this->photo( 105, 7, 'jpg', 'Scheduled', '', 0, 'scheduled' ),
			$this->photo( 106, 7, 'jpg', 'Private', '', 0, 'private' ),
			$this->photo( 107, -7, 'jpg', 'Trashed', '', 0, '' ),
			$this->photo( 108, 7, 'mp4', 'Video', '', 0, '' ),
			$this->photo( 109, 7, '../jpg', 'Traversal', '', 0, '' ),
			$this->photo( 110, 7, 'webp', 'Missing', '', 0, '' ),
			$this->photo( 111, 7, 'jpg', 'Escaped symlink', '', 0, '' ),
			$this->photo( 112, 7, 'jpg', 'Unknown status', '', 0, 'mystery' ),
			$this->photo( 113, 7, 'jpg', 'Malformed image', '', 0, 'publish' ),
			$this->photo( 801, 8, 'jpg', 'Restricted photo', '', 1, 'publish' ),
			$this->photo( 901, 9, 'jpg', 'Unknown album photo', '', 1, 'publish' ),
		);
		$wpdb->photos[1]->exifdtm = '2026:05:31 09:08:07';
		$GLOBALS['foogallery_migrate_test_options']['wppa_file_system'] = 'flat';

		$this->create_photo_file( '101.png' );
		$this->create_photo_file( '102.jpg' );
		$this->create_photo_file( '103.gif' );
		$this->create_photo_file( '104.jpg' );
		$this->create_photo_file( '105.jpg' );
		$this->create_photo_file( '106.jpg' );
		$this->create_photo_file( '107.jpg' );
		$this->create_photo_file( '108.mp4' );
		$this->create_photo_file( '112.jpg' );
		$this->create_photo_file( '801.jpg' );
		$this->create_photo_file( '901.jpg' );
		$wppa_directory = $this->test_upload_dir() . '/wppa';
		$malformed_file = $wppa_directory . '/113.jpg';
		file_put_contents( $malformed_file, 'not an image' );
		$this->created_files[] = $malformed_file;
		$outside_file = dirname( $this->test_upload_dir() ) . '/wppa-outside-image.jpg';
		$escaped_symlink = $wppa_directory . '/111.jpg';
		file_put_contents( $outside_file, 'outside synthetic fixture' );
		symlink( $outside_file, $escaped_symlink );
		$this->created_files[] = $escaped_symlink;
		$this->created_files[] = $outside_file;

		$galleries = $plugin->find_galleries();
		$this->assertCount( 1, $galleries );
		$this->assertInstanceOf( Gallery::class, $galleries[0] );
		$this->assertSame( 7, (int) $galleries[0]->ID );
		$this->assertSame( 'Summer', $galleries[0]->title );
		$this->assertSame( 3, $galleries[0]->children_count );

		$images = $plugin->load_object_children( $galleries[0] );
		$this->assertSame( array( 101, 103, 102 ), array_map( array( $this, 'object_id' ), $images ) );
		$this->assertSame( 'First', $images[0]->title );
		$this->assertSame( 'First caption', $images[0]->caption );
		$this->assertSame( 'First caption', $images[0]->description );
		$this->assertSame( 'First alt', $images[0]->alt );
		$this->assertSame( '2026-05-31 09:08:07', $images[0]->date );
		$this->assertSame( '', $images[1]->date, 'Invalid dates must not be passed into attachment creation.' );
		$this->assertSame( 'https://example.test/wp-content/uploads/wppa/101.png', $images[0]->source_url );
	}

	public function test_tree_paths_global_order_and_random_fallback_are_deterministic(): void {
		$plugin = new WpPhotoAlbumPlus();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->tables = array( 'wp_wppa_albums', 'wp_wppa_photos' );
		$wpdb->albums = array(
			$this->album( 20, 'Tree', '', 0, 1, 0 ),
			$this->album( 21, 'Random', '', 0, 2, 3 ),
		);
		$wpdb->photos = array(
			$this->photo( 12345, 20, 'webp', 'Zulu', '', 0 ),
			$this->photo( 12346, 20, 'jpg', 'Alpha', '', 0 ),
			$this->photo( 202, 21, 'jpg', 'B', '', 0 ),
			$this->photo( 201, 21, 'jpg', 'A', '', 0 ),
		);
		$GLOBALS['foogallery_migrate_test_options']['wppa_file_system'] = 'tree';
		$GLOBALS['foogallery_migrate_test_options']['wppa_list_photos_by'] = '-2';

		$this->create_photo_file( '12/34/5.webp' );
		$this->create_photo_file( '12/34/6.jpg' );
		$this->create_photo_file( '20/1.jpg' );
		$this->create_photo_file( '20/2.jpg' );

		$galleries = $plugin->find_galleries();
		$this->assertCount( 2, $galleries );

		$tree_images = $plugin->load_object_children( $galleries[0] );
		$this->assertSame( array( 12345, 12346 ), array_map( array( $this, 'object_id' ), $tree_images ) );
		$this->assertSame( 'https://example.test/wp-content/uploads/wppa/12/34/5.webp', $tree_images[0]->source_url );

		$random_images = $plugin->load_object_children( $galleries[1] );
		$this->assertSame( array( 201, 202 ), array_map( array( $this, 'object_id' ), $random_images ) );
	}

	public function test_hierarchy_is_flattened_into_safe_album_to_gallery_candidates(): void {
		$plugin = new WpPhotoAlbumPlus();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->tables = array( 'wp_wppa_albums', 'wp_wppa_photos' );
		$wpdb->albums = array(
			$this->album( 30, 'Parent', '', 0, 2, 1, -2 ),
			$this->album( 31, 'Child', '', 30, 1, 1 ),
			$this->album( 32, 'Grandchild', '', 31, 1, 1 ),
			$this->album( 33, 'Zulu child', '', 30, 2, 1 ),
		);
		$wpdb->photos = array(
			$this->photo( 301, 30, 'jpg', 'Parent photo', '', 1 ),
			$this->photo( 311, 31, 'jpg', 'Child photo', '', 1 ),
			$this->photo( 321, 32, 'jpg', 'Grandchild photo', '', 1 ),
			$this->photo( 331, 33, 'jpg', 'Zulu photo', '', 1 ),
		);
		$this->create_photo_file( '301.jpg' );
		$this->create_photo_file( '311.jpg' );
		$this->create_photo_file( '321.jpg' );
		$this->create_photo_file( '331.jpg' );

		$albums = $plugin->find_albums();
		$albums_by_id = array();
		foreach ( $albums as $album ) {
			$albums_by_id[ (int) $album->ID ] = $album;
		}

		$this->assertCount( 2, $albums );
		$this->assertInstanceOf( Album::class, $albums_by_id[30] );
		$this->assertSame( array( 30, 33, 31, 32 ), array_map( array( $this, 'object_id' ), $albums_by_id[30]->children ) );
		$this->assertSame( array( 31, 32 ), array_map( array( $this, 'object_id' ), $albums_by_id[31]->children ) );
		$this->assertContainsOnlyInstancesOf( Gallery::class, $albums_by_id[30]->children );
	}

	public function test_exact_numeric_shortcodes_and_blocks_match_while_dynamic_forms_remain_untouched(): void {
		$plugin = new WpPhotoAlbumPlus();
		$exact = array(
			'[wppa type="album" album="42"]',
			"[wppa type='slideonly' album='42']",
			'[wppa album=42]',
		);
		$dynamic = array(
			'[wppa]',
			'[wppa type="album" album="#last"]',
			'[wppa type="album" album="$Summer"]',
			'[wppa type="album" album="42.43"]',
			'[wppa type="album" album="42,43"]',
			'[wppa type="album" album="42" album="#last"]',
			'[wppa type="album" data-album="42"]',
			'[wppa type="album" caption=\'album="42"\']',
			'[wppa type="album" album="crypt-a1b2"]',
			'[wppa type="album" album="#tags,summer"]',
		);

		foreach ( $exact as $shortcode ) {
			$this->assertSame( 42, $this->extract_shortcode_id( $shortcode, $plugin ), $shortcode );
			$this->assertSame( 'gallery', $plugin->get_content_object_type( $shortcode ) );
		}
		foreach ( $dynamic as $shortcode ) {
			$this->assertFalse( $this->extract_shortcode_id( $shortcode, $plugin ), $shortcode );
		}

		$blocks = $plugin->get_block_patterns();
		$this->assertArrayHasKey( 'wp-photo-album-plus/general', $blocks );
		$this->assertArrayHasKey( 'wp-photo-album-plus/slideshow', $blocks );
		$this->assertArrayHasKey( 'wppa/gutenberg-wppa', $blocks );

		$exact_block = '<!-- wp:wp-photo-album-plus/general {"wppaAlbum":42,"wppaShortcode":"[wppa type=\\"slideonly\\" album=\\"42\\"]"} --><div>[wppa type="slideonly" album="42"]</div><!-- /wp:wp-photo-album-plus/general -->';
		$dynamic_block = '<!-- wp:wp-photo-album-plus/general {"wppaAlbum":0,"wppaShortcode":"[wppa type=\\"slideonly\\" album=\\"#last\\"]"} --><div>[wppa type="slideonly" album="#last"]</div><!-- /wp:wp-photo-album-plus/general -->';
		$this->assertSame( 42, $this->extract_shortcode_id( $exact_block, $plugin ) );
		$this->assertFalse( $this->extract_shortcode_id( $dynamic_block, $plugin ) );
		$this->assertSame(
			42,
			$plugin->get_content_block_identifier(
				array(
					'attrs' => array( 'wppaAlbum' => 42, 'wppaShortcode' => '[wppa type="slideonly" album="42"]' ),
					'innerContent' => array(),
				)
			)
		);
		$this->assertFalse(
			$plugin->get_content_block_identifier(
				array(
					'attrs' => array( 'id' => 99, 'wppaAlbum' => 0, 'wppaShortcode' => '[wppa type="slideonly" album="#last"]' ),
					'innerContent' => array(),
				)
			)
		);
		$this->assertFalse(
			$plugin->get_content_block_identifier(
				array(
					'attrs' => array( 'wppaShortcode' => '[wppa album="42"]' ),
					'innerContent' => array( '<div>[wppa album="#last"]</div>' ),
				)
			)
		);

		$migrator = new ContentMigrator( new MigratorEngine(), 'content' );
		$extract = new \ReflectionMethod( ContentMigrator::class, 'extract_gallery_id_from_block' );
		$this->assertSame(
			42,
			$extract->invoke(
				$migrator,
				array( 'attrs' => array( 'wppaAlbum' => 42, 'wppaShortcode' => '[wppa type="slideonly" album="42"]' ) ),
				$plugin
			)
		);
		$this->assertFalse(
			$extract->invoke(
				$migrator,
				array( 'attrs' => array( 'id' => 99, 'wppaAlbum' => 0, 'wppaShortcode' => '[wppa type="slideonly" album="#last"]' ) ),
				$plugin
			)
		);
	}

	public function test_retry_discovery_reuses_migrated_gallery_instead_of_creating_duplicates(): void {
		$plugin = new WpPhotoAlbumPlus();
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->tables = array( 'wp_wppa_albums', 'wp_wppa_photos' );
		$wpdb->albums = array( $this->album( 50, 'Retry', '', 0, 1, 1 ) );
		$wpdb->photos = array( $this->photo( 501, 50, 'jpg', 'Only', '', 1 ) );
		$this->create_photo_file( '501.jpg' );
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );
		$GLOBALS['foogallery_migrate_test_attachment_url_to_postid']['https://example.test/wp-content/uploads/wppa/501.jpg'] = 4500;

		$gallery = $plugin->find_galleries()[0];
		$gallery->migrate();
		$this->assertTrue( $gallery->migrated );
		$this->assertCount( 0, $GLOBALS['foogallery_migrate_test_imported_attachments'] );
		$post_count = count( $GLOBALS['foogallery_migrate_test_posts'] );

		$retry = $plugin->find_galleries()[0];
		$retry->migrate();
		$this->assertTrue( $retry->migrated );
		$this->assertSame( $gallery->migrated_id, $retry->migrated_id );
		$this->assertSame( $post_count, count( $GLOBALS['foogallery_migrate_test_posts'] ) );
		$this->assertCount( 0, $GLOBALS['foogallery_migrate_test_imported_attachments'] );
	}

	public function test_content_migrator_replaces_only_the_exact_numeric_shortcode(): void {
		$plugin = new WpPhotoAlbumPlus();
		$engine = $GLOBALS['foogallery_migrate_engine_instance'];
		$exact = '[wppa type="album" album="42"]';
		$dynamic = '[wppa type="album" album="#last"]';
		$post = (object) array(
			'ID'           => 700,
			'post_title'   => 'WPPA content',
			'post_content' => $exact . ' ' . $dynamic,
			'post_type'    => 'page',
			'post_status'  => 'publish',
		);
		$GLOBALS['foogallery_migrate_test_posts'][700] = $post;
		$GLOBALS['wpdb']->content_posts = array( $post );
		$plugin->is_detected = true;
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );
		$engine->set_migrator_setting( MigratorEngine::KEY_PLUGINS, array( $plugin ) );

		$gallery = $plugin->get_gallery(
			array(
				'ID'             => 42,
				'title'          => 'Exact',
				'data'           => null,
				'children'       => array(),
				'children_count' => 1,
				'settings'       => array(),
			)
		);
		$gallery->migrated = true;
		$gallery->migrated_id = 9042;
		$engine->add_migrated_object( $gallery );

		$migrator = new ContentMigrator( $engine, 'content' );
		$migrator->scan_content( true );
		$get_items = new \ReflectionMethod( $migrator, 'get_content_items' );
		$items = $get_items->invoke( $migrator );

		$this->assertCount( 1, $items );
		$this->assertSame( $exact, $items[0]['original_content'] );
		$result = $migrator->replace_content( array( 0 ) );
		$this->assertSame( 1, $result['success'] );
		$this->assertSame( '[foogallery id="9042"] ' . $dynamic, $GLOBALS['foogallery_migrate_test_posts'][700]->post_content );

		$exact_block = '<!-- wp:wp-photo-album-plus/general {"wppaShortcode":"[wppa album=\\"42\\"]"} --><div>[wppa album="42"]</div><!-- /wp:wp-photo-album-plus/general -->';
		$mixed_block = '<!-- wp:wp-photo-album-plus/general {"wppaShortcode":"[wppa album=\\"42\\"]"} --><div>[wppa album="#last"]</div><!-- /wp:wp-photo-album-plus/general -->';
		$post->post_content = $exact_block . "\nseparator\n" . $exact_block . "\n" . $mixed_block;
		$GLOBALS['foogallery_migrate_test_posts'][700] = $post;
		$GLOBALS['wpdb']->content_posts = array( $post );
		$this->assertIsArray( $migrator->scan_content( true ) );
		$items = $get_items->invoke( $migrator );
		$this->assertCount( 2, $items, 'Only exact blocks should be candidates.' );
		$keys = array_keys( $items );
		$this->assertNotSame( $items[ $keys[0] ]['match_offset'], $items[ $keys[1] ]['match_offset'] );
		$result = $migrator->replace_content( array( $keys[1] ) );
		$this->assertSame( 1, $result['success'] );
		$this->assertSame( 1, substr_count( $GLOBALS['foogallery_migrate_test_posts'][700]->post_content, $exact_block ) );
		$this->assertStringContainsString( $mixed_block, $GLOBALS['foogallery_migrate_test_posts'][700]->post_content );
	}

	public function object_id( $object ): int {
		return (int) $object->ID;
	}

	private function album( int $id, string $name, string $description, int $parent, int $order, int $photo_order, int $subalbum_order = 1 ): object {
		return (object) array(
			'id'            => $id,
			'name'          => $name,
			'description'   => $description,
			'a_order'       => $order,
			'a_parent'      => $parent,
			'p_order_by'    => $photo_order,
			'suba_order_by' => (string) $subalbum_order,
			'timestamp'     => '2026-01-01 00:00:00',
			'status'        => 'publish',
			'capability'    => '',
		);
	}

	private function photo( int $id, int $album, string $ext, string $name, string $description, int $order, string $status = 'publish', string $alt = '', string $timestamp = '2026-01-01 00:00:00' ): object {
		return (object) array(
			'id'           => $id,
			'album'        => $album,
			'ext'          => $ext,
			'name'         => $name,
			'description'  => $description,
			'p_order'      => $order,
			'owner'        => 'admin',
			'timestamp'    => $timestamp,
			'status'       => $status,
			'alt'          => $alt,
			'filename'     => $name . '.' . $ext,
			'modified'     => $timestamp,
			'exifdtm'      => '',
			'mean_rating'  => '0',
			'rating_count' => 0,
			'videox'       => 0,
			'videoy'       => 0,
			'duration'     => '',
			'misc'         => '',
		);
	}

	private function create_photo_file( string $relative_path ): void {
		$file = $this->test_upload_dir() . '/wppa/' . $relative_path;
		$directory = dirname( $file );
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}
		$fixtures = array(
			'gif'  => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
			'jpg'  => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/Iaf/2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
			'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/Iaf/2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
			'png'  => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
			'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
		);
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$data = isset( $fixtures[ $extension ] ) ? base64_decode( $fixtures[ $extension ] ) : 'synthetic non-image fixture';
		file_put_contents( $file, $data );
		$this->created_files[] = $file;
	}

	private function test_upload_dir(): string {
		$environment_basedir = getenv( 'FOOGALLERY_MIGRATE_TEST_UPLOAD_DIR' );
		$basedir = isset( $GLOBALS['foogallery_migrate_test_upload_dir'] )
			? $GLOBALS['foogallery_migrate_test_upload_dir']
			: ( is_string( $environment_basedir ) && '' !== $environment_basedir ? $environment_basedir : '/tmp/uploads' );

		return rtrim( (string) $basedir, '/\\' );
	}

	private function extract_shortcode_id( string $content, WpPhotoAlbumPlus $plugin ) {
		$ids = array();
		foreach ( $plugin->get_shortcode_patterns() as $pattern ) {
			if ( ! preg_match_all( $pattern, $content, $all_matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $all_matches as $matches ) {
				if ( method_exists( $plugin, 'get_content_match_identifier' ) ) {
					$id = $plugin->get_content_match_identifier( $matches );
					if ( false !== $id ) {
						$ids[ $id ] = true;
					}
					continue;
				}
				foreach ( array_slice( $matches, 1 ) as $match ) {
					if ( is_numeric( $match ) ) {
						$ids[ (int) $match ] = true;
						break;
					}
				}
			}
		}

		if ( 1 !== count( $ids ) ) {
			return false;
		}
		$ids = array_keys( $ids );
		return (int) reset( $ids );
	}
}

class FakeWpPhotoAlbumPlusWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $posts = 'wp_posts';
	public $tables = array();
	public $albums = array();
	public $photos = array();
	public $content_posts = array();
	public $table_detection_arguments = array();
	private $last_prepare_args = array();

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_prepare_args = $args;
		return $query;
	}

	public function get_var( $query ) {
		if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
			$table = isset( $this->last_prepare_args[0] ) ? str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $this->last_prepare_args[0] ) : '';
			$this->table_detection_arguments[] = $table;
			return in_array( $table, $this->tables, true ) ? $table : null;
		}

		return null;
	}

	public function get_results( $query, $output = null ) {
		if ( false !== strpos( $query, 'FROM ' . $this->posts ) ) {
			return $this->content_posts;
		}
		if ( false !== strpos( $query, 'wppa_albums' ) ) {
			return $this->albums;
		}
		if ( false !== strpos( $query, 'wppa_photos' ) ) {
			return $this->photos;
		}
		return array();
	}
}
