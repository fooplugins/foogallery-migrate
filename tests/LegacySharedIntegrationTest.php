<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
use PHPUnit\Framework\TestCase;

class LegacySharedIntegrationTest extends TestCase {
	private $previous_db;
	protected function setUp(): void {
		$this->previous_db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public $last_error = '';
			public function prepare( $sql, ...$values ) { return vsprintf( $sql, $values ); }
			public function esc_like( $value ) { return addcslashes( $value, '_%' ); }
			public function get_var( $sql ) { return null; }
		};
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_db;
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		unset( $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
	public function test_ordinary_facade_bridges_preserve_typed_root_and_child_keys(): void {
		$settings = new MigratorSettings(); $backend = new LegacyOptionBackend();
		$repo = new MigratableCollectionRepository( $backend, $settings );
		$image = new Image(); $image->source_url = 'https://example.test/雪.jpg';
		$image->children = array( '09' => clone $image, 9 => null );
		$image->settings = array( '09' => "\xff\x00", 9 => false, 'null' => null );
		foreach ( array( 'galleries', 'albums' ) as $bucket ) {
			$this->assertTrue( $repo->update_with_state( $bucket, array( '09' => $image, 9 => false ), null ) );
			$raw = $backend->read( $bucket )['value'];
			$this->assertSame( array( '09', 9 ), array_keys( $raw['items'] ) );
			$this->assertSame( array( '09', 9 ), array_keys( $raw['items']['09']['children'] ) );
			$logical = $settings->get_migrator_setting( $bucket );
			$this->assertSame( array( '09', 9 ), array_keys( $logical ) );
			$this->assertSame( array( '09', 9 ), array_keys( $logical['09']->children ) );
			$this->assertSame( $image->settings, $logical['09']->settings );
			$this->assertSame( serialize( $logical ), serialize( $repo->read_snapshot( $bucket ) ) );
			$child = clone $image; $child->children = array(); $child->migrated_id = 72;
			$this->assertTrue( $repo->update_record( $bucket, array( 'root' => '09', 'path' => array( '09' ) ), $child ) );
			$this->assertSame( 72, $repo->read_snapshot( $bucket )['09']->children['09']->migrated_id );
			$this->assertNull( $repo->read_snapshot( $bucket )['09']->children[9] );
		}
	}
	public function test_public_bridge_validates_wrapper_version_and_type_without_write(): void {
		$settings = new MigratorSettings(); $repo = new ScalarStateRepository( new LegacyOptionBackend(), $settings );
		foreach ( array( 99, null, '1' ) as $version ) {
			$raw = array( MigratorSettings::COMPACT_MARKER => $version, 'type' => 'migratable', 'items' => array() );
			try { $settings->compact_state_value( 'galleries', $raw ); $this->fail( 'Unsupported wrapper accepted' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'galleries' => $raw );
			try { $repo->get( 'galleries' ); $this->fail( 'Unsupported wrapper hydrated' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
	public function test_engine_uses_common_option_history_owner_and_status_preserves_raw_fields(): void {
		$raw = array( 'object_type' => 'image', 'source_url' => 'URL', 'migration_status' => 'completed',
			'migrated_id' => 71, 'future' => array( '09' => "\xff\x00", 9 => null ), 'children' => array( '09' => false, 9 => null ) );
		$initial = array( 'migrated' => array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable',
			'items' => array( 'legacy-exact' => $raw ), 'future_header' => false ), 'migrated-revision' => 4, 'unknown' => null );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $initial;
		$engine = new MigratorEngine();
		$factory = new \ReflectionMethod( $engine, 'migrated_store' );
		if ( PHP_VERSION_ID < 80100 ) { $factory->setAccessible( true ); }
		$this->assertInstanceOf( MigratedHistoryRepository::class, $factory->invoke( $engine ) );
		$this->assertSame( 'error', $engine->update_migrated_object_status( 'legacy-exact', 'error' )->migration_status );
		$expected = $initial; $expected['migrated']['items']['legacy-exact']['migration_status'] = 'error'; $expected['migrated-revision'] = 5;
		$this->assertSame( $expected, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertSame( 5, $engine->get_migrated_revision() );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertFalse( $engine->has_object_been_migrated( 'image-URL' ) );
		$engine->update_migrated_object_status( 'legacy-exact', 'error' );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_option_writes'] );
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertTrue( is_wp_error( $engine->update_migrated_object_status( 'legacy-exact', 'completed' ) ) );
		$this->assertSame( $expected, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}
	public function test_raw_historical_objects_are_readable_but_not_losslessly_table_encodable(): void {
		$image = new Image(); $image->future = new \stdClass(); $image->future->bytes = "\xff\x00";
		$raw = array( 'galleries' => array( '09' => $image, 9 => null ) );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $raw;
		$repo = new MigratableCollectionRepository( new LegacyOptionBackend(), new MigratorSettings() );
		$this->assertSame( $raw['galleries'], $repo->read_snapshot( 'galleries' ) );
		try { ( new LegacyStateCodec() )->encode( $raw ); $this->fail( 'Historical object was silently narrowed' ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertSame( $raw, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}
	public function test_pending_status_remains_batched_until_flush(): void {
		$engine = new MigratorEngine( new MigratedHistoryRepository( new LegacyOptionBackend(), new MigratorSettings() ) );
		$image = new Image(); $image->source_url = 'pending';
		$engine->begin_migrated_object_batch();
		$this->assertTrue( $engine->add_migrated_object( $image ) );
		$this->assertSame( 'error', $engine->update_migrated_object_status( $image->unique_identifier(), 'error' )->migration_status );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertTrue( $engine->flush_migrated_object_batch() );
		$this->assertSame( 'error', $engine->get_migrated_object( $image->unique_identifier() )->migration_status );
		$this->assertSame( 1, $engine->get_migrated_revision() );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
	public function test_unknown_compact_headers_survive_bridge_and_partial_nested_update(): void {
		$settings = new MigratorSettings(); $backend = new LegacyOptionBackend();
		$repo = new MigratableCollectionRepository( $backend, $settings );
		$wrapper = array( 'future_header' => "\xff\x00", MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable',
			'items' => array( '09' => array( 'object_type' => 'image', 'future' => false,
				'children' => array( '09' => null, 9 => false ) ), 9 => null ) );
		$this->assertSame( $wrapper, $settings->compact_state_value( 'albums', $wrapper ) );
		$this->assertTrue( $backend->replace_all( array( 'albums' => $wrapper ) ) );
		$this->assertTrue( $repo->update_record( 'albums', array( 'root' => '09', 'path' => array( '09' ) ), false ) );
		$expected = $wrapper; $expected['items']['09']['children']['09'] = false;
		$this->assertSame( $expected, $backend->read( 'albums' )['value'] );
		$this->assertSame( array( '09', 9 ), array_keys( $repo->read_snapshot( 'albums' ) ) );
	}
}
