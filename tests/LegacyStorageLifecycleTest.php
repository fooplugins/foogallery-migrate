<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/legacy-runtime-contract-fixtures.php';

/** Lifecycle regressions; real blog/table coverage lives in the guarded harness. */
class LegacyStorageLifecycleTest extends TestCase {
	private $saved_globals;

	protected function setUp(): void {
		$this->saved_globals = array();
		foreach ( array( 'wpdb', 'foogallery_migrate_test_options', 'foogallery_migrate_test_option_writes',
			'foogallery_migrate_test_dbdelta', 'foogallery_migrate_test_is_multisite',
			'foogallery_migrate_test_site_ids', 'foogallery_migrate_test_blog_prefix_stack' ) as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) { $this->saved_globals[$key] = $GLOBALS[$key]; }
		}
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$GLOBALS['foogallery_migrate_test_dbdelta'] = array();
		$GLOBALS['foogallery_migrate_test_blog_prefix_stack'] = array();
	}

	protected function tearDown(): void {
		foreach ( array( 'wpdb', 'foogallery_migrate_test_options', 'foogallery_migrate_test_option_writes',
			'foogallery_migrate_test_dbdelta', 'foogallery_migrate_test_is_multisite',
			'foogallery_migrate_test_site_ids', 'foogallery_migrate_test_blog_prefix_stack' ) as $key ) {
			if ( array_key_exists( $key, $this->saved_globals ) ) { $GLOBALS[$key] = $this->saved_globals[$key]; }
			else { unset( $GLOBALS[$key] ); }
		}
	}

	public static function scope_provider(): array {
		return array( 'single-site' => array( false, false, 'wp_' ),
			'network' => array( true, true, 'wp_' ),
			'network-subsite' => array( true, true, 'wp_7_' ),
			'site-on-network' => array( true, false, 'wp_7_' ) );
	}

	/** @dataProvider scope_provider */
	public function test_complete_options_and_partial_authority_survive_round_trip( $multisite, $network, $prefix ): void {
		$GLOBALS['foogallery_migrate_test_is_multisite'] = $multisite;
		$GLOBALS['foogallery_migrate_test_site_ids'] = array( 2, 7 );
		$GLOBALS['wpdb'] = new LifecycleUnavailableDatabase( $prefix );
		$fixture = \FooPlugins\FooGalleryMigrate\Tests\Fixtures\legacy_runtime_contract_fixture();
		$data = $fixture['logical'];
		$data['queue'] = array( 'gallery_AIGPL_204' => array( 'status' => 'started', 'cursor' => 27, 'selected' => true ) );
		$GLOBALS['foogallery_migrate_test_options'] = array(
			FOOGALLERY_MIGRATE_OPTION_DATA => $data,
			FOOGALLERY_MIGRATE_OPTION_SETTINGS => array( 'template' => 'default', 'unknown' => null ),
			MigratedStore::LEGACY_BACKUP_OPTION => array( 'stale' => 'not current history' ),
			MigratedStore::SCHEMA_OPTION => 1, MigratedStore::MIGRATION_OPTION => array( 'phase' => 'failed' ),
			StorageAuthority::CONTROL_OPTION => array( 'backend' => 'table', 'phase' => 'interrupted', 'epoch' => 9 ),
			StorageAuthority::AUTHORITY_OPTION => array( 'epoch' => 8 ),
			StorageAuthority::MAINTENANCE_OPTION => array( 'token' => 'retained-owner' ),
			'foogallery-migrate-data-storage-backup' => array( 'immutable' => false ),
			'foogallery_migrate_storage_writer_fixture' => array( 'token' => 'writer-owner' ),
			'unrelated-option' => 'keep',
		);
		$before = serialize( $GLOBALS['foogallery_migrate_test_options'] );
		for ( $i = 0; $i < 2; $i++ ) {
			MigratedStore::activate( $network );
			$this->assertTrue( MigratedStore::install_current_site() );
			MigratedStore::deactivate( $network );
			MigratedStore::activate( $network );
			$this->assertTrue( ( new MigratedStore( $GLOBALS['wpdb'], new MigratorSettings() ) )->deactivate_schema() );
			$this->assertSame( $before, serialize( $GLOBALS['foogallery_migrate_test_options'] ) );
		}
		$this->assertSame( $prefix, $GLOBALS['wpdb']->prefix );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_blog_prefix_stack'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_dbdelta'] );
	}

	public function test_virgin_lifecycle_does_not_create_options_without_a_database(): void {
		unset( $GLOBALS['wpdb'] );
		foreach ( array( false, true ) as $network ) {
			MigratedStore::activate( $network );
			MigratedStore::deactivate( $network );
			$this->assertTrue( MigratedStore::install_current_site() );
		}
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_dbdelta'] );
	}

	public function test_corrupt_raw_option_is_retained_without_decoding_or_repair(): void {
		$GLOBALS['wpdb'] = new LifecycleUnavailableDatabase( 'wp_7_' );
		$GLOBALS['foogallery_migrate_test_options'] = array( FOOGALLERY_MIGRATE_OPTION_DATA => 'not an array',
			StorageAuthority::CONTROL_OPTION => false, StorageAuthority::AUTHORITY_OPTION => null );
		$before = $GLOBALS['foogallery_migrate_test_options'];
		MigratedStore::deactivate( true );
		MigratedStore::activate( true );
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
}

/** Any lifecycle SQL use fails, including reads, reconnection or schema inspection. */
class LifecycleUnavailableDatabase {
	public $prefix;
	public $base_prefix = 'wp_';
	public function __construct( $prefix ) { $this->prefix = $prefix; }
	public function __call( $name, $args ) { throw new \RuntimeException( 'Lifecycle used unavailable database: ' . $name ); }
}
