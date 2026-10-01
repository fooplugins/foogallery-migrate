<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\Init;
use PHPUnit\Framework\TestCase;

class AdminFailureHandlingTest extends TestCase {

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['foogallery_migrate_engine_instance'] );
		parent::tearDown();
	}

	public function test_retry_ajax_returns_structured_server_error_when_store_write_fails(): void {
		$GLOBALS['foogallery_migrate_engine_instance'] = new FailingAdminMigratorEngine();
		$_POST['foogallery_migrate_retry_gallery_id'] = 'gallery_aigpl_406';

		try {
			$this->new_init()->ajax_retry_gallery_migration();
			$this->fail( 'Expected the AJAX error response to terminate the request.' );
		} catch ( \FooGalleryMigrateTestJsonResponse $error ) {
			$this->assertSame( 'Retry state could not be saved.', $error->getMessage() );
			$this->assertSame( array( 'message' => 'Retry state could not be saved.' ), $error->data );
			$this->assertSame( 500, $error->status_code );
			$this->assertSame( 'gallery_aigpl_406', $GLOBALS['foogallery_migrate_engine_instance']->retry_gallery_id );
		}
	}

	public function test_error_check_ajax_returns_structured_server_error_when_store_write_fails(): void {
		$GLOBALS['foogallery_migrate_engine_instance'] = new FailingAdminMigratorEngine();
		$_POST['foogallery_migrate_check_gallery_id'] = 'gallery_aigpl_406';

		try {
			$this->new_init()->ajax_check_gallery_errors();
			$this->fail( 'Expected the AJAX error response to terminate the request.' );
		} catch ( \FooGalleryMigrateTestJsonResponse $error ) {
			$this->assertSame( 'Error-check state could not be saved.', $error->getMessage() );
			$this->assertSame( array( 'message' => 'Error-check state could not be saved.' ), $error->data );
			$this->assertSame( 500, $error->status_code );
			$this->assertSame( 'gallery_aigpl_406', $GLOBALS['foogallery_migrate_engine_instance']->checked_gallery_id );
		}
	}

	public function test_full_page_error_check_renders_store_failure_notice(): void {
		$GLOBALS['foogallery_migrate_engine_instance'] = new FailingAdminMigratorEngine();
		$_POST = array(
			'foogallery_migrate_detect' => '1',
			'check_migration_errors'     => '1',
		);

		ob_start();
		require FOOGM_DIR . '/includes/views/view-migrate-tab-sources.php';
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error inline', $output );
		$this->assertStringContainsString( 'Full error check could not be saved.', $output );
		$this->assertFalse( $GLOBALS['foogallery_migrate_engine_instance']->gallery_objects_reloaded );
	}

	private function new_init(): Init {
		$reflection = new \ReflectionClass( Init::class );
		return $reflection->newInstanceWithoutConstructor();
	}
}

class FailingAdminMigratorEngine {
	public $retry_gallery_id = '';
	public $checked_gallery_id = '';
	public $gallery_objects_reloaded = false;

	public function retry_gallery_migration( $gallery_id ) {
		$this->retry_gallery_id = $gallery_id;
		return new \WP_Error( 'foogallery_migrate_store_write_failed', 'Retry state could not be saved.' );
	}

	public function check_gallery_migration_errors( $gallery_id ) {
		$this->checked_gallery_id = $gallery_id;
		return new \WP_Error( 'foogallery_migrate_store_write_failed', 'Error-check state could not be saved.' );
	}

	public function check_for_migration_errors() {
		return new \WP_Error( 'foogallery_migrate_store_write_failed', 'Full error check could not be saved.' );
	}

	public function get_gallery_migrator() {
		return new FailingAdminGalleryMigrator( $this );
	}

	public function has_detected_plugins() {
		return false;
	}

	public function get_migrator_setting( $key, $default = false ) {
		return $default;
	}

	public function get_plugins() {
		return array();
	}

	public function has_migrated_objects() {
		return false;
	}
}

class FailingAdminGalleryMigrator {
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
	}

	public function get_objects_to_migrate( $force = false ) {
		$this->engine->gallery_objects_reloaded = true;
		return array();
	}
}