<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageMaintenanceGateTest.php';

class StorageCallbackDrainTest extends TestCase {
	private $engine;
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new MaintenanceTestWpdb();
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_taxonomies'] = array( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG );
		$GLOBALS['foogallery_migrate_test_set_object_terms'] = array();
		$this->engine = new MigratorEngine();
		$GLOBALS['foogallery_migrate_engine_instance'] = $this->engine;
	}
	protected function tearDown(): void {
		StorageMaintenanceGate::current()->shutdown_writer();
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_engine_instance'], $GLOBALS['foogallery_migrate_test_terms_callback'] );
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_taxonomies'] = array();
	}
	private function source( $callback ) {
		return new DrainDetectionSource( $callback );
	}
	private function queue_tags(): void {
		$this->engine->set_migrator_setting( MigratorEngine::KEY_IMAGE_TAG_SYNC, array(
			'items' => array( array( 'attachment_id' => 501, 'tags' => array( 'first' ) ), array( 'attachment_id' => 502, 'tags' => array( 'second' ) ) ),
			'total' => 2, 'processed' => 0, 'updated' => 0,
		) );
	}
	public function test_source_callback_cannot_affirm_request_drain(): void {
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $this->source( function () {
			$this->assertFalse( $this->engine->storage_is_quiescent() );
			$this->assertFalse( MigratorEngine::storage_maintenance_is_quiescent() );
			return true;
		} ) );
		$this->engine->run_detection();
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	public function test_nested_normal_error_return_does_not_drain_outer_callback(): void {
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $this->source( function () {
			$this->assertTrue( is_wp_error( $this->engine->continue_image_tag_sync() ) );
			$this->assertFalse( $this->engine->storage_is_quiescent() );
			return true;
		} ) );
		$this->engine->run_detection();
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	/** @dataProvider callback_errors */
	public function test_callback_exception_unwinds_operation_depth( $error ): void {
		$GLOBALS['foogallery_migrate_test_plugins'] = array( $this->source( function () use ( $error ) { throw $error; } ) );
		try { $this->engine->run_detection(); $this->fail( 'Callback must throw.' ); }
		catch ( \Throwable $caught ) { $this->assertSame( $error, $caught ); }
		$this->assertTrue( $this->engine->storage_is_quiescent() );
		$this->assertFalse( array_key_exists( FOOGALLERY_MIGRATE_OPTION_DATA, $GLOBALS['foogallery_migrate_test_options'] ) );
	}
	public static function callback_errors(): array { return array( array( new \RuntimeException( 'source failure' ) ), array( new \Error( 'source error' ) ) ); }
	public function test_source_pin_loss_stops_next_callback_and_state_write(): void {
		$calls = 0;
		$GLOBALS['foogallery_migrate_test_plugins'] = array(
			$this->source( function () { $GLOBALS['wpdb']->current = false; return true; } ),
			$this->source( function () use ( &$calls ) { $calls++; return true; } ),
		);
		try { $this->engine->run_detection(); $this->fail( 'Uncertain pin must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 0, $calls ); }
		$this->assertFalse( $this->engine->storage_is_quiescent() );
		$this->assertFalse( array_key_exists( FOOGALLERY_MIGRATE_OPTION_DATA, $GLOBALS['foogallery_migrate_test_options'] ) );
	}
	public function test_real_engine_term_callback_cannot_affirm_drain(): void {
		$this->queue_tags();
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () {
			$this->assertFalse( $this->engine->storage_is_quiescent() );
			$this->assertFalse( MigratorEngine::storage_maintenance_is_quiescent() );
		};
		$result = $this->engine->continue_image_tag_sync();
		$this->assertSame( 2, $result['processed'] );
		$this->assertCount( 2, $GLOBALS['foogallery_migrate_test_set_object_terms'] );
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
	public function test_term_callback_pin_loss_refuses_subsequent_output_and_progress(): void {
		$this->queue_tags();
		$before = $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA];
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () { $GLOBALS['wpdb']->current = false; };
		try { $this->engine->continue_image_tag_sync(); $this->fail( 'Uncertain output must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertCount( 1, $GLOBALS['foogallery_migrate_test_set_object_terms'] ); }
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] );
		$this->assertFalse( $this->engine->storage_is_quiescent() );
	}
	public function test_term_callback_exception_preserves_progress_and_unwinds_depth(): void {
		$this->queue_tags();
		$before = $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA];
		$GLOBALS['foogallery_migrate_test_terms_callback'] = function () { throw new \RuntimeException( 'term callback failed' ); };
		try { $this->engine->continue_image_tag_sync(); $this->fail( 'Callback must throw.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'term callback failed', $error->getMessage() ); }
		$this->assertSame( $before, $GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] );
		$this->assertTrue( $this->engine->storage_is_quiescent() );
	}
}

class DrainDetectionSource {
	public $is_detected = false;
	private $callback;
	public function __construct( $callback ) { $this->callback = $callback; }
	public function detect() { return call_user_func( $this->callback ); }
	public function __serialize(): array { return array( 'is_detected' => $this->is_detected ); }
}
