<?php
/** Native uninstall acceptance. Only the manifest-bound disposable R0 fixture. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable fixture required.' );
}
$manifest = json_decode( file_get_contents( ABSPATH . '.fgm-r0-owner.json' ), true );
if ( ! is_array( $manifest ) || 'foogallery-migrate-r0-v1' !== $manifest['kind'] ||
	realpath( ABSPATH ) !== realpath( $manifest['site'] ) || DB_NAME !== $manifest['database'] || DB_HOST !== $manifest['host'] ||
	! preg_match( '#^/(private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$#D', realpath( ABSPATH ) ) ) {
	throw new RuntimeException( 'Fixture identity differs.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';

use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\StorageDiscoverySummary;

global $wpdb, $foogallery_migrate_engine_instance;
$check_count = 0;
$check = function ( $condition ) use ( &$check_count ) {
	++$check_count;
	if ( ! $condition ) { throw new RuntimeException( 'Retirement check failed: ' . $check_count ); }
};
$start = get_current_blog_id();
$sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( $start );
$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
foreach ( $tables as $table ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && ! $wpdb->last_error );
}
$protected = array();
foreach ( $sites as $site ) {
	if ( is_multisite() ) { switch_to_blog( $site ); }
	foreach ( array( 'foogallery', 'attachment' ) as $type ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'attachment' === $type ? 'inherit' : 'publish', 'post_title' => 'Retirement protected ' . $type ), true );
		$check( ! is_wp_error( $id ) && $id > 0 );
		$check( false !== add_post_meta( $id, '_fgm_retirement_protected', 'keep' ) );
	}
	$protected[$site] = array(
		serialize( $wpdb->get_results( 'SELECT * FROM `' . $wpdb->posts . '` ORDER BY ID', ARRAY_A ) ),
		serialize( $wpdb->get_results( 'SELECT * FROM `' . $wpdb->postmeta . '` ORDER BY meta_id', ARRAY_A ) ) );
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageDiscoverySummary::OPTION, 'fgm_retirement_unrelated' ) as $name ) {
		$check( add_option( $name, array( 'retirement-fixture' => (int) $site ), '', false ) );
	}
	if ( is_multisite() ) { restore_current_blog(); }
}
if ( ! is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$schema = new LegacyStorageSchema( $wpdb );
	foreach ( $schema->statements() as $ddl ) { dbDelta( $ddl ); }
	$check( true === $schema->verify() );
	$history = new MigratedHistoryRepository( new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), $start ), new MigratorSettings() );
	$image = new Image(); $image->source_url = 'https://example.invalid/retirement.jpg'; $image->migrated_id = 123;
	$check( 1 === $history->upsert( $image ) );
	$check( 123 === $history->get( $image->unique_identifier() )->migrated_id );
}
unset( $foogallery_migrate_engine_instance );
define( 'WP_UNINSTALL_PLUGIN', 'foogallery-migrate/migrate.php' );
require $root . '/uninstall.php';
$check( $start === get_current_blog_id() );
foreach ( $sites as $site ) {
	if ( is_multisite() ) { switch_to_blog( $site ); }
	$check( $protected[$site][0] === serialize( $wpdb->get_results( 'SELECT * FROM `' . $wpdb->posts . '` ORDER BY ID', ARRAY_A ) ) );
	$check( $protected[$site][1] === serialize( $wpdb->get_results( 'SELECT * FROM `' . $wpdb->postmeta . '` ORDER BY meta_id', ARRAY_A ) ) );
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageDiscoverySummary::OPTION ) as $name ) {
		$check( false === get_option( $name ) );
	}
	$check( array( 'retirement-fixture' => (int) $site ) === get_option( 'fgm_retirement_unrelated' ) );
	delete_option( 'fgm_retirement_unrelated' );
	if ( is_multisite() ) { restore_current_blog(); }
}
foreach ( $tables as $table ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && ! $wpdb->last_error );
}
$check( ! class_exists( 'FooPlugins\\FooGalleryMigrate\\MigratedStore', false ) );
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $check_count, 'multisite' => is_multisite(), 'sites' => count( $sites ), 'tables' => $tables ) ) . "\n";
