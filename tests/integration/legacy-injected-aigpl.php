<?php
/** Injected AIGPL backend vertical on real WP/SQL, with an explicit destination API fixture. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Explicit disposable WordPress admission required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
// No installed FooGallery is pretended here: these are configuration-only API fixtures.
foreach ( array( 'FOOGALLERY_CPT_GALLERY' => 'foogallery', 'FOOGALLERY_META_TEMPLATE' => '_foogallery_template', 'FOOGALLERY_META_SETTINGS' => '_foogallery_settings', 'FOOGALLERY_META_ATTACHMENTS' => '_foogallery_attachments' ) as $constant => $value ) { if ( defined( $constant ) ) { throw new RuntimeException( 'Destination fixture requires no loaded FooGallery.' ); } define( $constant, $value ); }
function foogallery_default_gallery_template() { return 'default'; }
function foogallery_get_setting( $name ) { return false; }
register_post_type( 'foogallery', array( 'public' => false ) ); register_post_type( Aigpl::POST_TYPE, array( 'public' => false ) );
global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing tables.' ); } }
if ( $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_type = %s LIMIT 1', Aigpl::POST_TYPE ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing unrelated AIGPL source posts.' ); }
$sentinel = new stdClass(); $saved = array(); foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS ) as $key ) { $saved[$key] = get_option( $key, $sentinel ); }
$old_engine = isset( $GLOBALS['foogallery_migrate_engine_instance'] ) ? $GLOBALS['foogallery_migrate_engine_instance'] : null;
$created = array(); $posts = array(); $checks = array();
$observe_destination = function ( $id, $post, $update ) use ( &$posts ) { if ( ! $update && 'foogallery' === $post->post_type ) { $posts[] = $id; } };
add_action( 'wp_insert_post', $observe_destination, 10, 3 );
$check = function ( $value, $label ) use ( &$checks ) { if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$make_post = function ( $args ) use ( &$posts ) { $id = wp_insert_post( $args, true ); if ( is_wp_error( $id ) || $id < 1 ) { throw new RuntimeException( 'Owned source post creation failed.' ); } $posts[] = $id; return $id; };
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$settings = new MigratorSettings(); $db = new LegacyTransactionConnection( $wpdb ); $blog = (int) get_current_blog_id();
	$empty_history = $settings->compact_state_value( 'migrated', array() );
	( new TableSnapshot( $db, $blog ) )->replace_many( array( array( 'key' => 'migrated', 'kind' => 'map', 'order' => 0, 'value' => $empty_history, 'expected_revision' => null ), array( 'key' => 'migrated-revision', 'kind' => 'scalar', 'order' => 1, 'value' => 0, 'expected_revision' => null ) ) );
	( new LegacyOptionBackend() )->replace_all( array( 'sentinel' => 'options must not be used by injected engine' ) );
	$option_before = serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$ids = array(); $base_url = wp_get_upload_dir()['baseurl'];
	foreach ( array( 'one', 'two' ) as $name ) {
		$relative = 't_04219c53-aigpl/' . $name . '-' . $blog . '.png';
		$id = $make_post( array( 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_title' => $name, 'guid' => $base_url . '/' . $relative ) );
		update_post_meta( $id, '_wp_attached_file', $relative ); update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => $relative, 'sizes' => array() ) ); $ids[] = $id;
	}
	$source_id = $make_post( array( 'post_type' => Aigpl::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Owned AIGPL source' ) ); update_post_meta( $source_id, Aigpl::META_GALLERY_IMAGES, $ids );
	$content_id = $make_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Owned AIGPL content', 'post_content' => '[aigpl-gallery id="' . $source_id . '"]' ) );
	$history = new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog ), $settings ); $engine = new MigratorEngine( $history ); $GLOBALS['foogallery_migrate_engine_instance'] = $engine;
	$engine->save_settings( array( 'images_per_turn' => 1 ) );
	$check( false === $engine->get_migrator_setting( 'galleries', false ), 'missing gallery default preserved for lazy discovery' );
	$plugins = $engine->run_detection(); $check( count( $plugins ) === count( $engine->get_plugins() ), 'real detection plugin descriptors round-trip through injected table backend' );
	$check( in_array( ( new Aigpl() )->name(), $engine->get_detected_plugins(), true ), 'real AIGPL source SQL detected' );
	$migrator = $engine->get_gallery_migrator(); $objects = $migrator->get_objects_to_migrate(); $check( count( $objects ) === 1 && $objects[0]->ID === $source_id && $objects[0]->children_count === 2, 'lazy AIGPL discovery persists missing gallery head without child hydration' );
	$uid = $objects[0]->unique_identifier();
	$content = $engine->get_content_migrator(); $scan = $content->scan_content_batch( true );
	$check( $scan['started'] && $scan['complete'] && count( $engine->get_migrator_setting( 'block-shortcode_scan_state' )['items'] ) === 1, 'actual pre-migration content scan persists finding and cursor through injected scalars' );
	$check( $migrator->queue_objects_for_migration( array( $uid => array( 'title' => 'Owned destination' ) ) ), 'actual gallery caller creates scalar queue summary and persists queue' );
	$check( $migrator->migrate(), 'first real WordPress migration turn persists destination and child mapping' );
	$first = $migrator->get_objects_to_migrate()[0]; $destination = $first->migrated_id; if ( $destination > 0 ) { $posts[] = $destination; }
	$check( $destination > 0 && ! $first->migrated && $first->migrated_child_count === 1, 'one child per turn with actual destination post and resumable checkpoint' );
	$fresh_history = new MigratedHistoryRepository( new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), $blog ), new MigratorSettings() );
	$engine = new MigratorEngine( $fresh_history ); $GLOBALS['foogallery_migrate_engine_instance'] = $engine; $migrator = $engine->get_gallery_migrator();
	$check( $migrator->migrate(), 'fresh injected engine resumes second child from committed SQL state' );
	$last = $migrator->get_objects_to_migrate()[0];
	$check( $last->migrated && $last->migrated_id === $destination && $last->migrated_child_count === 2, 'resume keeps one destination and completes ordered children' );
	$check( get_post_meta( $destination, FOOGALLERY_META_ATTACHMENTS, true ) === $ids, 'actual destination SQL attachment order matches source' );
	$check( count( get_posts( array( 'post_type' => 'foogallery', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => Migratable::META_SOURCE_KEY, 'meta_value' => $uid ) ) ) === 1, 'destination idempotency query returns exactly one owned post' );
	$check( $migrator->migrate() && $fresh_history->count() === 3, 'completed turn is idempotent and bounded history has gallery plus two image mappings' );
	$content = $engine->get_content_migrator(); $check( $content->is_migration_status_stale(), 'fresh engine sees content status made stale by committed history' );
	$status = $content->refresh_migration_status_batch(); $check( $status['complete'] && ! $content->is_migration_status_stale(), 'actual affected content status refresh avoids full history hydration' );
	$item = $engine->get_migrator_setting( 'block-shortcode_scan_state' )['items'][0]; $check( $item['migrated'] && $item['migrated_foogallery_id'] === $destination, 'saved content resolves only its committed gallery mapping' );
	$replaced = $content->replace_content( array( 0 ) );
	$check( 1 === $replaced['success'] && empty( $replaced['errors'] ) && '[foogallery id="' . $destination . '"]' === get_post( $content_id )->post_content, 'actual selected AIGPL occurrence replacement writes and reads back owned WP post' );
	$check( $option_before === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'actual injected vertical never reads/writes fallback option state' );
	echo wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'destination_contract' => 'FooGallery configuration API fixture; destination posts/meta and source SQL are real WordPress; no installed FooGallery acceptance', 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	remove_action( 'wp_insert_post', $observe_destination, 10 );
	foreach ( array_unique( array_reverse( $posts ) ) as $id ) { wp_delete_post( $id, true ); if ( get_post( $id ) ) { throw new RuntimeException( 'Owned post cleanup failed.' ); } }
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Owned table cleanup failed.' ); } }
	foreach ( $saved as $key => $value ) { if ( $value === $sentinel ) { delete_option( $key ); } else { update_option( $key, $value ); } if ( serialize( get_option( $key, $sentinel ) ) !== serialize( $value ) ) { throw new RuntimeException( 'Owned option restoration failed.' ); } }
	if ( null === $old_engine ) { unset( $GLOBALS['foogallery_migrate_engine_instance'] ); } else { $GLOBALS['foogallery_migrate_engine_instance'] = $old_engine; }
}
