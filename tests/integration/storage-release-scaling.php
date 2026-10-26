<?php
/**
 * R4h, one disposable, package-installed SQL fixture only. Each invocation is
 * a fresh WP-CLI request: init; seed (repeat); measure METRIC; write; advance;
 * seed (repeat); measure METRIC; write; compare. No production authority switch.
 */
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableReader;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

function scaling_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
scaling_check( defined( 'WP_CLI' ) && WP_CLI && isset( $args ) && is_array( $args ) && count( $args ) >= 2 && count( $args ) <= 3,
	'Explicit owned WP-CLI site and phase required.' );
$expected_site = realpath( '/tmp/foogallery-migrate-r0.QDqsXk' );
$site = realpath( $args[0] );
scaling_check( false !== $expected_site && $site === $expected_site && realpath( ABSPATH ) === $expected_site,
	'Only the pinned fresh R0 package site is accepted.' );
$manifest_path = $site . '/.fgm-r0-owner.json';
$manifest = is_file( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
$expected_container = '29b9ebe31b4d001eafc560f6d8e8597fd1607652b99d46c2bcd1b6a860d2ef86';
$expected_zip = 'fae4d5d1d922cede06d8a31a6c11ea3e3e0c063a3f663e16c663191e0df7cc93';
$expected_tree = '55b4972ed755f7738ae83b5eb828b00fbd7131fe86907fc334bcab6035ad7ad6';
$repo = realpath( dirname( dirname( __DIR__ ) ) );
scaling_check( is_array( $manifest ) && isset( $manifest['kind'], $manifest['site'], $manifest['database'],
	$manifest['host'], $manifest['repo'], $manifest['container'], $manifest['container_id'], $manifest['package'] ) &&
	$manifest['kind'] === 'foogallery-migrate-r0-v1' && realpath( $manifest['site'] ) === $site &&
	$manifest['database'] === 'fgm_r0_qdqsxk' && $manifest['container'] === 'fgm-r0-QDqsXk' &&
	$manifest['container_id'] === $expected_container && $manifest['host'] === '127.0.0.1:32772' &&
	$repo === realpath( $manifest['repo'] ) && isset( $manifest['package']['zip_sha256'], $manifest['package']['tree_sha256'] ) &&
	$manifest['package']['zip_sha256'] === $expected_zip && $manifest['package']['tree_sha256'] === $expected_tree,
	'Pinned package manifest or database identity differs.' );
$plugin_dir = $site . '/wp-content/plugins/foogallery-migrate';
scaling_check( ! is_link( $plugin_dir ) && realpath( $plugin_dir ) === $plugin_dir, 'Source-mode plugin is refused.' );
$identity_output = array(); $identity_code = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-release-package-identity.php' ) .
	' verify-tree ' . escapeshellarg( $plugin_dir ) . ' ' . escapeshellarg( $manifest_path ) . ' 2>/dev/null',
	$identity_output, $identity_code );
scaling_check( 0 === $identity_code && count( $identity_output ) === 1 && trim( $identity_output[0] ) === $expected_tree,
	'Installed package tree changed.' );
$container_output = array(); $container_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) .
	' ' . escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container_output, $container_code );
scaling_check( 0 === $container_code && count( $container_output ) === 1 &&
	trim( $container_output[0] ) === $expected_container . ' owned', 'Pinned SQL container differs.' );
ini_set( 'memory_limit', '256M' );
global $wpdb;
scaling_check( ini_get( 'memory_limit' ) === '256M' && ! is_multisite() &&
	DB_NAME === $manifest['database'] && DB_HOST === $manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error &&
	function_exists( 'foogallery_migrate_migrator_instance' ) && function_exists( 'foogallery_default_gallery_template' ) &&
	defined( 'FOOGALLERY_VERSION' ) && get_current_user_id() > 0 &&
	wp_get_current_user()->user_login === 'fgmr0admin' && current_user_can( 'manage_options' ),
	'Loaded site, database, memory, plugins or administrator differ.' );
