<?php
/** Shared production facade/engine checkpoint; guarded disposable WordPress only. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable authorization required.' );
}
$plugin_root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $plugin_root . '/migrate.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\Objects\Image;

$checks = array();
$check = function ( $condition, $name ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( $name ); }
	$checks[] = $name;
};
$missing = new stdClass();
$before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
global $wpdb;
$physical = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
$controls = array();
foreach ( array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION ) as $key ) {
	$controls[$key] = get_option( $key, $missing );
}
$writes = 0;
$observe = function ( $key ) use ( &$writes ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $key ) { $writes++; } };
$fail = function ( $value, $old ) { return $old; };
$settings = new MigratorSettings(); $backend = new LegacyOptionBackend();
$collections = new MigratableCollectionRepository( $backend, $settings );
add_action( 'added_option', $observe, 10, 1 ); add_action( 'updated_option', $observe, 10, 1 );
try {
	$check( false === StorageAuthority::option_mode_error(), 'ordinary option authority admitted' );
	$image = new Image(); $image->source_url = 'https://example.test/雪.jpg';
	$image->children = array( '09' => clone $image, 9 => null );
	$check( $collections->update_with_state( 'albums', array( '09' => $image, 9 => false ), null ), 'ordinary facade repository write' );
	$objects = $collections->read_snapshot( 'albums' );
	$raw = $backend->read( 'albums' )['value'];
	$check( array( '09', 9 ) === array_keys( $objects ) && array( '09', 9 ) === array_keys( $raw['items'] ), 'typed root keys survive persistence and hydration' );
	$check( array( '09', 9 ) === array_keys( $objects['09']->children ) && null === $objects['09']->children[9], 'typed child keys and null survive' );
	$check( serialize( $objects ) === serialize( $settings->get_migrator_setting( 'albums' ) ), 'ordinary settings facade agrees' );
	$child = clone $image; $child->children = array(); $child->migrated_id = 88;
	$check( $collections->update_record( 'albums', array( 'root' => '09', 'path' => array( '09' ) ), $child ), 'exact nested locator updates' );
	$check( 88 === $collections->read_snapshot( 'albums' )['09']->children['09']->migrated_id, 'nested persisted value read back' );
	$check( false === $collections->read_snapshot( 'albums' )[9] && null === $collections->read_snapshot( 'albums' )['09']->children[9], 'unrelated roots and children retained' );
	$history = array( 'future_header' => '雪', MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array(
		'exact-legacy-key' => array( 'object_type' => 'image', 'source_url' => 'https://example.test/status.jpg', 'migration_status' => 'not_started', 'future' => array( '09' => false, 9 => null ) ) ) );
	$check( $backend->replace_all( array( 'migrated' => $history, 'migrated-revision' => 4, 'stored-false' => false, 'stored-null' => null ) ), 'history fixture persisted' );
	$engine = new MigratorEngine();
	$factory = new ReflectionMethod( $engine, 'migrated_store' );
	if ( PHP_VERSION_ID < 80100 ) { $factory->setAccessible( true ); }
	$check( $factory->invoke( $engine ) instanceof MigratedHistoryRepository, 'ordinary engine adopts common option history owner' );
	$writes = 0;
	$check( 'error' === $engine->update_migrated_object_status( 'exact-legacy-key', 'error' )->migration_status, 'engine patches exact historical identifier' );
	$history['items']['exact-legacy-key']['migration_status'] = 'error';
	$check( $history === $backend->read( 'migrated' )['value'], 'all unknown raw fields and compact headers retained' );
	$check( 5 === $engine->get_migrated_revision() && 1 === $writes, 'single atomic status and revision write' );
	$engine->update_migrated_object_status( 'exact-legacy-key', 'error' );
	$check( 5 === $engine->get_migrated_revision() && 1 === $writes, 'identical status does not write or bump revision' );
	$stable = serialize( $backend->read_all() );
	add_filter( 'pre_update_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $fail, 10, 2 );
	$check( is_wp_error( $engine->update_migrated_object_status( 'exact-legacy-key', 'completed' ) ), 'failed persistence reported' );
	$check( $stable === serialize( $backend->read_all() ) && 5 === $engine->get_migrated_revision(), 'failed status retains raw history and revision' );
	remove_filter( 'pre_update_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $fail, 10 );
	$scalar = new ScalarStateRepository( $backend, $settings );
	$check( false === $scalar->get( 'stored-false', 'absent' ) && null === $scalar->get( 'stored-null', 'absent' ) && 'absent' === $scalar->get( 'missing', 'absent' ), 'false null and absence distinct' );
	$raw_object = new Image(); $raw_object->historical = array( '09' => false, 9 => null );
	$check( $backend->replace_all( array( 'albums' => array( '09' => $raw_object ) ) ), 'historical object persisted in option' );
	$stable = serialize( $backend->read_all() ); $writes = 0;
	$check( $stable === serialize( array( 'albums' => $collections->read_snapshot( 'albums' ) ) ) && 0 === $writes, 'historical object read is not an implicit rewrite' );
	$refused = false;
	try { ( new LegacyStateCodec() )->encode( $backend->read( 'albums' )['value'] ); } catch ( RuntimeException $error ) { $refused = true; }
	$check( $refused && $stable === serialize( $backend->read_all() ) && 0 === $writes, 'historical object cannot silently convert through lossless table codec' );
	$wrapper = array( MigratorSettings::COMPACT_MARKER => 99, 'type' => 'migratable', 'items' => array() );
	$refused = false;
	try { $settings->compact_state_value( 'albums', $wrapper ); } catch ( RuntimeException $error ) { $refused = true; }
	$check( $refused && $stable === serialize( $backend->read_all() ) && 0 === $writes, 'unsupported wrapper refuses before write' );
	foreach ( $controls as $key => $value ) { $check( $value === get_option( $key, $missing ), 'authority marker unchanged: ' . $key ); }
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$check( is_array( $row ) && in_array( $row['autoload'], array( 'no', 'off', 'auto-off' ), true ), 'runtime state option is nonautoloaded' );
} finally {
	remove_filter( 'pre_update_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $fail, 10 );
	remove_action( 'added_option', $observe, 10 ); remove_action( 'updated_option', $observe, 10 );
	if ( $missing === $before ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
	else {
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $before );
		$wpdb->update( $wpdb->options, $physical, array( 'option_name' => FOOGALLERY_MIGRATE_OPTION_DATA ) );
		wp_cache_delete( FOOGALLERY_MIGRATE_OPTION_DATA, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
	$restored = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
	$check( $missing === $before ? $missing === $restored : serialize( $before ) === serialize( $restored ), 'fixture option restored and read back' );
	$restored_physical = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$check( $physical === $restored_physical, 'original physical bytes and autoload restored' );
}
printf( "%s\n", wp_json_encode( array( 'ok' => true, 'check_count' => count( $checks ), 'checks' => $checks, 'blog_id' => get_current_blog_id() ) ) );
