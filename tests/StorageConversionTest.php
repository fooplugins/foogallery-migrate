<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use PHPUnit\Framework\TestCase;

class StorageConversionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new ConversionNoSqlWpdb();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_1', 'value' => 'valid', 'result' => 1 );
		unset( $GLOBALS['foogallery_migrate_engine_instance'] );
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_nonce'], $GLOBALS['foogallery_migrate_engine_instance'] );
	}
	private function request() { return array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => 'valid' ); }

	public function test_unauthorized_switch_and_recovery_never_touch_sql(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$manager = new StorageConversion();
		$this->assertSame( 'foogallery_migrate_storage_forbidden', $manager->switch_backend( $this->request() )->get_error_code() );
		$this->assertSame( 'foogallery_migrate_storage_forbidden', $manager->recover( $this->request() )->get_error_code() );
	}

	public function test_invalid_scope_site_confirmation_nonce_and_request_policy_never_touch_sql(): void {
		foreach ( array( 'scope' => 'network', 'blog_id' => 2, 'target' => 'attacker_table', 'confirmation' => 'yes', 'nonce' => 'other' ) as $key => $value ) {
			$r = $this->request(); $r[$key] = $value;
			$this->assertInstanceOf( \WP_Error::class, ( new StorageConversion() )->switch_backend( $r ) );
		}
		$r = $this->request(); $r['policy'] = array( 'max_raw_bytes' => PHP_INT_MAX );
		$this->assertInstanceOf( \WP_Error::class, ( new StorageConversion() )->switch_backend( $r ) );
	}

	public function test_reverse_and_recovery_use_distinct_purpose_nonces(): void {
		$r = $this->request(); $r['target'] = 'option'; $r['confirmation'] = 'switch-to-option';
		$this->assertSame( 'foogallery_migrate_storage_invalid_nonce', ( new StorageConversion() )->switch_backend( $r )->get_error_code() );
		$r['confirmation'] = 'recover-storage';
		$this->assertSame( 'foogallery_migrate_storage_invalid_nonce', ( new StorageConversion() )->recover( $r )->get_error_code() );
	}

	public function test_no_engine_means_no_request_local_work_but_injected_or_opaque_engine_cannot_affirm(): void {
		$this->assertTrue( MigratorEngine::storage_maintenance_is_quiescent() );
		$GLOBALS['foogallery_migrate_engine_instance'] = new \stdClass();
		$this->assertFalse( MigratorEngine::storage_maintenance_is_quiescent() );
		$backend = new LegacyOptionBackend();
		$GLOBALS['foogallery_migrate_engine_instance'] = new MigratorEngine( new MigratedHistoryRepository( $backend, new \FooPlugins\FooGalleryMigrate\MigratorSettings( $backend ) ) );
		$this->assertFalse( MigratorEngine::storage_maintenance_is_quiescent() );
	}
}

class ConversionNoSqlWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public function __call( $name, $args ) { throw new \LogicException( 'Unauthorized storage SQL: ' . $name ); }
}
