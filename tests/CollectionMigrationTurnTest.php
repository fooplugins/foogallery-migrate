<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Migrators\MigratorBase;
use PHPUnit\Framework\TestCase;

/** The table turn never asks for a whole collection and preserves the legacy batch order. */
class CollectionMigrationTurnTest extends TestCase {
	public function test_affected_turn_flushes_history_before_one_root_and_summary_checkpoint(): void {
		$events = array(); $object = new TurnObject( $events ); $object->part_of_migration = true;
		$repository = new TurnCollection( $events, $object, array( 'queued' => 121, 'completed' => 0, 'errors' => 1 ) );
		$engine = new TurnEngine( $events, $repository );
		$this->assertTrue( ( new TurnMigrator( $engine, 'galleries' ) )->migrate() );
		$this->assertSame( array( 'select', 'begin', 'migrate', 'flush', 'checkpoint' ), $events );
		$this->assertSame( array( 'queued' => 121, 'completed' => 1, 'progress' => 200 / 121 ), $repository->summary );
		$this->assertSame( 0, $repository->whole_reads );
	}
	public function test_no_runnable_root_still_flushes_and_saves_zero_summary(): void {
		$events = array(); $repository = new TurnCollection( $events, null, array( 'queued' => 0, 'completed' => 0, 'errors' => 0 ) );
		$this->assertTrue( ( new TurnMigrator( new TurnEngine( $events, $repository ), 'albums' ) )->migrate() );
		$this->assertSame( array( 'select', 'begin', 'flush', 'checkpoint' ), $events );
		$this->assertSame( array( 'queued' => 0, 'completed' => 0, 'progress' => 0 ), $repository->summary );
	}
	public function test_failed_history_flush_never_publishes_collection_checkpoint(): void {
		$events = array(); $object = new TurnObject( $events ); $object->part_of_migration = true;
		$repository = new TurnCollection( $events, $object, array( 'queued' => 1, 'completed' => 0, 'errors' => 0 ) );
		$engine = new TurnEngine( $events, $repository ); $engine->flush_result = false;
		$this->assertFalse( ( new TurnMigrator( $engine, 'galleries' ) )->migrate() );
		$this->assertSame( array( 'select', 'begin', 'migrate', 'flush' ), $events );
		$this->assertNull( $repository->summary );
	}
	public function test_compact_checkpoint_retains_opaque_root_and_child_fields_for_typed_gallery_and_album_keys(): void {
		foreach ( array( 'galleries' => 'gallery', 'albums' => 'album' ) as $bucket => $type ) {
			$raw = array( 'object_type' => $type, 'plugin_name' => 'Fixture', 'ID' => '7', 'migration_status' => 'started',
				'part_of_migration' => true, 'migrated' => false, 'opaque' => 'keep',
				'settings' => array( 'old' => 'cleared' ), 'error' => array( 'code' => 'old', 'message' => 'Cleared' ),
				'children' => array( '09' => array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => 9,
					'migration_status' => 'started', 'opaque_child' => 'retain' ),
					'gone' => array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => 10 ) ) );
			$backend = new TurnCompactBackend( array( 'key' => '09', 'value' => $raw ) );
			$repository = new MigratableCollectionRepository( $backend, new TurnCompactSettings() );
			$context = $repository->migration_turn_context( $bucket );
			$this->assertSame( '09', $context['root']['key'] );
			$context['object']->migration_status = 'completed'; $context['object']->migrated = true;
			$context['object']->settings = array(); $context['object']->error = false;
			if ( 'albums' === $bucket ) {
				$context['object']->children['09']->migration_status = 'completed';
				unset( $context['object']->children['gone'] );
			}
			$this->assertTrue( $repository->checkpoint_migration_turn( $bucket, $context,
				array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ) ) );
			$this->assertSame( 'keep', $backend->value['opaque'] );
			$this->assertArrayNotHasKey( 'settings', $backend->value );
			$this->assertArrayNotHasKey( 'error', $backend->value );
			if ( 'albums' === $bucket ) {
				$this->assertSame( 'retain', $backend->value['children']['09']['opaque_child'] );
				$this->assertArrayNotHasKey( 'gone', $backend->value['children'] );
			}
			else { $this->assertArrayNotHasKey( 'children', $backend->value ); }
			$this->assertSame( 'completed', $backend->value['migration_status'] );
			$this->assertSame( '09', $backend->key );
		}
	}
	public function test_targeted_gallery_checkpoint_keeps_exact_root_key_and_opaque_fields(): void {
		$raw = array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => '7',
			'migration_status' => 'error', 'migrated' => true, 'migrated_id' => 25,
			'part_of_migration' => true, 'opaque' => array( 'owner' => 'keep' ) );
		$backend = new TurnCompactBackend( array( 'key' => '09', 'value' => $raw ) );
		$repository = new MigratableCollectionRepository( $backend, new TurnCompactSettings() );
		$context = $repository->targeted_context( 'galleries', 'gallery_Fixture_7' );
		$this->assertSame( '09', $context['root']['key'] );
		$context['object']->migration_status = 'not_started'; $context['object']->migrated = false;
		$this->assertTrue( $repository->checkpoint_targeted( 'galleries', $context ) );
		$this->assertSame( '09', $backend->key );
		$this->assertSame( array( 'owner' => 'keep' ), $backend->value['opaque'] );
		$this->assertSame( 'not_started', $backend->value['migration_status'] );
		$this->assertFalse( $backend->value['migrated'] );
	}
}

