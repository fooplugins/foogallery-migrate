<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCleanup;
use FooPlugins\FooGalleryMigrate\StorageDiscoverySummary;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageCleanupTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CleanupExceptionBoundaryTest extends TestCase {

	/** @dataProvider resources */
	public function test_successful_sql_with_failed_readback_returns_a_plain_failure_receipt( $kind, $name ): void {
		$GLOBALS['wpdb'] = new CleanupRetainedResourceWpdb( $kind );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_remove_all_1', 'value' => 'remove-nonce', 'result' => 1 );
		$payload = '<img id="cleanup-value" src=x onerror="alert(1)">';
		$GLOBALS['foogallery_migrate_test_options'] = array( StorageDiscoverySummary::OPTION => $payload );
		$GLOBALS['wpdb']->tables = array( 'wp_' . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX => true );
		ob_start();
		try {
			$receipt = StorageCleanup::dispatch( 'remove_all', array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'all',
				'confirmation' => 'remove-all-internal-data', 'nonce' => 'remove-nonce' ) );
			$output = ob_get_clean();
		} catch ( \Throwable $error ) { ob_end_clean(); throw $error; }
		$this->assertSame( '', $output );
		$this->assertFalse( $receipt['complete'] );
		$this->assertContains( 'Owned ' . $kind . ' remained: ' . $name, $receipt['failed'] );
		$this->assertContains( $name, $receipt['residual'] );
		$this->assertStringNotContainsString( $payload, json_encode( $receipt ), 'Resource values never enter cleanup diagnostics.' );
	}

	public static function resources(): array {
		return array( array( 'option', StorageDiscoverySummary::OPTION ), array( 'table', 'wp_' . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX ) );
	}
}

/** A successful SQL result is deliberately contradicted by readback. */
class CleanupRetainedResourceWpdb extends CleanupTestWpdb {
	private $kind;
	public function __construct( $kind ) { $this->kind = $kind; }
	public function query( $sql ) {
		if ( ( 'table' === $this->kind && 'DROP TABLE `wp_' . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX . '`' === $sql ) ||
			( 'option' === $this->kind && 0 === strpos( $sql, 'DELETE FROM `wp_options`' ) && false !== strpos( $sql, "option_name='" . StorageDiscoverySummary::OPTION . "'" ) ) ) {
			$this->last_error = ''; return 1;
		}
		return parent::query( $sql );
	}
}
