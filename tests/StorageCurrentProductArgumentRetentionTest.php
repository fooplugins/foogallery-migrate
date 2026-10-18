<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageProductArgumentRetentionV3.php';

/** Modern conditional material controls, not a literal-runtime/admission test. */
class StorageCurrentProductArgumentRetentionTest extends TestCase {
	private $saved_globals;
	protected function setUp(): void {
		$this->saved_globals = array();
		foreach ( array( 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce' ) as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) { $this->saved_globals[$key] = $GLOBALS[$key]; }
		}
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
	}
	protected function tearDown(): void {
		foreach ( array( 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce' ) as $key ) {
			if ( array_key_exists( $key, $this->saved_globals ) ) { $GLOBALS[$key] = $this->saved_globals[$key]; }
			else { unset( $GLOBALS[$key] ); }
		}
	}
	private function arguments( $path ) {
		$r = array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'direct' === $path['phase'] ? 'table' : 'option',
			'confirmation' => 'recover' === $path['operation'] ? 'recover-storage' : ( 'replace' === $path['operation'] ? 'replace-retained-with-table' : 'switch-to-' . ( 'direct' === $path['phase'] ? 'table' : 'option' ) ), 'nonce' => 'valid' );
		$record = array( 'version' => 2, 'blog_id' => 1, 'backend' => 'flipped' === $path['phase'] ? 'option' : 'table', 'epoch' => 1,
			'switch_id' => str_repeat( 'a', 32 ), 'from' => 'table', 'to' => 'option', 'phase' => $path['phase'], 'table_id' => str_repeat( 'b', 32 ),
			'source_digest' => str_repeat( 'c', 64 ), 'logical_digest' => str_repeat( 'd', 64 ), 'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1, 'retained_digest' => str_repeat( 'e', 64 ) );
		return array( 'request' => $r, 'record' => $record, 'source' => $path['source_may_be_null'] ? null :
			array( 'physical' => str_repeat( 'f', 64 ), 'value' => array( array( str_repeat( 'x', 70000 ) ) ) ) );
	}
	private function capacities() {
		return array( 'request' => array( 'array_capacity_upper' => 8, 'string_extra_upper' => 0 ),
			'record' => array( 'array_capacity_upper' => 32, 'string_extra_upper' => 0 ),
			'source' => array( 'array_capacity_upper' => 8, 'string_extra_upper' => 0 ) );
	}
	private function decoder_failure( $source ) { ( new LegacyOptionDecoder( 60000 ) )->decode( 'a:1:{' ); }
	public function test_modern_decoder_exception_retains_preexisting_nested_argument_after_unwind() {
		// Real decoder; this test wrapper is NOT an executed product receiver.
		$this->assertSame( 'ff82db7eda0be9999675b94c1e7561ab009579b4f57540d2b6a47a0df331b474', hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-legacy-state-codec.php' ) );
		$source = array( 'physical' => str_repeat( 'a', 64 ), 'value' => array( array( str_repeat( 'x', 70000 ) ) ) );
		try { $this->decoder_failure( $source ); $this->fail( 'decoder accepted malformed bytes' ); }
		catch ( \RuntimeException $e ) {
			unset( $source ); $found = false;
			foreach ( $e->getTrace() as $frame ) {
				if ( 'decoder_failure' !== $frame['function'] ) { continue; }
				$found = true;
				$this->assertSame( 70000, strlen( $frame['args'][0]['value'][0][0] ) );
			}
			$this->assertTrue( $found );
		}
	}

	public function test_all_actual_suffixes_capture_only_concurrent_argument_roots() {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$t = new StorageProductCallerTraceV3( $version ); $m = new StorageProductArgumentRetentionV3( $version );
			foreach ( $t->paths() as $id => $path ) {
				$args = $this->arguments( $path );
				$purpose = 'recover' === $path['operation'] ? 'recover' : ( 'replace' === $path['operation'] ? 'option_reimport' : ( 'table' === $args['request']['target'] ? 'option_import' : 'table_export' ) );
				$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_' . $purpose . '_1', 'value' => 'valid', 'result' => 1 );
				$this->assertTrue( StorageConversion::authorize_request( $args['request'], 'recover' === $path['operation'], 1, 'replace' === $path['operation'] ) );
				if ( 'direct' !== $path['phase'] ) { $this->assertSame( $args['record'], StorageControl::decode( json_encode( $args['record'] ), 1 ) ); }
				$r = $m->retention( $id, $args, $this->capacities() );
				$this->assertSame( 'direct' !== $path['phase'], isset( $r['roots']['record'] ) );
				$this->assertSame( 'direct' !== $path['phase'], isset( $r['roots']['source'] ) );
				$this->assertSame( $m->extent( $args['request'], 8 ), $r['roots']['request'] );
				$this->assertSame( array_sum( $r['roots'] ), $r['existing_argument_graph_nominal_upper'] );
				$this->assertSame( $r['existing_argument_graph_nominal_upper'], $r['catch_reachable_graph_nominal_upper'] );
				$this->assertTrue( $r['original_outer_shells_conservatively_retained'] );
				$this->assertFalse( $r['deallocation_or_arena_release_proven'] );
				if ( 'direct' === $path['phase'] ) { $this->assertNotContains( 'source', $r['captured_families'] ); }
				if ( $path['source_may_be_null'] ) { $this->assertSame( $t->nominal( 32 ), $r['roots']['source'] ); }
			}
		}
	}

	public function test_nested_sharing_is_not_deep_copy_delta_or_zero_sized_existing_graph() {
		$m = new StorageProductArgumentRetentionV3( '5.4.0' ); $t = new StorageProductCallerTraceV3( '5.4.0' );
		$shared = str_repeat( 'x', 70000 ); $v = array( $shared, $shared );
		$expected = $t->nominal( 32 ) + $t->nominal( 72 ) + $t->nominal( 64 ) + 2 * $t->nominal( 72 ) +
			2 * ( $t->nominal( 32 ) + $t->nominal( 70001 ) );
		$this->assertSame( $expected, $m->extent( $v, 8 ) ); // Tree expansion may OVERcount aliases, never dedup by equal values.
		$a = array( &$shared, &$shared ); $this->assertSame( $expected, $m->extent( $a, 8 ) );
		$nested = array( $shared ); $aliases = array( &$nested, &$nested );
		$this->assertSame( $m->extent( array( $nested, $nested ), 8 ), $m->extent( $aliases, 8 ) );
		$this->assertGreaterThan( $t->shallow_array( array( 0, 1 ) ), $m->extent( $v, 8 ) );
		$this->assertSame( 2 * ( $t->nominal( 70001 + 20000 ) - $t->nominal( 70001 ) ), $m->extent( $v, 8, 20000 ) - $m->extent( $v, 8 ) );
	}

	public function test_historical_sparse_and_empty_array_capacity_is_not_element_count() {
		$m = new StorageProductArgumentRetentionV3( '5.6.20' ); $t = new StorageProductCallerTraceV3( '5.6.20' );
		$this->assertSame( $t->nominal( 256 ) - $t->nominal( 64 ), $m->extent( array(), 32 ) - $m->extent( array(), 8 ) );
		$this->assertSame( $t->nominal( 256 ) - $t->nominal( 64 ), $m->extent( array( 'x' => 1 ), 32 ) - $m->extent( array( 'x' => 1 ), 8 ) );
		$this->assertSame( $t->nominal( 32 ) + $t->nominal( 72 ) + $t->nominal( 8 * 524288 ), $m->extent( array(), 524288 ) );
		$v = null; for ( $i = 0; $i < 66; ++$i ) { $v = array( $v ); }
		$this->assertGreaterThan( 0, $m->extent( $v, 8 ) );
	}

	public function test_composition_reuses_one_joined_vector_and_keeps_frontiers_unresolved() {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$t = new StorageProductCallerTraceV3( $version ); $m = new StorageProductArgumentRetentionV3( $version );
			foreach ( $m->bindings()['product_files'] as $file => $hash ) { $this->assertSame( $hash, hash_file( 'sha256', dirname( __DIR__ ) . '/' . $file ) ); }
			foreach ( array( 0, 2, 4, 6, 10, 16 ) as $id ) {
				$args = $this->arguments( $t->paths()[$id] );
				$r = $m->compose( $id, 60000, 16, 1024, $args, $this->capacities() );
				$this->assertCount( count( ( new StorageScannerTraceCapacityV2( $version ) )->paths( 16 ) ) + count( StorageNativeScaleTrace::paths( 16 ) ), $r['cases'] );
				$rejected = $m->compose( $id, 60000, 16, 1024, $args, $this->capacities(), true );
				$this->assertCount( count( ( new StorageScannerTraceCapacityV2( $version ) )->paths( 16, true ) ) + count( StorageNativeScaleTrace::paths( 16 ) ), $rejected['cases'] );
				$this->assertGreaterThanOrEqual( $r['construction_argument_and_trace_nominal_upper'], $rejected['construction_argument_and_trace_nominal_upper'] );
				foreach ( $r['cases'] as $case ) {
					$this->assertSame( $case['trace']['catch_nominal'] + $r['retention']['existing_argument_graph_nominal_upper'], $case['catch_argument_and_trace_nominal_upper'] );
					$this->assertSame( $case['trace']['construction_nominal'] + $r['retention']['existing_argument_graph_nominal_upper'], $case['construction_argument_and_trace_nominal_upper'] );
				}
				foreach ( array( 'retained_arena_upper', 'whole_operation_nominal_upper', 'external_receiver_trace_nominal_upper', 'object_store_resize_nominal_upper' ) as $field ) { $this->assertNull( $r[$field] ); }
				foreach ( array( 'arena_retention_proven', 'shared_gc_frontier_proven', 'complete_trace_capacity_proven', 'fits_justified_helper_budget', 'task_scope_completed', 'children_released' ) as $field ) { $this->assertFalse( $r[$field] ); }
				$this->assertFalse( $r['filename_extent_proven'] ); $this->assertFalse( $r['historical_array_capacity_proven'] );
				$this->assertFalse( $r['retention']['string_backing_extent_proven'] );
			}
		}
	}

	public function test_unbounded_request_string_is_parameterized_not_capped_by_current_raw() {
		$t = new StorageProductCallerTraceV3( '5.4.0' ); $m = new StorageProductArgumentRetentionV3( '5.4.0' );
		$args = $this->arguments( $t->paths()[0] ); $small = $m->retention( 0, $args, $this->capacities() );
		$args['request']['nonce'] = str_repeat( 'n', 100000 ); $large = $m->retention( 0, $args, $this->capacities() );
		$this->assertSame( $t->nominal( 100001 ) - $t->nominal( 6 ), $large['existing_argument_graph_nominal_upper'] - $small['existing_argument_graph_nominal_upper'] );
	}

	public function test_v1_record_does_not_require_v2_field_or_duplicate_request_per_frame() {
		$t = new StorageProductCallerTraceV3( '5.6.20' ); $m = new StorageProductArgumentRetentionV3( '5.6.20' );
		$args = $this->arguments( $t->paths()[2] ); $args['record']['version'] = 1; unset( $args['record']['retained_digest'] );
		$this->assertSame( $args['record'], StorageControl::decode( json_encode( $args['record'] ), 1 ) );
		$r = $m->retention( 2, $args, $this->capacities() );
		$this->assertSame( $m->extent( $args['request'], 8 ), $r['roots']['request'] );
		$this->assertSame( $r, $m->retention( 3, $args, $this->capacities() ) ); // handle has no arguments.
		$contracts = $this->capacities(); $contracts['source']['string_extra_upper'] = 20000;
		$this->assertGreaterThan( $r['existing_argument_graph_nominal_upper'], $m->retention( 2, $args, $contracts )['existing_argument_graph_nominal_upper'] );
	}

	public function test_unsupported_domains_fail_before_composition() {
		$m = new StorageProductArgumentRetentionV3( '5.4.0' );
		foreach ( array( 0, 7, 9, 1048576, '8', -1 ) as $capacity ) {
			try { $m->extent( array(), $capacity ); $this->fail( 'invalid capacity accepted' ); }
			catch ( \InvalidArgumentException $e ) { $this->assertSame( 'product_argument_domain', $e->getMessage() ); }
		}
		foreach ( array( new \stdClass(), array_fill( 0, 9, null ), NAN, INF ) as $v ) {
			try { $m->extent( $v, 8 ); $this->fail( 'unsupported graph accepted' ); }
			catch ( \InvalidArgumentException $e ) { $this->assertSame( 'product_argument_domain', $e->getMessage() ); }
		}
		$cycle = array(); $cycle['self'] =& $cycle;
		try { $m->extent( $cycle, 8 ); $this->fail( 'cycle accepted' ); }
		catch ( \InvalidArgumentException $e ) { $this->assertSame( 'product_argument_domain', $e->getMessage() ); }
	}

	public function test_impossible_null_source_and_incomplete_roots_are_not_zero() {
		$t = new StorageProductCallerTraceV3( '5.4.0' ); $m = new StorageProductArgumentRetentionV3( '5.4.0' );
		foreach ( array( 'source', 'record', 'request' ) as $key ) {
			$args = $this->arguments( $t->paths()[2] ); unset( $args[$key] );
			try { $m->retention( 2, $args, $this->capacities() ); $this->fail( 'missing root accepted' ); }
			catch ( \InvalidArgumentException $e ) { $this->assertSame( 'product_argument_domain', $e->getMessage() ); }
		}
		$args = $this->arguments( $t->paths()[2] ); $args['source'] = null;
		$this->expectException( \InvalidArgumentException::class ); $m->retention( 2, $args, $this->capacities() );
	}
	public function test_hidden_backing_contract_is_mandatory_not_observed_from_modern_value() {
		$t = new StorageProductCallerTraceV3( '5.4.0' ); $m = new StorageProductArgumentRetentionV3( '5.4.0' );
		$args = $this->arguments( $t->paths()[2] );
		foreach ( array( 8, array( 'array_capacity_upper' => 8 ), array( 'array_capacity_upper' => 8, 'string_extra_upper' => -1 ),
			array( 'array_capacity_upper' => 8, 'string_extra_upper' => '0' ) ) as $bad ) {
			$contracts = $this->capacities(); $contracts['source'] = $bad;
			try { $m->retention( 2, $args, $contracts ); $this->fail( 'incomplete backing contract accepted' ); }
			catch ( \InvalidArgumentException $e ) { $this->assertSame( 'product_argument_domain', $e->getMessage() ); }
		}
	}
	public function test_unsupported_record_root_is_rejected_before_field_access() {
		$t = new StorageProductCallerTraceV3( '5.4.0' ); $m = new StorageProductArgumentRetentionV3( '5.4.0' );
		foreach ( array( new \stdClass(), 'not-a-record', null ) as $bad ) {
			$args = $this->arguments( $t->paths()[2] ); $args['record'] = $bad;
			try { $m->retention( 2, $args, $this->capacities() ); $this->fail( 'unsupported record root accepted' ); }
			catch ( \InvalidArgumentException $e ) { $this->assertSame( 'product_argument_domain', $e->getMessage() ); }
		}
	}
}
