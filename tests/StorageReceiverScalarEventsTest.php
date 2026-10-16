<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageReceiverNewCompositionV2.php';

/** Internal event projection only: not an allocation/admission envelope. */
class StorageReceiverScalarEventsTest extends TestCase {
	private $saved;
	private $post;
	private $server;

	protected function setUp(): void {
		$this->saved = array();
		foreach ( array( 'wpdb', 'wp_version', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce', 'foogallery_migrate_test_filters' ) as $name ) {
			$this->saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null );
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$GLOBALS['wpdb'] = new ReceiverScalarEventsWpdb();
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
	private function post( $operation = 'switch' ) {
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_1', 'value' => 'valid', 'result' => 1 );
		return array( 'action' => 'foogallery_migrate_storage', 'operation' => $operation, 'blog_id' => 1,
			'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => 'valid' );
	}
	private function profile() {
		// Synthetic fixture only; NOT an approved calibration.
		return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
			'evidence_sha256' => str_repeat( 'a', 64 ),
			'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
	}
	public function test_projection_owns_no_values_and_counts_each_alias_visit_without_free_credit() {
		$post = $this->post(); $shared = '\\q'; $post['nonce'] =& $shared; $post['confirmation'] =& $shared;
		$before = $post;
		$m = new StorageMemoryBaseline(); $m->observe_receiver( $post ); $r = $m->receiver_events();
		$this->assertSame( $before, $post );
		$this->assertSame( 'SCALAR_RECEIVER_EVENTS_ONLY', $r['status'] );
		$this->assertSame( 7, $r['leaf_visits'] );
		$this->assertSame( array( strlen( $post['action'] ), strlen( $post['operation'] ), null, strlen( $post['scope'] ), strlen( $post['target'] ), strlen( $shared ), strlen( $shared ) ), $r['string_lengths_upper'] );
		$this->assertSame( array_map( function ( $key ) { return strlen( $key ) + 1; }, array_keys( $post ) ), $r['key_request_bytes'] );
		$this->assertStringNotContainsString( 'foogallery_migrate_storage', json_encode( $r ) );
		$this->assertSame( '\\q', $shared );
		$this->assertNull( $r['whole_operation_new_upper'] );
	}
	public function test_observer_does_not_walk_or_refuse_invalid_nested_or_recursive_graphs() {
		$post = $this->post(); $object = new \stdClass(); $object->s = '\\q'; $object->self = $object;
		$post['nonce'] = $object;
		$m = new StorageMemoryBaseline(); $m->observe_receiver( $post );
		$this->assertSame( 'UNRESOLVED_RECEIVER_GRAPH', $m->receiver_events()['status'] );
		$this->assertSame( '\\q', $object->s );
		$post['nonce'] = array(); $post['nonce']['self'] =& $post['nonce'];
		$m->observe_receiver( $post );
		$this->assertSame( 'UNRESOLVED_RECEIVER_GRAPH', $m->receiver_events()['status'] );
		unset( $post['nonce']['self'] );
	}
	public function test_no_receiver_evidence_is_invented_for_direct_php_entry() {
		$m = new StorageMemoryBaseline(); $this->assertNull( $m->receiver_events() );
		$post = $this->post(); unset( $post['action'], $post['operation'] );
		( new StorageOperations( $this->profile() ) )->dispatch( 'switch', $post );
		$this->assertNull( $GLOBALS['wpdb']->memory->receiver_events() );
	}
	public function test_actual_handle_carries_pre_unslash_events_to_real_coordinator_begin() {
		$_POST = $this->post(); // bootstrap wp_unslash is an identity fixture.
		// Original high-capacity root remains outside this NEW-event projection.
		for ( $i = 0; $i < 4096; ++$i ) { $_POST['old-' . $i] = $i; }
		for ( $i = 0; $i < 4096; ++$i ) { unset( $_POST['old-' . $i] ); }
		$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function () { return $this->profile(); };
		try { StorageOperations::handle(); $this->fail( 'Receiver did not terminate.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $error ) {
			$this->assertSame( 'foogallery_migrate_storage_recovery_required', $error->data['code'] );
			$this->assertArrayNotHasKey( 'receiver_events', $error->data );
		}
		$m = $GLOBALS['wpdb']->memory; $this->assertInstanceOf( StorageMemoryBaseline::class, $m );
		$events = $m->receiver_events();
		$this->assertSame( strlen( $_POST['confirmation'] ), $events['string_lengths_upper'][5] );
		$this->assertSame( 'switch-to-table', $_POST['confirmation'] );
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$helper = new StorageReceiverNewCompositionV2( $version );
			$this->assertSame( $helper->bindings()['receiver_observer_sha256'], hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-storage-memory-baseline.php' ) );
			$r = $helper->receiver_events( $events );
			$this->assertSame( 8, $r['events']['map_deep_calls'] );
			$this->assertSame( 7, $r['events']['callback_calls'] );
			$this->assertSame( 6, $r['events']['stripslashes_calls'] );
			$this->assertSame( 8, $r['events']['foreach_fetch_attempts'] );
			$this->assertSame( 7, $r['events']['root_assignments'] );
			$this->assertGreaterThan( 0, $r['selected_copy_family_nominal_sum'] );
			$this->assertNull( $r['whole_operation_new_upper'] );
			$this->assertNull( $r['vm_allocation_bytes'] );
			$this->assertFalse( $r['safe_admission_approved'] );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->sql );
	}
	public function test_helper_cannot_launder_unresolved_graphs_into_zero_cost() {
		$post = $this->post(); $post['nonce'] = array( 'nested' => '\\q' );
		$m = new StorageMemoryBaseline(); $m->observe_receiver( $post );
		$this->expectException( \InvalidArgumentException::class );
		( new StorageReceiverNewCompositionV2( '5.4.0' ) )->receiver_events( $m->receiver_events() );
	}
	public function test_scalar_types_integer_keys_and_each_string_visit_reuse_existing_copy_helpers() {
		$post = array( '', '\\', null, false, 1, 1.5, '\\0' );
		$m = new StorageMemoryBaseline(); $m->observe_receiver( $post ); $events = $m->receiver_events();
		$this->assertSame( array( 0, 1, null, null, null, null, 2 ), $events['string_lengths_upper'] );
		$this->assertSame( array_fill( 0, 7, 0 ), $events['key_request_bytes'] );
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$helper = new StorageReceiverNewCompositionV2( $version ); $r = $helper->receiver_events( $events );
			$this->assertSame( 3, $r['events']['stripslashes_calls'] );
			$copies = $helper->compose( array( array( '', '' ), array( '\\', '' ), array( '\\0', "\0" ) ), array( array_keys( $post ) ) );
			$this->assertSame( $copies['selected_copy_family_nominal_sum'], $r['selected_copy_family_nominal_sum'] );
		}
	}
	/** @dataProvider invalid_lengths */
	public function test_malformed_or_out_of_helper_domain_projection_never_produces_a_cost( $bad ) {
		$m = new StorageMemoryBaseline(); $m->observe_receiver( $this->post() ); $events = $m->receiver_events();
		$events['string_lengths_upper'][0] = $bad;
		$this->expectException( \InvalidArgumentException::class );
		( new StorageReceiverNewCompositionV2( '5.4.0' ) )->receiver_events( $events );
	}
	public static function invalid_lengths() { return array( array( -1 ), array( '1' ), array( PHP_INT_MAX ), array( 1073741824 ) ); }
}
class ReceiverScalarEventsWpdb {
	public $prefix = 'wp_';
	public $sql = array();
	public $memory;
	public function __get( $name ) {
		if ( 'dbh' !== $name ) { throw new \LogicException( 'Unexpected database property.' ); }
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( isset( $frame['object'] ) && $frame['object'] instanceof StorageConversion ) {
				$p = new \ReflectionProperty( StorageConversion::class, 'memory' );
				$this->memory = $p->getValue( $frame['object'] ); break;
			}
		}
		return null; // Witness actual begin; never emulate a valid pinned connection.
	}
}
