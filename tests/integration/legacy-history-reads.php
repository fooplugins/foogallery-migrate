<?php
/** Read-only common history dispatch on an explicitly admitted disposable WP DB. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableReader;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
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
$checks = array(); $created = array(); $writes = 0;
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $call, $label ) use ( $check ) { try { $call(); } catch ( RuntimeException $error ) { $check( true, $label ); return; } $check( false, $label ); };
$observe = function ( $name ) use ( &$writes ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $writes++; } };
$change = function ( $key, $order, $kind, $value ) { return array( 'key' => $key, 'order' => $order, 'kind' => $kind, 'value' => $value, 'expected_revision' => null ); };
$blog = (int) get_current_blog_id();
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'unchanged schema v1' );
	$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog );
	$snapshot = new LegacyTableSnapshot( $db, $blog ); $reader = new LegacyTableReader( $db, $blog );
	$record = array( 'object_type' => 'image', 'plugin_name' => '', 'ID' => '009', 'migrated' => false, 'migrated_id' => 71,
		'migration_status' => 'completed', 'source_url' => 'https://example.test/Case.jpg', 'migration_parent_key' => 'Parent',
		'unknown' => '雪', 'settings' => array( '09' => false, 9 => null ), 'children' => array( '09' => false, 9 => null ) );
	$later = $record; $later['migrated_id'] = 72; $later['migrated'] = true;
	$history = array( 'before' => -0.0, 'items' => array( '09' => $record, 9 => null, 'stored-false' => false, 'later' => $later ),
		MigratorSettings::COMPACT_MARKER => 1, 'after' => '雪', 'type' => 'migratable' );
	$data = array( 'migrated' => $history, 'migrated-revision' => 5 );
	$snapshot->replace_many( array( $change( 'migrated', 0, 'map', $history ), $change( 'migrated-revision', 1, 'scalar', 5 ) ) );
	$check( ( new LegacyOptionBackend() )->replace_all( $data ), 'seed option parity fixture' );
	$settings = new MigratorSettings(); $option = new MigratedHistoryRepository( new LegacyOptionBackend(), $settings );
	$logical = new MigratedHistoryRepository( $backend, $settings );
	add_action( 'updated_option', $observe ); add_action( 'added_option', $observe );
	$metadata = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$check( $metadata[0]['root']['root_count'] === 4 && $metadata[0]['root']['wrapper']['values']['after'] === '雪' && ! isset( $metadata[0]['root']['items'] ), 'bounded collection metadata excludes all item payloads' );
	$check( $metadata[1]['root']['value'] === 5 && $metadata[0]['head']['revision'] === 1, 'logical scalar revision differs from physical CAS revision' );
	foreach ( array( '09', 9, 'stored-false', 'absent' ) as $key ) {
		$check( $logical->has( $key ) === $option->has( $key ) && serialize( $logical->get( $key ) ) === serialize( $option->get( $key ) ), 'typed presence and hydration: ' . gettype( $key ) . ':' . $key );
	}
	$keys = array( 9, 'absent', '09', 'stored-false' );
	$check( serialize( $logical->get_many( $keys ) ) === serialize( $option->get_many( $keys ) ), 'requested order and opaque false/null tuples' );
	$refuses( function () use ( $logical ) { $logical->get_many( array( '09', '09' ) ); }, 'duplicate history keys refuse without normalization' );
	$check( $logical->count() === $option->count() && $logical->revision() === $option->revision(), 'count and logical revision parity' );
	$check( $logical->find_by_source_identity( 'image', '', '009' )->migrated_id === $option->find_by_source_identity( 'image', '', '009' )->migrated_id, 'first positive target wins even with migrated false' );
	$physical = $backend->source_identities( 'migrated', array( array( 'type' => 'image', 'plugin' => '', 'id' => '009' ) ) );
	$check( $physical[0]['key'] === 'later', 'existing physical migrated-flag lookup policy unchanged' );
	$identity_codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(); $identity_hash = $identity_codec->snapshot_digest( array( 'source-exact-v1', 'image', '', '009' ) );
	foreach ( array( 0, 1 ) as $flag ) {
		$explain = $db->get_results( $db->prepare( 'EXPLAIN SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $tables[1] . '` FORCE INDEX (`source_identity`) WHERE blog_id = %d AND state_id = %d AND generation = %d AND source_identity_hash = UNHEX(%s) AND is_migrated = %d AND migrated_id > 0 AND parent_node_hash IS NULL ORDER BY item_order ASC, id ASC LIMIT 1', array( $blog, $metadata[0]['head']['id'], $metadata[0]['head']['active_generation'], $identity_hash, $flag ) ), ARRAY_A );
		$check( count( $explain ) === 1 && $explain[0]['key'] === 'source_identity' && false === stripos( $explain[0]['Extra'], 'filesort' ), 'native exact source selector uses ordering index without filesort: flag ' . $flag );
	}
	$check( false === $logical->find_by_source_identity( 'image', '', '9' ), 'exact source ID retains leading zeroes' );
	$page = $logical->iterate_page( 2 ); $next = $logical->iterate_page( 2, $page['cursor'] );
	$check( count( $page['rows'] ) === 2 && null !== $page['cursor'] && $page['revision'] === 5 && null === $next['cursor'] && count( $next['rows'] ) === 2, 'exact full terminal page uses null cursor without extra empty read' );
	$all = array_merge( $page['rows'], $next['rows'] );
	$check( array_column( $all, 'key' ) === array( '09', 9, 'stored-false', 'later' ), 'paged keys retain types and insertion order' );
	$filtered = $logical->page_log( 'image', 'completed', 2 );
	$check( count( $filtered['rows'] ) === 2 && null === $filtered['cursor'] && $filtered['revision'] === 5, 'filtered terminal page translated to logical contract' );
	$empty = $logical->page_log( 'gallery', '', 1 );
	$check( array() === $empty['rows'] && null === $empty['cursor'], 'empty filter yields terminal cursor' );
	$refuses( function () use ( $logical, $page ) { $logical->page_log( 'image', '', 2, $page['cursor'] ); }, 'cross-filter cursor refuses' );
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableBackend( $db, $blog, 1 ) )->metadata_many( array( 'migrated', 'migrated-revision' ) ); }, 'shared multi-head row budget refuses' );
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableBackend( $db, $blog, 10000, 16 ) )->metadata_many( array( 'migrated', 'migrated-revision' ) ); }, 'shared multi-head byte budget refuses before root retrieval' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 3 ), new MigratorSettings() ) )->get( 'absent' ); }, 'logical operation shares row budget across both metadata checks' );
	$state_codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
	$one_context_bytes = $metadata[0]['head']['root_payload_bytes'] + $metadata[1]['head']['root_payload_bytes'] + strlen( $state_codec->key_value( 'migrated' ) ) + strlen( $state_codec->key_value( 'migrated-revision' ) );
	$refuses( function () use ( $db, $blog, $one_context_bytes ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 10000, $one_context_bytes + 100 ), new MigratorSettings() ) )->get( 'absent' ); }, 'logical operation shares encoded byte budget across metadata and lookup' );
	$refuses( function () use ( $backend ) { $backend->metadata_many( array( 'missing' ) ); }, 'missing metadata has no fallback' );
	$refuses( function () use ( $logical ) { $logical->find_by_source_url( 'image', 'https://example.test/Case.jpg' ); }, 'unindexed source URL refuses rather than history scan' );
	$refuses( function () use ( $logical ) { $logical->find_by_source_identity( 'image', null, '009' ); }, 'unindexed pluginless source identity explicitly refuses' );
	$refuses( function () use ( $logical ) { $logical->log_summary(); }, 'physical counts are not exposed as logical log totals' );
	$refuses( function () use ( $logical ) { $logical->clear(); }, 'unadmitted writes refuse rather than option fallback' );
	$refuses( function () use ( $logical ) { $logical->read_snapshot(); }, 'no implicit whole-history materialization' );
	$check( 0 === $writes && serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ) === serialize( $data ), 'all logical reads/refusals leave options unchanged' );
	$backend->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => 1, 'value' => 6 ) ) );
	$refuses( function () use ( $logical, $page ) { $logical->iterate_page( 2, $page['cursor'] ); }, 'cursor also binds scalar revision head, not just history CAS' );
	$page = $logical->iterate_page( 2 );
	$backend->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => 1, 'patches' => array( array( 'key' => 'later', 'value' => $record ) ) ),
		array( 'key' => 'migrated-revision', 'expected_revision' => 2, 'value' => 7 ) ) );
	$refuses( function () use ( $logical, $page ) { $logical->iterate_page( 2, $page['cursor'] ); }, 'stale cursor refuses after atomic physical patch' );
	$check( $logical->revision() === 7 && $logical->get( 'later' )->migrated_id === 71, 'dirty digest allows only verified bounded logical reads' );
	$refuses( function () use ( $snapshot ) { $snapshot->read_bounded( 'migrated' ); }, 'dirty full digest remains an explicit maintenance gate' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog + 1000 ), new MigratorSettings() ) )->get( '09' ); }, 'wrong-blog lookup refuses without options' );
	$check( $reader->head( 'migrated' )['revision'] === 2 && 0 === $writes, 'reads never advance physical/logical revision or option state' );
	$null_id = $record; $null_id['ID'] = null; $empty_id = $record; $empty_id['ID'] = '';
	$backend->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => 2, 'patches' => array( array( 'key' => 'null-id', 'value' => $null_id ), array( 'key' => 'empty-id', 'value' => $empty_id ) ) ) ) );
	$refuses( function () use ( $logical ) { $logical->find_by_source_identity( 'image', '', '' ); }, 'null and empty source ID projection collision explicitly refuses without scanning' );
} finally {
	remove_action( 'updated_option', $observe ); remove_action( 'added_option', $observe );
	if ( array_key_exists( FOOGALLERY_MIGRATE_OPTION_DATA, $options ) ) {
		if ( $options[FOOGALLERY_MIGRATE_OPTION_DATA] === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
		else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $options[FOOGALLERY_MIGRATE_OPTION_DATA], false ); }
	}
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); } }
	foreach ( $options as $key => $before ) { $check( get_option( $key, $missing ) === $before, 'option cleanup read back: ' . $key ); }
	foreach ( $created as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && empty( $wpdb->last_error ), 'table cleanup read back: ' . $table ); }
}
fwrite( STDOUT, wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'actual_multisite' => is_multisite(), 'check_count' => count( $checks ), 'checks' => $checks ) ) . "\n" );
