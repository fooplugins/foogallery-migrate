<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

class LegacyHistoryMaintenanceContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_option_writes'] ); }

	public function test_retry_and_clear_dispatch_without_option_fallback(): void {
		$backend = new HistoryMaintenanceBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$this->assertTrue( $history->delete_for_retry( '09' ) );
		$this->assertTrue( $history->clear() );
		$this->assertSame( array( array( 'retry', array( 'key' => '09' ), array() ), array( 'clear', array(), array() ) ), $backend->calls );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_coercible_retry_key_refuses_before_backend_call(): void {
		$backend = new HistoryMaintenanceBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		try { $history->delete_for_retry( '9' ); $this->fail( 'Expected exact key refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $backend->calls ); }
	}

	public function test_engine_option_settings_respect_the_explicit_backend_instance(): void {
		$backend = new EngineOptionStateBackend();
		$history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$engine = new \FooPlugins\FooGalleryMigrate\MigratorEngine( $history );
		$this->assertSame( 'injected', $engine->get_migrator_setting( 'queue' ) );
		$this->assertTrue( $engine->set_migrator_setting( 'queue', 'updated' ) );
		$this->assertSame( array( 'queue', 'mutate' ), $backend->calls );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_empty_table_history_does_not_fall_back_to_stale_option_history(): void {
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'migrated' => array( 'stale' => array( 'object_type' => 'image' ) ) );
		$history = new EmptyTableHistoryRepository( new HistoryMaintenanceBackend(), new MigratorSettings() );
		$this->assertFalse( ( new \FooPlugins\FooGalleryMigrate\MigratorEngine( $history ) )->has_migrated_objects() );
	}

	public function test_option_raw_export_preserves_opaque_values_typed_order_and_headers(): void {
		$wrapper = array( 'before' => -0.0, MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable',
			'items' => array( '09' => false, 9 => null, 'opaque' => array( 'unknown' => "\xff\x00" ) ), 'after' => '雪' );
		$data = array( 'migrated' => $wrapper, 'migrated-revision' => 4 );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $data;
		$history = new MigratedHistoryRepository( new LegacyOptionBackend(), new MigratorSettings() );
		$page = $history->export_page( 2 );
		$this->assertSame( array( array( 'key' => '09', 'value' => false ), array( 'key' => 9, 'value' => null ) ), $page['rows'] );
		$last = $history->export_page( 2, $page['cursor'] );
		$this->assertNull( $last['cursor'] );
		$this->assertSame( 4, $last['revision'] );
		$items = array(); foreach ( array_merge( $page['rows'], $last['rows'] ) as $row ) { $items[$row['key']] = $row['value']; }
		$this->assertSame( serialize( $wrapper ), serialize( ( new \FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope() )->rebuild( $page['root'], $items ) ) );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_engine_restores_only_requested_children_with_exact_parent_without_history_scan(): void {
		$backend = new ChildHistoryBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$parent = new \FooPlugins\FooGalleryMigrate\Objects\Image(); $parent->source_url = 'parent';
		$children = array();
		for ( $i = 0; $i < 103; $i++ ) { $child = new \FooPlugins\FooGalleryMigrate\Objects\Image(); $child->source_url = 'child-' . $i; $children['key-' . $i] = $child; }
		$parent->children = $children; $backend->parent = $parent->unique_identifier();
		( new \FooPlugins\FooGalleryMigrate\MigratorEngine( $history ) )->apply_migrated_children( $parent );
		$this->assertSame( array( 100, 3 ), array_map( 'count', $backend->requests ) );
		$this->assertSame( 71, $parent->children['key-0']->migrated_id );
		$this->assertTrue( $parent->children['key-0']->migration_store_checked );
		$this->assertFalse( property_exists( $parent->children['key-1'], 'migration_store_checked' ) );
		$this->assertSame( array_keys( $children ), array_keys( $parent->children ) );
	}
}

/** Translation spy only; real SQL parity and rollback use guarded WordPress. */
class EngineOptionStateBackend extends LegacyOptionBackend {
	public $calls = array();
	public function read( $key ) { $this->calls[] = $key; return array( 'found' => true, 'key' => $key, 'value' => 'injected' ); }
	public function mutate( $callback ) { $this->calls[] = 'mutate'; $callback( array( 'queue' => 'injected' ) ); return true; }
}

class EmptyTableHistoryRepository extends MigratedHistoryRepository {
	public function count() { return 0; }
}

class HistoryMaintenanceBackend extends LegacyTableBackend {
	public $calls = array(); public function __construct() {}
	public function history_mutate( $operation, $request, $companions = array() ) {
		$this->calls[] = array( $operation, $request, $companions ); return true;
	}
}

class ChildHistoryBackend extends HistoryMaintenanceBackend {
	public $requests = array(); public $parent;
	public function history_read( $operation, $request = array() ) {
		if ( 'children' !== $operation ) { throw new \RuntimeException( 'Only exact-key child reads admitted.' ); }
		$rows = array();
		foreach ( array_chunk( $request['keys'], 100 ) as $keys ) {
			$this->requests[] = $keys;
			foreach ( $keys as $key ) {
				if ( 'child-1' === $key ) { continue; }
				$rows[] = array( 'found' => true, 'key' => $key, 'value' => array( 'object_type' => 'image', 'source_url' => $key, 'migrated_id' => 71, 'migration_parent_key' => $this->parent ) );
			}
		}
		return array( 'result' => $rows, 'context' => array( array( 'key' => 'migrated', 'head' => array( 'state_kind' => 'map', 'revision' => 1 ), 'root' => array( 'version' => 1, 'kind' => 'map', 'wrapper' => 'plain' ) ), array( 'key' => 'migrated-revision', 'head' => array( 'state_kind' => 'scalar', 'revision' => 1 ), 'root' => array( 'version' => 1, 'kind' => 'scalar', 'value' => 1 ) ) ) );
	}
}
