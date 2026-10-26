<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageAuthorityTest.php';

class StorageRuntimeTransportTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new AuthorityTestWpdb();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_options'] = array();
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id'] );
	}

	public function test_runtime_authority_cannot_use_healthy_wpdb_after_its_writer_pin_is_lost(): void {
		$gate = StorageMaintenanceGate::current();
		$pin = new AuthorityTestWpdb();
		$property = new \ReflectionProperty( $gate, 'connection' );
		$property->setValue( $gate, $pin );
		$this->assertNull( StorageAuthority::runtime_record( $gate ) );
		$pin->current = false;
		$GLOBALS['wpdb']->reads = array();
		$this->assertInstanceOf( \WP_Error::class, StorageAuthority::runtime_record( $gate ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->reads, 'No authority read/reconnect through replacement wpdb.' );
	}

	public function test_options_authority_cannot_fall_back_after_writer_pin_loss(): void {
		$gate = StorageMaintenanceGate::current();
		$pin = new AuthorityTestWpdb();
		$property = new \ReflectionProperty( $gate, 'connection' );
		$property->setValue( $gate, $pin );
		$this->assertFalse( StorageAuthority::option_mode_error( $gate ) );
		$pin->current = false;
		$this->assertInstanceOf( \WP_Error::class, StorageAuthority::option_mode_error( $gate ) );
	}

	public function test_engine_cannot_affirm_drain_after_its_shared_transport_is_unusable(): void {
		$engine = new MigratorEngine();
		$this->assertTrue( $engine->storage_is_quiescent() );
		$GLOBALS['wpdb']->current = false;
		$this->assertFalse( $engine->storage_is_quiescent() );
	}
}
