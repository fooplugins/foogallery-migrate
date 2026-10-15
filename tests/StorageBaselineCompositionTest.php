<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use PHPUnit\Framework\TestCase;

/** No-free-credit composition witnesses, NOT incremental-allocation/SQL proof. */
class StorageBaselineCompositionTest extends TestCase {
	private $saved;
	private $post;
	private $server;

	protected function setUp(): void {
		$this->saved = array();
		foreach ( array( 'wpdb', 'wp_version', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce', 'foogallery_migrate_test_filters' ) as $name ) {
			$this->saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null );
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$GLOBALS['wpdb'] = new BaselineCompositionWpdb();
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
	private function request( $operation = 'preflight', $target = 'table' ) {
		$r = array( 'blog_id' => 1, 'scope' => 'site', 'target' => $target,
			'confirmation' => 'recover' === $operation ? 'recover-storage' : ( 'replace' === $operation ? 'replace-retained-with-table' : 'switch-to-' . $target ), 'nonce' => '0123456789' );
		$purpose = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'option_reimport' : ( 'table' === $target ? 'option_import' : 'table_export' ) );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_' . $purpose . '_1', 'value' => $r['nonce'], 'result' => 1 );
		// An accepted historical root; its original backing is never inferred
		// from count/strlen or supplied as a fabricated capacity to a helper.
		for ( $i = 0; $i < 4096; ++$i ) { $r['discard-' . $i] = $i; }
		for ( $i = 0; $i < 4096; ++$i ) { unset( $r['discard-' . $i] ); }
		return $r;
	}
	private function policy() { return array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ); }
	private function profile() {
		// Trusted-injection fixture only, NOT an independently approved profile.
		return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
			'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => $this->policy() );
	}
	private function property( $object, $name ) {
		$p = new \ReflectionProperty( $object, $name );
		return $p->getValue( $object );
	}
	public function test_high_water_keeps_a_real_released_sample_and_existing_budget_boundary() {
		$baseline = new StorageMemoryBaseline();
		$before = $baseline->sample();
		$live = str_repeat( 'x', 8 * 1048576 );
		$high = $baseline->sample();
		$this->assertGreaterThan( $before, $high );
		unset( $live );
		$this->assertLessThan( $high, memory_get_usage( true ) );
		$this->assertSame( $high, $baseline->sample() );
		$policy = array( 'max_raw_bytes' => 1, 'amplification' => 1, 'reserve_bytes' => 1 );
		$this->assertIsArray( StoragePreflight::decode_budget( 1, (string) ( $high + 2 ), $baseline->sample(), $policy ) );
		$this->assertSame( 'foogallery_migrate_storage_decode_refused', StoragePreflight::decode_budget( 1, (string) ( $high + 1 ), $baseline->sample(), $policy )->get_error_code() );
	}
	public function test_actual_dispatch_preflight_carries_the_entry_sample_across_calibration_and_size_sql() {
		$r = $this->request(); $profile = $this->profile();
		$live = str_repeat( 'c', 8 * 1048576 ); $entry = memory_get_usage( true );
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function ( $default ) use ( $profile, &$live ) { $live = null; return $profile; };
		$result = ( new StorageOperations() )->dispatch( 'preflight', $r );
		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( $entry, $result['memory_used_bytes'] );
		$this->assertLessThan( $entry, memory_get_usage( true ) );
		$this->assertFalse( $result['conversion_ready'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->sql );
	}
	public function test_actual_handle_carries_the_pre_unslash_sample_to_preflight() {
		$r = $this->request(); $profile = $this->profile();
		$_POST = array_merge( $r, array( 'action' => 'foogallery_migrate_storage', 'operation' => 'preflight' ) );
		$live = str_repeat( 'h', 8 * 1048576 ); $entry = memory_get_usage( true );
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function ( $default ) use ( $profile, &$live ) { $live = null; return $profile; };
		try { StorageOperations::handle(); $this->fail( 'Receiver did not terminate.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $response ) {
			$this->assertArrayNotHasKey( 'code', $response->data );
			$this->assertGreaterThanOrEqual( $entry, $response->data['memory_used_bytes'] );
			$this->assertFalse( $response->data['conversion_ready'] );
		}
		$this->assertLessThan( $entry, memory_get_usage( true ) );
	}
	public static function operations() {
		return array( array( 'switch', 'table' ), array( 'switch', 'option' ), array( 'recover', 'table' ), array( 'recover', 'option' ), array( 'replace', 'table' ) );
	}
	/** @dataProvider operations */
	public function test_actual_dispatch_carries_entry_through_calibration_to_coordinator_begin( $operation, $target ) {
		$r = $this->request( $operation, $target ); $profile = $this->profile();
		$live = str_repeat( 'd', 8 * 1048576 ); $entry = memory_get_usage( true );
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function ( $default ) use ( $profile, &$live ) { $live = null; return $profile; };
		$result = ( new StorageOperations() )->dispatch( $operation, $r );
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
		$this->assertInstanceOf( StorageMemoryBaseline::class, $GLOBALS['wpdb']->memory );
		$this->assertGreaterThanOrEqual( $entry, $GLOBALS['wpdb']->memory->sample() );
		$this->assertLessThan( $entry, memory_get_usage( true ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->sql );
	}
	public function test_direct_coordinator_resamples_at_begin_advance_and_budget_without_free_credit() {
		$r = $this->request( 'switch' );
		$manager = new StorageConversion( $this->policy() );
		$manager->switch_backend( $r ); // Deliberately missing pinned connection.
		$baseline = $this->property( $manager, 'memory' );
		$this->assertInstanceOf( StorageMemoryBaseline::class, $baseline );
		$live = str_repeat( 'a', 8 * 1048576 ); $high = memory_get_usage( true );
		// Reach the actual advance method without substituting a product subclass.
		// Stop at the control-read seam; do not emulate a valid connection/phase.
		$advance = new \ReflectionMethod( $manager, 'advance' );
		try { $advance->invoke( $manager, array( 'phase' => 'unrecognized' ), null ); $this->fail( 'Expected control-read refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'advance control-read witness only', $error->getMessage() ); }
		unset( $live );
		$this->assertGreaterThanOrEqual( $high, $baseline->sample() );
		$budget = new \ReflectionMethod( $manager, 'budget' );
		$budget->invoke( $manager, 0 );
		$this->assertGreaterThanOrEqual( $high, $baseline->sample() );
		// A second direct operation must not inherit a previous operation's peak.
		$manager->switch_backend( $r );
		$this->assertNotSame( $baseline, $this->property( $manager, 'memory' ) );
	}
	public function test_denials_preserve_authorization_and_no_sql_before_policy() {
		$r = $this->request();
		foreach ( array( 'scope' => 'network', 'blog_id' => 2, 'nonce' => 'bad', 'policy' => array() ) as $key => $value ) {
			$bad = $r; $bad[$key] = $value;
			$result = ( new StorageOperations() )->dispatch( 'preflight', $bad );
			$this->assertSame( 'foogallery_migrate_storage_' . ( 'nonce' === $key ? 'invalid_nonce' : 'invalid_request' ), $result->get_error_code() );
		}
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->assertSame( 'foogallery_migrate_storage_forbidden', ( new StorageOperations() )->dispatch( 'switch', $r )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->sql );
	}
}

class BaselineCompositionWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $options = 'wp_options';
	public $last_error = '';
	public $sql = array();
	public $memory;
	public function prepare( $sql, $value ) { return str_replace( '%s', "'" . ( is_array( $value ) ? $value[0] : $value ) . "'", $sql ); }
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, "CONCAT('v:'" ) ) { throw new \RuntimeException( 'advance control-read witness only' ); }
		$this->sql[] = $sql; return '0';
	}
	public function __get( $name ) {
		if ( 'dbh' !== $name ) { throw new \LogicException( 'Unexpected database property.' ); }
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( isset( $frame['object'] ) && $frame['object'] instanceof StorageConversion ) {
				$p = new \ReflectionProperty( StorageConversion::class, 'memory' );
				if ( $p->isInitialized( $frame['object'] ) ) { $this->memory = $p->getValue( $frame['object'] ); }
				break;
			}
		}
		return null;
	}
}
