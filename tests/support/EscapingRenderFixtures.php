<?php
// Data/repository doubles shared by unit and native-WordPress renderer checks.
namespace {
	if ( ! function_exists( 'foogallery_plugin_name' ) ) { function foogallery_plugin_name() { return 'FooGallery'; } }
	if ( ! function_exists( 'foogallery_migrate_admin_url' ) ) { function foogallery_migrate_admin_url( $tab ) { return 'https://example.test/admin.php?tab=' . $tab; } }
	if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $value ) { return (string) $value; } }
	if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $key, $value, $url ) { return $url . '&' . $key . '=' . $value; } }
}
namespace FooPlugins\FooGalleryMigrate\Tests {
use FooPlugins\FooGalleryMigrate\Migrators\GalleryMigrator;
use FooPlugins\FooGalleryMigrate\Migrators\AlbumMigrator;
use FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Image;

class EscapingProbePlugin {
	private $text;
	public function __construct( $text ) { $this->text = $text; }
	public function name() { return $this->text; }
	public function get_migration_gallery_template( $gallery ) { return $this->text; }
	public function get_gallery_settings( $gallery, $default ) { return $default; }
}
class EscapingProbeRepository {
	public $change = false;
	private $items; private $table; private $headers = 0;
	public function __construct( $items, $table ) { $this->items = $items; $this->table = $table; }
	public function is_table() { return $this->table; }
	public function view_context( $bucket ) {
		return array( 'found' => true, 'total' => count( $this->items ), 'head' => array( 'id' => 1, 'revision' => $this->change && ++$this->headers > 1 ? 2 : 1 ) );
	}
	public function first_started( $bucket ) { return array( 'found' => false ); }
	public function view_page( $bucket, $offset, $limit ) {
		$rows = array(); foreach ( array_slice( $this->items, $offset, $limit, true ) as $key => $item ) { $rows[] = array( 'key' => $key, 'value' => $item ); }
		return array( 'head' => array( 'id' => 1, 'revision' => 1 ), 'rows' => $rows );
	}
	public function window( $offset, $limit, $keys = null ) {
		$revision = 1;
		if ( array() === $keys && $this->change && ++$this->headers > 1 ) { $revision = 2; }
		return array( 'items' => array() === $keys ? array() : array_slice( $this->items, $offset, $limit, true ),
			'total' => count( $this->items ), 'revision' => $revision,
			'progress' => array( 'started' => true, 'complete' => true, 'scanned' => count( $this->items ) ) );
	}
	public function plugin_window( $plugin, $offset, $limit ) { return array( 'items' => array(), 'total' => count( $this->items ), 'revision' => 1 ); }
}
class EscapingProbeEngine {
	private $repository; private $items; private $kind;
	public function __construct( $repository, $items, $kind ) { $this->repository = $repository; $this->items = $items; $this->kind = $kind; }
	public function assert_storage_writer() { return true; }
	public function table_collection_repository() { return $this->repository->is_table() ? $this->repository : null; }
	public function content_scan_repository() { return $this->repository; }
	public function get_migrator_setting( $key, $default = false ) {
		if ( $key === $this->kind ) { return $this->items; }
		if ( $key === $this->kind . '_scan_state' ) { return $this->repository->window( 0, 20 ); }
		return $default;
	}
	public function get_page_size() { return 20; }
	public function is_debug_enabled() { return false; }
	public function get_plugins( $detected = true ) { return array(); }
	public function get_migrated_objects() { return array(); }
	public function get_migrated_revision() { return 0; }
}

function render_escaping_probe( $kind, $table, $text, $change = false, $url = null, $compact_error = false ) {
	$plugin = new EscapingProbePlugin( $text );
	if ( 'content' === $kind ) {
		$items = array( $text => array( 'post_id' => 71, 'post_title' => $text, 'post_type' => $text,
			'plugin_name' => $text, 'source_context' => $text, 'gallery_id' => $text,
			'type' => $text, 'migrated_foogallery_id' => 0, 'original_content' => $text,
			'replacement_content' => $text, 'match_offset' => 0 ) );
		$bucket = 'block-shortcode';
	} else {
		$image = new Image(); $image->source_url = null === $url ? 'https://example.test/image.jpg?title=' . $text : $url;
		$image->error = new \WP_Error( 'fixture_error', $text );
		$gallery = new Gallery( $plugin ); $gallery->ID = $text; $gallery->title = $text;
		$gallery->children = array( $image ); $gallery->migration_status = 'error';
		$gallery->error = new \WP_Error( 'fixture_error', $text );
		if ( $compact_error ) {
			$gallery->children = array(); $gallery->children_count = 3;
			$gallery->children_errors = array( $text ); $gallery->migrated = true;
		}
		$object = $gallery;
		if ( 'albums' === $kind ) { $object = new Album( $plugin ); $object->ID = $text; $object->title = $text; $object->children = array( $gallery ); }
		$items = array( $object ); $bucket = $kind;
	}
	$repository = new EscapingProbeRepository( $items, $table ); $repository->change = $change;
	$engine = new EscapingProbeEngine( $repository, $items, $bucket );
	$migrator = 'content' === $kind ? new ContentMigrator( $engine, $bucket ) : ( 'galleries' === $kind ? new GalleryMigrator( $engine, $bucket ) : new AlbumMigrator( $engine, $bucket ) );
	$method = 'content' === $kind ? 'render_content_form' : ( 'galleries' === $kind ? 'render_gallery_form' : 'render_album_form' );
	$level = ob_get_level(); ob_start();
	try { $migrator->$method(); $html = ob_get_clean(); }
	catch ( \Exception $error ) { while ( ob_get_level() > $level ) { ob_end_clean(); } throw $error; }
	catch ( \Throwable $error ) { while ( ob_get_level() > $level ) { ob_end_clean(); } throw $error; }
	return $html;
}
}
