<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use PHPUnit\Framework\TestCase;

class LegacyHistoryMutationContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_option_writes'] ); }

	public function test_upsert_translates_objects_without_reading_options(): void {
		$settings = new MigratorSettings(); $backend = new HistoryMutationBackend();
		$history = new MigratedHistoryRepository( $backend, $settings );
		$image = new Image(); $image->source_url = 'https://example.test/New.jpg'; $image->migrated_id = 91;
		$backend->result = 2;
		$this->assertSame( 2, $history->upsert_many( array( array( 'object' => $image, 'parent_key' => 'Parent' ), $image ), array( 'queue' => false ) ) );
		$this->assertSame( array( 'upsert', array( array( 'key' => $image->unique_identifier(), 'value' => $settings->compact_migrated_object( $image ), 'parent' => 'Parent', 'fallback_parent' => null ),
			array( 'key' => $image->unique_identifier(), 'value' => $settings->compact_migrated_object( $image ), 'parent' => null, 'fallback_parent' => null ) ), array( 'queue' => false ) ), $backend->calls[0] );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_status_and_delete_use_exact_typed_keys(): void {
		$backend = new HistoryMutationBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$this->assertTrue( $history->set_status( '09', '' ) ); $this->assertTrue( $history->delete( 9 ) );
		$this->assertSame( array( array( 'status', array( 'key' => '09', 'status' => '' ), array() ), array( 'delete', array( 'key' => 9 ), array() ) ), $backend->calls );
		$this->assertTrue( $history->manages_revision() );
	}

	public function test_upsert_retains_existing_parent_fallback_when_no_explicit_parent_is_passed(): void {
		$backend = new HistoryMutationBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$image = new Image(); $image->source_url = 'https://example.test/Fallback.jpg'; $image->migration_parent_key = 'Prior Parent';
		$this->assertTrue( $history->upsert( $image ) );
		$this->assertSame( null, $backend->calls[0][1][0]['parent'] );
		$this->assertSame( 'Prior Parent', $backend->calls[0][1][0]['fallback_parent'] );
	}

	public function test_logical_batch_larger_than_one_sql_window_preserves_input_order_and_duplicates(): void {
		$backend = new HistoryMutationBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$records = array();
		for ( $i = 0; $i < 204; ++$i ) {
			$image = new Image(); $image->source_url = 'https://example.test/batch-' . $i . '.jpg'; $records[] = $image;
		}
		$records[] = $records[0];
		$backend->result = 205;
		$this->assertSame( 205, $history->upsert_batch( $records ) );
		$this->assertCount( 1, $backend->calls );
		$this->assertCount( 205, $backend->calls[0][1] );
		$this->assertSame( $records[0]->unique_identifier(), $backend->calls[0][1][0]['key'] );
		$this->assertSame( $records[0]->unique_identifier(), $backend->calls[0][1][204]['key'] );
		$this->assertSame( $records[203]->unique_identifier(), $backend->calls[0][1][203]['key'] );
	}

	public function test_invalid_batches_refuse_before_backend_calls(): void {
		$backend = new HistoryMutationBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$this->assertSame( 0, $history->upsert_batch( array() ) );
		$this->assertFalse( $history->upsert_batch( array( false ) ) );
		$this->assertFalse( $history->upsert_batch( array(), array( 'migrated-revision' => 0 ) ) );
		$this->assertFalse( $history->set_status( '09', false ) );
		foreach ( array( function () use ( $history ) { $history->delete( '9' ); },
			function () use ( $history ) { $history->set_status( '09', str_repeat( 'x', 33 ) ); },
			function () use ( $history ) { $history->upsert_batch( array_fill( 0, 10001, new Image() ) ); } ) as $call ) {
			try { $call(); $this->fail( 'Expected admission refusal.' ); } catch ( \RuntimeException $error ) { $this->assertSame( array(), $backend->calls ); }
		}
		$this->assertSame( array(), $backend->calls );
	}

	public function test_cumulative_compact_request_refuses_before_backend_call(): void {
		$backend = new HistoryMutationBackend(); $history = new MigratedHistoryRepository( $backend, new MigratorSettings() );
		$records = array();
		for ( $i = 0; $i < 40; ++$i ) {
			$image = new Image(); $image->source_url = 'https://example.test/' . str_repeat( 'x', 240000 ) . $i;
			$records[] = $image;
		}
		try { $history->upsert_batch( $records ); $this->fail( 'Expected cumulative byte refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		$this->assertSame( array(), $backend->calls );
	}

	public function test_option_behavior_stays_owned_by_option_store(): void {
		$history = new MigratedHistoryRepository( new LegacyOptionBackend(), new MigratorSettings() );
		$image = new Image(); $image->source_url = 'https://example.test/Option.jpg';
		$this->assertSame( 1, $history->upsert( $image ) ); $this->assertSame( 1, $history->revision() );
		$this->assertSame( 1, $history->upsert( $image ) ); $this->assertSame( 1, $history->revision() );
		$this->assertTrue( $history->set_status( $image->unique_identifier(), 'custom-unbounded-option-status-value' ) );
		$this->assertSame( 2, $history->revision() );
	}
}

/** Translation spy only; transactional behavior is exercised on real WordPress/MariaDB. */
class HistoryMutationBackend extends LegacyTableBackend {
	public $calls = array(); public $result = true;
	public function __construct() {}
	public function history_mutate( $operation, $request, $companions = array() ) {
		$this->calls[] = array( $operation, $request, $companions ); return $this->result;
	}
}