class TurnMigrator extends MigratorBase {}
class TurnObject {
	public $part_of_migration = false;
	public $migrated = false;
	public $migration_status = 'queued';
	private $events;
	public function __construct( &$events ) { $this->events =& $events; }
	public function migrate() { $this->events[] = 'migrate'; $this->migrated = true; $this->migration_status = 'completed'; }
}
class TurnEngine {
	public $flush_result = true;
	private $events;
	private $repository;
	public function __construct( &$events, $repository ) { $this->events =& $events; $this->repository = $repository; }
	public function assert_storage_writer() { return true; }
	public function table_collection_repository() { return $this->repository; }
	public function begin_migrated_object_batch() { $this->events[] = 'begin'; }
	public function flush_migrated_object_batch() { $this->events[] = 'flush'; return $this->flush_result; }
}
class TurnCollection {
	public $summary = null;
	public $whole_reads = 0;
	private $events;
	private $object;
	private $counts;
	public function __construct( &$events, $object, $counts ) { $this->events =& $events; $this->object = $object; $this->counts = $counts; }
	public function view_context( $bucket ) { return array( 'found' => true ); }
	public function migration_turn_context( $bucket ) {
		$this->events[] = 'select';
		return array( 'found' => true, 'root' => null === $this->object ? null : array( 'key' => '09', 'value' => array() ),
			'object' => $this->object, 'counts' => $this->counts );
	}
	public function checkpoint_migration_turn( $bucket, $context, $summary ) { $this->events[] = 'checkpoint'; $this->summary = $summary; return true; }
	public function read_snapshot() { ++$this->whole_reads; throw new \RuntimeException( 'Whole collection read.' ); }
}
class TurnCompactSettings extends MigratorSettings {
	protected function get_available_plugin_by_name( $name ) { return 'Fixture' === $name ? new TurnCompactPlugin() : false; }
}
class TurnCompactPlugin { public function name() { return 'Fixture'; } }
class TurnCompactBackend extends LegacyTableBackend {
	public $key;
	public $value;
	private $row;
	public function __construct( $row ) { $this->row = $row; }
	public function migration_turn_context( $key ) { return array( 'found' => true, 'root' => $this->row, 'counts' => array( 'queued' => 1, 'completed' => 0, 'errors' => 0 ) ); }
	public function targeted_collection_context( $key, $uid ) { return array( 'found' => true, 'root' => $this->row ); }
	public function checkpoint_migration_turn( $key, $context, $value, $summary ) {
		$this->key = $context['root']['key']; $this->value = $value; return true;
	}
	public function checkpoint_targeted_collection( $key, $context, $value ) {
		$this->key = $context['root']['key']; $this->value = $value; return true;
	}
}
