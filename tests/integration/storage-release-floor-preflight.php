<?php
/** Public storage preflight under an explicit floor memory limit; no provision. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) || ! is_file( '/fixture-owner.json' ) ) {
	throw new RuntimeException( 'Owned floor WP-CLI fixture required.' );
}
$owner = json_decode( file_get_contents( '/fixture-owner.json' ), true );
if ( ! is_array( $owner ) || ! isset( $owner['kind'], $owner['database'], $owner['container'] ) ||
	$owner['kind'] !== 'foogallery-migrate-floor-v1' || DB_NAME !== $owner['database'] || DB_HOST !== $owner['container'] ||
	PHP_VERSION !== '5.4.45' || $GLOBALS['wp_version'] !== '5.0' || is_multisite() || ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'Floor runtime/site ownership mismatch.' );
}
if ( false === ini_set( 'memory_limit', '256M' ) || '256M' !== ini_get( 'memory_limit' ) ) {
	throw new RuntimeException( 'Explicit preflight memory limit unavailable.' );
}

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

global $wpdb;
$raw_sql = $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', FOOGALLERY_MIGRATE_OPTION_DATA );
$raw = $wpdb->get_var( $raw_sql );
if ( $wpdb->last_error || ! is_string( $raw ) || 2998086 !== strlen( $raw ) ||
	'b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677' !== hash( 'sha256', $raw ) ) {
	throw new RuntimeException( 'Floor native seed identity mismatch.' );
}
$marker_names = array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION,
	StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $marker_names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Storage marker present before preflight.' );
	}
}
$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Storage table present before preflight.' );
	}
}
$result = StorageCli::execute( 'preflight', array( 'target' => 'table', 'confirm' => 'switch-to-table' ) );
if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Public preflight: ' . $result->get_error_code() ); }
$after = $wpdb->get_var( $raw_sql );
if ( $wpdb->last_error || $after !== $raw ) { throw new RuntimeException( 'Preflight changed the native option.' ); }
foreach ( $marker_names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Preflight changed a storage marker.' );
	}
}
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Preflight created a storage table.' );
	}
}
echo json_encode( array( 'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'database' => DB_NAME,
	'memory_limit' => ini_get( 'memory_limit' ), 'raw_bytes' => strlen( $after ),
	'raw_sha256' => hash( 'sha256', $after ), 'result' => $result,
	'option_and_storage_unchanged' => true ) ) . "\n";
