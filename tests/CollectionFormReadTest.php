<?php
namespace {
	if ( ! function_exists( 'foogallery_plugin_name' ) ) { function foogallery_plugin_name() { return 'FooGallery'; } }
	if ( ! function_exists( 'foogallery_migrate_admin_url' ) ) { function foogallery_migrate_admin_url( $tab ) { return 'https://example.test/admin.php?tab=' . $tab; } }
	if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $key, $value, $url ) { return $url . '&' . $key . '=' . $value; } }
	if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $number ) { return (string) $number; } }
}

namespace FooPlugins\FooGalleryMigrate\Tests {

use FooPlugins\FooGalleryMigrate\Migrators\AlbumMigrator;
use FooPlugins\FooGalleryMigrate\Migrators\GalleryMigrator;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use PHPUnit\Framework\TestCase;

class CollectionFormReadTest extends TestCase {
	private function objects( $kind ) {
		$plugin = new CollectionFormPlugin(); $objects = array();
		for ( $i = 0; $i < 121; ++$i ) {
			$object = 'galleries' === $kind ? new Gallery( $plugin ) : new Album( $plugin );
			$object->ID = $i; $object->title = 'Item ' . $i; $object->children_count = 2;
			$objects[9 === $i ? '09' : $i] = $object;
		}
		return $objects;
	}
	private function render( $kind, $repository, $objects, $size, $page = 1 ) {
		$engine = new CollectionFormEngine( $repository, $objects, $kind, $size );
		$key = 'galleries' === $kind ? 'gallery_paged' : 'album_paged';
		$_GET[$key] = $page;
		$migrator = 'galleries' === $kind ? new GalleryMigrator( $engine, $kind ) : new AlbumMigrator( $engine, $kind );
		ob_start(); try {
			if ( 'galleries' === $kind ) { $migrator->render_gallery_form(); } else { $migrator->render_album_form(); }
			return ob_get_clean();
		} catch ( \Exception $error ) { ob_end_clean(); throw $error; }
		finally { unset( $_GET[$key] ); }
	}
	public function test_gallery_and_album_page_two_read_only_visible_roots_and_match_option_markup(): void {
		foreach ( array( 'galleries', 'albums' ) as $kind ) {
			$objects = $this->objects( $kind ); $repository = new CollectionFormRepository( $objects );
			$table = $this->render( $kind, $repository, $objects, 20, 2 );
			$option = $this->render( $kind, null, $objects, 20, 2 );
			$this->assertSame( preg_replace( '/\s+/', '', $option ), preg_replace( '/\s+/', '', $table ) );
			$this->assertSame( array( array( 20, 20 ) ), $repository->pages );
			$this->assertSame( 1, $repository->started_reads );
			$this->assertStringContainsString( 'Item 20', $table );
			$this->assertStringNotContainsString( 'Item 120', $table );
		}
	}
	public function test_show_all_streams_internal_hundreds_without_full_collection_read(): void {
		foreach ( array( 'galleries', 'albums' ) as $kind ) {
			$objects = $this->objects( $kind ); $repository = new CollectionFormRepository( $objects );
			$html = $this->render( $kind, $repository, $objects, 0 );
			$this->assertSame( array( array( 0, 100 ), array( 100, 21 ) ), $repository->pages );
			$this->assertStringContainsString( 'Item 120', $html );
			$this->assertSame( 121, substr_count( $html, 'name="foogallery-title-' ) + substr_count( $html, 'name="foogallery-album-title-' ) );
		}
	}
	public function test_last_page_requests_only_remaining_roots(): void {
		foreach ( array( 'galleries', 'albums' ) as $kind ) {
			$objects = $this->objects( $kind ); $repository = new CollectionFormRepository( $objects );
			$html = $this->render( $kind, $repository, $objects, 20, 7 );
			$this->assertSame( array( array( 120, 1 ) ), $repository->pages );
			$this->assertStringContainsString( 'Item 120', $html );
			$this->assertStringNotContainsString( 'Item 119', $html );
		}
	}
	public function test_deleted_earlier_root_keeps_offset_as_sorted_position_not_original_order(): void {
		foreach ( array( 'galleries', 'albums' ) as $kind ) {
			$objects = $this->objects( $kind ); unset( $objects[0] );
			$repository = new CollectionFormRepository( $objects );
			$table = $this->render( $kind, $repository, $objects, 20, 2 );
			$this->assertSame( array( array( 20, 20 ) ), $repository->pages );
			$this->assertStringContainsString( 'Item 21', $table );
			$this->assertStringNotContainsString( 'Item 20', $table );
		}
	}
	public function test_changed_collection_discards_buffered_form(): void {
		$objects = $this->objects( 'galleries' ); $repository = new CollectionFormRepository( $objects ); $repository->change_after_page = true;
		ob_start(); try {
			$this->render( 'galleries', $repository, $objects, 20, 2 );
			$this->fail( 'Stale collection must refuse.' );
		} catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
		finally { $html = ob_get_clean(); }
		$this->assertSame( '', $html );
	}
	public function test_empty_collection_and_short_page_do_not_fall_back_to_full_table_read(): void {
		foreach ( array( 'galleries', 'albums' ) as $kind ) {
			$empty = new CollectionFormRepository( array() );
			$html = $this->render( $kind, $empty, array(), 20 );
			$this->assertStringContainsString( 'No ' . $kind . ' found!', $html );
			$this->assertSame( array(), $empty->pages );
			$objects = $this->objects( $kind ); $repository = new CollectionFormRepository( $objects ); $repository->short_page = true;
			ob_start(); try {
				$this->render( $kind, $repository, $objects, 20, 2 );
				$this->fail( 'Short indexed page must refuse.' );
			} catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
			finally { $this->assertSame( '', ob_get_clean() ); }
		}
	}
	public function test_started_root_outside_visible_page_uses_targeted_read(): void {
		foreach ( array( 'galleries', 'albums' ) as $kind ) {
			$objects = $this->objects( $kind ); $repository = new CollectionFormRepository( $objects );
			$repository->started = $objects[120];
			$engine = new CollectionFormEngine( $repository, $objects, $kind, 20 );
			$migrator = 'galleries' === $kind ? new GalleryMigrator( $engine, $kind ) : new AlbumMigrator( $engine, $kind );
			$this->assertSame( $objects[120]->unique_identifier(), $migrator->get_current_object_being_migrated() );
			$this->assertSame( 1, $repository->started_reads );
			$this->assertSame( array(), $repository->pages );
		}
	}
}

class CollectionFormPlugin {
	public function name() { return 'Fixture'; }
	public function get_migration_gallery_template( $gallery ) { return 'default'; }
	public function get_gallery_settings( $gallery, $default ) { return $default; }
}

class CollectionFormRepository {
	public $pages = array();
	public $started_reads = 0;
	public $change_after_page = false;
	public $short_page = false;
	public $started = null;
	private $objects;
	private $head;
	public function __construct( $objects ) { $this->objects = $objects; $this->head = array( 'id' => 1, 'revision' => 1 ); }
	public function view_context( $bucket ) { return array( 'found' => true, 'total' => count( $this->objects ), 'head' => $this->head ); }
	public function view_page( $bucket, $offset, $limit ) {
		if ( $limit > 100 ) { throw new \RuntimeException( 'Unbounded page.' ); }
		$this->pages[] = array( $offset, $limit ); $rows = array();
		foreach ( array_slice( $this->objects, $offset, $limit, true ) as $key => $object ) { $rows[] = array( 'key' => $key, 'value' => $object ); }
		if ( $this->short_page ) { array_pop( $rows ); }
		$head = $this->head;
		if ( $this->change_after_page ) { $this->head['revision']++; }
		return array( 'rows' => $rows, 'head' => $head );
	}
	public function first_started( $bucket ) {
		++$this->started_reads;
		return null === $this->started ? array( 'found' => false, 'head' => null ) : array( 'found' => true, 'value' => $this->started );
	}
}

class CollectionFormEngine {
	private $repository;
	private $objects;
	private $kind;
	private $size;
	public function __construct( $repository, $objects, $kind, $size ) {
		$this->repository = $repository; $this->objects = $objects; $this->kind = $kind; $this->size = $size;
	}
	public function table_collection_repository() { return $this->repository; }
	public function get_migrator_setting( $key, $default = false ) {
		if ( $key === $this->kind ) {
			if ( null !== $this->repository ) { throw new \RuntimeException( 'Full table collection read forbidden.' ); }
			return $this->objects;
		}
		return $default;
	}
	public function get_page_size() { return $this->size; }
	public function is_debug_enabled() { return false; }
	public function assert_storage_writer() { return true; }
}
}
