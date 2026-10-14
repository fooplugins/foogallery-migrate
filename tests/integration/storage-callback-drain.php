<?php
/** Actual native image-tag output callbacks; not full drain or hard-death proof. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Disposable fixture required.' ); }
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) ) { define( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG', 'foogallery_attachment_tag' ); }

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratorEngine;

class FgmDrainCallbackFailure extends RuntimeException {}
global $wpdb;
$original = $wpdb; $blog = (int) get_current_blog_id();
$observer_wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$observer_wpdb->set_prefix( $wpdb->base_prefix );
if ( is_multisite() ) { $observer_wpdb->set_blog_id( $blog ); }
$observer = new LegacyTransactionConnection( $observer_wpdb );
$schema = new LegacyStorageSchema( $observer_wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
$pattern = $observer->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%';
foreach ( $tables as $table ) {
	if ( null !== $observer->get_var( $observer->prepare( 'SHOW TABLES LIKE %s', array( $observer->esc_like( $table ) ) ) ) ) { throw new RuntimeException( 'Refusing existing tables.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $observer->get_var( $observer->prepare( 'SELECT 1 FROM `' . $original->options . '` WHERE option_name = %s', array( $name ) ) ) ) { throw new RuntimeException( 'Refusing existing evidence.' ); }
}
$writers = function () use ( $observer, $original, $pattern ) {
	return $observer->get_results( $observer->prepare( 'SELECT option_name, option_value, autoload FROM `' . $original->options . '` WHERE option_name LIKE %s ORDER BY option_name', array( $pattern ) ), ARRAY_A );
};
if ( array() !== $writers() ) { throw new RuntimeException( 'Refusing existing writers.' ); }
$checks = array(); $created = array(); $posts = array(); $terms = array(); $owned_writers = array(); $hook = null; $gate = null;
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$clean_state = function () use ( $observer, $original, $names, &$created, $blog, &$owned_writers, $check ) {
	foreach ( $owned_writers as $row ) {
		$check( 1 === $observer->query( $observer->prepare( 'DELETE FROM `' . $original->options . '` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $row['option_name'], $row['option_value'] ) ) ), 'explicit fixture-owned uncertain marker cleanup, not product recovery' );
		wp_cache_delete( $row['option_name'], 'options' );
	}
	$owned_writers = array();
	foreach ( $names as $name ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $original->options . '` WHERE option_name = %s', array( $name ) ) ); wp_cache_delete( $name, 'options' ); }
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	foreach ( array_reverse( $created ) as $table ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', array( $blog ) ) ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
try {
	register_taxonomy( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, 'attachment' );
	foreach ( array( 'first', 'second' ) as $suffix ) {
		$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Owned drain fixture ' . $suffix ), true );
		$check( ! is_wp_error( $id ) && $id > 0, 'owned native attachment created' ); $posts[] = $id;
		$term = wp_insert_term( 'owned-drain-' . $suffix, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG );
		$check( ! is_wp_error( $term ), 'owned native tag created' ); $terms[] = (int) $term['term_id'];
	}
	foreach ( array( 'option', 'table' ) as $mode ) {
		$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( $original->base_prefix );
		if ( is_multisite() ) { $wpdb->set_blog_id( $blog ); }
		$gate = StorageMaintenanceGate::current();
		if ( 'table' === $mode ) {
			foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
			$check( $schema->verify(), 'verified fixture table schema' );
			$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'opaque' => array( '09' => false, 9 => null ) ), false ), 'seed compact conversion source' );
			$request = array( 'blog_id' => $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) );
			$check( ! is_wp_error( ( new StorageConversion( array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) ) )->switch_backend( $request ) ), 'actual conversion coordinator admits table backend with synthetic fixture policy' );
		}
		$engine = foogallery_migrate_migrator_instance();
		$state = array( 'items' => array( array( 'attachment_id' => $posts[0], 'tags' => array( 'owned-drain-first' ) ), array( 'attachment_id' => $posts[1], 'tags' => array( 'owned-drain-second' ) ) ), 'processed' => 0, 'total' => 2, 'updated' => 0 );
		$queue = function () use ( $engine, $state, $check ) { $check( true === $engine->set_migrator_setting( MigratorEngine::KEY_IMAGE_TAG_SYNC, $state ), 'actual engine queues output state' ); };
		$queue(); $calls = 0;
		$hook = function () use ( $engine, &$calls, $check, $mode ) {
			$calls++; $check( false === $engine->storage_is_quiescent() && false === MigratorEngine::storage_maintenance_is_quiescent(), $mode . ': native term hook cannot affirm request drain' );
		};
		add_action( 'set_object_terms', $hook );
		$result = $engine->continue_image_tag_sync();
		remove_action( 'set_object_terms', $hook ); $hook = null;
		$check( 2 === $calls && 2 === $result['processed'] && true === $engine->storage_is_quiescent(), $mode . ': real output completes and operation depth unwinds' );
		$check( array( 'owned-drain-first' ) === wp_get_object_terms( $posts[0], FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, array( 'fields' => 'names' ) ), $mode . ': native first output persisted' );
		$check( array( 'owned-drain-second' ) === wp_get_object_terms( $posts[1], FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, array( 'fields' => 'names' ) ), $mode . ': native second output persisted' );
		$queue();
		$hook = function () { throw new FgmDrainCallbackFailure( 'owned term callback failure' ); }; add_action( 'set_object_terms', $hook );
		try { $engine->continue_image_tag_sync(); throw new LogicException( 'Callback failure was swallowed.' ); }
		catch ( FgmDrainCallbackFailure $error ) { $check( true === $engine->storage_is_quiescent(), $mode . ': supported exception unwinds active operation' ); }
		remove_action( 'set_object_terms', $hook ); $hook = null;
		$check( $state === $engine->get_migrator_setting( MigratorEngine::KEY_IMAGE_TAG_SYNC ), $mode . ': callback failure preserves queued progress, no output rollback claim' );
		if ( is_multisite() ) {
			switch_to_blog( 1 === $blog ? 2 : 1 );
			try { $engine->continue_image_tag_sync(); throw new LogicException( 'Cross-blog output admitted.' ); }
			catch ( RuntimeException $error ) { $check( false === $engine->storage_is_quiescent(), $mode . ': cached output engine refuses changed blog' ); }
			finally { restore_current_blog(); }
		}
		// Deliberate real mysqli handle replacement, NOT a network fault or hard death.
		$replacement = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $calls = 0;
		$hook = function () use ( $replacement, &$calls ) { global $wpdb; $calls++; $wpdb->dbh = $replacement->dbh; };
		add_action( 'set_object_terms', $hook );
		try { $engine->continue_image_tag_sync(); throw new LogicException( 'Changed pin reported success.' ); }
		catch ( RuntimeException $error ) { $check( 1 === $calls && false === $gate->connection()->is_current() && false === $engine->storage_is_quiescent(), $mode . ': native handle replacement refuses subsequent output and latches pin' ); }
		remove_action( 'set_object_terms', $hook ); $hook = null;
		$owned_writers = $writers(); $check( 1 === count( $owned_writers ), $mode . ': uncertain owned writer retained' );
		$gate->shutdown_writer(); $check( $owned_writers === $writers(), $mode . ': shutdown cannot release uncertain writer' );
		if ( 'table' === $mode ) {
			$check( array( 'found' => true, 'value' => $state ) === ( new LegacyTableBackend( $observer, $blog ) )->read( MigratorEngine::KEY_IMAGE_TAG_SYNC ), 'independent table observer proves no acknowledged progress advance' );
		} else {
			$raw = $observer->get_var( $observer->prepare( 'SELECT option_value FROM `' . $original->options . '` WHERE option_name = %s', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
			$data = unserialize( $raw, array( 'allowed_classes' => false ) );
			$check( $state === $data[MigratorEngine::KEY_IMAGE_TAG_SYNC], 'independent option observer proves no progress advance' );
		}
		$clean_state();
	}
} finally {
	if ( null !== $hook ) { remove_action( 'set_object_terms', $hook ); }
	if ( null !== $gate ) { $gate->shutdown_writer(); }
	$clean_state();
	$wpdb = $original;
	foreach ( $posts as $post ) { wp_delete_post( $post, true ); }
	foreach ( $terms as $term ) { wp_delete_term( $term, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG ); }
	unregister_taxonomy( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG );
	foreach ( array_reverse( $created ) as $table ) { $observer->query( 'DROP TABLE `' . $table . '`' ); }
}
$check( array() === $writers() && false === StorageAuthority::option_mode_error(), 'fixture state and writer rows removed; virgin option authority restored' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'bounded actual image-tag callbacks and real handle replacement only; no hard-death/full-drain/stale-owner acceptance', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $observer->get_var( 'SELECT VERSION()' ), 'blog_id' => $blog, 'multisite' => is_multisite(), 'checks' => $checks, 'check_count' => count( $checks ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