$phase = $args[1]; $metric = isset( $args[2] ) ? $args[2] : null;
$metrics = array( 'history_page', 'history_summary', 'history_lookup', 'content_window', 'gallery_page', 'next_context' );
scaling_check( in_array( $phase, array( 'init', 'seed', 'measure', 'write', 'advance', 'compare', 'status' ), true ) &&
	( ( 'measure' === $phase && in_array( $metric, $metrics, true ) ) || ( 'measure' !== $phase && null === $metric ) ),
	'Invalid bounded scaling phase or metric.' );

$journal_key = 'fgm_r4i_scaling_qdqsxk_v1';
$raw_option = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $name ) );
	scaling_check( ! $wpdb->last_error, 'Raw option lookup failed.' ); return $value;
};
$save = function ( $journal ) use ( $journal_key, $raw_option ) {
	scaling_check( false !== update_option( $journal_key, $journal, false ) || get_option( $journal_key, null ) === $journal,
		'Journal update failed.' );
	$raw = $raw_option( $journal_key );
	scaling_check( is_string( $raw ) && maybe_unserialize( $raw ) === $journal && get_option( $journal_key, null ) === $journal,
		'Journal durable readback failed.' );
};
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$db = new LegacyTransactionConnection( $wpdb ); $blog = (int) get_current_blog_id();
$reader = new LegacyTableReader( $db, $blog );
$backend = new LegacyTableBackend( $db, $blog );
$settings = new MigratorSettings(); $plugin = new Aigpl();
$history = new MigratedHistoryRepository( $backend, $settings );
$content = new ContentScanRepository( $backend, $settings );
$collection = new MigratableCollectionRepository( $backend, $settings );
$head_count = function ( $key ) use ( $reader ) { $head = $reader->head( $key ); return false === $head ? 0 : $head['record_count']; };
$actual_counts = function () use ( $head_count ) {
	return array( 'history' => $head_count( 'migrated' ), 'content' => $head_count( ContentScanRepository::STATE_KEY ),
		'gallery' => $head_count( 'galleries' ) );
};
$journal = get_option( $journal_key, null );
if ( 'init' === $phase ) {
	scaling_check( null === $raw_option( $journal_key ), 'Scaling journal already exists.' );
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION,
		StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION,
		LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) {
		scaling_check( null === $raw_option( $name ), 'Existing migration state is refused.' );
	}
	foreach ( $tables as $table ) {
		scaling_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) &&
			! $wpdb->last_error, 'Existing shared SQL table is refused.' );
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( $schema->statements() as $ddl ) { dbDelta( $ddl ); }
	scaling_check( $schema->verify(), 'Schema verification failed.' );
	( new LegacyTableSnapshot( $db, $blog ) )->replace_many( array( array( 'key' => 'galleries', 'order' => 0,
		'kind' => 'map', 'value' => $settings->compact_state_value( 'galleries', array() ), 'expected_revision' => null ) ) );
	scaling_check( $actual_counts() === array( 'history' => 0, 'content' => 0, 'gallery' => 0 ), 'Empty head setup differs.' );
	$journal = array( 'identity' => hash( 'sha256', $site . '|' . $expected_container . '|' . $expected_tree ),
		'stage' => 2000, 'counts' => $actual_counts(), 'measurements' => array(), 'writes' => array() );
	$save( $journal ); echo wp_json_encode( array( 'phase' => 'init', 'journal' => $journal ) ) . "\n"; return;
}
scaling_check( is_array( $journal ) && isset( $journal['identity'], $journal['stage'], $journal['counts'],
	$journal['measurements'], $journal['writes'] ) &&
	$journal['identity'] === hash( 'sha256', $site . '|' . $expected_container . '|' . $expected_tree ) &&
	in_array( $journal['stage'], array( 2000, 20000 ), true ) && $schema->verify(), 'Scaling journal or schema differs.' );
