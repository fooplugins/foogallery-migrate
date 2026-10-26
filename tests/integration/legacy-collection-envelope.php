<?php
/** Guarded lossless compact-wrapper/indexed-item integration, not public table selection. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\TableReader;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing tables/unhealthy fixture DB.' ); }
}
$options = array(); $missing = new stdClass();
foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION,
	StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $key ) { $options[$key] = get_option( $key, $missing ); }
$checks = array();
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $callback, $label ) use ( $check ) { try { $callback(); } catch ( RuntimeException $error ) { $check( true, $label ); return; } $check( false, $label ); };
$change = function ( $key, $order, $kind, $value ) { return array( 'key' => $key, 'order' => $order, 'kind' => $kind, 'value' => $value, 'expected_revision' => null ); };
$codec = new LegacyStateCodec(); $blog = (int) get_current_blog_id(); $created = array();
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'schema remains exact version one' );
	$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog );
	$snapshot = new TableSnapshot( $db, $blog ); $reader = new TableReader( $db, $blog );
	$record = array( 'object_type' => 'image', 'plugin_name' => 'Case', 'ID' => '009', 'migrated' => true, 'migrated_id' => 71,
		'migration_status' => 'completed', 'part_of_migration' => true, 'error' => false, 'unknown' => "\xff\x00", 'children' => array( '09' => false, 9 => null ) );
	$history = array( 'before' => -0.0, 'items' => array( '09' => $record, 9 => null, 'stored-false' => false ),
		MigratorSettings::COMPACT_MARKER => 1, 'after' => "\xff\x00", 'type' => 'migratable' );
	$empty = array( 'type' => 'migratable', 'items' => array(), MigratorSettings::COMPACT_MARKER => 1 );
	$plugins = array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'plugins', 'items' => array( array( 'name' => 'Case', 'is_detected' => true ) ) );
	$snapshot->replace_many( array( $change( 'migrated', 0, 'map', $history ), $change( 'migrated-revision', 1, 'scalar', 5 ),
		$change( 'albums', 2, 'map', $empty ), $change( 'plugins', 3, 'list', $plugins ), $change( 'unknown', 4, 'map', $history ) ) );
	$check( serialize( $snapshot->read_bounded( 'migrated' ) ) === serialize( $history ), 'history wrapper round trip is byte/order/type lossless' );
	$check( $reader->head( 'migrated' )['record_count'] === 5, 'only item trees stored as physical roots/children' );
	$check( $backend->count_roots( 'migrated' ) === 3, 'wrapper headers excluded from indexed root count' );
	$tuples = $backend->identifiers( 'migrated', array( '09', 9, 'stored-false', 'absent' ) );
	$check( $tuples[0]['found'] && $tuples[0]['key'] === '09' && $tuples[0]['value'] === $record, 'indexed compact record under exact string key' );
	$check( $tuples[1]['found'] && $tuples[1]['key'] === 9 && null === $tuples[1]['value'], 'indexed stored null under integer key' );
	$check( $tuples[2]['found'] && false === $tuples[2]['value'] && ! $tuples[3]['found'], 'indexed false distinguished from absence' );
	$lookup = $backend->source_identities( 'migrated', array( array( 'type' => 'image', 'plugin' => 'Case', 'id' => '009' ) ) );
	$check( $lookup[0]['found'] && '09' === $lookup[0]['key'], 'source identity searches actual items not wrapper headers' );
	$page = $backend->filtered_page( 'migrated', 10, array( 'object_type' => 'image' ) );
	$check( 1 === count( $page['rows'] ) && '09' === $page['rows'][0]['key'], 'filtered page uses item projections' );
	$check( 0 === $backend->count_roots( 'albums' ) && $snapshot->read_bounded( 'albums' ) === $empty, 'empty compact wrapper retained without fake roots' );
	$check( $snapshot->read_bounded( 'plugins' ) === $plugins, 'list wrapper exact snapshot parity' );
	$check( serialize( $snapshot->read_bounded( 'unknown' ) ) === serialize( $history ) && 5 === $backend->count_roots( 'unknown' ), 'unknown state marker remains uninterpreted raw map' );
	$check( $snapshot->verify_digest( 'migrated', 1 )['logical_digest'] === $codec->snapshot_digest( $history ), 'streamed current wrapper digest agrees with original canonical value' );
	$record['migration_status'] = 'retry'; $record['unknown'] = "\x00\xffchanged";
	$backend->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => 1, 'patches' => array( array( 'key' => '09', 'value' => $record ) ) ),
		array( 'key' => 'migrated-revision', 'expected_revision' => 1, 'value' => 6 ) ) );
	$check( $backend->read( 'migrated-revision' )['value'] === 6 && $reader->head( 'migrated' )['revision'] === 2, 'item patch and scalar companion commit together' );
	$refuses( function () use ( $snapshot ) { $snapshot->read_bounded( 'migrated' ); }, 'dirty wrapper digest refuses implicit full snapshot read' );
	$history['items']['09'] = $record;
	$check( $snapshot->verify_digest( 'migrated', 2 )['logical_digest'] === $codec->snapshot_digest( $history ), 'explicit paged dirty digest includes headers in original field order' );
	$check( serialize( $snapshot->read_bounded( 'migrated' ) ) === serialize( $history ), 'item patch preserves all wrapper headers exactly' );
	$backend->patch_lists_and_scalars( array( array( 'key' => 'plugins', 'expected_revision' => 1, 'patches' => array( array( 'key' => 1, 'value' => array( 'name' => 'Second', 'is_detected' => false ) ) ) ) ) );
	$plugins['items'][] = array( 'name' => 'Second', 'is_detected' => false );
	$check( $snapshot->verify_digest( 'plugins', 2 )['logical_digest'] === $codec->snapshot_digest( $plugins ) && $snapshot->read_bounded( 'plugins' ) === $plugins, 'list append preserves compact wrapper and canonical digest' );
	$bad = $history; $bad['type'] = 'unsupported';
	$refuses( function () use ( $snapshot, $change, $bad ) { $snapshot->replace_many( array( $change( 'galleries', 5, 'map', $bad ) ) ); }, 'malformed known wrapper refused before publication' );
	$check( false === $reader->head( 'galleries' ), 'refused wrapper leaves no head' );
} finally {
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); } }
	foreach ( $options as $key => $before ) { $check( get_option( $key, $missing ) === $before, 'public option untouched: ' . $key ); }
	foreach ( $created as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && empty( $wpdb->last_error ), 'table cleanup read back: ' . $table ); }
}
fwrite( STDOUT, wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'actual_multisite' => is_multisite(), 'check_count' => count( $checks ), 'checks' => $checks ) ) . "\n" );
