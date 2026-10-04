<?php
/** Replay only on an explicitly authorized disposable WordPress instance. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable authorization required.' );
}
$plugin_root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $plugin_root . '/migrate.php';
require_once dirname( __DIR__ ) . '/fixtures/legacy-runtime-contract-fixtures.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Tests\Fixtures;


$missing = new stdClass();
$before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
$checks = array(); $writes = 0;
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$refuses = function ( $call, $label ) use ( $check ) {
	$refused = false;
	try { $call(); } catch ( RuntimeException $error ) { $refused = true; }
	$check( $refused, $label );
};
$observe = function ( $name ) use ( &$writes ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $writes++; } };
$fail = function ( $value, $old ) { return $old; };
$fixture = Fixtures\legacy_runtime_contract_fixture( false );
$backend = new LegacyOptionBackend(); $settings = new MigratorSettings();
$scalar = new ScalarStateRepository( $backend, $settings );
$collections = new MigratableCollectionRepository( $backend, $settings );
$history = new MigratedHistoryRepository( $backend, $settings );
$content = new ContentScanRepository( $backend, $settings );
$queue = new ImageTagQueueRepository( $backend, $settings );
add_action( 'updated_option', $observe ); add_action( 'added_option', $observe );
try {
	$check( $backend->replace_all( $fixture['logical'] ), 'seed actual serialized WordPress fixture' ); $writes = 0;
	$check( false === $backend->replace_all( array( 'binary' => "\xff\x00" ) ) && serialize( $backend->read_all() ) === serialize( $fixture['logical'] ), 'invalid UTF-8 option bytes refuse without changing supported state' );
	foreach ( $fixture['logical'] as $key => $value ) { $check( serialize( $value ) === serialize( $scalar->get( $key ) ), 'logical raw read ' . $key ); }
	$rows = $scalar->read_many( array( 'stored-null', 'absent' ) );
	$check( true === $rows[0]['found'] && null === $rows[0]['value'] && false === $rows[1]['found'], 'null and absent presence' );
	$check( 0 === $writes, 'reads never rewrite history or create settings' );
	$check( $scalar->set( 'galleries-state', false ) && 0 === $writes, 'same-value no write after object deserialization' );
	$check( $history->get( $fixture['image']->unique_identifier() )->children['09']->migrated_id === 71, 'legacy historical children survive real WP read' );
	$check( $history->find_by_source_identity( 'image', null, '009' )->migrated_id === 71, 'legacy pluginless source identity uses first positive target' );
	$page = $history->iterate_page( 1 );
	$check( 1 === count( $page['rows'] ) && null === $page['cursor'] && 4 === $page['revision'], 'real page envelope and revision' );
	$check( $collections->update_with_state( 'galleries', array( $fixture['image'] ), array( 'queued' => 1 ) ), 'compact collection and state save' );
	$check( 1 === $writes && serialize( $settings->get_migrator_setting( 'galleries' ) ) === serialize( $collections->read_snapshot( 'galleries' ) ), 'one nonpartial collection write with facade runtime parity' ); $writes = 0;
	$check( $content->replace_post_matches( '09', array( 'new' => array( 'post_id' => '09', 'original_content' => 'new' ) ), array( 'cursor' => 10 ) ), 'replace exact typed post findings and checkpoint' );
	$check( array( 9, 'new' ) === array_keys( $content->read_snapshot() ) && array( 'cursor' => 10 ) === $content->metadata() && 1 === $writes, 'content companion is atomic and key types survive' ); $writes = 0;
	$check( $queue->update_item( 0, array( 'attachment_id' => 71, 'tags' => array( '雪' ) ), array( 'processed' => 1 ) ) && 1 === $writes, 'queue item and processed state save once' ); $writes = 0;
	$check( $history->update_status( $fixture['image']->unique_identifier(), 'error' ) && 5 === $history->revision() && 1 === $writes, 'history status and revision advance once' ); $writes = 0;
	$check( $history->update_status( $fixture['image']->unique_identifier(), 'error' ) && 0 === $writes, 'status no-op does not advance revision or write' );
	$check( 1 === $history->log_summary()['error'] && 1 === $history->log_summary()['total'], 'scalar-only log summary' );
	$stable = serialize( $backend->read_all() );
	add_filter( 'pre_update_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $fail, 10, 2 );
	$check( false === $history->upsert_many( array( $fixture['image'] ), array( 'galleries-state' => false ) ), 'failed history and companion update refuses' );
	$check( false === $collections->update_with_state( 'galleries', array(), false ), 'failed collection and state update refuses' );
	$check( false === $content->update_with_scan_state( array(), array( 'cursor' => 999 ) ), 'failed content and checkpoint refuses' );
	$check( false === $queue->update_with_sync_state( array(), array( 'processed' => 999 ) ), 'failed queue and checkpoint refuses' );
	$check( $stable === serialize( $backend->read_all() ) && 0 === $writes && 5 === $history->revision(), 'all failed writes leave exact previous state and revision' );
	remove_filter( 'pre_update_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $fail, 10 );
	$refuses( function () use ( $history ) { $history->get_many( array( 'x', 'x' ) ); }, 'duplicate exact keys refuse' );
	$refuses( function () use ( $settings ) { new ScalarStateRepository( new stdClass(), $settings ); }, 'unsupported table backend cannot fall back to options' );
	if ( is_multisite() ) {
		$source_blog = get_current_blog_id();
		$other_blogs = get_sites( array( 'fields' => 'ids', 'number' => 1, 'site__not_in' => array( $source_blog ) ) );
		$check( 1 === count( $other_blogs ), 'real multisite requires a second blog' );
		$source_state = $backend->read_all();
		$cursor_state = $source_state;
		$cursor_state['migrated'] = array( '09' => $fixture['historical'], 9 => $fixture['historical'] );
		$check( $backend->replace_all( $cursor_state ), 'seed real cross-blog cursor fixture' );
		$cursor = $history->iterate_page( 1 )['cursor'];
		$check( null !== $cursor, 'cursor fixture has a second row' );
		switch_to_blog( (int) $other_blogs[0] );
		$other_before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
		try {
			$other_state = $cursor_state; $other_state['unknown'] = 'second-blog';
			$check( $backend->replace_all( $other_state ), 'seed second real blog' );
			$check( 'second-blog' === $scalar->get( 'unknown' ), 'same repository follows actual switched option scope' );
			$refuses( function () use ( $history, $cursor ) { $history->iterate_page( 1, $cursor ); }, 'cross-blog cursor refuses even identical history and revision' );
			$check( $scalar->set( 'stored-false', 'second-only' ), 'mutate only switched blog' );
		} finally {
			if ( $other_before === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
			else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $other_before, false ); }
			$other_after = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
			restore_current_blog();
			$backend->replace_all( $source_state );
			$check( $other_before === $missing ? $other_after === $missing : serialize( $other_before ) === serialize( $other_after ), 'second blog exact original state restored' );
		}
		$check( $source_blog === get_current_blog_id() && serialize( $source_state ) === serialize( $backend->read_all() ), 'original blog context and exact state restored' );
	}
} finally {
	remove_filter( 'pre_update_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $fail, 10 );
	remove_action( 'updated_option', $observe ); remove_action( 'added_option', $observe );
	if ( $before === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
	else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $before, false ); }
	$restored = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
	$check( $before === $missing ? $restored === $missing : serialize( $before ) === serialize( $restored ), 'exact original option restored and read back' );
}
print wp_json_encode( array( 'checks' => $checks, 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'database' => $GLOBALS['wpdb']->get_var( 'SELECT VERSION()' ), 'multisite' => is_multisite(), 'blog' => get_current_blog_id() ) ) . "\n";