scaling_check( $actual_counts() === $journal['counts'], 'SQL count differs from durable journal; no automatic replay.' );
if ( 'status' === $phase ) { echo wp_json_encode( $journal ) . "\n"; return; }

if ( 'seed' === $phase ) {
	scaling_check( ! isset( $journal['writes'][ $journal['stage'] ] ) &&
		$journal['counts']['history'] === $journal['counts']['content'] &&
		$journal['counts']['content'] === $journal['counts']['gallery'] &&
		$journal['counts']['gallery'] < $journal['stage'], 'Seeding is not at a safe equal-count boundary.' );
	$start = $journal['counts']['gallery']; $limit = min( 100, $journal['stage'] - $start );
	$images = array(); $findings = array(); $patches = array();
	for ( $i = $start; $i < $start + $limit; ++$i ) {
		$image = new Image( $plugin ); $image->ID = 3000000 + $i;
		$image->source_url = 'https://r4h.invalid/image/' . $i; $image->migrated = true;
		$image->migrated_id = 4000000 + $i; $image->migration_status = 'completed'; $images[] = $image;
		$findings[] = array( 'post_id' => 5000000 + $i, 'plugin_name' => 'WordPress Core',
			'type' => 'shortcode', 'original_content' => '[gallery ids="1"]', 'scan_occurrence' => 0 );
		$gallery = new Gallery( $plugin ); $gallery->ID = 6000000 + $i; $gallery->title = 'R4h gallery';
		if ( 0 === $i ) { $gallery->part_of_migration = true; $gallery->migration_status = 'queued'; }
		$compact = $settings->compact_state_value( 'galleries', array( $gallery ) );
		$patches[] = array( 'key' => $i, 'value' => $compact['items'][0] );
	}
	// Commit each domain once, with a durable receipt. Any interrupted triad refuses
	// on the next request until a human reconciles actual counts; no blind replay.
	scaling_check( false !== $history->upsert_batch( $images ), 'History batch refused.' );
	$journal['counts']['history'] += $limit; $save( $journal );
	scaling_check( true === $content->append_batch( $findings, array( 'r4h' => $start + $limit ) ), 'Content batch refused.' );
	$journal['counts']['content'] += $limit; $save( $journal );
	$head = $reader->head( 'galleries' );
	scaling_check( false !== $backend->patch_many( array( array( 'key' => 'galleries',
		'expected_revision' => $head['revision'], 'patches' => $patches ) ) ), 'Gallery batch refused.' );
	$journal['counts']['gallery'] += $limit; $save( $journal );
	scaling_check( $actual_counts() === $journal['counts'], 'Seed readback differs.' );
	echo wp_json_encode( array( 'phase' => 'seed', 'stage' => $journal['stage'], 'counts' => $journal['counts'] ) ) . "\n"; return;
}
scaling_check( $journal['counts'] === array( 'history' => $journal['stage'], 'content' => $journal['stage'],
	'gallery' => $journal['stage'] ), 'Full stage corpus required.' );
