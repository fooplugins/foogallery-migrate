<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;
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
	public $found = true;
	public $absent = false;
	public $fail = false;
	public $whole_reads = 0;
	public function __construct( &$events, $object ) { $this->events =& $events; $this->object = $object; }
	public function targeted_context( $bucket, $uid ) {
		$this->events[] = 'select';
		return $this->found ? array( 'found' => true, 'absent' => false, 'object' => $this->object ) : array( 'found' => false, 'absent' => $this->absent );
	}
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
