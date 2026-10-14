<?php
/** Real endpoint/engine barrier adoption; explicitly disposable WordPress only. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ||
	! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Explicitly disposable fixture required.' ); }
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
// wp_send_json() otherwise uses an uncatchable die outside real AJAX requests.
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

use FooPlugins\FooGalleryMigrate\Init;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;

class StorageAdoptionDenied extends RuntimeException {}
global $wpdb, $foogallery_migrate_engine_instance;
if ( false !== StorageAuthority::option_mode_error() ) { throw new RuntimeException( 'Refusing existing storage state.' ); }
$table = $wpdb->options;
$blog = get_current_blog_id();
$gate = StorageMaintenanceGate::current();
$prefix = $wpdb->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%';
if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $table . '` WHERE option_name LIKE %s LIMIT 1', $prefix ) ) ||
	false !== get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) || false !== get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS ) ) {
	throw new RuntimeException( 'Refusing existing migration options or writer.' );
}
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$raw = function () use ( $wpdb, $table ) {
	return $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM `' . $table . '` WHERE option_name IN (%s, %s) ORDER BY option_name',
		FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS ), ARRAY_A );
};
$writer_count = function () use ( $wpdb, $table, $prefix ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE option_name LIKE %s', $prefix ) );
};
$trap = function () { throw new RuntimeException( 'Unexpected engine snapshot hydration.' ); };
$die = function () { return function ( $message ) { throw new StorageAdoptionDenied( is_scalar( $message ) ? (string) $message : 'Unexpected denial payload' ); }; };
$race = null;
$denied = function ( $init, $method ) use ( $check ) {
	ob_start();
	try {
		if ( 'source-screen-post' === $method ) { require FOOGM_STORAGE_TEST_ROOT . '/includes/views/view-migrate-tab-sources.php'; }
		else { $init->$method(); }
		throw new RuntimeException( 'Endpoint returned without denial: ' . $method );
	}
	catch ( StorageAdoptionDenied $error ) {
		$output = ob_get_contents();
		$json = json_decode( $output, true );
		$check( false !== strpos( $error->getMessage(), 'storage' ) ||
			( is_array( $json ) && isset( $json['success'], $json['data']['message'] ) && false === $json['success'] && false !== strpos( $json['data']['message'], 'storage' ) ), $method . ' structured storage denial' );
	} finally { ob_end_clean(); }
};
$methods = array( 'ajax_start_migration', 'ajax_continue_migration', 'ajax_cancel_migration', 'ajax_refresh_migration',
	'ajax_retry_gallery_migration', 'ajax_check_gallery_errors', 'ajax_sync_image_tags', 'ajax_start_album_migration',
	'ajax_continue_album_migration', 'ajax_cancel_album_migration', 'ajax_refresh_album_migration', 'ajax_replace_content',
	'ajax_refresh_content', 'ajax_refresh_content_status', 'ajax_update_migrated_status', 'ajax_delete_migrated_object',
	'save_settings', 'detect_wordpress_core_galleries', 'migrate_content_no_js', 'refresh_content_no_js', 'refresh_content_status_no_js', 'source-screen-post' );
try {
	$check( add_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'opaque' => array( '09' => false, 9 => null, 'bytes' => "snow 雪\x00" ) ), '', 'no' ), 'seed retained opaque state' );
	$check( add_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, array( 'retained' => 'settings' ), '', 'no' ), 'seed retained settings' );
	$source = $raw();
	add_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $trap );
	add_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_SETTINGS, $trap );
	add_filter( 'wp_die_handler', $die, 999 );
	add_filter( 'wp_die_ajax_handler', $die, 999 );
	$init_reflection = new ReflectionClass( 'FooPlugins\\FooGalleryMigrate\\Init' );
	$init = $init_reflection->newInstanceWithoutConstructor();
	$foogallery_migrate_engine_instance = new stdClass(); // Any accidental engine access is fatal, not accepted denial.
	foreach ( array( 'maintenance', 'control', 'race' ) as $mode ) {
		foreach ( $methods as $method ) {
			$_POST = array( 'object_id' => 'retained', 'status' => 'completed', 'foogallery_migrate_retry_gallery_id' => 'retained',
				'foogallery_migrate_check_gallery_id' => 'retained', 'wordpress_gallery_mode' => 'create' );
			foreach ( array( 'foogallery_migrate', 'foogallery_album_migrate', 'foogallery_content_migrate', 'foogallery_migrate_settings', 'foogallery_migrate_detect_wordpress_core', 'foogallery_migrate_image_tags', 'foogallery_migrate_log', 'foogallery_migrate_detect' ) as $nonce ) {
				$_POST[$nonce] = wp_create_nonce( $nonce );
			}
			$_POST['action'] = str_replace( array( 'ajax_continue_migration', 'ajax_cancel_migration', 'ajax_refresh_migration', 'ajax_continue_album_migration', 'ajax_cancel_album_migration', 'ajax_refresh_album_migration' ), array( 'foogallery_migrate_continue', 'foogallery_migrate_cancel', 'foogallery_migrate_refresh', 'foogallery_album_migrate_continue', 'foogallery_album_migrate_cancel', 'foogallery_album_migrate_refresh' ), $method );
			$_REQUEST = $_POST;
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'foogallery_migrate_detect_wordpress_core' );
			$marker = 'control' === $mode ? StorageAuthority::CONTROL_OPTION : StorageAuthority::MAINTENANCE_OPTION;
			if ( 'race' === $mode ) {
				$race = function ( $sql ) {
					if ( 0 === strpos( $sql, 'INSERT IGNORE INTO' ) && false !== strpos( $sql, StorageMaintenanceGate::WRITER_PREFIX ) ) {
						if ( ! add_option( StorageAuthority::MAINTENANCE_OPTION, 'invocation-owned-race', '', 'no' ) ) { throw new RuntimeException( 'Racing marker insert failed.' ); }
					}
					return $sql;
				};
				add_filter( 'query', $race );
			} else { $check( add_option( $marker, '', '', 'no' ), $mode . ' seed empty marker' ); }
			$denied( $init, $method );
			if ( null !== $race ) { remove_filter( 'query', $race ); $race = null; }
			$gate->shutdown_writer();
			$check( $source === $raw(), $mode . ' ' . $method . ' preserves source bytes/autoload' );
			$check( 0 === $writer_count(), $mode . ' ' . $method . ' releases only own admission' );
			$check( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $table . '` WHERE option_name = %s', $marker ) ), $mode . ' ' . $method . ' retains barrier/evidence' );
			$check( delete_option( $marker ), 'remove only invocation-owned ' . $mode . ' marker' );
		}
	}
	remove_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $trap );
	remove_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_SETTINGS, $trap );
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
	$engine = foogallery_migrate_migrator_instance();
	$check( true === $engine->set_migrator_setting( 'writer_adoption_test', true ) && 1 === $writer_count(), 'real production engine persists under one admission' );
	$check( true === $gate->acquire_maintenance(), 'real engine admits maintenance behind active writer' );
	$check( is_wp_error( $gate->maintenance_ready( array( $engine, 'storage_is_quiescent' ) ) ), 'real engine writer blocks conversion readiness' );
	$check( true === $engine->set_migrator_setting( 'admitted_can_finish', true ), 'already admitted engine finishes behind gate' );
	$engine->begin_migrated_object_batch();
	$gate->shutdown_writer();
	$check( false === $engine->storage_is_quiescent() && is_wp_error( $gate->maintenance_ready( array( $engine, 'storage_is_quiescent' ) ) ), 'undrained engine batch is not quiescent even with zero writer rows' );
	$check( true === $gate->release_maintenance(), 'release invocation-owned maintenance' );
	if ( is_multisite() ) {
		switch_to_blog( 1 === $blog ? 2 : 1 );
		try {
			try { $engine->set_migrator_setting( 'wrong_blog', true ); throw new RuntimeException( 'Cached engine crossed blogs.' ); }
			catch ( RuntimeException $error ) { $check( false !== strpos( $error->getMessage(), 'storage' ), 'cached production engine refuses cross-blog mutation' ); }
		} finally { restore_current_blog(); }
	}
} finally {
	if ( null !== $race ) { remove_filter( 'query', $race ); }
	remove_filter( 'wp_die_handler', $die, 999 );
	remove_filter( 'wp_die_ajax_handler', $die, 999 );
	remove_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $trap );
	remove_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_SETTINGS, $trap );
	$gate->shutdown_writer();
	$gate->release_maintenance();
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION, StorageAuthority::MAINTENANCE_OPTION ) as $name ) { delete_option( $name ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
$check( false === StorageAuthority::option_mode_error() && 0 === $writer_count(), 'virgin authority and zero writer rows restored' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'production writer adoption only; no conversion/recovery acceptance',
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ),
	'blog_id' => $blog, 'multisite' => is_multisite(), 'checks' => $checks, 'check_count' => count( $checks ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
