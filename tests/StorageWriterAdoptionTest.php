<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\Init;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageMaintenanceGateTest.php';

class StorageWriterAdoptionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$GLOBALS['foogallery_migrate_test_options'] = array( FOOGALLERY_MIGRATE_OPTION_DATA => array( 'retained' => null ) );
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_engine_instance'] = new \stdClass();
		$_POST = $_REQUEST = array();
	}
	protected function tearDown(): void {
		StorageMaintenanceGate::current()->shutdown_writer();
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_engine_instance'], $GLOBALS['foogallery_migrate_test_capabilities'] );
		$_POST = $_REQUEST = array();
	}
	public static function endpoints(): array {
		$result = array();
		foreach ( array( 'ajax_start_migration', 'ajax_continue_migration', 'ajax_cancel_migration',
			'ajax_refresh_migration', 'ajax_retry_gallery_migration', 'ajax_check_gallery_errors', 'ajax_sync_image_tags',
			'ajax_start_album_migration', 'ajax_continue_album_migration', 'ajax_cancel_album_migration',
			'ajax_refresh_album_migration', 'ajax_replace_content', 'ajax_refresh_content', 'ajax_refresh_content_status',
			'ajax_update_migrated_status', 'ajax_delete_migrated_object', 'save_settings', 'detect_wordpress_core_galleries',
			'migrate_content_no_js', 'refresh_content_no_js', 'refresh_content_status_no_js' ) as $method ) {
			$result[$method] = array( $method );
		}
		return $result;
	}
	/** @dataProvider endpoints */
	public function test_maintenance_refuses_every_endpoint_before_engine_access( $method ): void {
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = '';
		$this->assert_endpoint_denied( $method );
	}
	/** @dataProvider endpoints */
	public function test_control_refuses_every_endpoint_before_engine_access( $method ): void {
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::CONTROL_OPTION] = new AuthorityWakeupTrap();
		$this->assert_endpoint_denied( $method );
	}
	/** @dataProvider endpoints */
	public function test_maintenance_racing_writer_insert_refuses_every_endpoint( $method ): void {
		$GLOBALS['wpdb']->before_write = function ( $sql ) {
			if ( 0 === strpos( $sql, 'INSERT' ) ) { $GLOBALS['foogallery_migrate_test_options'][StorageAuthority::MAINTENANCE_OPTION] = 'racing-owner'; }
		};
		$this->assert_endpoint_denied( $method, array( StorageAuthority::MAINTENANCE_OPTION => 'racing-owner' ) );
	}
	private function assert_endpoint_denied( $method, $expected_additions = array() ): void {
		$_POST = array( 'object_id' => 'retained', 'status' => 'completed', 'foogallery_migrate_retry_gallery_id' => 'retained', 'foogallery_migrate_check_gallery_id' => 'retained', 'wordpress_gallery_mode' => 'create' );
		$_REQUEST['action'] = str_replace( array( 'ajax_continue_migration', 'ajax_cancel_migration', 'ajax_refresh_migration', 'ajax_continue_album_migration', 'ajax_cancel_album_migration', 'ajax_refresh_album_migration' ), array( 'foogallery_migrate_continue', 'foogallery_migrate_cancel', 'foogallery_migrate_refresh', 'foogallery_album_migrate_continue', 'foogallery_album_migrate_cancel', 'foogallery_album_migrate_refresh' ), $method );
		$before = $GLOBALS['foogallery_migrate_test_options'];
		$init = ( new \ReflectionClass( Init::class ) )->newInstanceWithoutConstructor();
		try { $init->$method(); $this->fail( 'Endpoint must terminate before engine access.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $error ) {
			$this->assertSame( 503, $error->status_code );
			$this->assertStringContainsString( 'storage', $error->getMessage() );
		} catch ( \FooGalleryMigrateTestDie $error ) {
			$this->assertStringContainsString( 'storage', $error->getMessage() );
		}
		StorageMaintenanceGate::current()->shutdown_writer();
		$this->assertSame( array_merge( $before, $expected_additions ), $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_default_engine_rechecks_authority_before_later_write(): void {
		$engine = new MigratorEngine();
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::CONTROL_OPTION] = '';
		$before = $GLOBALS['foogallery_migrate_test_options'];
		try { $engine->set_migrator_setting( 'new', true ); $this->fail( 'Stale engine must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'recovery', $error->getMessage() ); }
		StorageMaintenanceGate::current()->shutdown_writer();
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_default_engine_admits_one_request_writer_and_gate_waits_for_it(): void {
		$engine = new MigratorEngine();
		$this->assertTrue( $engine->set_migrator_setting( 'new', true ) );
		$gate = StorageMaintenanceGate::current();
		$this->assertTrue( $gate->assert_writer() );
		$this->assertTrue( $gate->acquire_maintenance() );
		$this->assertInstanceOf( \WP_Error::class, $gate->maintenance_ready( array( $engine, 'storage_is_quiescent' ) ) );
		$this->assertTrue( $engine->set_migrator_setting( 'admitted', true ) );
		$gate->shutdown_writer();
		$this->assertTrue( $gate->maintenance_ready( array( $engine, 'storage_is_quiescent' ) ) );
		$this->assertTrue( $gate->release_maintenance() );
	}
	public function test_authority_sql_does_not_hydrate_control_payload(): void {
		$GLOBALS['foogallery_migrate_test_options'][StorageAuthority::CONTROL_OPTION] = new AuthorityWakeupTrap();
		$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error() );
	}
	public function test_pending_batch_is_not_a_quiescent_engine(): void {
		$engine = new MigratorEngine();
		$engine->begin_migrated_object_batch();
		$gate = StorageMaintenanceGate::current();
		$this->assertTrue( $gate->acquire_maintenance() );
		$gate->shutdown_writer();
		$this->assertFalse( $engine->storage_is_quiescent() );
		$this->assertInstanceOf( \WP_Error::class, $gate->maintenance_ready( array( $engine, 'storage_is_quiescent' ) ) );
		$this->assertTrue( $gate->release_maintenance() );
	}
	public function test_cached_engine_cannot_write_after_blog_switch(): void {
		$engine = new MigratorEngine();
		$before = $GLOBALS['foogallery_migrate_test_options'];
		$GLOBALS['foogallery_migrate_test_blog_id'] = 2;
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		try {
			$this->assertFalse( $engine->storage_is_quiescent() );
			try { $engine->set_migrator_setting( 'wrong_blog', true ); $this->fail( 'Cached engine must refuse.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'storage', $error->getMessage() ); }
			$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
		} finally { $GLOBALS['foogallery_migrate_test_blog_id'] = 1; $GLOBALS['wpdb']->prefix = 'wp_'; }
	}
	public function test_expired_writer_is_never_replaced_by_fresh_admission(): void {
		$engine = new MigratorEngine();
		$this->assertTrue( $engine->set_migrator_setting( 'first', true ) );
		foreach ( $GLOBALS['foogallery_migrate_test_options'] as $name => $raw ) {
			if ( 0 === strpos( $name, StorageMaintenanceGate::WRITER_PREFIX ) ) {
				$marker = json_decode( $raw, true ); $marker['expires'] = 1;
				$GLOBALS['foogallery_migrate_test_options'][$name] = json_encode( $marker );
			}
		}
		$before = $GLOBALS['foogallery_migrate_test_options'];
		try { $engine->set_migrator_setting( 'second', true ); $this->fail( 'Lost writer must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'storage', $error->getMessage() ); }
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
	}
	public function test_backend_requires_strict_affirmative_admission(): void {
		$backend = new \FooPlugins\FooGalleryMigrate\LegacyOptionBackend( function () { return 1; } );
		$before = $GLOBALS['foogallery_migrate_test_options'];
		try { $backend->replace_all( array( 'changed' => true ) ); $this->fail( 'Truthy callback must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'not admitted', $error->getMessage() ); }
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'] );
	}
}
class AuthorityWakeupTrap {
	public function __wakeup() { throw new \RuntimeException( 'Control was hydrated.' ); }
}
