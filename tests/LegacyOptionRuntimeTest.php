<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
use FooPlugins\FooGalleryMigrate\OptionMigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/legacy-runtime-contract-fixtures.php';

class RuntimeContractSettings extends \FooPlugins\FooGalleryMigrate\MigratorSettings {
	public function compact_state_value( $key, $value ) { return $this->compact_migrator_setting( $key, $value ); }
	public function hydrate_state_value( $key, $value ) { return $this->hydrate_migrator_setting( $key, $value ); }
}

class LegacyOptionRuntimeTest extends TestCase {
	private $backend;
	private $settings;
	private $fixture;
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$GLOBALS['foogallery_migrate_test_serialized_reads'] = array();
		$this->backend = new LegacyOptionBackend();
		$this->settings = new RuntimeContractSettings();
		$this->fixture = Fixtures\legacy_runtime_contract_fixture();
		$this->backend->replace_all( $this->fixture['logical'] );
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void {
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$GLOBALS['foogallery_migrate_test_serialized_reads'] = array();
		unset( $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
	private function writes( $count = 1 ) {
		$this->assertCount( $count, $GLOBALS['foogallery_migrate_test_option_writes'] );
		foreach ( $GLOBALS['foogallery_migrate_test_option_writes'] as $write ) {
			$this->assertSame( array( FOOGALLERY_MIGRATE_OPTION_DATA, false ), $write );
		}
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	public function test_inventory_and_historical_reads_do_not_rewrite(): void {
		$repo = new ScalarStateRepository( $this->backend, $this->settings );
		foreach ( $this->fixture['logical'] as $key => $value ) { $this->assertSame( $value, $repo->get( $key, 'missing' ) ); }
		$this->assertSame( 'missing', $repo->get( 'absent', 'missing' ) );
		$this->assertSame( array( array( 'found' => true, 'key' => 'stored-null', 'value' => null ), array( 'found' => false, 'key' => 'absent', 'value' => null ) ), $repo->read_many( array( 'stored-null', 'absent' ) ) );
		$this->writes( 0 );
		$this->assertSame( $this->fixture['logical'], $this->backend->read_all() );
	}
	public function test_scalar_multi_key_failure_and_delete_preserve_unknowns(): void {
		$repo = new ScalarStateRepository( $this->backend, $this->settings );
		$this->assertTrue( $repo->mutate_many( array( 'galleries-state' => null, 'albums-state' => false ) ) ); $this->writes();
		$stable = $this->backend->read_all();
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( $repo->mutate_many( array( 'galleries-state' => 1, 'albums-state' => 2 ) ) ); $this->writes();
		$this->assertSame( $stable, $this->backend->read_all() );
		$this->assertTrue( $repo->delete( 'absent' ) ); $this->writes( 0 );
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$this->assertTrue( $repo->delete( 'stored-null' ) ); $this->writes();
		$this->assertSame( "\xff\x00", $repo->get( 'unknown' ) );
		$this->assertFalse( $repo->mutate_many( array( 'migrated' => array() ) ) ); $this->writes( 0 );
	}
	public function test_collections_compact_wrapper_matches_current_facade_and_couples_state(): void {
		$repo = new MigratableCollectionRepository( $this->backend, $this->settings );
		$this->assertSame( $this->fixture['logical']['galleries'], $repo->read_snapshot( 'galleries' ) );
		$this->writes( 0 );
		$this->assertTrue( $repo->update_with_state( 'galleries', array( $this->fixture['image'] ), array( 'queued' => 1 ) ) ); $this->writes();
		$this->assertSame( serialize( $this->settings->get_migrator_setting( 'galleries' ) ), serialize( $repo->read_snapshot( 'galleries' ) ) );
		$this->assertSame( array( 'queued' => 1 ), $this->backend->read( 'galleries-state' )['value'] );
		$stable = serialize( $this->backend->read_all() );
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( $repo->update_with_state( 'galleries', array(), array( 'queued' => 0 ) ) );
		$this->assertSame( $stable, serialize( $this->backend->read_all() ) );
	}
	public function test_nested_locator_updates_only_containing_historical_root(): void {
		$repo = new MigratableCollectionRepository( $this->backend, $this->settings );
		$child = clone $this->fixture['image']; $child->migrated_id = 72;
		$this->assertTrue( $repo->update_record( 'galleries', array( 'root' => 0, 'path' => array( '09' ) ), $child ) ); $this->writes();
		$root = $repo->read_snapshot( 'galleries' )[0];
		$this->assertSame( 72, $root->children['09']->migrated_id );
		$this->assertNull( $root->children[9] );
		$this->assertSame( "\xff\x00", $root->settings['unknown'] );
		$this->assertFalse( $repo->update_record( 'galleries', array( 'root' => 0, 'path' => array( 'missing' ) ), $child ) ); $this->writes( 0 );
	}
	public function test_content_post_replacement_and_checkpoint_are_atomic_and_typed(): void {
		$repo = new ContentScanRepository( $this->backend, $this->settings );
		$this->assertSame( array( 'cursor' => 9 ), $repo->metadata() );
		$this->assertTrue( $repo->replace_post_matches( '09', array( 'new' => array( 'post_id' => '09', 'original_content' => 'new' ) ), array( 'cursor' => 10 ) ) ); $this->writes();
		$this->assertSame( array( 9, 'new' ), array_keys( $repo->read_snapshot() ) );
		$this->assertSame( array( 'cursor' => 10 ), $repo->metadata() );
		$stable = $this->backend->read_all();
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( $repo->update_with_scan_state( array(), array( 'cursor' => 100 ) ) );
		$this->assertSame( $stable, $this->backend->read_all() );
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$this->assertTrue( $repo->remove_post( 9 ) );
		$this->assertSame( array( 'new' ), array_keys( $repo->read_snapshot() ) );
	}
	public function test_legacy_content_read_does_not_import_or_rewrite(): void {
		$data = array( 'block-shortcode' => array( '09' => false, 9 => null ), 'block-shortcode_scan_progress' => array( 'cursor' => 3 ) );
		$this->backend->replace_all( $data ); $GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$repo = new ContentScanRepository( $this->backend, $this->settings );
		$this->assertSame( $data['block-shortcode'], $repo->read_snapshot() );
		$this->assertSame( array( 'cursor' => 3 ), $repo->metadata() );
		$this->assertSame( $data, $this->backend->read_all() ); $this->writes( 0 );
	}
	public function test_queue_item_error_and_companion_state_commit_once(): void {
		$repo = new ImageTagQueueRepository( $this->backend, $this->settings );
		$this->assertTrue( $repo->update_item( 0, array( 'attachment_id' => 71, 'tags' => array( 'new' ) ), array( 'processed' => 1 ) ) ); $this->writes();
		$this->assertSame( 1, $repo->read_snapshot()['processed'] );
		$this->assertSame( array( 'new' ), $repo->read_snapshot()['items'][0]['tags'] );
		$this->assertTrue( $repo->append_error( 'engine error' ) ); $this->writes();
		$this->assertSame( array( 'engine error' ), $repo->read_snapshot()['errors'] );
		$stable = $this->backend->read_all();
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( $repo->update_with_sync_state( array(), array( 'processed' => 99 ) ) );
		$this->assertSame( $stable, $this->backend->read_all() );
	}
	public function test_history_adapter_uses_existing_owner_and_presence_envelope(): void {
		$repo = new MigratedHistoryRepository( $this->backend, $this->settings );
		$key = $this->fixture['image']->unique_identifier();
		$this->assertSame( $this->fixture['historical'], $repo->get( $key ) );
		$rows = $repo->get_many( array( 'missing', $key ) );
		$this->assertFalse( $rows[0]['found'] ); $this->assertSame( $key, $rows[1]['key'] );
		$this->assertTrue( $repo->update_status( $key, 'error' ) ); $this->writes();
		$this->assertSame( 5, $repo->revision() );
		$this->assertSame( 1, $repo->log_summary()['error'] );
	}
	private function seed( $records ) {
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'migrated' => array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => $records ), 'opaque' => "\xff\x00" );
		return new OptionMigratedStore();
	}
	public function test_compact_collection_version_refuses_before_mutation(): void {
		$this->backend->replace_all( array( 'galleries' => array( MigratorSettings::COMPACT_MARKER => 99, 'type' => 'migratable', 'items' => array( $this->fixture['image'] ) ) ) );
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$stable = serialize( $this->backend->read_all() );
		$repo = new MigratableCollectionRepository( $this->backend, $this->settings );
		try { $repo->read_snapshot( 'galleries' ); $this->fail( 'Unsupported wrapper accepted' ); } catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		try { $repo->update_record( 'galleries', array( 'root' => 0, 'path' => array() ), $this->fixture['image'] ); $this->fail( 'Unsupported wrapper written' ); } catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->assertSame( $stable, serialize( $this->backend->read_all() ) );
		$this->writes( 0 );
	}
	public function test_history_wrapper_unknown_metadata_and_unrelated_bytes_survive_status_change(): void {
		$store = $this->seed( array( 'i' => array( 'object_type' => 'image', 'future' => "\xff\x00" ), 'opaque' => false ) );
		$data = $this->backend->read_all(); $data['migrated']['future-wrapper'] = array( '09' => null, 9 => false );
		$this->backend->replace_all( $data ); $GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$this->assertTrue( $store->set_status( 'i', 'error' ) );
		$this->assertSame( $data['migrated']['future-wrapper'], $this->backend->read_all()['migrated']['future-wrapper'] );
		$this->assertSame( "\xff\x00", $this->backend->read_all()['migrated']['items']['i']['future'] );
		$this->assertFalse( $this->backend->read_all()['migrated']['items']['opaque'] );
		$this->writes();
	}
	public function test_scalar_same_value_is_no_write_even_with_serialized_object_reads(): void {
		$repo = new ScalarStateRepository( $this->backend, $this->settings );
		$GLOBALS['foogallery_migrate_test_serialized_reads'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertTrue( $repo->set( 'galleries-state', $this->fixture['logical']['galleries-state'] ) );
		$this->writes( 0 );
	}
	public function test_scalar_delete_rejects_coercible_keys_without_writing(): void {
		$repo = new ScalarStateRepository( $this->backend, $this->settings );
		try { $repo->delete( '9' ); $this->fail( 'Coercible key accepted' ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->writes( 0 );
	}
	public function test_compact_nested_locator_preserves_root_and_unrelated_records(): void {
		$repo = new MigratableCollectionRepository( $this->backend, $this->settings );
		$this->assertTrue( $repo->replace_snapshot( 'galleries', array( '09' => $this->fixture['historical'], 9 => null ) ) );
		$before = $this->backend->read_all();
		$child = clone $this->fixture['image']; $child->migrated_id = 72;
		// Current facade compaction uses list positions; lossless typed codec integration is a later lane.
		$this->assertTrue( $repo->update_record( 'galleries', array( 'root' => 0, 'path' => array( 0 ) ), $child ) );
		$after = $this->backend->read_all();
		$this->assertSame( 72, $repo->read_snapshot( 'galleries' )[0]->children[0]->migrated_id );
		$this->assertSame( array( 0, 1 ), array_keys( $repo->read_snapshot( 'galleries' ) ) );
		$this->assertSame( $before['galleries']['items'][0]['settings'], $after['galleries']['items'][0]['settings'] );
		$this->assertSame( $before['galleries']['items'][0]['children'][1], $after['galleries']['items'][0]['children'][1] );
		$this->assertSame( $before['galleries']['items'][1], $after['galleries']['items'][1] );
		unset( $before['galleries'], $after['galleries'] );
		$this->assertSame( serialize( $before ), serialize( $after ) );
	}
	public function test_many_presence_order_and_false_null_unknown(): void {
		$store = $this->seed( array( '09' => false, 9 => null, 'opaque' => array( 'unknown' => true ) ) );
		$this->assertSame( array( array( 'found' => true, 'key' => 9, 'value' => false ), array( 'found' => false, 'key' => 'missing', 'value' => null ), array( 'found' => true, 'key' => '09', 'value' => false ) ), $store->get_many( array( 9, 'missing', '09' ) ) );
		$this->writes( 0 );
	}
	public function test_duplicate_and_oversized_many_refuse(): void {
		$store = $this->seed( array() );
		foreach ( array( array( 'x', 'x' ), array( '9' ), range( 0, 100 ) ) as $keys ) {
			try { $store->get_many( $keys ); $this->fail( 'Expected exact-key refusal' ); } catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}
	public function test_source_identity_uses_first_positive_legacy_order_not_migrated_flag(): void {
		$base = array( 'object_type' => 'image', 'plugin_name' => 'Case', 'ID' => '09', 'source_url' => 'URL' );
		$store = $this->seed( array( 'zero' => $base + array( 'migrated_id' => 0 ), 'first' => $base + array( 'migrated_id' => 17, 'migrated' => false ), 'second' => $base + array( 'migrated_id' => 18, 'migrated' => true ) ) );
		$this->assertSame( 17, $store->find_by_source_identity( 'image', 'Case', '09' )->migrated_id );
		$this->assertFalse( $store->find_by_source_identity( 'image', 'case', '09' ) );
		$this->assertFalse( $store->find_by_source_identity( 'image', 'Case', 9 ) );
		$this->assertSame( 17, $store->find_by_source_url( 'image', 'URL' )->migrated_id );
		$this->assertFalse( $store->find_by_source_url( 'image', 'url' ) );
	}
	public function test_log_aliases_summary_and_filtered_pages_keep_keys(): void {
		$store = $this->seed( array( '09' => array( 'object_type' => 'image', 'migration_status' => 'completed' ), 9 => array( 'object_type' => 'image', 'migration_status' => 'error' ), 'opaque' => false ) );
		$summary = $store->log_summary();
		$this->assertSame( $summary['total'], $summary['count'] );
		$this->assertSame( $summary['by_type'], $summary['types'] );
		$this->assertSame( $summary['by_status'], $summary['statuses'] );
		$this->assertSame( 1, $summary['error'] );
		$this->assertSame( 1, $summary['completed'] );
		$this->assertSame( array( 9 ), array_keys( $store->log_page( 'image', 'error', 0, 1 ) ) );
		$this->assertSame( array( '09' ), array_keys( $store->log_page( 'image', '', 0, 1 ) ) );
		$this->assertSame( array(), $store->log_page( 'image', '', 2, 1 ) );
	}
	public function test_status_and_companion_mutation_revision_once_and_failure_atomic(): void {
		$image = new Image(); $image->source_url = 'old'; $image->migrated_id = 42;
		$store = $this->seed( array( $image->unique_identifier() => $image ) );
		$key = $image->unique_identifier();
		$this->assertTrue( $store->set_status( $key, 'error' ) );
		$this->assertSame( 2, $store->revision() );
		$this->assertTrue( $store->set_status( $key, 'error' ) );
		$this->writes();
		$this->assertFalse( $store->set_status( 'missing', 'error' ) );
		$this->assertSame( 1, $store->upsert_batch( array( $image ), array( 'block-shortcode_scan_state' => array( 'items' => array(), 'progress' => array( 'cursor' => 5 ) ) ) ) );
		$this->assertSame( 3, $store->revision() );
		$stable = serialize( $GLOBALS['foogallery_migrate_test_options'] );
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$image->migrated_id = 99;
		$this->assertFalse( $store->upsert_batch( array( $image ), array( 'galleries-state' => false ) ) );
		$this->assertSame( $stable, serialize( $GLOBALS['foogallery_migrate_test_options'] ) );
		$this->assertFalse( $store->upsert_batch( array( $image ), array( 'migrated-revision' => 99 ) ) );
	}
	public function test_page_envelope_binds_state_revision_filter_and_scope(): void {
		$this->seed( array( '09' => array( 'object_type' => 'image' ), 9 => array( 'object_type' => 'image' ), 'opaque' => false ) );
		$store = new class extends OptionMigratedStore {
			public $blog = 1;
			protected function current_blog_id() { return $this->blog; }
		};
		$page = $store->iterate_page( 1 );
		$this->assertSame( '09', $page['rows'][0]['key'] );
		$next = $store->iterate_page( 1, $page['cursor'] );
		$this->assertSame( 9, $next['rows'][0]['key'] );
		$this->assertSame( 1, $page['revision'] );
		$last = $store->iterate_page( 1, $next['cursor'] );
		$this->assertSame( array( array( 'key' => 'opaque', 'value' => false ) ), $last['rows'] );
		$this->assertNull( $last['cursor'] );
		$store->blog = 2;
		try { $store->iterate_page( 1, $page['cursor'] ); $this->fail( 'Cross-scope cursor accepted' ); } catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$store->blog = 1;
		try { $store->page_log( 'image', '', 1, $page['cursor'] ); $this->fail( 'Cross-filter cursor accepted' ); } catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->assertTrue( $store->set_status( '09', 'completed' ) );
		$this->expectException( \RuntimeException::class );
		$store->iterate_page( 1, $page['cursor'] );
	}

	public function test_unsupported_backend_refuses_without_reading_options(): void {
		$this->expectException( \RuntimeException::class );
		new ScalarStateRepository( new \stdClass(), $this->settings );
	}
}
