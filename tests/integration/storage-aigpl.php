<?php
/** Finite 204-image AIGPL + 5,000 history integration on real FooGallery/WP/SQL. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Owned disposable WordPress required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
if ( ! function_exists( 'foogallery_default_gallery_template' ) || ! defined( 'FOOGALLERY_VERSION' ) || ! post_type_exists( FOOGALLERY_CPT_GALLERY ) ) { throw new RuntimeException( 'Real installed active FooGallery required; no API substitute.' ); }
global $wpdb;
$blog = get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$main = is_multisite() ? get_main_site_id( get_main_network_id() ) : $blog; $main_options = $wpdb->get_blog_prefix( $main ) . 'options';
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing shared tables.' ); } }
$missing = new stdClass(); $saved = array(); foreach ( $names as $name ) { $saved[$name] = get_option( $name, $missing ); }
foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $main_options . '` WHERE option_name=%s', $name ) ) ) { throw new RuntimeException( 'Refusing existing schema metadata.' ); } }
if ( $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_type=%s LIMIT 1', Aigpl::POST_TYPE ) ) ) { throw new RuntimeException( 'Refusing unrelated source posts.' ); }
$checks = array(); $posts = array(); $metrics = array(); $files = array(); $active = is_multisite() ? get_site_option( 'active_sitewide_plugins', null ) : null;
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$make = function ( $args ) use ( &$posts ) { $id = wp_insert_post( $args, true ); if ( is_wp_error( $id ) || $id < 1 ) { throw new RuntimeException( 'Owned fixture post creation failed.' ); } $posts[] = $id; return $id; };
$observe = function ( $id, $post, $update ) use ( &$posts ) { if ( ! $update && FOOGALLERY_CPT_GALLERY === $post->post_type ) { $posts[] = $id; } };
$session = function () use ( $wpdb ) { $r = array(); foreach ( $wpdb->get_results( "SHOW SESSION STATUS WHERE Variable_name IN ('Questions','Rows_read','Rows_sent','Bytes_received','Bytes_sent')", ARRAY_A ) as $row ) { $r[$row['Variable_name']] = (int) $row['Value']; } return $r; };
$upload = wp_upload_dir(); $dir = $upload['basedir'] . '/fgm-resume-' . wp_generate_password( 12, false, false );
if ( file_exists( $dir ) || ! wp_mkdir_p( $dir ) ) { throw new RuntimeException( 'Owned media directory unavailable.' ); }
add_action( 'wp_insert_post', $observe, 10, 3 );
register_post_type( Aigpl::POST_TYPE, array( 'public' => false ) );
try {
	$ids = array();
	for ( $i = 1; $i <= 204; $i++ ) {
		$file = $dir . '/' . $i . '.png';
		if ( false === file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' ) ) ) { throw new RuntimeException( 'Owned PNG creation failed.' ); } $files[] = $file;
		$relative = substr( $file, strlen( $upload['basedir'] ) + 1 );
		$id = $make( array( 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_title' => 'Owned image ' . $i, 'guid' => $upload['baseurl'] . '/' . $relative ) );
		update_post_meta( $id, '_wp_attached_file', $relative ); update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => $relative, 'sizes' => array() ) ); $ids[] = $id;
	}
	$source = $make( array( 'post_type' => Aigpl::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Owned 204-image AIGPL' ) ); update_post_meta( $source, Aigpl::META_GALLERY_IMAGES, $ids );
	foreach ( array( defined( 'FOOGM_AIGPL_MODE' ) ? FOOGM_AIGPL_MODE : 'table' ) as $mode ) {
		foreach ( $names as $name ) { delete_option( $name ); }
		unset( $GLOBALS['foogallery_migrate_engine_instance'] );
		$settings = new MigratorSettings(); $settings->set_migrator_setting( MigratorSettings::KEY_MIGRATED, array() ); $settings->set_migrator_setting( MigratorSettings::KEY_MIGRATED_REVISION, 0 );
		if ( 'table' === $mode ) {
			if ( is_multisite() ) { $enabled = is_array( $active ) ? $active : array(); $enabled[plugin_basename( FOOGM_FILE )] = time(); update_site_option( 'active_sitewide_plugins', $enabled ); }
			// Finite synthetic trusted routing profile, NOT production approval.
			$profile = array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(), 'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 8388608, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
			$request = array( 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) );
			$switched = ( new StorageOperations( $profile ) )->dispatch( 'switch', $request );
			$check( ! is_wp_error( $switched ), 'public route selects normal table runtime ' . $mode . ( is_wp_error( $switched ) ? ': ' . $switched->get_error_code() : '' ) );
		}
		$engine = new MigratorEngine(); $GLOBALS['foogallery_migrate_engine_instance'] = $engine; $engine->save_settings( array( 'images_per_turn' => 5 ) );
		$plugin = new Aigpl();
		for ( $i = 1; $i <= 5000; $i++ ) {
			if ( 1 === $i % 100 ) { $engine->begin_migrated_object_batch(); }
			$image = new Image( $plugin ); $image->source_url = 'https://owned-history.example.test/' . $i . '.png'; $image->migrated = true; $image->migrated_id = 1000000 + $i; $image->migration_status = Migratable::PROGRESS_COMPLETED;
			$engine->add_migrated_object( $image );
			if ( 0 === $i % 100 ) { $check( $engine->flush_migrated_object_batch(), 'history seed batch ' . $mode . ' ' . $i ); }
		}
		$check( $engine->has_object_been_migrated( 'https://owned-history.example.test/1.png' ), 'unrelated first history mapping exists ' . $mode );
		$engine->run_detection(); $check( in_array( $plugin->name(), $engine->get_detected_plugins(), true ), 'real AIGPL source detected ' . $mode );
		$migrator = $engine->get_gallery_migrator(); $objects = $migrator->get_objects_to_migrate();
		$check( 1 === count( $objects ) && 204 === $objects[0]->children_count, 'real ordered 204-image discovery ' . $mode ); $uid = $objects[0]->unique_identifier();
		$check( $migrator->queue_objects_for_migration( array( $uid => array( 'title' => 'Owned destination ' . $mode ) ) ), 'real gallery queued ' . $mode );
		$turns = array(); $destination = 0;
		for ( $turn = 1; $turn <= 42; $turn++ ) {
			$before = $session(); $start_memory = memory_get_usage( true ); $start = microtime( true );
			$check( $migrator->migrate(), 'real image turn ' . $mode . ' ' . $turn );
			$elapsed = microtime( true ) - $start; $after = $session(); $deltas = array(); foreach ( $after as $key => $value ) { $deltas[$key] = $value - $before[$key]; }
			$object = $migrator->get_objects_to_migrate()[0]; $destination = $object->migrated_id;
			$turns[] = array( 'turn' => $turn, 'elapsed_ms' => round( 1000 * $elapsed, 3 ), 'memory_delta_bytes' => memory_get_usage( true ) - $start_memory, 'peak_memory_bytes' => memory_get_peak_usage( true ), 'session_deltas' => $deltas, 'children_completed' => $object->migrated_child_count );
			if ( $object->migrated ) { break; }
			unset( $GLOBALS['foogallery_migrate_engine_instance'], $migrator, $engine ); $engine = new MigratorEngine(); $GLOBALS['foogallery_migrate_engine_instance'] = $engine; $migrator = $engine->get_gallery_migrator();
		}
		$check( $object->migrated && 204 === $object->migrated_child_count && $turn <= 42, 'fresh normal engines complete finite large gallery ' . $mode );
		$check( $ids === get_post_meta( $destination, FOOGALLERY_META_ATTACHMENTS, true ), 'all destination image IDs remain exact and ordered ' . $mode );
		$check( 1 === count( get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => Migratable::META_SOURCE_KEY, 'meta_value' => $uid ) ) ), 'exactly one destination ' . $mode );
		$check( $migrator->migrate(), 'completed rerun is idempotent ' . $mode );
		$check( $engine->has_object_been_migrated( 'https://owned-history.example.test/5000.png' ), 'unrelated last history mapping survives ' . $mode );
		$summary = $engine->get_migrated_objects_summary();
		$check( is_array( $summary ), 'bounded actual history summary ' . $mode );
		$html = do_shortcode( '[foogallery id="' . $destination . '"]' );
		$check( is_string( $html ) && false !== strpos( $html, 'foogallery' ), 'installed FooGallery renders destination shortcode ' . $mode );
		$status = StorageAdmin::status(); $check( $mode === $status['backend'] && '' === $status['problem'], 'operator status uses live normal authority ' . $mode );
		$metrics[$mode] = array( 'history_seed' => 5000, 'images' => 204, 'turns' => $turns, 'summary' => $summary, 'rendered_bytes' => strlen( $html ), 'status' => $status );
		wp_delete_post( $destination, true );
	}
} finally {
	remove_action( 'wp_insert_post', $observe, 10 ); unset( $GLOBALS['foogallery_migrate_engine_instance'] );
	foreach ( array_unique( array_reverse( $posts ) ) as $id ) { if ( 'attachment' === get_post_type( $id ) ) { wp_delete_attachment( $id, true ); } else { wp_delete_post( $id, true ); } if ( get_post( $id ) ) { throw new RuntimeException( 'Owned post cleanup failed.' ); } }
	foreach ( $files as $file ) { if ( file_exists( $file ) && ! unlink( $file ) ) { throw new RuntimeException( 'Owned PNG cleanup failed.' ); } }
	if ( is_dir( $dir ) && ! rmdir( $dir ) ) { throw new RuntimeException( 'Owned media directory cleanup failed.' ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); }
	foreach ( $saved as $name => $value ) { if ( $missing === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
	foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $main_options . '` WHERE option_name=%s', $name ) ); }
	if ( is_multisite() ) { if ( null !== $active ) { update_site_option( 'active_sitewide_plugins', $active ); } else { delete_site_option( 'active_sitewide_plugins' ); } }
}
echo wp_json_encode( array( 'status' => 'pass', 'check_count' => count( $checks ), 'checks' => $checks, 'blog_id' => $blog, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'foogallery' => FOOGALLERY_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ), 'metrics' => $metrics, 'calibration_status' => 'synthetic-routing-only-not-production-approval', 'measurement_scope' => 'native CLI same-process session deltas; not an approved ingress envelope, HTTP/legacy timing or independent acceptance' ) ) . "\n";
