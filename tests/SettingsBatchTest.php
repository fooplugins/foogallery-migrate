<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

class SettingsBatchTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void {
		$GLOBALS['foogallery_migrate_test_failed_options'] = array();
		unset( $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_multi_key_write_preserves_order_unknowns_and_presence_in_one_nonautoload_update(): void {
		$initial = array( 'unknown' => array( '09' => "\xff\x00", 9 => null ), 'migrated' => array(), 'checkpoint' => false );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $initial;
		$settings = new MigratorSettings();
		$this->assertTrue( $settings->set_migrator_settings( array( 'checkpoint' => null, 'new' => false, 'migrated' => array( '09' => false ) ) ) );
		$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		$this->assertSame( array( 'unknown', 'migrated', 'checkpoint', 'new', 'migrated-revision' ), array_keys( $data ) );
		$this->assertSame( $initial['unknown'], $data['unknown'] );
		$this->assertNull( $data['checkpoint'] ); $this->assertFalse( $data['new'] );
		$this->assertSame( 1, $data['migrated-revision'] );
		$this->assertSame( array( array( FOOGALLERY_MIGRATE_OPTION_DATA, false ) ), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$this->assertTrue( $settings->set_migrator_settings( array( 'checkpoint' => null, 'new' => false, 'migrated' => array( '09' => false ) ) ) );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}

	public function test_failed_batch_cannot_publish_history_revision_or_checkpoint(): void {
		$initial = array( 'migrated' => array(), 'checkpoint' => false );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $initial;
		$GLOBALS['foogallery_migrate_test_failed_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = true;
		$this->assertFalse( ( new MigratorSettings() )->set_migrator_settings( array( 'migrated' => array( 'a' => false ), 'checkpoint' => 7 ) ) );
		$this->assertSame( $initial, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}

	public function test_batch_refuses_malformed_root_and_explicit_revision_collision_without_write(): void {
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = false;
		$this->assertFalse( ( new MigratorSettings() )->set_migrator_settings( array( 'a' => null ) ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array();
		$this->assertFalse( ( new MigratorSettings() )->set_migrator_settings( array( 'migrated' => array(), 'migrated-revision' => 20 ) ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}
}
