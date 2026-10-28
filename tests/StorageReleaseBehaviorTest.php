<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use PHPUnit\Framework\TestCase;

/** Portable release behavior, independent of historical PHP allocator/source hashes. */
class StorageReleaseBehaviorTest extends TestCase {
	private $saved;
	private $post;
	private $server;
	private $memory_limit;

	protected function setUp(): void {
		$this->saved = array();
		foreach ( array( 'wpdb', 'wp_version', 'foogallery_migrate_test_blog_id',
			'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce',
			'foogallery_migrate_test_unslash_callback' ) as $name ) {
			$this->saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null );
		}
		$this->post = $_POST; $this->server = $_SERVER;
		$this->memory_limit = ini_get( 'memory_limit' );
		$GLOBALS['wpdb'] = new ReleaseBehaviorNoSqlWpdb();
		$GLOBALS['wp_version'] = '6.9-test';
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		unset( $GLOBALS['foogallery_migrate_test_unslash_callback'] );
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $name => $saved ) {
			if ( $saved[0] ) { $GLOBALS[$name] = $saved[1]; } else { unset( $GLOBALS[$name] ); }
		}
		$_POST = $this->post; $_SERVER = $this->server;
		ini_set( 'memory_limit', $this->memory_limit );
	}

	private function nested_raw( $depth, $leaf = 'i:1;' ) {
		for ( $i = 0; $i < $depth; ++$i ) { $leaf = 'a:1:{i:0;' . $leaf . '}'; }
		return $leaf;
	}

	private function refuses_then_reuses( $decoder, $raw ) {
		try { $decoder->decode( $raw ); $this->fail( 'Unsupported serialized input reached native hydration.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Retain recorded authority', $error->getMessage() );
		}
		$this->assertSame( array(), $decoder->decode( 'a:0:{}' ) );
	}

	public function test_decoder_exact_depth_boundaries_malformed_siblings_and_reuse(): void {
		foreach ( array( 16, 64 ) as $limit ) {
			$decoder = new LegacyOptionDecoder( 16777216, $limit );
			$accepted = $this->nested_raw( $limit );
			$this->assertSame( $accepted, serialize( $decoder->decode( $accepted ) ) );
			$this->assertSame( $limit, $decoder->structural_statistics( $accepted )['D'] );
			$this->refuses_then_reuses( $decoder, $this->nested_raw( $limit + 1 ) );
			$this->refuses_then_reuses( $decoder, $this->nested_raw( $limit, 'O:8:"stdClass":0:{}' ) );
			$this->refuses_then_reuses( $decoder, substr( $accepted, 0, -1 ) );
			$this->refuses_then_reuses( $decoder, 'a:2:{i:0;a:0:{}i:1;a:1:{i:0;O:8:"stdClass":0:{}}}' );
			$this->assertSame( array( array(), array() ), $decoder->decode( 'a:2:{i:0;a:0:{}i:1;a:0:{}}' ) );
		}
	}

	public function test_scanner_float_token_128_bytes_admitted_and_129_refused_before_native_decode(): void {
		$decoder = new LegacyOptionDecoder();
		$at_limit = 'a:1:{i:0;d:' . str_repeat( '1', 128 ) . ';}';
		$over_limit = 'a:1:{i:0;d:' . str_repeat( '1', 129 ) . ';}';
		$this->assertSame( 3, $decoder->structural_statistics( $at_limit )['T'] );
		try { $decoder->structural_statistics( $over_limit ); $this->fail( '129-byte float token passed the scanner.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Retain recorded authority', $error->getMessage() ); }
		$this->refuses_then_reuses( $decoder, $over_limit );
	}

	private function post() {
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_1',
			'value' => 'switch-to-table', 'result' => 1 );
		return array( 'action' => 'foogallery_migrate_storage', 'operation' => 'switch', 'blog_id' => 1,
			'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => 'switch-to-table' );
	}

	private function response() {
		try { StorageOperations::handle(); $this->fail( 'Public receiver did not terminate.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $response ) { return $response; }
	}

	public function test_receiver_projection_preserves_scalar_types_and_integer_keys_without_a_cost_claim(): void {
		$root = array( '', '\\', null, false, 1, 1.5, '\\0' );
		$before = $root;
		$observer = new StorageMemoryBaseline(); $observer->observe_receiver( $root );
		$events = $observer->receiver_events();
		$this->assertSame( $before, $root );
		$this->assertSame( 'SCALAR_RECEIVER_EVENTS_ONLY', $events['status'] );
		$this->assertSame( 7, $events['leaf_visits'] );
		$this->assertSame( array( 0, 1, null, null, null, null, 2 ), $events['string_lengths_upper'] );
		$this->assertSame( array_fill( 0, 7, 0 ), $events['key_request_bytes'] );
		$this->assertNull( $events['whole_operation_new_upper'] );
	}

	public function test_public_receiver_alias_observation_is_private_nonmutating_and_noncrediting(): void {
		$_POST = $this->post();
		$shared = 'switch-to-table';
		$_POST['confirmation'] =& $shared; $_POST['nonce'] =& $shared;
		$before = serialize( $_POST );
		$response = $this->response();
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $response->data['code'] );
		$this->assertSame( $before, serialize( $_POST ) );
		$this->assertSame( 'switch-to-table', $shared );
		$this->assertStringNotContainsString( $shared, json_encode( $response->data ) );
		$this->assertArrayNotHasKey( 'receiver_events', $response->data );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
		$this->assertInstanceOf( StorageMemoryBaseline::class, $GLOBALS['wpdb']->memory );
		$events = $GLOBALS['wpdb']->memory->receiver_events();
		$this->assertSame( 'SCALAR_RECEIVER_EVENTS_ONLY', $events['status'] );
		$this->assertSame( 7, $events['leaf_visits'] );
		$this->assertSame( strlen( $shared ), $events['string_lengths_upper'][5] );
		$this->assertSame( strlen( $shared ), $events['string_lengths_upper'][6] );
		$this->assertNull( $events['whole_operation_new_upper'] );
		$this->assertStringNotContainsString( $shared, json_encode( $events ) );
	}

	public function test_nested_receiver_is_unresolved_private_and_refused_without_mutation_or_sql(): void {
		$_POST = $this->post(); $_POST['nonce'] = array( 'private' => 'never expose this value' );
		$before = $_POST;
		$observer = new StorageMemoryBaseline(); $observer->observe_receiver( $_POST );
		$this->assertSame( 'UNRESOLVED_RECEIVER_GRAPH', $observer->receiver_events()['status'] );
		$this->assertNull( $observer->receiver_events()['whole_operation_new_upper'] );
		$this->assertStringNotContainsString( 'never expose this value', json_encode( $observer->receiver_events() ) );
		$recursive = array(); $recursive['self'] =& $recursive;
		$_POST['nonce'] = $recursive;
		$observer->observe_receiver( $_POST );
		$this->assertSame( 'UNRESOLVED_RECEIVER_GRAPH', $observer->receiver_events()['status'] );
		$this->assertNull( $observer->receiver_events()['whole_operation_new_upper'] );
		$_POST = $before;
		$response = $this->response();
		$this->assertSame( 'foogallery_migrate_storage_invalid_request', $response->data['code'] );
		$this->assertSame( $before, $_POST );
		$this->assertStringNotContainsString( 'never expose this value', json_encode( $response->data ) );
		$this->assertArrayNotHasKey( 'receiver_events', $response->data );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_public_receiver_uses_release_memory_refusal_for_aliased_scalar_request(): void {
		$this->assertNotFalse( ini_set( 'memory_limit', '128M' ) );
		$this->assertSame( '128M', ini_get( 'memory_limit' ) );
		$_POST = $this->post();
		$shared = 'switch-to-table'; $_POST['confirmation'] =& $shared; $_POST['nonce'] =& $shared;
		$before = serialize( $_POST );
		$response = $this->response();
		$this->assertSame( 'foogallery_migrate_storage_decode_refused', $response->data['code'] );
		$this->assertSame( 409, $response->status_code );
		$this->assertSame( $before, serialize( $_POST ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
		$this->assertArrayNotHasKey( 'receiver_events', $response->data );
	}
}

class ReleaseBehaviorNoSqlWpdb {
	public $prefix = 'wp_';
	public $calls = array();
	public $memory;
	public function __get( $name ) {
		if ( 'dbh' !== $name ) { throw new \LogicException( 'Unexpected database property.' ); }
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
			if ( isset( $frame['object'] ) && $frame['object'] instanceof StorageConversion ) {
				$p = new \ReflectionProperty( StorageConversion::class, 'memory' );
				$this->memory = $p->getValue( $frame['object'] ); break;
			}
		}
		return null;
	}
	public function __call( $name, $args ) {
		$this->calls[] = $name;
		throw new \LogicException( 'Release behavior reached SQL.' );
	}
}
