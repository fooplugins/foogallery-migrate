<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;
use FooPlugins\FooGalleryMigrate\Migrators\MigratorBase;
use PHPUnit\Framework\TestCase;

/** Explicit table targets leave decisions in the engine and never request a collection snapshot. */
class TargetedGalleryOperationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_attached_files'] = array();
		$GLOBALS['foogallery_migrate_test_attachment_urls'] = array();
	}
	private function fixture( $with_child = true ) {
		$events = array(); $plugin = new TargetOperationPlugin();
		$gallery = new Gallery( $plugin ); $gallery->ID = '7_9'; $gallery->title = 'Selected';
		$gallery->migrated = true; $gallery->part_of_migration = true;
		$gallery->migration_status = Migratable::PROGRESS_COMPLETED; $gallery->migrated_id = 82;
		if ( $with_child ) {
			$child = new Image( $plugin ); $child->source_url = 'https://example.test/target.jpg';
			$child->migrated = true; $child->migrated_id = 41; $child->migration_status = Migratable::PROGRESS_COMPLETED;
			$gallery->children = array( $child ); $gallery->children_count = 1;
		}
		$target = new TargetOperationCollection( $events, $gallery );
		$store = new TargetOperationStore( $events );
		$migrator = new TargetOperationMigrator( $events );
		$migrator->target = $target;
		$engine = new TargetOperationEngine( $store, $target, $migrator );
		return array( $engine, $target, $store, $migrator, &$events, $gallery );
	}
	public function test_retry_resets_error_child_then_checkpoints_before_queue_and_immediate_turn(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture();
		$gallery->children[0]->migration_status = Migratable::PROGRESS_ERROR;
		$gallery->children[0]->error = new \WP_Error( 'forced', 'Forced.' );
		$this->assertTrue( $engine->retry_gallery_migration( $gallery->unique_identifier() ) );
		$this->assertSame( array( 'select', 'history', 'checkpoint', 'queue', 'migrate' ), $target->events );
		$this->assertSame( Migratable::PROGRESS_NOT_STARTED, $gallery->children[0]->migration_status );
		$this->assertFalse( $gallery->children[0]->error );
		$this->assertFalse( $gallery->migrated );
		$this->assertSame( 0, $gallery->migrated_child_count );
		$this->assertSame( $gallery->unique_identifier(), key( $migrator->selection ) );
		$this->assertSame( 2, count( $store->records ) );
		$this->assertSame( 0, $target->whole_reads );
	}
	public function test_error_check_flushes_history_before_one_root_save_and_preserves_counters(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture();
		$result = $engine->check_for_migration_errors( $gallery->unique_identifier() );
		$this->assertSame( array( 'checked' => 1, 'errors' => 1 ), $result );
		$this->assertSame( array( 'select', 'history', 'checkpoint' ), $target->events );
		$this->assertSame( Migratable::PROGRESS_ERROR, $gallery->migration_status );
		$this->assertSame( Migratable::PROGRESS_ERROR, $gallery->children[0]->migration_status );
		$this->assertSame( 2, count( $store->records ) );
		$this->assertSame( 0, $target->whole_reads );
	}
	public function test_targeted_check_and_retry_submit_204_images_plus_parent_as_one_logical_history_batch(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture( false );
		$gallery->children = array(); $gallery->children_count = 204;
		for ( $i = 0; $i < 204; ++$i ) {
			$child = new Image( new TargetOperationPlugin() );
			$child->source_url = 'https://example.test/target-' . $i . '.jpg';
			$child->migrated = true; $child->migrated_id = 1000 + $i;
			$child->migration_status = Migratable::PROGRESS_COMPLETED;
			$gallery->children[] = $child;
		}
		$this->assertSame( array( 'checked' => 204, 'errors' => 204 ),
			$engine->check_gallery_migration_errors( $gallery->unique_identifier() ) );
		$this->assertCount( 205, $store->records );
		$this->assertSame( array( 'select', 'history', 'checkpoint' ), $target->events );
		$this->assertTrue( $engine->retry_gallery_migration( $gallery->unique_identifier() ) );
		$this->assertCount( 205, $store->records );
		$this->assertSame( 'not_started', $store->records[0]['object']->migration_status );
		$this->assertSame( 'not_started', $store->records[204]['object']->migration_status );
		$this->assertSame( 0, $target->whole_reads );
	}
	public function test_missing_target_and_failed_history_never_checkpoint_or_queue(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture();
		$target->found = false;
		$result = $engine->retry_gallery_migration( 'gallery_Target_999' );
		$this->assertSame( 'foogallery_migrate_missing_gallery', $result->get_error_code() );
		$this->assertSame( array( 'select' ), $target->events );
		$this->assertSame( 0, $migrator->discovery_calls );
		$target->found = true; $store->fail = true; $target->events = array();
		$result = $engine->retry_gallery_migration( $gallery->unique_identifier() );
		$this->assertSame( 'foogallery_migrate_store_write_failed', $result->get_error_code() );
		$this->assertSame( array( 'select', 'history' ), $target->events );
		$target->events = array();
		$result = $engine->check_for_migration_errors( $gallery->unique_identifier() );
		$this->assertSame( 'foogallery_migrate_store_write_failed', $result->get_error_code() );
		$this->assertSame( array( 'select', 'history' ), $target->events );
	}
	public function test_only_absent_collection_triggers_legacy_discovery_then_reselects(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture( false );
		$target->found = false; $target->absent = true; $migrator->discover = true;
		$this->assertTrue( $engine->retry_gallery_migration( $gallery->unique_identifier() ) );
		$this->assertSame( array( 'select', 'discover', 'select', 'history', 'checkpoint', 'queue', 'migrate' ), $target->events );
		$this->assertSame( 1, $migrator->discovery_calls );
	}
	public function test_failed_target_checkpoint_does_not_run_retry_queue_or_immediate_turn(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture( false );
		$target->fail = true;
		$result = $engine->retry_gallery_migration( $gallery->unique_identifier() );
		$this->assertSame( 'foogallery_migrate_store_write_failed', $result->get_error_code() );
		$this->assertSame( array( 'select', 'history', 'checkpoint' ), $target->events );
	}
	public function test_preexisting_child_error_is_skipped_and_failed_error_checkpoint_is_reported(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture();
		$gallery->children[0]->migration_status = Migratable::PROGRESS_ERROR;
		$target->fail = true;
		$result = $engine->check_for_migration_errors( $gallery->unique_identifier() );
		$this->assertSame( 'foogallery_migrate_store_write_failed', $result->get_error_code() );
		$this->assertSame( array( 'select', 'history', 'checkpoint' ), $target->events );
		$this->assertSame( 1, count( $store->records ) );
		$this->assertSame( Migratable::PROGRESS_ERROR, $gallery->children[0]->migration_status );
	}
	public function test_table_check_all_visits_121_roots_and_explicit_uid_checks_both_duplicate_matches(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture();
		$target->objects = array();
		for ( $i = 0; $i < 121; ++$i ) {
			$copy = clone $gallery; $copy->ID = 1000 + $i;
			$copy->children = array( clone $gallery->children[0] );
			$copy->children[0]->source_url = 'https://example.test/checked-' . $i . '.jpg';
			$target->objects[] = $copy;
		}
		$this->assertSame( array( 'checked' => 121, 'errors' => 121 ), $engine->check_for_migration_errors() );
		$this->assertCount( 242, $store->records );
		$this->assertSame( array( 'select', 'history', 'checkpoint' ), $target->events );
		$this->assertSame( 0, $target->whole_reads );
		$target->events = array(); $target->objects = array( $gallery, clone $gallery );
		$target->objects[1]->children = array( clone $gallery->children[0] );
		$target->objects[1]->children[0]->source_url = 'https://example.test/duplicate-second.jpg';
		$this->assertSame( array( 'checked' => 2, 'errors' => 2 ), $engine->check_for_migration_errors( $gallery->unique_identifier() ) );
		$this->assertSame( array( 'select', 'history', 'checkpoint' ), $target->events );
		$this->assertSame( Migratable::PROGRESS_ERROR, $target->objects[0]->migration_status );
		$this->assertSame( Migratable::PROGRESS_ERROR, $target->objects[1]->migration_status );
	}
	public function test_forced_refresh_does_not_read_discarded_table_collection(): void {
		$engine = new RefreshProbeEngine();
		$engine->target = new RefreshProbeCollection();
		$this->assertSame( array(), ( new RefreshProbeMigrator( $engine, 'galleries' ) )->get_objects_to_migrate( true ) );
		$this->assertSame( 0, $engine->reads );
		$this->assertSame( 0, $engine->writes );
		$this->assertSame( array( array( 'galleries', array(), array( 'found' => true, 'total' => 121, 'head' => array( 'revision' => 1 ) ) ) ), $engine->target->replacements );
	}
	public function test_forced_discovery_refuses_changed_or_new_head_before_replacement(): void {
		foreach ( array( array( 'revision' => 1 ), false ) as $initial ) {
			$engine = new RefreshProbeEngine(); $engine->target = new RefreshProbeCollection(); $engine->target->head = $initial;
			$engine->plugins[] = new RefreshProbePlugin( function () use ( $engine ) { $engine->target->head = array( 'revision' => 2 ); } );
			try { ( new RefreshProbeMigrator( $engine, 'galleries' ) )->get_objects_to_migrate( true ); $this->fail( 'Interleaved discovery must refuse.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( 'Discovered collection changed during source discovery.', $error->getMessage() ); }
			$this->assertSame( array(), $engine->target->replacements );
			$this->assertSame( 0, $engine->reads + $engine->writes );
		}
	}
	public function test_lazy_absent_discovery_pins_absence_before_source_callbacks(): void {
		$engine = new RefreshProbeEngine(); $engine->allow_absent = true;
		$engine->target = new RefreshProbeCollection(); $engine->target->head = false;
		$this->assertSame( array(), ( new RefreshProbeMigrator( $engine, 'galleries' ) )->get_objects_to_migrate() );
		$this->assertSame( 1, $engine->reads );
		$this->assertSame( array( array( 'galleries', array(), array( 'found' => false, 'total' => 0, 'head' => false ) ) ), $engine->target->replacements );
		$engine = new RefreshProbeEngine(); $engine->allow_absent = true;
		$engine->target = new RefreshProbeCollection(); $engine->target->head = false;
		$engine->plugins[] = new RefreshProbePlugin( function () use ( $engine ) { $engine->target->head = array( 'revision' => 1 ); } );
		try { ( new RefreshProbeMigrator( $engine, 'galleries' ) )->get_objects_to_migrate(); $this->fail( 'Absent-to-present lazy discovery must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Discovered collection changed during source discovery.', $error->getMessage() ); }
		$this->assertSame( array(), $engine->target->replacements );
	}
	public function test_checked_collection_admission_refusal_precedes_history_publication(): void {
		list( $engine, $target, $store, $migrator, $events, $gallery ) = $this->fixture();
		$target->fail_prepare = true;
		try { $engine->check_for_migration_errors( $gallery->unique_identifier() ); $this->fail( 'Affected storage should refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Affected checked roots are not admitted.', $error->getMessage() ); }
		$this->assertSame( array( 'select' ), $target->events );
		$this->assertSame( array(), $store->records );
		$this->assertSame( 0, $target->whole_reads );
	}
	public function test_table_pending_history_duplicate_replaces_its_encoded_byte_charge(): void {
		$backend = new PendingBudgetBackend(); $settings = new MigratorSettings( $backend );
		$engine = new MigratorEngine( new MigratedHistoryRepository( $backend, $settings ) );
		$image = new Image( new TargetOperationPlugin() ); $image->source_url = 'https://example.test/duplicate.jpg';
		$image->title = str_repeat( 'a', 1000 ); $engine->begin_migrated_object_batch();
		$this->assertTrue( $engine->add_migrated_object( $image ) );
		$property = new \ReflectionProperty( MigratorEngine::class, 'pending_migrated_request_bytes' );
		$first = $property->getValue( $engine );
		$image->title = 'short'; $this->assertTrue( $engine->add_migrated_object( $image ) );
		$entry = array( 'key' => $image->unique_identifier(), 'value' => $settings->compact_migrated_object( $image ),
			'parent' => null, 'fallback_parent' => null );
		$this->assertLessThan( $first, $property->getValue( $engine ) );
		$this->assertSame( strlen( ( new LegacyStateCodec() )->encode( $entry ) ), $property->getValue( $engine ) );
	}
	public function test_oversized_table_pending_history_refuses_before_retaining_or_upserting(): void {
		$backend = new PendingBudgetBackend(); $engine = new MigratorEngine( new MigratedHistoryRepository( $backend, new MigratorSettings( $backend ) ) );
		$first = new Image( new TargetOperationPlugin() ); $first->source_url = 'https://example.test/first.jpg';
		$engine->begin_migrated_object_batch(); $this->assertTrue( $engine->add_migrated_object( $first ) );
		$bytes = new \ReflectionProperty( MigratorEngine::class, 'pending_migrated_request_bytes' );
		$records = new \ReflectionProperty( MigratorEngine::class, 'pending_migrated_objects' );
		$title = str_repeat( 'x', 100000 );
		$item = new Image( new TargetOperationPlugin() ); $item->source_url = 'https://example.test/pending-0000.jpg'; $item->title = $title;
		$initial = $bytes->getValue( $engine ); $this->assertTrue( $engine->add_migrated_object( $item ) );
		$each = $bytes->getValue( $engine ) - $initial;
		$slots = (int) floor( ( 16777216 - $bytes->getValue( $engine ) ) / $each );
		for ( $i = 1; $i <= $slots; ++$i ) {
			$item = new Image( new TargetOperationPlugin() ); $item->source_url = 'https://example.test/pending-' . sprintf( '%04d', $i ) . '.jpg'; $item->title = $title;
			$this->assertTrue( $engine->add_migrated_object( $item ) );
		}
		$before = $bytes->getValue( $engine ); $before_keys = array_keys( $records->getValue( $engine ) );
		$oversized = new Image( new TargetOperationPlugin() ); $oversized->source_url = 'https://example.test/pending-' . sprintf( '%04d', $slots + 1 ) . '.jpg';
		$oversized->title = $title;
		try { $engine->add_migrated_object( $oversized ); $this->fail( 'Oversized pending history must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Pending table history exceeds the bounded operation size.', $error->getMessage() ); }
		$this->assertSame( $before, $bytes->getValue( $engine ) );
		$this->assertSame( $before_keys, array_keys( $records->getValue( $engine ) ) );
		$this->assertSame( 0, $backend->upserts );
	}
}

class TargetOperationPlugin extends Plugin {
	public function name() { return 'Target'; }
	public function detect() { return true; }
	public function find_galleries() { return array(); }
	public function find_albums() { return array(); }
	public function get_gallery_template( $gallery ) { return 'default'; }
	public function get_gallery_settings( $gallery, $default_settings ) { return $default_settings; }
}
class TargetOperationStore extends MigratedStore {
	public $events;
	public $records = array();
	public $fail = false;
	public function __construct( &$events ) { $this->events =& $events; }
	public function manages_revision() { return true; }
	public function get_by_parent( $parent_key ) { return array(); }
	public function upsert_batch( $records ) {
		$this->events[] = 'history';
		if ( $this->fail ) { return false; }
		$this->records = $records;
		return true;
	}
}
class TargetOperationCollection {
	public $events;
	public $object;
	public $objects;
	public $found = true;
	public $absent = false;
	public $fail = false;
	public $fail_prepare = false;
	public $whole_reads = 0;
	public function __construct( &$events, $object ) { $this->events =& $events; $this->object = $object; $this->objects = array( $object ); }
	public function targeted_context( $bucket, $uid ) {
		$this->events[] = 'select';
		return $this->found ? array( 'found' => true, 'absent' => false, 'object' => $this->object ) : array( 'found' => false, 'absent' => $this->absent );
	}
	public function checked_context( $bucket, $uid ) {
		$this->events[] = 'select';
		return $this->found ? array( 'found' => true, 'absent' => false, 'objects' => $this->objects ) : array( 'found' => false, 'absent' => $this->absent );
	}
	public function append_checked( $bucket, $context, $index, $object ) { unset( $context['objects'][$index] ); return $context; }
	public function prepare_checked( $bucket, $context ) {
		if ( $this->fail_prepare ) { throw new \RuntimeException( 'Affected checked roots are not admitted.' ); }
		return $context;
	}
	public function checkpoint_checked( $bucket, $context ) { $this->events[] = 'checkpoint'; return ! $this->fail; }
	public function checkpoint_targeted( $bucket, $context ) { $this->events[] = 'checkpoint'; return ! $this->fail; }
	public function read_snapshot() { ++$this->whole_reads; throw new \RuntimeException( 'Whole collection read.' ); }
}
class TargetOperationMigrator {
	public $events;
	public $selection;
	public $target;
	public $discover = false;
	public $discovery_calls = 0;
	public function __construct( &$events ) { $this->events =& $events; }
	public function get_objects_to_migrate() {
		++$this->discovery_calls;
		if ( ! $this->discover || ! $this->target->absent ) { throw new \RuntimeException( 'Whole gallery read.' ); }
		$this->events[] = 'discover'; $this->target->absent = false; $this->target->found = true;
		return array( $this->target->object );
	}
	public function queue_objects_for_migration( $selection ) { $this->events[] = 'queue'; $this->selection = $selection; return true; }
	public function migrate() { $this->events[] = 'migrate'; return true; }
}
class TargetOperationEngine extends MigratorEngine {
	private $target;
	private $migrator;
	public function __construct( $store, $target, $migrator ) { parent::__construct( $store ); $this->target = $target; $this->migrator = $migrator; }
	public function table_collection_repository() { return $this->target; }
	public function get_gallery_migrator() { return $this->migrator; }
}
class RefreshProbeMigrator extends MigratorBase {}
class RefreshProbeEngine {
	public $reads = 0;
	public $writes = 0;
	public $target;
	public $plugins = array();
	public $allow_absent = false;
	public function assert_storage_writer() { return true; }
	public function table_collection_repository() { return $this->target; }
	public function get_migrator_setting() { ++$this->reads; if ( $this->allow_absent ) { return false; } throw new \RuntimeException( 'Discarded old collection was read.' ); }
	public function get_plugins() { return $this->plugins; }
	public function set_migrator_setting( $key, $value ) { ++$this->writes; return true; }
}
class RefreshProbeCollection {
	public $replacements = array();
	public $head = array( 'revision' => 1 );
	public function view_context( $key ) { return array( 'found' => false !== $this->head, 'total' => false === $this->head ? 0 : 121, 'head' => $this->head ); }
	public function replace_discovered( $key, $value, $context ) {
		if ( $this->head !== $context['head'] ) { throw new \RuntimeException( 'Discovered collection changed during source discovery.' ); }
		$this->replacements[] = array( $key, $value, $context ); return true;
	}
}
class RefreshProbePlugin {
	public $is_detected = true;
	private $callback;
	public function __construct( $callback ) { $this->callback = $callback; }
	public function find_objects( $type ) { call_user_func( $this->callback ); return array(); }
}
class PendingBudgetBackend extends LegacyTableBackend {
	public $upserts = 0;
	public function __construct() {}
	public function history_mutate( $operation, $request, $companions = array() ) { ++$this->upserts; return count( $request ); }
}
