<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\OptionMigratedStore;
use PHPUnit\Framework\TestCase;

class LegacyBackendContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$GLOBALS['foogallery_migrate_test_serialized_reads'] = array();
	}
	protected function tearDown(): void {
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		unset( $GLOBALS['foogallery_migrate_test_option_writes'] );
		unset( $GLOBALS['foogallery_migrate_test_serialized_reads'] );
	}

	public function test_raw_inventory_reads_are_lossless_and_never_write(): void {
		$legacy = new \stdClass(); $legacy->extra_runtime_property = "\xff\x00";
		$compact = array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array( '09' => false, 9 => null ) );
		$data = array( 'plugins' => array( $legacy ), 'galleries' => $compact, 'galleries-state' => false,
			'albums' => array( $legacy ), 'albums-state' => null, 'migrated' => $compact, 'migrated-revision' => 4,
			'image-tag-sync' => array( 'items' => array( '09' => false ), 'cursor' => 0 ), 'wordpress-core-mode' => 'media',
			'block-shortcode_scan_state' => array( 'items' => array( $legacy ), 'checkpoint' => false ),
			'block-shortcode' => array( '09' => $legacy ), 'block-shortcode_scan_progress' => null, 'unknown' => "\xff\x00" );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $data;
		$backend = new LegacyOptionBackend();
		foreach ( $data as $key => $value ) { $this->assertSame( array( 'found' => true, 'value' => $value ), $backend->read( $key ) ); }
		$this->assertSame( array( 'found' => false, 'value' => null ), $backend->read( 'missing' ) );
		$this->assertSame( $data, $backend->read_all() );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_atomic_raw_mutation_preserves_order_unknowns_and_same_value_success(): void {
		$backend = new LegacyOptionBackend();
		$this->assertSame( array(), $backend->read_all() );
		$this->assertFalse( $backend->exists() );
		$initial = array( 'unknown' => "\xff\x00", 'history' => array( '09' => false ), 'revision' => 1 );
		$this->assertTrue( $backend->replace_all( $initial ) );
		$this->assertTrue( $backend->exists() );
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$mutate = function ( $data ) { $data['history']['new'] = null; $data['revision']++; return $data; };
		$this->assertTrue( $backend->mutate( $mutate ) );
		$this->assertSame( array( 'unknown', 'history', 'revision' ), array_keys( $backend->read_all() ) );
		$this->assertSame( array( array( FOOGALLERY_MIGRATE_OPTION_DATA, false ) ), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertTrue( $backend->mutate( function ( $data ) { return $data; } ) );
		$stable = $backend->read_all();
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( $backend->mutate( $mutate ) );
		$this->assertSame( $stable, $backend->read_all() );
	}

	public function test_malformed_root_and_declined_mutation_never_overwrite_evidence(): void {
		$backend = new LegacyOptionBackend();
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = false;
		$this->assertFalse( $backend->exists() );
		try { $backend->read( 'anything' ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'unreadable', $error->getMessage() ); }
		$this->assertFalse( $backend->mutate( function () { return array(); } ) );
		$this->assertFalse( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array();
		$this->assertFalse( $backend->mutate( function () { return false; } ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_facade_preserves_historical_runtime_reads_without_recompaction(): void {
		$legacy = new \stdClass(); $legacy->extra_runtime_property = 'must survive';
		$data = array( 'galleries' => array( $legacy ), 'unknown' => false, 'nullable' => null );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $data;
		$settings = new MigratorSettings();
		$this->assertSame( array( $legacy ), $settings->get_migrator_setting( 'galleries' ) );
		$this->assertFalse( $settings->get_migrator_setting( 'unknown', 'missing' ) );
		$this->assertNull( $settings->get_migrator_setting( 'nullable', 'missing' ) );
		$this->assertSame( 'missing', $settings->get_migrator_setting( 'absent', 'missing' ) );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_raw_multi_root_reads_preserve_request_order_and_presence(): void {
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'history' => array( '09' => null, 9 => false ) );
		$backend = new LegacyOptionBackend();
		$this->assertSame( array(
			array( 'found' => true, 'key' => 9, 'value' => false ),
			array( 'found' => false, 'key' => 'missing', 'value' => null ),
			array( 'found' => true, 'key' => '09', 'value' => null ),
		), $backend->roots( 'history', array( 9, 'missing', '09' ) ) );
		$this->assertSame( array(), $backend->roots( 'history', array() ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_serialized_object_readback_reports_success_and_preserves_unknowns(): void {
		$legacy = new \stdClass(); $legacy->unknown = array( '09' => null, 9 => false );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'legacy' => $legacy );
		$GLOBALS['foogallery_migrate_test_serialized_reads'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$settings = new MigratorSettings();
		$this->assertTrue( $settings->set_migrator_settings( array( 'checkpoint' => 9 ) ) );
		$this->assertSame( serialize( $legacy ), serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['legacy'] ) );
		$this->assertTrue( $settings->set_migrator_settings( array( 'checkpoint' => 9 ) ) );
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( $settings->set_migrator_settings( array( 'checkpoint' => 10 ) ) );
	}

	public function test_history_noop_preserves_absence_and_succeeds_without_a_write(): void {
		$store = new OptionMigratedStore();
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertTrue( $store->clear() );
		$this->assertTrue( $store->delete_for_retry( 'missing' ) );
		$this->assertFalse( ( new LegacyOptionBackend() )->exists() );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$this->assertTrue( $store->clear() );
		$this->assertFalse( ( new LegacyOptionBackend() )->exists() );
		$this->assertTrue( ( new MigratorSettings() )->clear_migrator_setting() );
		$this->assertTrue( ( new LegacyOptionBackend() )->exists() );
	}

	public function test_table_backend_construction_is_inert_and_has_no_full_state_api(): void {
		$db = new BackendTestConnection();
		$backend = new LegacyTableBackend( $db, 7 );
		$this->assertSame( array(), $db->queries );
		foreach ( array( 'read_all', 'replace_all', 'mutate' ) as $method ) {
			$this->assertFalse( method_exists( $backend, $method ) );
		}
	}
}

class BackendTestConnection extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $queries = array();
	public function __construct() {}
	public function get_results( $sql, $format ) { $this->queries[] = $sql; throw new \RuntimeException( 'Unexpected SQL.' ); }
}
