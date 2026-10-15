<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageCli;
use PHPUnit\Framework\TestCase;

class StorageCliTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
	}
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_nonce'] ); }
	private function error( $code, $operation, $flags ): void {
		$r = StorageCli::execute( $operation, $flags ); $this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 'foogallery_migrate_storage_' . $code, $r->get_error_code() );
	}
	public function test_capability_precedes_any_status_or_mutation(): void {
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		foreach ( array( 'status', 'preflight', 'switch', 'recover', 'replace' ) as $op ) { $this->error( 'forbidden', $op, array() ); }
	}
	public function test_unknown_flags_or_missing_exact_confirmation_cannot_reach_storage(): void {
		foreach ( array( array(), array( 'target' => 'table' ), array( 'target' => 'table', 'confirm' => 'yes' ), array( 'target' => 'table', 'confirm' => 'switch-to-table', 'policy' => array() ), array( 'target' => 'table', 'confirm' => 'switch-to-table', 'blog_id' => 1 ) ) as $flags ) { $this->error( 'invalid_request', 'switch', $flags ); }
		$this->error( 'invalid_request', 'purge', array() );
		$this->error( 'invalid_request', 'status', array( 'force' => true ) );
		$this->error( 'invalid_request', 'preflight', array( 'target' => 'option', 'confirm' => 'switch-to-option' ) );
	}
	public function test_cli_does_not_bypass_receiver_calibration(): void {
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_7', 'value' => 'nonce-foogallery_migrate_storage_option_import_7', 'result' => 1 );
		$this->error( 'calibration_required', 'switch', array( 'target' => 'table', 'confirm' => 'switch-to-table' ) );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_recover_7', 'value' => 'nonce-foogallery_migrate_storage_recover_7', 'result' => 1 );
		$this->error( 'calibration_required', 'recover', array( 'target' => 'table', 'confirm' => 'recover-storage' ) );
	}
}
