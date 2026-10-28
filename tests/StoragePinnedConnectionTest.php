<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;

class StoragePinnedConnectionTest extends TestCase {
	private $previous_db;
	private $previous_blog;
	protected function setUp(): void {
		$this->previous_db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$this->previous_blog = isset( $GLOBALS['foogallery_migrate_test_blog_id'] ) ? $GLOBALS['foogallery_migrate_test_blog_id'] : null;
	}
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_db;
		$GLOBALS['foogallery_migrate_test_blog_id'] = $this->previous_blog;
	}
	public function test_gate_captures_one_transport_and_never_reacquires_after_loss(): void {
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$gate = StorageMaintenanceGate::current();
		$this->assertSame( $GLOBALS['wpdb'], $gate->connection() );
		$GLOBALS['wpdb']->current = false;
		try { $gate->connection(); $this->fail( 'Lost transport must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'connection', $error->getMessage() ); }
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_scalar_reads_preserve_empty_and_zero_but_distinguish_absence(): void {
		$db = new PinnedScalarTestConnection();
		foreach ( array( '' => '', '0' => '0', 'v:' => 'v:' ) as $input => $expected ) {
			$db->rows = array( array( 'value' => (string) $input ) );
			$this->assertSame( $expected, $db->get_var( 'SELECT fixture' ) );
		}
		$db->rows = array(); $this->assertNull( $db->get_var( 'SELECT fixture' ) );
		$db->rows = null; $db->last_error = 'failed read';
		$this->assertNull( $db->get_var( 'SELECT fixture' ) );
		$this->assertSame( 'failed read', $db->last_error );
	}
}

class PinnedScalarTestConnection extends LegacyTransactionConnection {
	public $rows;
	public function __construct() {}
	public function get_results( $sql, $format ) { return $this->rows; }
}
