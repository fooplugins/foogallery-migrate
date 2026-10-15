<?php
/** Owned native lazy schema provisioning and public-selection regression. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Disposable WordPress fixture required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
global $wpdb;
$blog = get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$main = is_multisite() ? get_main_site_id( get_main_network_id() ) : $blog;
$main_options = $wpdb->get_blog_prefix( $main ) . 'options';
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing shared tables.' ); } }
foreach ( $names as $name ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) ) ) { throw new RuntimeException( 'Refusing existing state.' ); } }
foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $main_options . '` WHERE option_name=%s', $name ) ) ) { throw new RuntimeException( 'Refusing existing schema metadata.' ); } }
$checks = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$request = function ( $target ) use ( $blog ) { return array( 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => 'switch-to-' . $target, 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . ( 'table' === $target ? 'option_import' : 'table_export' ) . '_' . $blog ) ); };
$absent = function () use ( $wpdb, $tables ) { foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { return false; } } return true; };
$profile = array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(), 'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
// Synthetic trusted routing profile ONLY. Never installed or claimed approved.
$receiver = new StorageOperations( $profile );
$active = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : null;
$initial = array( 'fixture' => array( 'ordered' => array( '009', false, null, -0.0 ) ) );
try {
	$check( $absent(), 'ordinary plugin bootstrap creates no tables' );
	$r = $request( 'table' ); $r['nonce'] = 'wrong';
	$check( is_wp_error( $receiver->dispatch( 'switch', $r ) ) && $absent(), 'wrong nonce never provisions' );
	$check( is_wp_error( ( new StorageOperations() )->dispatch( 'switch', $request( 'table' ) ) ) && $absent(), 'missing calibration never provisions' );
	update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false );
	if ( is_multisite() ) {
		delete_site_option( 'active_sitewide_plugins' );
		$result = $receiver->dispatch( 'switch', $request( 'table' ) );
		$check( is_wp_error( $result ) && $absent(), 'site-only activation cannot provision installation-shared schema' );
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( FOOGM_FILE ) => time() ) );
	}
	$result = $receiver->dispatch( 'switch', $request( 'table' ) );
	$check( ! is_wp_error( $result ), 'public confirmed selection provisions missing schema and switches' );
	$check( $schema->verify(), 'new shared InnoDB columns and indexes verified' );
	$check( (string) LegacyStorageSchema::VERSION === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $main_options . '` WHERE option_name=%s', LegacyStorageSchema::VERSION_OPTION ) ), 'verified version stored in installation main-site regular options' );
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $main_options . '` WHERE option_name=%s', LegacyStorageSchema::LOCK_OPTION ) ), 'owned installation schema lock released' );
	$record = StorageAuthority::runtime_record();
	$check( is_array( $record ) && 'table' === $record['backend'], 'normal runtime factory observes table authority' );
	$result = $receiver->dispatch( 'switch', $request( 'option' ) );
	$check( ! is_wp_error( $result ) && $initial === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'public reverse switch preserves exact logical state' );
} finally {
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); }
	foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $main_options . '` WHERE option_name=%s', $name ) ); wp_cache_delete( $name, 'options' ); }
	if ( is_multisite() ) { if ( $active ) { update_site_option( 'active_sitewide_plugins', $active ); } else { delete_site_option( 'active_sitewide_plugins' ); } }
}
$check( $absent(), 'exact owned tables removed' );
echo wp_json_encode( array( 'status' => 'pass', 'check_count' => count( $checks ), 'checks' => $checks, 'blog_id' => $blog, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ), 'calibration_status' => 'synthetic-routing-only-not-production-approval' ) ) . "\n";
