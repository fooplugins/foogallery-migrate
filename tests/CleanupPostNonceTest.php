<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CleanupPostNonceTest extends TestCase {
	/** @dataProvider malformed_requests */
	public function test_cleanup_rejects_missing_or_nonstring_post_nonce_without_warnings_or_sql( $view, $present, $value ): void {
		$_GET = array( 'tab' => 'troubleshooting', 'foogallery_migrate_clear_nonce' => 'valid-query-nonce' );
		$_POST = array( 'clear_migration_history' => '1' );
		if ( $present ) { $_POST['foogallery_migrate_clear_nonce'] = $value; }
		$_REQUEST = array_merge( $_GET, $_POST );
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['wpdb'] = new CleanupPostNonceNoSql();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
		$level = ob_get_level(); ob_start();
		set_error_handler( function ( $severity, $message, $file, $line ) { throw new \ErrorException( $message, 0, $severity, $file, $line ); } );
		try {
			require FOOGM_DIR . '/includes/views/' . $view;
			$this->fail( 'Malformed cleanup must stop before dispatch.' );
		} catch ( \FooGalleryMigrateTestDie $error ) {
			$this->assertSame( 'Invalid migration storage request.', $error->getMessage() );
		} finally {
			restore_error_handler(); while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public static function malformed_requests(): array {
		$cases = array();
		foreach ( array( 'view-migrate.php', 'view-migrate-tab-sources.php' ) as $view ) {
			foreach ( array( array( false, null ), array( true, null ), array( true, array( 'valid-query-nonce' ) ),
				array( true, 123 ), array( true, false ), array( true, new \stdClass() ) ) as $case ) {
				$cases[] = array( $view, $case[0], $case[1] );
			}
		}
		return $cases;
	}
}

class CleanupPostNonceNoSql {
	public $queries = array();
	public function __call( $name, $args ) { $this->queries[] = $name; throw new \LogicException( 'Unexpected storage access before cleanup authorization.' ); }
}