$stage = $journal['stage'];
$target_uid = 'gallery_' . $plugin->name() . '_6000000';
if ( 'measure' === $phase ) {
	scaling_check( ! isset( $journal['writes'][$stage] ) && ! isset( $journal['measurements'][$stage][$metric] ),
		'Metric already measured or target write completed.' );
	$questions = function () use ( $wpdb ) {
		$result = $wpdb->dbh->query( "SHOW SESSION STATUS LIKE 'Questions'" );
		scaling_check( $result instanceof mysqli_result, 'Native SQL questions unavailable.' );
		$row = $result->fetch_row(); $result->free();
		scaling_check( is_array( $row ) && isset( $row[1] ), 'Malformed SQL questions.' ); return (int) $row[1];
	};
	$before_questions = $questions();
	scaling_check( function_exists( 'memory_reset_peak_usage' ), 'Independent operation peak reset unavailable.' );
	memory_reset_peak_usage();
	$before_live = memory_get_usage( false ); $before_allocated = memory_get_usage( true );
	$start_time = microtime( true );
	switch ( $metric ) {
		case 'history_page': $result = $history->page_log( 'image', 'completed', 25 );
			$keys = array_column( $result['rows'], 'key' ); break;
		case 'history_summary': $result = $history->log_summary(); $keys = array_keys( $result ); break;
		case 'history_lookup': $ids = array(); for ( $i = 0; $i < 25; ++$i ) { $ids[] = 'https://r4h.invalid/image/' . $i; }
			$result = $history->get_many( $ids ); $keys = array_column( $result, 'key' ); break;
		case 'content_window': $result = $content->window( 0, 25 ); $keys = array_keys( $result['items'] ); break;
		case 'gallery_page': $result = $collection->view_page( 'galleries', 0, 25 );
			$keys = array_column( $result['rows'], 'key' ); break;
		case 'next_context': $result = $collection->migration_turn_context( 'galleries' );
			$keys = ! empty( $result['found'] ) && isset( $result['object'] ) ?
				array( $result['object']->unique_identifier() ) : array(); break;
	}
	$elapsed = microtime( true ) - $start_time;
	$peak_live = memory_get_peak_usage( false ); $peak_allocated = memory_get_peak_usage( true );
	$after_questions = $questions();
	$first25 = range( 0, 24 );
	if ( 'history_page' === $metric ) {
		scaling_check( $keys === array_map( function ( $i ) { return 'https://r4h.invalid/image/' . $i; }, $first25 ),
			'History page identities differ.' );
		foreach ( $result['rows'] as $i => $row ) {
			scaling_check( $row['value'] instanceof Image && $row['value']->migrated_id === 4000000 + $i,
				'History page hydration differs.' );
		}
	} elseif ( 'history_summary' === $metric ) {
		scaling_check( isset( $result['total'], $result['completed'], $result['by_type']['image'] ) &&
			$result['total'] === $stage && $result['completed'] === $stage &&
			$result['by_type']['image'] === $stage, 'History summary corpus differs.' );
	} elseif ( 'history_lookup' === $metric ) {
		scaling_check( $keys === $ids, 'History lookup keys differ.' );
		foreach ( $result as $i => $row ) {
			scaling_check( ! empty( $row['found'] ) && $row['value'] instanceof Image &&
				$row['value']->source_url === $ids[$i] && $row['value']->migrated_id === 4000000 + $i,
				'History lookup missed or mis-hydrated an exact image.' );
		}
	} elseif ( 'content_window' === $metric ) {
		scaling_check( $keys === $first25 && $result['total'] === $stage,
			'Content fixed page keys or total differ.' );
		foreach ( $result['items'] as $i => $item ) {
			scaling_check( is_array( $item ) && isset( $item['post_id'] ) &&
				$item['post_id'] === 5000000 + $i, 'Content post identity differs.' );
		}
	} elseif ( 'gallery_page' === $metric ) {
		scaling_check( $keys === $first25, 'Gallery fixed page keys differ.' );
		foreach ( $result['rows'] as $i => $row ) {
			scaling_check( $row['value'] instanceof Gallery && $row['value']->ID === 6000000 + $i,
				'Gallery page ID differs.' );
		}
	} elseif ( 'next_context' === $metric ) {
		scaling_check( $keys === array( $target_uid ) && 6000000 === $result['object']->ID &&
			'queued' === $result['object']->migration_status, 'Small pending next root differs.' );
	}
	$receipt = array( 'stage' => $stage, 'metric' => $metric, 'keys_sha256' => hash( 'sha256', serialize( $keys ) ),
		'keys_count' => count( $keys ), 'questions' => $after_questions - $before_questions,
		'elapsed_seconds' => $elapsed, 'baseline_live' => $before_live, 'baseline_allocated' => $before_allocated,
		'peak_live' => $peak_live, 'peak_allocated' => $peak_allocated,
		'incremental_live' => max( 0, $peak_live - $before_live ),
		'incremental_allocated' => max( 0, $peak_allocated - $before_allocated ) );
	if ( in_array( $metric, array( 'history_page', 'history_lookup', 'content_window', 'gallery_page' ), true ) ) {
		scaling_check( 25 === $receipt['keys_count'], 'Fixed page did not return 25 keys.' );
	}
	if ( 'next_context' === $metric ) { scaling_check( $keys === array( $target_uid ), 'Next context did not select the small pending root.' ); }
	$journal['measurements'][$stage][$metric] = $receipt; $save( $journal );
	echo wp_json_encode( $receipt ) . "\n"; return;
}
$unrelated_hash = function () use ( $reader, $tables, $wpdb, $blog, $stage ) {
	$receipts = array();
	foreach ( array( 'history' => 'migrated', 'content' => ContentScanRepository::STATE_KEY,
		'gallery' => 'galleries' ) as $domain => $key ) {
		$head = $reader->head( $key );
		$exclude_target = 'gallery' === $domain ? ' AND item_order>0' : '';
		$sql = $wpdb->prepare( 'SELECT id, HEX(node_hash), HEX(logical_hash), HEX(payload_hash), payload, mutable_payload, item_order FROM `' . $tables[1] . '` WHERE blog_id=%d AND state_id=%d AND generation=%d AND parent_node_hash IS NULL' . $exclude_target . ' ORDER BY item_order,id',
			$blog, $head['id'], $head['active_generation'] );
		$result = $wpdb->dbh->query( $sql, MYSQLI_USE_RESULT );
		scaling_check( $result instanceof mysqli_result, 'Unrelated streamed SQL read failed.' );
		$hash = hash_init( 'sha256' ); $count = 0;
		while ( $row = $result->fetch_row() ) { hash_update( $hash, serialize( $row ) ); ++$count; }
		$result->free();
		scaling_check( $count === $stage - ( 'gallery' === $domain ? 1 : 0 ), 'Unrelated root count differs.' );
		$receipts[$domain] = array( 'count' => $count, 'sha256' => hash_final( $hash ) );
	}
	return $receipts;
};
if ( 'write' === $phase ) {
	scaling_check( ! isset( $journal['writes'][$stage] ) && isset( $journal['measurements'][$stage] ) &&
		count( $journal['measurements'][$stage] ) === count( $metrics ), 'Six fresh-request metrics required before target write.' );
	$before = $unrelated_hash();
	$questions = function () use ( $wpdb ) {
		$result = $wpdb->dbh->query( "SHOW SESSION STATUS LIKE 'Questions'" );
		scaling_check( $result instanceof mysqli_result, 'Native SQL questions unavailable.' );
		$row = $result->fetch_row(); $result->free();
		scaling_check( is_array( $row ) && isset( $row[1] ), 'Malformed SQL questions.' ); return (int) $row[1];
	};
	$before_questions = $questions();
	scaling_check( function_exists( 'memory_reset_peak_usage' ), 'Independent operation peak reset unavailable.' );
	memory_reset_peak_usage();
	$before_live = memory_get_usage( false ); $before_allocated = memory_get_usage( true );
	$started = microtime( true );
	$context = $collection->targeted_context( 'galleries', $target_uid );
	scaling_check( ! empty( $context['found'] ) && $context['object']->unique_identifier() === $target_uid,
		'Targeted gallery context differs.' );
	$context['object']->title = 2000 === $stage ? 'R4h touched' : 'R4h edited!';
	scaling_check( false !== $collection->checkpoint_targeted( 'galleries', $context ), 'Affected-root checkpoint refused.' );
	$elapsed = microtime( true ) - $started; $peak_live = memory_get_peak_usage( false );
	$peak_allocated = memory_get_peak_usage( true ); $question_count = $questions() - $before_questions;
	$readback = $collection->targeted_context( 'galleries', $target_uid );
	scaling_check( ! empty( $readback['found'] ) && $readback['object']->title === $context['object']->title,
		'Target write readback differs.' );
	$receipt = array( 'stage' => $stage, 'elapsed_seconds' => $elapsed,
		'questions' => $question_count,
		'baseline_live' => $before_live, 'baseline_allocated' => $before_allocated,
		'peak_live' => $peak_live, 'peak_allocated' => $peak_allocated,
		'incremental_live' => max( 0, $peak_live - $before_live ),
		'incremental_allocated' => max( 0, $peak_allocated - $before_allocated ) );
	$after = $unrelated_hash(); scaling_check( $before === $after, 'Unrelated gallery roots changed.' );
	$receipt['unrelated'] = $after; $journal['writes'][$stage] = $receipt; $save( $journal );
	echo wp_json_encode( $receipt ) . "\n"; return;
}
if ( 'advance' === $phase ) {
	scaling_check( 2000 === $stage && isset( $journal['writes'][2000] ) &&
		count( $journal['measurements'][2000] ) === count( $metrics ), 'Completed 2K stage required.' );
	// Restore the same small target payload before the second stage's read/write.
	$context = $collection->targeted_context( 'galleries', $target_uid );
	scaling_check( ! empty( $context['found'] ), 'Target restore context absent.' );
	$context['object']->title = 'R4h gallery';
	scaling_check( false !== $collection->checkpoint_targeted( 'galleries', $context ), 'Target restore refused.' );
	$restored = $collection->targeted_context( 'galleries', $target_uid );
	scaling_check( ! empty( $restored['found'] ) && 'R4h gallery' === $restored['object']->title,
		'Target restore readback differs.' );
	$journal['stage'] = 20000; $save( $journal );
	echo wp_json_encode( array( 'phase' => 'advance', 'stage' => 20000 ) ) . "\n"; return;
}
if ( 'compare' === $phase ) {
	scaling_check( 20000 === $stage && isset( $journal['writes'][2000], $journal['writes'][20000] ) &&
		count( $journal['measurements'][2000] ) === count( $metrics ) &&
		count( $journal['measurements'][20000] ) === count( $metrics ), 'Both stage receipts required.' );
	$report = array(); $passed = true;
	foreach ( array_merge( $metrics, array( 'write' ) ) as $name ) {
		$low = 'write' === $name ? $journal['writes'][2000] : $journal['measurements'][2000][$name];
		$high = 'write' === $name ? $journal['writes'][20000] : $journal['measurements'][20000][$name];
		$fields = array( 'peak_live', 'peak_allocated', 'incremental_live', 'incremental_allocated' );
		foreach ( $fields as $field ) {
			$base = $low[$field]; $larger = $high[$field];
			$ratio = 0 === $base ? ( 0 === $larger ? 1.0 : null ) : $larger / $base;
			$primary = in_array( $field, array( 'peak_live', 'peak_allocated' ), true );
			$field_pass = null === $ratio ? null : $ratio <= 1.25;
			$report[$name][$field] = array( 'low' => $base, 'high' => $larger, 'ratio' => $ratio,
				'pass' => $field_pass, 'primary' => $primary );
			if ( $primary && true !== $field_pass ) { $passed = false; }
		}
		if ( 'write' !== $name ) {
			$report[$name]['keys_equal'] = $low['keys_sha256'] === $high['keys_sha256'];
			if ( ! $report[$name]['keys_equal'] ) { $passed = false; }
		}
	}
	echo wp_json_encode( array( 'phase' => 'compare', 'pass' => $passed, 'report' => $report ) ) . "\n";
	scaling_check( $passed, 'R4h primary peak or fixed-page identity threshold failed.' ); return;
}
throw new RuntimeException( 'Unreachable phase.' );
