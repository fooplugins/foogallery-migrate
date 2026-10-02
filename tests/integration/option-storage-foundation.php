<?php
/**
 * Replayable real WordPress/MariaDB checkpoint for the option-default seam.
 *
 * Run only on a disposable site with FooGallery Migrate inactive:
 * wp --skip-plugins --skip-themes eval 'define("FOOGM_STORAGE_TEST_DISPOSABLE", true);
 *     require "/absolute/repo/tests/integration/option-storage-foundation.php";'
 *
 * This is NOT the complete opt-in/table-conversion acceptance harness.
 * Existing options are restored and only fixture-created tables/posts removed.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'This harness requires an explicitly disposable WordPress site.' );
}

require_once dirname( dirname( __DIR__ ) ) . '/migrate.php';

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

global $wpdb, $foogallery_migrate_engine_instance;
$options = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS,
	MigratedStore::SCHEMA_OPTION, MigratedStore::MIGRATION_OPTION,
	StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
	'foogallery_migrate_storage' );
$tables = array( $wpdb->prefix . MigratedStore::TABLE_SUFFIX, $wpdb->prefix . StorageAuthority::STATE_TABLE_SUFFIX,
	$wpdb->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX,
	$wpdb->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX );
foreach ( $tables as $table ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	if ( ! empty( $wpdb->last_error ) || null !== $found ) {
		throw new RuntimeException( 'Refusing to touch existing storage tables or an unhealthy database.' );
	}
}

$missing = new stdClass();
$before = array();
foreach ( $options as $option ) {
	$before[ $option ] = get_option( $option, $missing );
}
$created_tables = array();
$destination = 0;
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	$checks[] = $label;
};

try {
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	$queue = array( 'gallery_AIGPL_204' => array( 'status' => 'started', 'cursor' => 27, 'selected' => true ) );
	$data = array( 'queue' => $queue, 'opaque' => array( 4 => false, '004' => null, 'text' => 'Case-sensitive UTF-8: 雪' ) );
	update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data, false );
	$check( $data === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'seed exact WordPress option fixture' );
	$check( false === StorageAuthority::option_mode_error(), 'virgin option authority is read-only' );
	foogallery_migrate_activate();
	foogallery_migrate_upgrade_store();
	$check( $data === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'activation and admin upgrade retain original options' );
	foreach ( $tables as $table ) {
		$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'no implicit table: ' . $table );
	}
	$check( $missing === get_option( StorageAuthority::CONTROL_OPTION, $missing ), 'no implicit authority control' );

	$destination = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Storage foundation fixture' ), true );
	$check( ! is_wp_error( $destination ) && $destination > 0, 'create disposable destination record' );
	$image = new Image();
	$image->source_url = 'https://example.test/' . str_repeat( 'MixedCase-', 30 ) . 'image.jpg';
	$image->migrated = true;
	$image->migrated_id = $destination;
	$image->migration_status = 'completed';
	$image->caption = 'UTF-8 caption: 雪';
	$engine = new MigratorEngine();
	$check( true === $engine->add_migrated_object( $image ), 'default engine persists mapping to options' );
	$reloaded = new MigratorEngine();
	$check( $destination === $reloaded->get_migrated_object( $image->unique_identifier() )->migrated_id, 'fresh engine resolves exact long source identity' );
	$check( 1 === $reloaded->get_migrated_revision(), 'history revision persisted with mapping' );
	$check( $queue === get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['queue'], 'history mutation retains resumable queue' );

	$snapshot = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	foogallery_migrate_deactivate( true );
	foogallery_migrate_activate( true );
	$check( $snapshot === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'deactivate/reactivate retains byte-exact state' );
	$check( $destination === ( new MigratorEngine() )->get_migrated_object( $image->unique_identifier() )->migrated_id, 'mapping survives deactivate/reactivate' );
	$check( null !== get_post( $destination ), 'lifecycle never removes migrated output' );

	foreach ( array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, MigratedStore::MIGRATION_OPTION ) as $option ) {
		update_option( $option, array( 'test' => 'ambiguous-authority' ), false );
		$check( is_wp_error( StorageAuthority::option_mode_error() ), 'persisted authority evidence blocks: ' . $option );
		try {
			new MigratorEngine();
			$check( false, 'ambiguous authority must refuse construction' );
		} catch ( RuntimeException $error ) {
			$check( $snapshot === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'blocked engine retains source: ' . $option );
		}
		delete_option( $option );
	}

	foreach ( array( StorageAuthority::SHARED_STATE_TABLE_SUFFIX, StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX ) as $suffix ) {
		$table = $wpdb->base_prefix . $suffix;
		$created_tables[] = $table;
		$check( false !== $wpdb->query( 'CREATE TABLE `' . $table . '` (blog_id bigint unsigned NOT NULL, KEY blog_id (blog_id)) ENGINE=InnoDB' ), 'create authority-probe fixture: ' . $suffix );
		$check( false !== $wpdb->insert( $table, array( 'blog_id' => get_current_blog_id() + 1 ), array( '%d' ) ), 'seed unrelated blog rows' );
		$check( false === StorageAuthority::option_mode_error(), 'unrelated blog does not confer authority: ' . $suffix );
		$check( false !== $wpdb->insert( $table, array( 'blog_id' => get_current_blog_id() ), array( '%d' ) ), 'seed current blog evidence' );
		$check( is_wp_error( StorageAuthority::option_mode_error() ), 'own state or orphan records fail closed: ' . $suffix );
		$wpdb->query( 'DROP TABLE `' . $table . '`' );
	}

	$result = array( 'status' => 'pass', 'scope' => 'option-default checkpoint only; opt-in conversion is not yet implemented',
		'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ),
		'blog_id' => get_current_blog_id(), 'checks' => $checks, 'peak_bytes' => memory_get_peak_usage( true ) );
} finally {
	foreach ( $created_tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
	}
	if ( is_int( $destination ) && $destination > 0 ) {
		wp_delete_post( $destination, true );
	}
	foreach ( $before as $option => $value ) {
		if ( $missing === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, false );
		}
	}
	unset( $foogallery_migrate_engine_instance );
}
foreach ( $before as $option => $value ) {
	$check( $value === get_option( $option, $missing ), 'restore original option: ' . $option );
}
foreach ( $tables as $table ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'remove disposable fixture table: ' . $table );
}
$check( ! $destination || null === get_post( $destination ), 'remove disposable destination fixture' );
$result['checks'] = $checks;
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
