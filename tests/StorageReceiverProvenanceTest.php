<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use PHPUnit\Framework\TestCase;

/** Actual receiver entry witnesses, NOT SQL, historical capacity or admission proof. */
class StorageReceiverProvenanceTest extends TestCase {
	private $saved;
	private $post;
	private $server;

	protected function setUp(): void {
		$this->saved = array();
		foreach ( array( 'wpdb', 'wp_version', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce', 'foogallery_migrate_test_filters' ) as $name ) {
			$this->saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null );
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$GLOBALS['wpdb'] = new ReceiverProvenanceWpdb();
		$GLOBALS['wp_version'] = '6.9-test';
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_filters'] = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $name => $saved ) {
			if ( $saved[0] ) { $GLOBALS[$name] = $saved[1]; } else { unset( $GLOBALS[$name] ); }
		}
		$_POST = $this->post; $_SERVER = $this->server;
	}
	public static function operations() {
		return array( array( 'switch', 'table' ), array( 'switch', 'option' ), array( 'recover', 'table' ), array( 'recover', 'option' ), array( 'replace', 'table' ) );
	}
	private function request( $operation, $target, $churn = false ) {
		$r = array( 'blog_id' => 1, 'scope' => 'site', 'target' => $target,
			'confirmation' => 'recover' === $operation ? 'recover-storage' : ( 'replace' === $operation ? 'replace-retained-with-table' : 'switch-to-' . $target ), 'nonce' => '0123456789' );
		$purpose = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'option_reimport' : ( 'table' === $target ? 'option_import' : 'table_export' ) );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_' . $purpose . '_1', 'value' => $r['nonce'], 'result' => 1 );
		if ( $churn ) {
			// Exercise a real accepted input history, NOT a measured capacity claim.
			for ( $i = 0; $i < 4096; ++$i ) { $r['discard-' . $i] = $i; }
			for ( $i = 0; $i < 4096; ++$i ) { unset( $r['discard-' . $i] ); }
		}
		return $r;
	}
	private function profile() {
		// Trusted-injection shape fixture only; not independently approved calibration.
		return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
			'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
	}
	private function assertCoordinatorEntry( $operation, $target, $request, $handle ) {
		$frames = $GLOBALS['wpdb']->frames;
		$this->assertArrayHasKey( 'dispatch', $frames );
		$this->assertSame( array( $operation, $request ), $frames['dispatch'] );
		$entry = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'replace_retained' : 'switch_backend' );
		$this->assertSame( array( $request ), $frames[$entry] );
		$this->assertArrayHasKey( 'begin', $frames );
		$this->assertSame( $handle, array_key_exists( 'handle', $frames ) );
		if ( 'recover' !== $operation ) { $this->assertSame( array( $request, 'replace' === $operation ), $frames['switch_storage'] ); }
		$this->assertSame( 0, $GLOBALS['wpdb']->sql_calls );
	}
	/** @dataProvider operations */
	public function test_dispatch_reaches_actual_coordinator_with_both_fresh_and_churned_request( $operation, $target ) {
		foreach ( array( false, true ) as $churn ) {
			$request = $this->request( $operation, $target, $churn );
			$this->assertTrue( StorageConversion::authorize_request( $request, 'recover' === $operation, 1, 'replace' === $operation ) );
			$result = ( new StorageOperations( $this->profile() ) )->dispatch( $operation, $request );
			$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
			$this->assertCoordinatorEntry( $operation, $target, $request, false );
		}
	}
	/** @dataProvider operations */
	public function test_handle_reaches_actual_dispatch_and_coordinator_without_a_test_product_subclass( $operation, $target ) {
		$request = $this->request( $operation, $target, true );
		$profile = $this->profile();
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function ( $default ) use ( $profile ) { return $profile; };
		$_POST = array_merge( $request, array( 'action' => 'foogallery_migrate_storage', 'operation' => $operation ) );
		try { StorageOperations::handle(); $this->fail( 'Receiver did not terminate.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $response ) {
			$this->assertSame( 'foogallery_migrate_storage_recovery_required', $response->data['code'] );
			$this->assertSame( 409, $response->status_code );
		}
		$this->assertCoordinatorEntry( $operation, $target, $request, true );
	}
	/** @dataProvider operations */
	public function test_missing_calibration_and_unauthorized_endpoint_never_enter_the_coordinator( $operation, $target ) {
		$request = $this->request( $operation, $target, true );
		$_POST = array_merge( $request, array( 'action' => 'foogallery_migrate_storage', 'operation' => $operation ) );
		foreach ( array( true, false ) as $allowed ) {
			$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = $allowed;
			try { StorageOperations::handle(); $this->fail( 'Receiver did not terminate.' ); }
			catch ( \FooGalleryMigrateTestJsonResponse $response ) {
				$this->assertSame( 'foogallery_migrate_storage_' . ( $allowed ? 'calibration_required' : 'forbidden' ), $response->data['code'] );
				$this->assertSame( $allowed ? 409 : 403, $response->status_code );
			}
			$this->assertSame( array(), $GLOBALS['wpdb']->frames );
			$this->assertSame( 0, $GLOBALS['wpdb']->sql_calls );
		}
	}
}

/** Observe the real pinned-connection boundary and deliberately supply NO connection. */
class ReceiverProvenanceWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $frames = array();
	public $sql_calls = 0;
	public function __get( $name ) {
		if ( 'dbh' !== $name ) { throw new \LogicException( 'Unexpected database property.' ); }
		$this->frames = array();
		foreach ( debug_backtrace( 0 ) as $frame ) {
			if ( isset( $frame['class'] ) && in_array( $frame['class'], array( StorageOperations::class, StorageConversion::class ), true ) ) {
				$this->frames[$frame['function']] = $frame['args'];
			}
		}
		return null; // The product refuses before SQL/authority mutation, as required.
	}
	public function __call( $name, $args ) { ++$this->sql_calls; throw new \LogicException( 'SQL is outside this entry-only witness.' ); }
}
