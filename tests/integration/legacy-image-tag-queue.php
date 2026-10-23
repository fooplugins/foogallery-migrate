<?php
/** Disposable SQL contract. An outer runner must pin the exact site/DB/container. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ||
	! defined( 'FOOGM_STORAGE_TEST_ROOT' ) || realpath( FOOGM_STORAGE_TEST_ROOT ) !== realpath( dirname( dirname( __DIR__ ) ) ) ) {
	throw new RuntimeException( 'Exact owned disposable site admission required.' );
}
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;

class ImageTagQueueMeasuredConnection extends LegacyTransactionConnection {
	public $queries = 0;
	public $fail_head_update = false;
	public $fail_checkpoint_update = false;
	public $fault_fired = false;
	public function get_results( $sql, $format ) { ++$this->queries; return parent::get_results( $sql, $format ); }
	public function query( $sql ) {
		++$this->queries;
		if ( $this->fail_head_update && false !== strpos( $sql, 'SET active_generation =' ) ) {
			$this->fail_head_update = false; $this->fault_fired = true;
			$this->last_error = 'Owned late queue head fault.'; return false;
		}
		if ( $this->fail_checkpoint_update && false !== strpos( $sql, 'SET root_payload =' ) ) {
			$this->fail_checkpoint_update = false; $this->fault_fired = true;
			$this->last_error = 'Owned late queue checkpoint fault.'; return false;
		}
		return parent::query( $sql );
	}
}

global $wpdb;
if ( is_multisite() || ! ( $wpdb->dbh instanceof mysqli ) || ini_get( 'memory_limit' ) !== '256M' ) {
	throw new RuntimeException( 'Single-site mysqli and effective 256M are required.' );
}
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	if ( $wpdb->last_error || null !== $found ) { throw new RuntimeException( 'Refusing existing shared storage.' ); }
}
foreach ( array( 'foogallery-migrate-data', 'foogallery_migrate_storage_authority', 'foogallery_migrate_storage_control',
	'foogallery_migrate_storage_maintenance', 'foogallery_migrate_storage_cleanup', 'foogallery_migrate_storage_schema_version' ) as $name ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2', $name ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) ) { throw new RuntimeException( 'Refusing occupied plugin storage.' ); }
}
$protected = $wpdb->get_results( 'SELECT ID, post_type, post_status, post_title, post_content FROM `' . $wpdb->posts . '` ORDER BY ID', ARRAY_A );
if ( ! is_array( $protected ) || $wpdb->last_error ) { throw new RuntimeException( 'Protected posts cannot be pinned.' ); }
$check = function ( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } };
$refuses = function ( $call, $label, $message = null ) use ( $check ) {
	try { $call(); } catch ( RuntimeException $error ) {
		$check( null === $message || false !== strpos( $error->getMessage(), $message ), $label . ' wrong refusal' ); return true;
	}
	$check( false, $label ); return false;
};
$success = false; $created = array(); $metrics = array();
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'schema' );
	$db = new ImageTagQueueMeasuredConnection( $wpdb );
	$backend = new LegacyTableBackend( $db, (int) get_current_blog_id() );
	$queue = new ImageTagQueueRepository( $backend, new MigratorSettings() );
	$other = new ImageTagQueueRepository( new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), (int) get_current_blog_id() ), new MigratorSettings() );
	$check( false === $queue->summary()['found'], 'initial absence' );
	$check( $queue->beginReplacement(), 'begin absent replacement' );
	$refuses( function () use ( $other ) { $other->beginReplacement(); }, 'overlapping build must refuse', 'Inactive queue generation' );
	$check( false === $other->summary()['found'], 'absent old queue stays absent while staged' );
	$rows = array(); for ( $i = 1; $i <= 205; ++$i ) { $rows[] = array( 'attachment_id' => $i, 'tags' => array( 'old-' . $i ) ); }
	$early = null; $late = null; $page_queries = array();
	foreach ( array_chunk( $rows, 50 ) as $page ) {
		$before = $db->queries; $started = microtime( true );
		$check( $queue->applyEngineComputedBuildPage( $page ), 'staged bounded page' );
		$page_queries[] = array( 'queries' => $db->queries - $before, 'seconds' => round( microtime( true ) - $started, 3 ) );
		$check( false === $other->summary()['found'], 'staging is not authoritative' );
	}
	$staged = $queue->getManyBuildRowsByKeys( array( 1, 205 ) );
	$check( count( $staged ) === 2 && $staged[0]['found'] && $staged[1]['found'] &&
		$staged[0]['value']['tags'] === array( 'old-1' ), 'bounded staged lookup' );
	$check( $queue->applyEngineComputedBuildPage( array( array( 'attachment_id' => 1, 'tags' => array( 'merged' ) ) ) ), 'cross-page merge' );
	$check( $queue->getManyBuildRowsByKeys( array( 1 ) )[0]['value']['tags'] === array( 'merged' ), 'merged staged row' );
	$started = microtime( true ); $before = $db->queries;
	$check( $queue->finalizeReplacement( array( 'total' => 205, 'processed' => 0, 'errors' => array(), 'unknown' => array( '09' => false ) ) ), 'publish' );
	$metrics['finalize'] = array( 'queries' => $db->queries - $before, 'seconds' => round( microtime( true ) - $started, 3 ) );
	$summary = $other->summary();
	$check( $summary['found'] && $summary['total'] === 205 && $summary['revision'] === 1 &&
		$summary['summary']['unknown'] === array( '09' => false ), 'published summary and unknown fields' );
	$cursor = null; $seen = array(); $read_queries = array(); $batch_pages = 0;
	do {
		++$batch_pages; $check( $batch_pages <= 9, 'bounded queue batch count' );
		$before = $db->queries; $started = microtime( true );
		$batch = $queue->nextBatch( 25, $cursor );
		$check( count( $batch['rows'] ) > 0 && count( $batch['rows'] ) <= 25 &&
			( null === $batch['cursor'] || ( is_array( $batch['cursor'] ) &&
				( null === $cursor || $batch['cursor']['position'] > $cursor['position'] ) ) ),
			'queue batch makes bounded cursor progress' );
		$read_queries[] = array( 'queries' => $db->queries - $before, 'seconds' => round( microtime( true ) - $started, 3 ) );
		foreach ( $batch['rows'] as $entry ) { $seen[$entry['key']] = $entry['value']; }
		$cursor = $batch['cursor'];
	} while ( null !== $cursor );
	$check( 9 === $batch_pages && count( $seen ) === 205 && array_keys( $seen ) === range( 0, 204 ) &&
		$seen[0]['tags'] === array( 'merged' ) && $seen[204]['attachment_id'] === 205, '205 ordered indexed items' );
	$metrics['build_pages'] = $page_queries; $metrics['read_pages'] = $read_queries;
	$old_cursor = $queue->nextBatch( 1 )['cursor']; $revision = $summary['revision'];
	$db->fail_checkpoint_update = true; $db->fault_fired = false;
	$refuses( function () use ( $queue, $revision ) { $queue->applyBatchPatch( array( array( 'key' => 0,
		'value' => array( 'attachment_id' => 1, 'tags' => array( 'checked' ) ) ) ), array( 'processed' => 1, 'updated' => 1 ), $revision ); },
		'late queue checkpoint rollback' );
	$check( $db->fault_fired && $other->summary()['revision'] === $revision &&
		! array_key_exists( 'updated', $other->summary()['summary'] ) &&
		$other->nextBatch( 1 )['rows'][0]['value']['tags'] === array( 'merged' ), 'late checkpoint leaves item and new header unchanged' );
	$check( $queue->applyBatchPatch( array( array( 'key' => 0,
		'value' => array( 'attachment_id' => 1, 'tags' => array( 'checked' ) ) ) ),
		array( 'processed' => 1, 'updated' => 1, 'skipped' => 0, 'complete' => false, 'progress' => 1, 'errors' => array() ),
		$revision ), 'item and expanded summary checkpoint' );
	$check( $other->summary()['revision'] === $revision + 1 && $other->summary()['summary']['processed'] === 1 &&
		array_keys( $other->summary()['summary'] ) === array( 'total', 'processed', 'errors', 'unknown', 'updated', 'skipped', 'complete', 'progress' ) &&
		$other->nextBatch( 1 )['rows'][0]['value']['tags'] === array( 'checked' ), 'checkpoint item and summary readback' );
	$refuses( function () use ( $queue, $old_cursor ) { $queue->nextBatch( 1, $old_cursor ); }, 'stale bound queue cursor', 'Stale queue batch cursor' );
	$refuses( function () use ( $queue, $revision ) { $queue->applyBatchPatch( array(), array( 'processed' => 2 ), $revision ); }, 'stale queue head' );
	$refuses( function () use ( $queue, $revision ) { $queue->applyBatchPatch( array( array( 'key' => 0,
		'value' => array( 'attachment_id' => 999, 'tags' => array( 'wrong' ) ) ) ), array( 'processed' => 2 ), $revision + 1 ); },
		'indexed attachment identity cannot change', 'Indexed queue attachment identity changed' );
	$check( $other->summary()['revision'] === $revision + 1 &&
		$other->nextBatch( 1 )['rows'][0]['value']['attachment_id'] === 1, 'identity refusal has no checkpoint side effect' );
	$snapshot = new LegacyTableSnapshot( $db, (int) get_current_blog_id() );
	$check( $snapshot->verify_digest( ImageTagQueueRepository::STATE_KEY, $revision + 1 ), 'explicit dirty queue maintenance proof' );
	$current = $snapshot->read_bounded( ImageTagQueueRepository::STATE_KEY );
	$check( $current['items'][0]['tags'] === array( 'checked' ) && count( $current['items'] ) === 205,
		'new indexed queue reconstructs exactly for maintenance/reversal' );
	$check( $queue->beginReplacement(), 'begin replacement retaining old queue' );
	$check( $other->summary()['total'] === 205, 'old queue remains authoritative during replacement' );
	$refuses( function () use ( $other ) { $other->beginReplacement(); }, 'second builder cannot erase stage', 'Inactive queue generation' );
	$refuses( function () use ( $other ) { $other->abortReplacement(); }, 'wrong owner cannot abort staged queue', 'No owned image-tag queue replacement' );
	$check( $other->applyBatchPatch( array( array( 'key' => 0,
		'value' => array( 'attachment_id' => 1, 'tags' => array( 'interleaved' ) ) ) ), array( 'processed' => 2 ), $revision + 1 ),
		'legitimate active queue checkpoint while replacement is staged' );
	$refuses( function () use ( $queue ) { $queue->finalizeReplacement( array( 'total' => 1 ) ); },
		'stale staged replacement cannot publish', 'Queue head changed during replacement' );
	$check( $queue->abortReplacement() && $other->summary()['revision'] === $revision + 2 &&
		$other->nextBatch( 1 )['rows'][0]['value']['tags'] === array( 'interleaved' ),
		'exact owner aborts inactive stage without undoing active checkpoint' );
	$check( $queue->beginReplacement(), 'fresh replacement after owned abort' );
	$check( $queue->applyEngineComputedBuildPage( array( array( 'attachment_id' => 9, 'tags' => array( 'new' ) ) ) ), 'stage replacement' );
	$db->fail_head_update = true; $db->fault_fired = false;
	$refuses( function () use ( $queue ) { $queue->finalizeReplacement( array( 'total' => 1 ) ); }, 'late publication fault' );
	$check( $db->fault_fired && $other->summary()['total'] === 205 &&
		$other->nextBatch( 1 )['rows'][0]['value']['tags'] === array( 'interleaved' ), 'late rollback retains old queue' );
	$check( $queue->abortReplacement(), 'same-owner abort' );
	$check( $other->summary()['total'] === 205, 'abort retains old queue' );
	$check( $queue->clear(), 'clear owned queue' );
	$check( false === $other->summary()['found'], 'clear removed queue' );
	$legacy_items = array( '09' => array( 'attachment_id' => 7, 'tags' => array( 'first' ) ) );
	for ( $i = 0; $i < 204; ++$i ) { $legacy_items[$i] = array( 'attachment_id' => 7, 'tags' => array( 'legacy-' . $i ) ); }
	$legacy_items[1] = null; $legacy_items[2] = false; $legacy_items[3] = 'opaque';
	$legacy = array( 'opaque-before' => "caf\xc3\xa9\0", 'items' => $legacy_items, 'total' => 205,
		'errors' => array( '09' => false, 9 => 'retained' ), 'opaque-after' => null );
	$option = new LegacyOptionBackend();
	$check( $option->replace_all( array( ImageTagQueueRepository::STATE_KEY => $legacy ) ), 'save exact legacy option queue' );
	$option_queue = new ImageTagQueueRepository( $option, new MigratorSettings() );
	$effective = $option_queue->nextBatch( 4 )['rows'];
	$check( $effective[1] === array( 'key' => 1, 'found' => false, 'value' => array() ) &&
		$effective[2] === array( 'key' => 2, 'found' => true, 'value' => array() ) &&
		$effective[3] === array( 'key' => 3, 'found' => true, 'value' => array() ), 'legacy option executable slot semantics' );
	$raw_before = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2',
		FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$check( is_array( $raw_before ) && ! $wpdb->last_error && count( $raw_before ) === 1 &&
		serialize( maybe_unserialize( $raw_before[0]['option_value'] ) ) === serialize( array( ImageTagQueueRepository::STATE_KEY => $legacy ) ),
		'exact SQL-backed legacy option source' );
	$check( $snapshot->replace_many( array( array( 'key' => ImageTagQueueRepository::STATE_KEY, 'order' => 0,
		'kind' => 'map', 'value' => $legacy, 'expected_revision' => null ) ) ), 'copy saved queue into indexed rows' );
	$imported = $snapshot->read_bounded( ImageTagQueueRepository::STATE_KEY );
	$check( serialize( $imported ) === serialize( $legacy ), 'saved option queue exact table roundtrip' );
	$check( $queue->nextBatch( 4 )['rows'] === $effective, 'indexed table executable slots match option null/scalar behavior' );
	$export = new LegacyTableRepository( $db, (int) get_current_blog_id() ); $cursor = null; $exported = array();
	$export_sizes = array(); $export_pages = 0;
	do {
		++$export_pages; $check( $export_pages <= 6, 'bounded export page count' );
		$page = $export->export_page( ImageTagQueueRepository::STATE_KEY, 50, $cursor );
		$size = count( $page['rows'] ); $export_sizes[] = $size;
		if ( $size ) {
			$check( is_array( $page['cursor'] ) && isset( $page['cursor']['order'], $page['cursor']['id'] ) &&
				( null === $cursor || $page['cursor']['order'] > $cursor['order'] ||
					( $page['cursor']['order'] === $cursor['order'] && $page['cursor']['id'] > $cursor['id'] ) ),
				'export cursor advances on nonempty page' );
		} else { $check( $page['cursor'] === $cursor, 'terminal empty export keeps the last cursor' ); }
		foreach ( $page['rows'] as $entry ) { $exported[$entry['key']] = $entry['value']; }
		$cursor = $page['cursor'];
	} while ( $size );
	$check( $export_sizes === array( 50, 50, 50, 50, 5, 0 ) &&
		serialize( $exported ) === serialize( $legacy_items ), '205 saved roots remain typed, sparse and duplicated on export' );
	$check( delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'remove only pinned test-owned option before reverse write' );
	$absent = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2',
		FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$check( is_array( $absent ) && ! $wpdb->last_error && ! count( $absent ), 'reverse target genuinely absent' );
	$check( $option->replace_all( array( ImageTagQueueRepository::STATE_KEY => $imported ) ) &&
		serialize( $option->read_all()[ImageTagQueueRepository::STATE_KEY] ) === serialize( $legacy ),
		'indexed table queue reverses to exact legacy option value' );
	$raw_after = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2',
		FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$check( is_array( $raw_after ) && ! $wpdb->last_error && count( $raw_after ) === 1 &&
		$raw_after[0]['option_value'] === $raw_before[0]['option_value'], 'fresh reverse write restores exact raw option bytes' );
	$imported_revision = $queue->summary()['revision'];
	$check( $queue->applyBatchPatch( array( array( 'key' => 1, 'value' => 'opaque-updated' ) ),
		array( 'processed' => 1 ), $imported_revision ) &&
		$backend->roots( ImageTagQueueRepository::STATE_KEY, array( 1 ) )[0]['value'] === 'opaque-updated',
		'legacy NULL-dedupe item remains mutable without an invented attachment-ID requirement' );
	$check( $queue->clear(), 'clear imported queue' );
	$check( delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'remove exact test-owned option' );
	$posts_after = $wpdb->get_results( 'SELECT ID, post_type, post_status, post_title, post_content FROM `' . $wpdb->posts . '` ORDER BY ID', ARRAY_A );
	$check( is_array( $posts_after ) && ! $wpdb->last_error && $protected === $posts_after, 'protected posts unchanged' );
	$success = true;
} finally {
	if ( $success ) {
		foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE `' . $table . '`' ); }
		foreach ( $created as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $wpdb->last_error || null !== $found ) { throw new RuntimeException( 'Test-owned table cleanup incomplete.' ); }
		}
		$left = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2',
			FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
		if ( ! is_array( $left ) || $wpdb->last_error || count( $left ) ) { throw new RuntimeException( 'Test-owned option cleanup incomplete.' ); }
	}
}
$metrics['peak_bytes'] = memory_get_peak_usage( true );
echo wp_json_encode( array( 'ok' => true, 'metrics' => $metrics, 'protected_posts' => count( $protected ) ) );
