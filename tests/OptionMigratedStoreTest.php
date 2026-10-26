<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\OptionMigratedStore;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use PHPUnit\Framework\TestCase;

class OptionMigratedStoreTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		parent::tearDown();
	}

	public function test_noncompact_reads_preserve_runtime_properties_and_children(): void {
		$image = $this->image( 'https://example.test/legacy-extra.jpg', 50 );
		$image->settings = array( 'legacy-extra' => array( false, null, "\xff" ) );
		$image->children = array( 'original-key' => $this->image( 'https://example.test/child-extra.jpg', 51 ) );
		$data = array( 'migrated' => array( $image->unique_identifier() => $image ) );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data );
		$store = new OptionMigratedStore();
		$this->assertSame( $image, $store->get( $image->unique_identifier() ) );
		$this->assertSame( $data['migrated'], $store->get_all() );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}

	public function test_failed_history_save_does_not_advance_revision(): void {
		$engine = new MigratorEngine( new OptionMigratedStore() );
		$GLOBALS['foogallery_migrate_test_failed_options'][ FOOGALLERY_MIGRATE_OPTION_DATA ] = true;
		$this->assertFalse( $engine->add_migrated_object( $this->image( 'https://example.test/failed.jpg', 52 ) ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( 0, $engine->get_migrated_revision() );
	}

	public function test_store_commits_history_and_revision_in_the_same_option(): void {
		$store = new OptionMigratedStore();
		$image = $this->image( 'https://example.test/atomic.jpg', 53 );
		$this->assertSame( 1, $store->upsert_batch( array( $image ) ) );
		$this->assertSame( 1, get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated-revision'] );
		$this->assertSame( 1, $store->upsert_batch( array( $image ) ) );
		$this->assertSame( 1, get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated-revision'] );
		$this->assertTrue( $store->delete( $image->unique_identifier() ) );
		$this->assertSame( 2, get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated-revision'] );
	}

	public function test_null_history_is_corrupt_not_an_empty_map(): void {
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'migrated' => null ) );
		$this->expectException( \RuntimeException::class );
		( new OptionMigratedStore() )->clear();
	}

	public function test_null_compact_marker_does_not_discard_existing_records(): void {
		$history = array( MigratorSettings::COMPACT_MARKER => null, 'type' => 'migratable', 'items' => array() );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'migrated' => $history ) );
		$this->expectException( \RuntimeException::class );
		( new OptionMigratedStore() )->clear();
	}

	public function test_false_legacy_revision_uses_the_engines_nonempty_history_fallback(): void {
		$image = $this->image( 'https://example.test/revision.jpg', 54 );
		$data = array( 'migrated' => array( $image->unique_identifier() => $image ), 'migrated-revision' => false );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data );
		$engine = new MigratorEngine( new OptionMigratedStore() );
		$this->assertSame( 1, $engine->get_migrated_revision() );
		$this->assertTrue( $engine->add_migrated_object( $this->image( 'https://example.test/revision-new.jpg', 55 ) ) );
		$this->assertSame( 2, $engine->get_migrated_revision() );
	}

	public function test_unrelated_operational_writes_refuse_a_corrupt_root_option(): void {
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, 'corrupt-root' );
		$engine = new MigratorEngine( new OptionMigratedStore() );
		$this->assertFalse( $engine->set_migrator_setting( 'queue', array( 'new' ) ) );
		$this->assertSame( 'corrupt-root', get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}

	public function test_batch_survives_a_new_store_and_leaves_other_buckets_unchanged(): void {
		$data = array( 'plugins' => array(), 'custom' => array( 'opaque' => 'retain' ), 'migrated-revision' => 3 );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data );
		$image = $this->image( 'https://example.test/image.jpg', 41 );
		$store = new OptionMigratedStore();
		$this->assertSame( 1, $store->upsert_batch( array( array( 'object' => $image, 'parent_key' => 'gallery_aigpl_2' ) ) ) );
		$reloaded = new OptionMigratedStore();
		$this->assertSame( 41, $reloaded->get( $image->unique_identifier() )->migrated_id );
		$this->assertSame( 'gallery_aigpl_2', $reloaded->get( $image->unique_identifier() )->migration_parent_key );
		$this->assertCount( 1, $reloaded->get_by_parent( 'gallery_aigpl_2' ) );
		$saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		unset( $saved['migrated'] );
		$this->assertSame( 4, $saved['migrated-revision'] );
		$saved['migrated-revision'] = 3;
		$this->assertSame( $data, $saved );
	}

	public function test_upsert_without_parent_retains_the_saved_relationship(): void {
		$image = $this->image( 'https://example.test/child.jpg', 42 );
		$store = new OptionMigratedStore();
		$store->upsert_batch( array( array( 'object' => $image, 'parent_key' => 'gallery_aigpl_2' ) ) );
		$image->migrated_id = 43;
		$store->upsert_batch( array( $image ) );
		$this->assertSame( 43, ( new OptionMigratedStore() )->get_by_parent( 'gallery_aigpl_2' )[0]->migrated_id );
	}

	public function test_legacy_objects_remain_readable_without_an_upgrade_or_backup(): void {
		$image = $this->image( 'https://example.test/legacy.jpg', 44 );
		$data = array( 'migrated' => array( $image->unique_identifier() => $image ), 'galleries' => array() );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data );
		$store = new OptionMigratedStore();
		$this->assertTrue( $store->has( $image->unique_identifier() ) );
		$this->assertSame( 44, $store->get( $image->unique_identifier() )->migrated_id );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertFalse( get_option( 'foogallery_migrate_migrated_backup' ) );
	}

	public function test_unknown_compact_record_survives_an_unrelated_history_write(): void {
		$unknown = array( 'object_type' => 'future', 'source_url' => 'opaque', 'extra' => array( 'a' => 1 ) );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'migrated' => array(
			MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION,
			'type' => 'migratable', 'items' => array( 'unknown' => $unknown ),
		) ) );
		$store = new OptionMigratedStore();
		$this->assertSame( 1, $store->upsert_batch( array( $this->image( 'https://example.test/new.jpg', 45 ) ) ) );
		$this->assertSame( $unknown, get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated']['items']['unknown'] );
	}

	public function test_retry_deletes_only_target_gallery_and_errored_children(): void {
		$ok = $this->image( 'https://example.test/ok.jpg', 46 );
		$error = $this->image( 'https://example.test/error.jpg', 0 );
		$error->migration_status = 'error';
		$other = $this->image( 'https://example.test/other.jpg', 47 );
		$store = new OptionMigratedStore();
		$store->upsert_batch( array(
			array( 'object' => $ok, 'parent_key' => 'gallery_aigpl_2' ),
			array( 'object' => $error, 'parent_key' => 'gallery_aigpl_2' ),
			array( 'object' => $other, 'parent_key' => 'gallery_aigpl_3' ),
		) );
		$this->assertTrue( $store->delete_for_retry( 'gallery_aigpl_2' ) );
		$this->assertTrue( $store->has( $ok->unique_identifier() ) );
		$this->assertFalse( $store->has( $error->unique_identifier() ) );
		$this->assertTrue( $store->has( $other->unique_identifier() ) );
		$this->assertSame( array( 'image' => array( 'count' => 2, 'errors' => 0 ) ), $store->summary() );
	}

	public function test_invalid_input_and_corrupt_history_do_not_overwrite_state(): void {
		$store = new OptionMigratedStore();
		$this->assertFalse( $store->upsert_batch( array( 'invalid' ) ) );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'migrated' => 'corrupt' ) );
		$before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		try {
			$store->upsert_batch( array( $this->image( 'https://example.test/new.jpg', 49 ) ) );
			$this->fail( 'Corrupt history must not be silently discarded.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'history', $error->getMessage() );
		}
		$this->assertSame( $before, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}

	public function test_engine_revision_and_lookup_are_option_backed(): void {
		$engine = new MigratorEngine( new OptionMigratedStore() );
		$image = $this->image( 'https://example.test/engine.jpg', 48 );
		$this->assertTrue( $engine->add_migrated_object( $image ) );
		$this->assertSame( 1, $engine->get_migrated_revision() );
		$fresh = new MigratorEngine( new OptionMigratedStore() );
		$this->assertSame( 48, $fresh->get_migrated_object( $image->unique_identifier() )->migrated_id );
		$this->assertTrue( $fresh->delete_migrated_object( $image->unique_identifier() ) );
		$this->assertSame( 2, $fresh->get_migrated_revision() );
	}

	private function image( $url, $id ) {
		$image = new Image();
		$image->source_url = $url;
		$image->migrated = true;
		$image->migrated_id = $id;
		$image->migration_status = 'completed';
		return $image;
	}
}
