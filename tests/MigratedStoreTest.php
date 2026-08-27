<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;
use PHPUnit\Framework\TestCase;

class MigratedStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_dbdelta'] = array();
	}

	public function test_schema_uses_site_prefix_and_required_indexes(): void {
		$wpdb = new RecordingStoreWpdb( 'wp_7_' );
		$store = new MigratedStore( $wpdb, new MigratorSettings() );

		$this->assertTrue( $store->install_schema() );
		$this->assertSame( 'wp_7_foogallery_migrate_objects', $store->table_name() );
		$this->assertCount( 1, $GLOBALS['foogallery_migrate_test_dbdelta'] );

		$sql = $GLOBALS['foogallery_migrate_test_dbdelta'][0];
		$this->assertStringContainsString( 'object_key varchar(191) NOT NULL', $sql );
		$this->assertStringContainsString( 'parent_key varchar(191) DEFAULT NULL', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (object_key)', $sql );
		$this->assertStringContainsString( 'KEY parent_key (parent_key)', $sql );
		$this->assertStringContainsString( 'KEY object_type (object_type)', $sql );
		$this->assertStringContainsString( 'KEY plugin_name (plugin_name)', $sql );
		$this->assertStringContainsString( 'KEY status (status)', $sql );
	}

	public function test_batch_upsert_uses_one_database_write_for_many_records(): void {
		$wpdb = new RecordingStoreWpdb();
		$store = new MigratedStore( $wpdb, new MigratorSettings() );
		$plugin = new StoreSourcePlugin();
		$records = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$records[] = array(
				'object'     => $this->image( $plugin, 'https://example.test/' . $i . '.jpg', 1000 + $i ),
				'parent_key' => 'gallery_StoreSource_204',
			);
		}

		$this->assertSame( 5, $store->upsert_batch( $records ) );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringContainsString( 'INSERT INTO wp_foogallery_migrate_objects', $wpdb->queries[0] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $wpdb->queries[0] );

		$diagnostics = $store->diagnostics();
		$this->assertSame( 1, $diagnostics['write_queries'] );
		$this->assertSame( 5, $diagnostics['rows_written'] );
	}

	public function test_single_lookup_is_constant_and_long_identifiers_are_hashed(): void {
		$store = new MigratedStore( null, new MigratorSettings() );
		$plugin = new StoreSourcePlugin();
		$long_url = 'https://example.test/' . str_repeat( 'nested-path/', 30 ) . 'image.jpg';
		$image = $this->image( $plugin, $long_url, 5001 );

		$this->assertSame( 1, $store->upsert_batch( array( array( 'object' => $image, 'parent_key' => null ) ) ) );
		$store->reset_diagnostics();

		$loaded = $store->get( $long_url );

		$this->assertInstanceOf( Image::class, $loaded );
		$this->assertSame( 5001, $loaded->migrated_id );
		$this->assertLessThanOrEqual( 191, strlen( $store->storage_key( $long_url ) ) );
		$this->assertSame( 1, $store->diagnostics()['read_queries'] );
		$this->assertSame( 0, $store->diagnostics()['full_history_hydrations'] );
	}

	public function test_legacy_compact_option_migrates_idempotently_before_removal(): void {
		$plugin = new StoreSourcePlugin();
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $plugin );
		$legacy = array(
			MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION,
			'type'                           => 'migratable',
			'items'                          => array(
				'https://example.test/legacy.jpg' => array(
					'object_type'     => 'image',
					'plugin_name'     => 'StoreSource',
					'ID'              => 91,
					'source_url'      => 'https://example.test/legacy.jpg',
					'migrated'        => true,
					'migrated_id'     => 9091,
					'migration_status' => 'completed',
				),
			),
		);
		$GLOBALS['foogallery_migrate_test_options'][ FOOGALLERY_MIGRATE_OPTION_DATA ] = array(
			'plugins'  => array(),
			'migrated' => $legacy,
		);

		$store = new MigratedStore( null, new MigratorSettings() );
		$this->assertTrue( $store->migrate_legacy() );
		$this->assertTrue( $store->migrate_legacy() );

		$active = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		$this->assertArrayNotHasKey( 'migrated', $active );
		$this->assertSame( $legacy, get_option( MigratedStore::LEGACY_BACKUP_OPTION ) );
		$this->assertInstanceOf( Image::class, $store->get( 'https://example.test/legacy.jpg' ) );
		$this->assertSame( 9091, $store->get( 'https://example.test/legacy.jpg' )->migrated_id );
		$this->assertSame( 1, $store->diagnostics()['legacy_hydrations'] );
	}

	public function test_failed_legacy_copy_keeps_original_option_untouched(): void {
		$legacy = array(
			MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION,
			'type'                           => 'migratable',
			'items'                          => array(
				'image-key' => array(
					'object_type' => 'image',
					'source_url'  => 'image-key',
				),
			),
		);
		$GLOBALS['foogallery_migrate_test_options'][ FOOGALLERY_MIGRATE_OPTION_DATA ] = array( 'migrated' => $legacy );

		$store = new FailingMigratedStore( null, new MigratorSettings() );

		$this->assertFalse( $store->migrate_legacy() );
		$this->assertSame( $legacy, get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated'] );
		$this->assertSame( $legacy, get_option( MigratedStore::LEGACY_BACKUP_OPTION ) );
	}

	private function image( StoreSourcePlugin $plugin, string $url, int $migrated_id ): Image {
		$image = new Image( $plugin );
		$image->ID = $migrated_id;
		$image->source_url = $url;
		$image->migrated = true;
		$image->migrated_id = $migrated_id;
		$image->migration_status = 'completed';

		return $image;
	}
}

class FailingMigratedStore extends MigratedStore {
	public function upsert_batch( $records ) {
		return false;
	}
}

class RecordingStoreWpdb {
	public $prefix;
	public $queries = array();

	public function __construct( $prefix = 'wp_' ) {
		$this->prefix = $prefix;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );

		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[dfs]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}
}

class StoreSourcePlugin extends Plugin {
	public function name() {
		return 'StoreSource';
	}

	public function detect() {
		return true;
	}

	public function find_galleries() {
		return array();
	}

	public function find_albums() {
		return array();
	}

	public function get_gallery_template( $gallery ) {
		return 'default';
	}

	public function get_gallery_settings( $gallery, $default_settings ) {
		return $default_settings;
	}
}
