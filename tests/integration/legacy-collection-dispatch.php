<?php
/** Guarded, real-SQL collection dispatch and shared clear-deadline checkpoint. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\Migrators\MigratorBase;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Image;

/** Delay a real database operation, without synthesizing its result. */
class CollectionDeadlineConnection extends LegacyTransactionConnection {
	public $delay_match;
	public $delay_reads = false;
	private function delay( $sql ) {
		if ( $this->delay_match && false !== strpos( $sql, $this->delay_match ) ) {
			$this->delay_match = null; usleep( 1100000 );
		}
	}
	public function query( $sql ) { if ( ! $this->delay_reads ) { $this->delay( $sql ); } return parent::query( $sql ); }
	public function get_results( $sql, $format ) { if ( $this->delay_reads ) { $this->delay( $sql ); } return parent::get_results( $sql, $format ); }
}

/** Inject one server-style failure during an affected-root/checkpoint transaction. */
class CollectionQueueFaultConnection extends LegacyTransactionConnection {
	public $state_table;
	public $records_table;
	public $fail_insert = false;
	public $fail_state_update_id = null;
	public $record_inserts = 0;
	public $record_inserts_at_fault = 0;
	public $after_identity_read;
	public function get_results( $sql, $format ) {
		$rows = parent::get_results( $sql, $format );
		if ( $this->after_identity_read && false !== strpos( $sql, 'SELECT id, source_plugin_value, source_id_value' ) ) {
			$call = $this->after_identity_read; $this->after_identity_read = null; call_user_func( $call );
		}
		return $rows;
	}
	public function query( $sql ) {
		if ( $this->fail_insert && 0 === strpos( $sql, 'INSERT INTO `' . $this->state_table . '`' ) ) {
			$this->fail_insert = false; $this->last_error = 'Injected checkpoint insert failure.'; return false;
		}
		if ( null !== $this->fail_state_update_id && 0 === strpos( $sql, 'UPDATE `' . $this->state_table . '` SET ' ) &&
			preg_match( '/ WHERE blog_id = [0-9]+ AND id = ' . preg_quote( (string) $this->fail_state_update_id, '/' ) . ' AND active_generation = [0-9]+ AND revision = [0-9]+$/', $sql ) ) {
			$this->record_inserts_at_fault = $this->record_inserts; $this->fail_state_update_id = null;
			$this->last_error = 'Injected late collection head failure.'; return false;
		}
		$result = parent::query( $sql );
		if ( false !== $result && $this->records_table && 0 === strpos( $sql, 'INSERT INTO `' . $this->records_table . '` ' ) ) { ++$this->record_inserts; }
		return $result;
	}
}

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing tables/unhealthy fixture DB.' ); }
}
$missing = new stdClass(); $saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
$checks = array(); $created = array(); $blog = (int) get_current_blog_id();
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $call, $label ) use ( $check ) { try { $call(); } catch ( RuntimeException $error ) { $check( true, $label . ': ' . $error->getMessage() ); return $error; } $check( false, $label ); };
$physical = function () use ( $wpdb, $tables ) {
	$result = array(); foreach ( $tables as $table ) { $rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Physical readback failed.' ); } $result[$table] = $rows; } return serialize( $result );
};
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'schema v1 unchanged' );
	$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog ); $snapshot = new LegacyTableSnapshot( $db, $blog );
	$settings = new MigratorSettings(); $plugin = new \FooPlugins\FooGalleryMigrate\Plugins\Aigpl();
	$queue = function ( $store, $bucket, $selection ) use ( $settings ) {
		return ( new MigratableCollectionRepository( $store, $settings ) )->queue_selected( $bucket, $selection,
			array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_transition' ),
			array( 'FooPlugins\\FooGalleryMigrate\\Migrators\\MigratorBase', 'queue_summary' ) );
	};
	$image = new Image( $plugin ); $image->source_url = 'typed-child'; $image->data = array( 'opaque' => "\xff\x00" );
	$gallery = new Gallery( $plugin ); $gallery->ID = 101; $gallery->children = array( '09' => $image, 9 => clone $image );
	$other = new Gallery( $plugin ); $other->ID = 102;
	$objects = array( '09' => $gallery, 9 => $other );
	$album = new Album( $plugin ); $album->ID = 201; $album->children = array( '09' => clone $gallery, 9 => clone $gallery );
	$second_album = new Album( $plugin ); $second_album->ID = 202;
	( new LegacyOptionBackend() )->replace_all( array() );
	$settings->set_migrator_settings( array( 'galleries' => $objects, 'galleries-state' => null, 'albums' => array( '09' => $album, 9 => $second_album ), 'albums-state' => null,
		'queue' => false, 'image_tag_sync_queue' => array(), 'migrated' => array( '09' => $image ) ) );
	$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$changes = array(); $order = 0; foreach ( $data as $key => $value ) { $changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => in_array( $key, array( 'galleries', 'albums', 'migrated' ), true ) ? 'map' : 'scalar', 'value' => $value, 'expected_revision' => null ); }
	$snapshot->replace_many( $changes ); ( new LegacyOptionBackend() )->replace_all( $data );
	$option = new MigratableCollectionRepository( new LegacyOptionBackend(), $settings ); $table = new MigratableCollectionRepository( $backend, $settings );
	$prepare_checked = function ( $repository, $context ) {
		foreach ( array_keys( $context['objects'] ) as $index ) {
			$context = $repository->append_checked( 'galleries', $context, $index, $context['objects'][$index] );
		}
		return $repository->prepare_checked( 'galleries', $context );
	};
	$check( serialize( $option->read_snapshot( 'galleries' ) ) === serialize( $table->read_snapshot( 'galleries' ) ), 'typed gallery/child snapshot parity' );
	$compare = function ( $label, $bucket = 'galleries' ) use ( $backend, $check ) { $raw = get_option( FOOGALLERY_MIGRATE_OPTION_DATA ); $check( serialize( $backend->collection_snapshot( $bucket ) ) === serialize( $raw[$bucket] ), $label . ': exact compact collection parity' ); $check( $backend->read( $bucket . '-state' )['value'] === $raw[$bucket . '-state'], $label . ': companion parity' ); };
	$checked_map = $table->checked_context( 'galleries', '' );
	$check( $checked_map['found'] && array( '09', 9 ) === array( $checked_map['roots'][0]['key'], $checked_map['roots'][1]['key'] ),
		'checked map retains string and integer root keys in source order' );
	$checked_map['objects'][0]->title = 'Checked typed map';
	$prepared_map = $prepare_checked( $table, $checked_map );
	$check( $table->checkpoint_checked( 'galleries', $prepared_map ), 'typed-map checked root checkpoint succeeds' );
	$option_checked_map = $option->read_snapshot( 'galleries' ); $option_checked_map['09']->title = 'Checked typed map';
	$check( $option->replace_snapshot( 'galleries', $option_checked_map ), 'option typed-map result retained for parity' );
	$compare( 'checked typed map' );
	$gallery->migrated_id = 91; $locator = array( 'root' => '09', 'path' => array( 9 ) );
	$codec = new LegacyStateCodec(); $node = $codec->node_hash( $codec->key_hash( 'albums' ), null, 9 );
	$untouched = function () use ( $wpdb, $tables, $node ) { return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE node_hash = UNHEX(%s) ORDER BY id', $node ), ARRAY_A ); };
	$before_other = $untouched(); $check( $option->update_record( 'albums', $locator, $gallery ) && $table->update_record( 'albums', $locator, $gallery ), 'nested exact integer child update parity' ); $compare( 'nested update', 'albums' );
	$check( $before_other === $untouched(), 'unrelated typed root physical bytes untouched' );
	$before = $physical(); $noop_result = $table->update_record( 'albums', $locator, $gallery );
	$check( $noop_result && $before === $physical(), 'nested no-op preserves every physical row; changed=' . ( $before === $physical() ? 'no' : 'yes' ) );
	$check( false === $table->update_record( 'albums', array( 'root' => '09', 'path' => array( 'absent' ) ), $gallery ) && $before === $physical(), 'missing nested locator preserves physical bytes' );
	$objects = $option->read_snapshot( 'galleries' ); $objects[9]->title = 'changed second root';
	$check( $option->update_with_state( 'galleries', $objects, array( 'cursor' => 9 ) ) === $table->update_with_state( 'galleries', $objects, array( 'cursor' => 9 ) ), 'collection and checkpoint atomic update parity' ); $compare( 'checkpoint update' );
	$before = $physical(); $check( $table->update_with_state( 'galleries', $objects, array( 'cursor' => 9 ) ) && $before === $physical(), 'snapshot/checkpoint no-op preserves all physical bytes' );
	$refuses( function () use ( $db, $blog ) { ( new MigratableCollectionRepository( new LegacyTableBackend( $db, $blog, 2 ), new MigratorSettings() ) )->read_snapshot( 'galleries' ); }, 'snapshot aggregate row bound refuses' );
	$check( $before === $physical(), 'snapshot budget refusal leaves physical bytes unchanged' );
	$reordered = array( 9 => $objects[9], '09' => $objects['09'] );
	$check( $table->replace_snapshot( 'galleries', $reordered ), 'bounded reorder succeeds through staged replacement' );
	$check( array( 9, '09' ) === array_keys( $table->read_snapshot( 'galleries' ) ), 'staged reorder readback preserves typed identity and exact order' );
	$scalars = new ScalarStateRepository( $backend, $settings );
	$check( $scalars->read_many( array( 'queue', 'galleries-state', 'missing' ) ) === array( array( 'found' => true, 'key' => 'queue', 'value' => false ), array( 'found' => true, 'key' => 'galleries-state', 'value' => array( 'cursor' => 9 ) ), array( 'found' => false, 'key' => 'missing', 'value' => null ) ), 'bounded scalar read distinguishes stored false and missing' );
	$option_before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$engine = new MigratorEngine( new MigratedHistoryRepository( $backend, $settings ) ); $objects[9]->title = 'engine checkpoint';
	$check( $engine->set_migrator_setting( 'galleries', $objects ), 'injected engine existing gallery setter writes tables' );
	$check( 'engine checkpoint' === $engine->get_migrator_setting( 'galleries' )[9]->title, 'injected engine reads its own table collections' );
	$check( $option_before === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'engine never writes option authority' );
	$before = $physical();
	$refuses( function () use ( $db, $blog ) { ( new MigratableCollectionRepository( new LegacyTableBackend( $db, $blog + 1000 ), new MigratorSettings() ) )->read_snapshot( 'galleries' ); }, 'wrong-blog collection refuses' ); $check( $before === $physical(), 'wrong-blog refusal leaves bytes unchanged' );
	// The same one-second caller deadline spans metadata and real staging/publication SQL.
	foreach ( array( array( 'root_payload_bytes', true ), array( 'SET root_payload', false ), array( 'SET active_generation', false ) ) as $fault ) {
		$delayed = new CollectionDeadlineConnection( $wpdb ); $delayed->delay_match = $fault[0]; $delayed->delay_reads = $fault[1];
		$bounded = new LegacyTableRepository( $delayed, $blog, 10000, 16777216, 1 ); $before = $physical();
		$error = $refuses( function () use ( $bounded ) { $bounded->history_mutate( 'clear', array() ); }, 'shared clear deadline: ' . $fault[0] );
		$check( false !== strpos( $error->getMessage(), 'time budget' ) && null === $delayed->delay_match, 'actual delayed operation exhausts caller time budget (' . $fault[0] . '): ' . $error->getMessage() );
		$check( $before === $physical(), 'timeout rollback preserves all physical heads/records/companions' );
	}
	$logical_revision = $backend->read( 'migrated-revision' )['value'];
	$check( $backend->history_mutate( 'clear', array() ), 'normal shared-deadline clear succeeds' );
	$check( array() === $snapshot->read_bounded( 'migrated' )['items'] && $logical_revision + 1 === $backend->read( 'migrated-revision' )['value'], 'clear and logical revision companion publication explicitly read back' );
	$check( $option_before === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'table clear/timeout paths retain option authority bytes' );
	// Queue/cancel must patch only affected roots, including a missing companion.
	$many = array(); $selection = array();
	for ( $i = 0; $i < 121; ++$i ) {
		$item = new Gallery( $plugin ); $item->ID = 1000 + $i; $item->title = 'Queue root ' . $i;
		$many[$i] = $item; $selection[$item->unique_identifier()] = array( 'title' => 'Target ' . $i );
	}
	$check( $backend->replace_collection( 'galleries', $settings->compact_state_value( 'galleries', $many ) ), '121 small roots prepared' );
	$check( $backend->delete_scalar( 'galleries-state' ), 'queue companion absent for atomic-create test' );
	$root_node = $codec->node_hash( $codec->key_hash( 'galleries' ), null, 0 );
	$unrelated = function () use ( $wpdb, $tables, $root_node ) { return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE node_hash = UNHEX(%s)', $root_node ), ARRAY_A ); };
	$unrelated_before = $unrelated(); $before_failure = $physical();
	$fault = new CollectionQueueFaultConnection( $wpdb ); $fault->state_table = $tables[0]; $fault->fail_insert = true;
	$refuses( function () use ( $fault, $blog, $many, $queue ) { $queue( new LegacyTableBackend( $fault, $blog ), 'galleries',
		array( $many[120]->unique_identifier() => array( 'title' => 'Last' ) ) ); }, 'absent checkpoint insert failure rolls back affected root' );
	$check( $before_failure === $physical(), 'failed checkpoint publication restores exact physical state' );
	$stale = new CollectionQueueFaultConnection( $wpdb ); $stale->after_identity_read = function () use ( $backend ) {
		$row = $backend->roots( 'galleries', array( 2 ) )[0]['value']; $row['title'] = 'Concurrent writer';
		$backend->update_collection_record( 'galleries', array( 'root' => 2, 'path' => array() ), $row );
	};
	$refuses( function () use ( $stale, $blog, $many, $queue ) { $queue( new LegacyTableBackend( $stale, $blog ), 'galleries',
		array( $many[120]->unique_identifier() => array( 'title' => 'Last' ) ) ); }, 'head change during identity selection refuses queue' );
	$check( ! $backend->read( 'galleries-state' )['found'] &&
		! $backend->roots( 'galleries', array( 120 ) )[0]['value']['part_of_migration'], 'stale selector leaves queue and companion unpublished' );
	$check( $queue( $backend, 'galleries', array( $many[120]->unique_identifier() => array( 'title' => 'Last' ) ) ), 'one of 121 roots queued' );
	$check( $unrelated_before === $unrelated(), 'unrelated root physical payload untouched' );
	$last = $backend->roots( 'galleries', array( 120 ) )[0]['value'];
	$check( true === $last['part_of_migration'] && 'queued' === $last['migration_status'] && 'Last' === $last['migrated_title'] &&
		$backend->read( 'galleries-state' )['value'] === array( 'queued' => 1, 'completed' => 0, 'progress' => 0 ), 'created summary and exact selected root parity' );
	$last_node = $codec->node_hash( $codec->key_hash( 'galleries' ), null, 120 );
	$last_physical = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE node_hash = UNHEX(%s)', $last_node ), ARRAY_A );
	$queued_again = $queue( $backend, 'galleries', array( $many[120]->unique_identifier() => array( 'title' => 'Last' ) ) );
	$last_physical_again = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE node_hash = UNHEX(%s)', $last_node ), ARRAY_A );
	$check( $queued_again && $last_physical === $last_physical_again, 'identical selected root is not rewritten' );
	$check( $queue( $backend, 'galleries', array() ) &&
		$backend->read( 'galleries-state' )['value'] === array( 'queued' => 0, 'completed' => 0, 'progress' => 0 ), 'cancel previous selection' );
	$check( $queue( $backend, 'galleries', $selection ) &&
		$backend->read( 'galleries-state' )['value'] === array( 'queued' => 121, 'completed' => 0, 'progress' => 0 ), '121 affected roots share one atomic budget' );
	$completed_error = $backend->roots( 'galleries', array( 0 ) )[0]['value'];
	$completed_error['migrated'] = true; $completed_error['migration_status'] = 'error';
	$check( $backend->update_collection_record( 'galleries', array( 'root' => 0, 'path' => array() ), $completed_error ) &&
		$queue( $backend, 'galleries', $selection ), 'selected migrated error retains status' );
	$summary = $backend->read( 'galleries-state' )['value'];
	$check( $summary['queued'] === 121 && $summary['completed'] === 1 && abs( $summary['progress'] - 200 / 121 ) < 0.000001,
		'progress counts selected migration_status error independently of has_error' );
	$album_other = $backend->roots( 'albums', array( 9 ) )[0]['value'];
	$check( $queue( $backend, 'albums', array( $album->unique_identifier() => array( 'title' => 'Selected album' ) ) ), 'typed album queue succeeds' );
	$album_queued = $backend->roots( 'albums', array( '09' ) )[0]['value'];
	$check( true === $album_queued['part_of_migration'] && 'queued' === $album_queued['migration_status'] &&
		'Selected album' === $album_queued['migrated_title'] && $album_other === $backend->roots( 'albums', array( 9 ) )[0]['value'] &&
		$backend->read( 'albums-state' )['value'] === array( 'queued' => 1, 'completed' => 0, 'progress' => 0 ), 'typed album queue changes only selected root and state' );
	$check( $queue( $backend, 'albums', array() ), 'typed album cancel succeeds' );
	$album_cancelled = $backend->roots( 'albums', array( '09' ) )[0]['value'];
	$check( false === $album_cancelled['part_of_migration'] && 'not_started' === $album_cancelled['migration_status'] &&
		$backend->read( 'albums-state' )['value'] === array( 'queued' => 0, 'completed' => 0, 'progress' => 0 ), 'typed album cancel restores status and summary' );
	// A migration turn reads one runnable tree and carries its original budget
	// across external output. Failed companion creation must roll the tree back.
	$check( $backend->delete_scalar( 'galleries-state' ), 'turn companion absent for atomic-create test' );
	$turn_fault = new CollectionQueueFaultConnection( $wpdb ); $turn_fault->state_table = $tables[0]; $turn_fault->fail_insert = true;
	$turn_backend = new LegacyTableBackend( $turn_fault, $blog );
	$turn = $turn_backend->migration_turn_context( 'galleries' );
	$check( $turn['found'] && 1 === $turn['root']['key'] && 'queued' === $turn['root']['value']['migration_status'] &&
		$turn['counts'] === array( 'queued' => 121, 'completed' => 1, 'errors' => 1 ), 'first queued root follows preceding completed error in persisted order' );
	$turn_value = $turn['root']['value']; $turn_value['migration_status'] = 'completed'; $turn_value['migrated'] = true;
	$check( $backend->mutate_scalars( array( 'galleries-state' => MigratorBase::queue_summary( 121, 1, 1 ) ) ), 'interleaved companion creation' );
	$before_absent_race = $physical();
	$refuses( function () use ( $turn_backend, $turn, $turn_value ) {
		$turn_backend->checkpoint_migration_turn( 'galleries', $turn, $turn_value, MigratorBase::queue_summary( 121, 2, 1 ) );
	}, 'absent companion becoming present refuses turn checkpoint' );
	$check( $before_absent_race === $physical(), 'absent-to-present race leaves root and companion untouched' );
	$check( $backend->delete_scalar( 'galleries-state' ), 'turn companion absent again for rollback test' );
	$turn = $turn_backend->migration_turn_context( 'galleries' );
	$turn_summary = MigratorBase::queue_summary( 121, 2, 1 ); $before_turn_failure = $physical();
	$refuses( function () use ( $turn_backend, $turn, $turn_value, $turn_summary ) {
		$turn_backend->checkpoint_migration_turn( 'galleries', $turn, $turn_value, $turn_summary );
	}, 'turn companion insert failure rolls back affected root' );
	$check( $before_turn_failure === $physical() && ! $backend->read( 'galleries-state' )['found'], 'failed turn checkpoint leaves no root or companion change' );
	$fresh_turn = $backend->migration_turn_context( 'galleries' );
	$check( 1 === $fresh_turn['root']['key'] && 'queued' === $fresh_turn['root']['value']['migration_status'], 'fresh context sees original runnable root after rollback' );
	$fresh_value = $fresh_turn['root']['value']; $fresh_value['migration_status'] = 'completed'; $fresh_value['migrated'] = true;
	$check( $backend->checkpoint_migration_turn( 'galleries', $fresh_turn, $fresh_value, $turn_summary ), 'fresh turn checkpoint succeeds' );
	$check( 'completed' === $backend->roots( 'galleries', array( 1 ) )[0]['value']['migration_status'] &&
		$backend->read( 'galleries-state' )['value'] === $turn_summary, 'root and exact selected/status-error summary publish together' );
	$present_turn = $backend->migration_turn_context( 'galleries' );
	$check( $backend->mutate_scalars( array( 'galleries-state' => MigratorBase::queue_summary( 121, 2, 0 ) ) ), 'interleaved companion revision change' );
	$before_present_race = $physical();
	$refuses( function () use ( $backend, $present_turn, $turn_summary ) {
		$backend->checkpoint_migration_turn( 'galleries', $present_turn,
			null === $present_turn['root'] ? null : $present_turn['root']['value'], $turn_summary );
	}, 'existing companion revision change refuses turn checkpoint' );
	$check( $before_present_race === $physical(), 'existing companion race leaves root and state untouched' );
	$stale_turn = $backend->migration_turn_context( 'galleries' );
	$changed = $backend->roots( 'galleries', array( 2 ) )[0]['value']; $changed['title'] = 'Interleaved checkpoint writer';
	$check( $backend->update_collection_record( 'galleries', array( 'root' => 2, 'path' => array() ), $changed ), 'interleaved root write changes head' );
	$before_stale_turn = $physical();
	$refuses( function () use ( $backend, $stale_turn, $turn_summary ) {
		$backend->checkpoint_migration_turn( 'galleries', $stale_turn, null === $stale_turn['root'] ? null : $stale_turn['root']['value'], $turn_summary );
	}, 'turn checkpoint refuses stale head after external work' );
	$check( $before_stale_turn === $physical(), 'stale turn checkpoint leaves both collection and companion untouched' );
	// Check-all reads only affected bounded trees and publishes one collection CAS
	// after the caller's separate history batch. UID collisions are not split.
	$checked = $table->checked_context( 'galleries', '' );
	$check( $checked['found'] && 121 === count( $checked['objects'] ) && 0 === $checked['roots'][0]['key'] &&
		120 === $checked['roots'][120]['key'], '121 checked roots retain persisted order and typed keys' );
	$before_unrelated = $unrelated(); $checked['objects'][120]->migration_status = 'error';
	$prepared = $prepare_checked( $table, $checked );
	$check( $prepared['budget']['seconds'] > $checked['budget']['seconds'] &&
		1 === count( $prepared['patches'] ) && 120 === $prepared['patches'][0]['key'] &&
		$table->checkpoint_checked( 'galleries', $prepared ), '121-root check prepares one affected patch and one collection CAS' );
	$check( $before_unrelated === $unrelated() && 'error' === $backend->roots( 'galleries', array( 120 ) )[0]['value']['migration_status'],
		'check-all leaves unrelated physical root bytes untouched' );
	$large = $backend->roots( 'galleries', array( 0 ) )[0]['value']; $large['children'] = array();
	for ( $i = 0; $i < 204; ++$i ) {
		$child = new Image( $plugin ); $child->source_url = 'https://example.test/checked-large-' . $i . '.jpg';
		$large['children'][$i] = $settings->compact_migrated_object( $child );
	}
	$check( $backend->update_collection_record( 'galleries', array( 'root' => 0, 'path' => array() ), $large ), '204-image root prepared in owned SQL collection' );
	$checked = $table->checked_context( 'galleries', $many[0]->unique_identifier() );
	$check( 1 === count( $checked['objects'] ) && 204 === count( $checked['objects'][0]->children ),
		'explicit UID check hydrates one 204-image affected root without unrelated roots' );
	$checked['objects'][0]->migration_status = 'error';
	$prepared = $prepare_checked( $table, $checked );
	$check( $table->checkpoint_checked( 'galleries', $prepared ) && 'error' === $backend->roots( 'galleries', array( 0 ) )[0]['value']['migration_status'],
		'204-image checked root publishes one affected collection patch' );
	$duplicate = $backend->roots( 'galleries', array( 120 ) )[0]['value']; $duplicate['ID'] = $many[0]->ID;
	$check( $backend->update_collection_record( 'galleries', array( 'root' => 120, 'path' => array() ), $duplicate ), 'distinct typed root with colliding reconstructed UID prepared' );
	$matches = $table->checked_context( 'galleries', $many[0]->unique_identifier() );
	$first = $table->targeted_context( 'galleries', $many[0]->unique_identifier() );
	$check( 2 === count( $matches['objects'] ) && 0 === $matches['roots'][0]['key'] && 120 === $matches['roots'][1]['key'] &&
		$first['found'] && 0 === $first['root']['key'], 'check visits both duplicate UIDs in order; retry selects first' );
	$stale_check = $prepare_checked( $table, $matches );
	$interleaved = $backend->roots( 'galleries', array( 1 ) )[0]['value']; $interleaved['title'] = 'Interleaved after checked read';
	$check( $backend->update_collection_record( 'galleries', array( 'root' => 1, 'path' => array() ), $interleaved ), 'checked head changed between preparation and checkpoint' );
	$after_interleaved = $physical();
	$refuses( function () use ( $table, $stale_check ) { $table->checkpoint_checked( 'galleries', $stale_check ); }, 'checked collection refuses stale head after separate work' );
	$check( $after_interleaved === $physical(), 'stale checked checkpoint leaves every head and record unchanged' );
	$limited = new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), $blog, 121 );
	$limited_table = new MigratableCollectionRepository( $limited, $settings ); $before_limited = $physical();
	$refuses( function () use ( $limited_table ) { $limited_table->checked_context( 'galleries', '' ); },
		'checked roots refuse a reduced shared affected-row budget before history publication' );
	$check( $before_limited === $physical(), 'oversized checked read leaves history and collection unchanged' );
	$fault_connection = new CollectionQueueFaultConnection( $wpdb );
	$fault_connection->state_table = $tables[0]; $fault_connection->records_table = $tables[1];
	$fault_backend = new LegacyTableBackend( $fault_connection, $blog );
	$fault_table = new MigratableCollectionRepository( $fault_backend, $settings );
	$fault_context = $fault_table->checked_context( 'galleries', $many[0]->unique_identifier() );
	$fault_context['objects'][0]->title = 'Late collection fault';
	$fault_prepared = $prepare_checked( $fault_table, $fault_context );
	$fault_connection->fail_state_update_id = $fault_prepared['head']['id']; $before_fault = $physical();
	$refuses( function () use ( $fault_table, $fault_prepared ) { $fault_table->checkpoint_checked( 'galleries', $fault_prepared ); },
		'late checked collection head failure rolls back affected roots' );
	$check( null === $fault_connection->fail_state_update_id && $fault_connection->record_inserts_at_fault > 0 &&
		$before_fault === $physical(), 'late collection fault follows record inserts yet preserves every physical head and row' );
	$retained = $table->checked_context( 'galleries', $many[0]->unique_identifier() );
	$retained_gallery = $retained['objects'][0];
	$checked_child = new Image( $plugin ); $checked_child->source_url = 'https://example.test/checked-error.jpg';
	$checked_child->error = new WP_Error( 'checked', 'Checked child error.' ); $checked_child->migration_status = 'error';
	$unmigrated_child = new Image( $plugin ); $unmigrated_child->source_url = 'https://example.test/checked-unmigrated.jpg';
	$retained_gallery->children = array( $checked_child, $unmigrated_child ); $retained_gallery->children_count = 2;
	$before_release = $settings->compact_migrated_object( $retained_gallery ); $before_child_count = $retained_gallery->children_count;
	$retained = $table->append_checked( 'galleries', $retained, 0, $retained_gallery );
	$check( array() === $retained_gallery->children && $retained_gallery->children_count === $before_child_count &&
		$before_release === $settings->compact_migrated_object( $retained_gallery ),
		'checked gallery releases unmigrated child cache without changing pending compact history' );
	$refreshed = new Gallery( $plugin ); $refreshed->ID = 777; $refreshed->title = 'Fresh forced discovery';
	$forced = new MigratableCollectionRepository( new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), $blog, 20 ), $settings );
	$forced_context = $forced->view_context( 'galleries' );
	$check( $forced->replace_discovered( 'galleries', array( $refreshed ), $forced_context ) &&
		1 === $backend->count_roots( 'galleries' ) && 777 === $backend->roots( 'galleries', array( 0 ) )[0]['value']['ID'],
		'forced discovery replaces 121 discarded roots without reading their payloads under 20-row budget' );
	$stale_discovery = $forced->view_context( 'galleries' );
	$interleaved_root = $backend->roots( 'galleries', array( 0 ) )[0]['value']; $interleaved_root['title'] = 'Changed during discovery';
	$check( $backend->update_collection_record( 'galleries', array( 'root' => 0, 'path' => array() ), $interleaved_root ), 'interleaved discovery head change' );
	$before_stale_discovery = $physical();
	$refuses( function () use ( $forced, $refreshed, $stale_discovery ) { $forced->replace_discovered( 'galleries', array( $refreshed ), $stale_discovery ); },
		'forced discovery refuses changed collection head' );
	$check( $before_stale_discovery === $physical(), 'changed-head discovery refusal leaves every row unchanged' );
	$other_blog = new MigratableCollectionRepository( new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), $blog + 1000, 20 ), $settings );
	$absent_discovery = $other_blog->view_context( 'galleries' );
	$check( false === $absent_discovery['found'] && $other_blog->replace_discovered( 'galleries', array( $refreshed ), $absent_discovery ),
		'owned synthetic blog starts with absent collection and creates one discovered root' );
	$before_present_discovery = $physical();
	$refuses( function () use ( $other_blog, $refreshed, $absent_discovery ) { $other_blog->replace_discovered( 'galleries', array( $refreshed ), $absent_discovery ); },
		'forced discovery refuses absent-to-present head race' );
	$check( $before_present_discovery === $physical(), 'absent-to-present discovery refusal leaves every row unchanged' );
	echo wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	foreach ( array_reverse( $created ) as $table_name ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table_name . '`' ); }
	if ( $saved === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $saved ); }
}
