<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Translation/admission tests; real SQL and scope evidence lives in the WP harness. */
class LegacyHistorySummaryContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_option_writes'] ); }

	public function test_log_summary_dispatch_preserves_option_shape_and_does_not_hydrate(): void {
		$items = array( false, null, array( 'object_type' => 'unknown', 'migration_status' => 'pending' ),
			array( 'object_type' => 'image', 'migration_status' => 'error', 'error' => false ),
			array( 'object_type' => 'unknown', 'migration_status' => 'completed', 'error' => 'not a status error' ),
			array( 'object_type' => '', 'migration_status' => 'error' ) );
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'migrated' => array(
			MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => $items ), 'migrated-revision' => 5 );
		$settings = new SummaryNoHydrationSettings();
		$option = new MigratedHistoryRepository( new LegacyOptionBackend(), $settings );
		$expected = $option->log_summary();
		$this->assertSame( array( 'unknown' => 2, 'image' => 1 ), $expected['types'] );
		$this->assertSame( array( 'pending' => 1, 'error' => 1, 'completed' => 1 ), $expected['statuses'] );
		$table = new HistorySummaryBackend( $expected );
		$history = new MigratedHistoryRepository( $table, $settings );
		$this->assertSame( array(), $table->calls );
		$this->assertSame( $expected, $history->log_summary() );
		$this->assertSame( array( array( 'log_summary', array() ) ), $table->calls );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_option_keeps_missing_null_and_empty_status_semantics(): void {
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = array( 'migrated' => array(
			MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array(
				array( 'object_type' => 'image' ), array( 'object_type' => 'image', 'migration_status' => null ),
				array( 'object_type' => 'image', 'migration_status' => '' ) ) ) );
		$option = new MigratedHistoryRepository( new LegacyOptionBackend(), new MigratorSettings() );
		$this->assertSame( array( '' => 1 ), $option->log_summary()['statuses'] );
		$this->assertSame( 3, $option->log_summary()['total'] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_summary_validates_wrapper_and_revision_metadata_without_option_fallback(): void {
		foreach ( array( 'wrapper', 'revision' ) as $invalid ) {
			$table = new HistorySummaryBackend( array() ); $table->invalid = $invalid;
			$history = new MigratedHistoryRepository( $table, new MigratorSettings() );
			try { $history->log_summary(); $this->fail( 'Expected metadata refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'wrapper' === $invalid ? 'wrapper' : 'revision', $error->getMessage() ); }
		}
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_per_type_summary_remains_unadmitted(): void {
		$table = new HistorySummaryBackend( array() ); $history = new MigratedHistoryRepository( $table, new MigratorSettings() );
		$this->expectExceptionMessage( 'not admitted' ); $history->summary();
	}
}

class SummaryNoHydrationSettings extends MigratorSettings {
	public function hydrate_migrated_object( $record ) { throw new \RuntimeException( 'Summary must not hydrate.' ); }
}

class HistorySummaryBackend extends LegacyTableBackend {
	public $calls = array(); public $invalid; private $result;
	public function __construct( $result ) { $this->result = $result; }
	public function history_read( $operation, $request = array() ) {
		$this->calls[] = array( $operation, $request );
		$root = array( 'wrapper' => array() );
		if ( 'wrapper' === $this->invalid ) { $root = array(); }
		return array( 'context' => array(
			array( 'head' => array( 'state_kind' => 'map' ), 'root' => $root ),
			array( 'head' => array( 'state_kind' => 'scalar' ), 'root' => array( 'value' => 'revision' === $this->invalid ? -1 : 5 ) ) ), 'result' => $this->result );
	}
}
