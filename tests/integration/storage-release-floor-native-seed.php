<?php
/** Seed the same native-text A7 logical shape on PHP 5.4's serialization. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) || ! is_file( '/fixture-owner.json' ) ) {
	throw new RuntimeException( 'Owned floor WP-CLI fixture required.' );
}
$owner = json_decode( file_get_contents( '/fixture-owner.json' ), true );
if ( ! is_array( $owner ) || ! isset( $owner['kind'], $owner['database'], $owner['container'] ) ||
	$owner['kind'] !== 'foogallery-migrate-floor-v1' || DB_NAME !== $owner['database'] || DB_HOST !== $owner['container'] ||
	PHP_VERSION !== '5.4.45' || $GLOBALS['wp_version'] !== '5.0' || is_multisite() ) {
	throw new RuntimeException( 'Floor runtime/site ownership mismatch.' );
}
require_once __DIR__ . '/../support/StorageA7Fixture.php';

use FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture as Native;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

global $wpdb;
if ( $wpdb->get_var( 'SELECT DATABASE()' ) !== $owner['database'] || $wpdb->last_error ) {
	throw new RuntimeException( 'Floor SQL database ownership mismatch.' );
}
$option = FOOGALLERY_MIGRATE_OPTION_DATA;
$raw_sql = $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $option );
if ( null !== $wpdb->get_var( $raw_sql ) || $wpdb->last_error ) {
	throw new RuntimeException( 'Migration option already exists; refusing reseed.' );
}
$marker_names = array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION,
	StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $marker_names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Existing storage marker; refusing seed.' );
	}
}
$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Existing storage table; refusing seed.' );
	}
}
$value = Native::value( 20000 );
$gallery =& $value['galleries']['items']['gallery-009'];
$changes = 0;
foreach ( $gallery['children'] as &$image ) {
	if ( isset( $image['unknown'] ) ) {
		if ( "\xff\x00" !== $image['unknown']['binary'] ) { throw new RuntimeException( 'Unexpected source leaf.' ); }
		$image['unknown']['binary'] = "x\x00";
		++$changes;
	}
}
unset( $image );
if ( "\x00\xff" !== $gallery['unknown'][2] ) { throw new RuntimeException( 'Unexpected gallery leaf.' ); }
$gallery['unknown'][2] = "\x00x";
++$changes;
if ( 208 !== $changes ) { throw new RuntimeException( 'Unexpected fixture shape.' ); }
$serialized = serialize( $value );
// PHP 5.4 writes 207 negative-zero leaves as d:0;, versus modern PHP's d:-0;.
// Pin this native WordPress/PHP-floor representation for later before/after checks.
if ( 2998086 !== strlen( $serialized ) ||
	'b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677' !== hash( 'sha256', $serialized ) ) {
	throw new RuntimeException( 'Floor native option identity drift.' );
}
if ( ! update_option( $option, $value, false ) ) { throw new RuntimeException( 'WordPress option seed failed.' ); }
unset( $value );
$persisted = $wpdb->get_var( $raw_sql );
if ( $wpdb->last_error || $persisted !== $serialized ) { throw new RuntimeException( 'WordPress changed the seeded representation.' ); }
echo json_encode( array( 'fixture' => 'a7-options-utf8mb4-20k-php54', 'changes' => $changes,
	'raw_bytes' => strlen( $persisted ), 'raw_sha256' => hash( 'sha256', $persisted ),
	'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'database' => DB_NAME ) ) . "\n";
