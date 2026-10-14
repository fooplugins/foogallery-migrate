<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use PHPUnit\Framework\TestCase;

class StorageOperationsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new OperationsNoSqlWpdb();
		$GLOBALS['wp_version'] = '6.9-test';
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_1', 'value' => 'valid', 'result' => 1 );
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_nonce'], $_SERVER['REQUEST_METHOD'] );
		$_POST = array();
	}
	private function request() { return array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => 'valid' ); }
	private function profile() {
		// Synthetic contract fixture, NOT an approved production calibration.
		return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
			'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
	}
	private function error( $suffix, $result ) {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'foogallery_migrate_storage_' . $suffix, $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
	}
	public function test_missing_calibration_refuses_all_authorized_operations_before_sql(): void {
		$receiver = new StorageOperations();
		$this->error( 'calibration_required', $receiver->dispatch( 'preflight', $this->request() ) );
		$this->error( 'calibration_required', $receiver->dispatch( 'switch', $this->request() ) );
		$r = $this->request(); $r['confirmation'] = 'recover-storage';
		$GLOBALS['foogallery_migrate_test_nonce']['action'] = 'foogallery_migrate_storage_recover_1';
		$this->error( 'calibration_required', $receiver->dispatch( 'recover', $r ) );
	}
	public function test_authentication_and_exact_request_precede_calibration_or_sql(): void {
		$receiver = new StorageOperations( $this->profile() );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->error( 'forbidden', $receiver->dispatch( 'switch', $this->request() ) );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = true;
		foreach ( array( 'policy' => array(), 'scope' => 'network', 'blog_id' => '01', 'nonce' => 'wrong', 'confirmation' => true ) as $key => $value ) {
			$r = $this->request(); $r[$key] = $value;
			$this->error( 'nonce' === $key ? 'invalid_nonce' : 'invalid_request', $receiver->dispatch( 'switch', $r ) );
		}
		$this->error( 'invalid_request', $receiver->dispatch( 'purge', $this->request() ) );
		$r = $this->request(); $r['target'] = 'option'; $r['confirmation'] = 'switch-to-option';
		$this->error( 'invalid_request', $receiver->dispatch( 'preflight', $r ) );
	}
	public function test_measurement_only_invalid_and_cross_runtime_profiles_never_authorize(): void {
		$profile = $this->profile();
		$this->assertSame( $profile['policy'], StorageCalibration::policy( $profile ) );
		foreach ( array( 'status' => 'measurement_only', 'fingerprint' => str_repeat( '0', 64 ), 'evidence_sha256' => '', 'version' => '1', 'policy' => array() ) as $key => $value ) {
			$p = $profile; $p[$key] = $value;
			$this->error( 'calibration_required', ( new StorageOperations( $p ) )->dispatch( 'switch', $this->request() ) );
		}
		$GLOBALS['wp_version'] = 'other-runtime';
		$this->assertNotSame( $profile['fingerprint'], StorageCalibration::fingerprint() );
		$this->error( 'calibration_required', ( new StorageOperations( $profile ) )->dispatch( 'switch', $this->request() ) );
	}
	public function test_direct_uncalibrated_coordinator_refuses_before_claiming_gate(): void {
		$this->error( 'decode_policy_required', ( new StorageConversion() )->switch_backend( $this->request() ) );
		$r = $this->request(); $r['confirmation'] = 'recover-storage';
		$GLOBALS['foogallery_migrate_test_nonce']['action'] = 'foogallery_migrate_storage_recover_1';
		$this->error( 'decode_policy_required', ( new StorageConversion() )->recover( $r ) );
	}
	public function test_http_receiver_rejects_get_and_unknown_transport_fields(): void {
		$_POST = array_merge( $this->request(), array( 'action' => 'foogallery_migrate_storage', 'operation' => 'switch' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertHttpError( 'invalid_request', 400 );
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['policy'] = array( 'amplification' => 1 );
		$this->assertHttpError( 'invalid_request', 400 );
		unset( $_POST['policy'] );
		$this->assertHttpError( 'calibration_required', 409 );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->assertHttpError( 'forbidden', 403 );
	}
	private function assertHttpError( $suffix, $status ) {
		try { StorageOperations::handle(); $this->fail( 'HTTP receiver must terminate.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $error ) {
			$this->assertSame( 'foogallery_migrate_storage_' . $suffix, $error->data['code'] );
			$this->assertSame( $status, $error->status_code );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
	}
}
class OperationsNoSqlWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $calls = array();
	public function __call( $name, $args ) { $this->calls[] = $name; throw new \LogicException( 'Unexpected storage SQL.' ); }
}
