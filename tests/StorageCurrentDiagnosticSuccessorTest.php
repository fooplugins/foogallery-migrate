<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageReceiverNewCompositionV3.php';

/** Fresh modern diagnostic only. No SQL, calibrated admission or PHP5 claim. */
class StorageCurrentDiagnosticSuccessorTest extends TestCase {
	public function test_originals_and_provenance_remain_historical_not_current_acceptance() {
		$originals = array(
			'StorageProductCallerTrace.php' => 'f7d2eb510f87fa02e4d8a7da474a94caaab42f6297b14fc445b7c70442243ac9',
			'StorageProductArgumentRetention.php' => '5f2bb4983dae1ece2d5f970fffe493fff62b12bbb66e4e2a7490ac9c63ac050e',
			'StorageReceiverNewComposition.php' => 'ce4df0d455c76f15ba2a59cb7e4599e45bd5b1d1b3a2ca0f7a6196dfba00e99b' );
		foreach ( $originals as $file => $hash ) { $this->assertSame( $hash, hash_file( 'sha256', __DIR__ . '/support/' . $file ) ); }
		$old = ( new \ReflectionClass( StorageProductCallerTrace::class ) )->newInstanceWithoutConstructor(); $new = new StorageProductCallerTraceV3( '5.4.0' );
		$this->assertNotSame( '957291fa4d17323fd877e0d11045a042afe17f962b58148a5e7bd5d07f6d89b8', $new->bindings()['product_files']['includes/class-storage-operations.php'] );
		$this->assertSame( ( new StorageScannerTraceWitnessV2( '5.4.0' ) )->bindings(), $new->bindings()['local_bindings'] );
		$this->assertSame( '2b304920b0de417aa34d8263629a24bec279052919b0fabb98da207686fa826a', $new->bindings()['original_material_members_manifest_sha256'] );
		$this->expectExceptionMessage( 'diagnostic_binding:includes/class-storage-operations.php' );
		$b = $new->bindings(); $b['product_files']['includes/class-storage-operations.php'] = '957291fa4d17323fd877e0d11045a042afe17f962b58148a5e7bd5d07f6d89b8';
		$new->assert_witness( $old->paths(), $b, dirname( __DIR__ ) );
	}
	/** @dataProvider misbound_evidence */
	public function test_hash_only_rebinding_stale_lines_and_wrong_argument_edges_are_rejected( $mutation ) {
		$m = new StorageProductCallerTraceV3( '5.4.0' ); $paths = $m->paths();
		if ( 'historical_lines' === $mutation ) { $paths = ( new \ReflectionClass( StorageProductCallerTrace::class ) )->newInstanceWithoutConstructor()->paths(); }
		elseif ( 'argument' === $mutation ) { $paths[0]['edges'][2]['actual_arguments'] = array( '$request', 'true' ); }
		elseif ( 'callee' === $mutation ) { $paths[0]['edges'][3]['callee'] = 'replace_retained'; }
		elseif ( 'receiver' === $mutation ) { $paths[0]['edges'][3]['actual_receiver'] = '$unrelated'; }
		else { --$paths[1]['edges'][4]['line']; }
		$this->expectExceptionMessage( 'diagnostic_edge_witness' );
		$m->assert_witness( $paths, $m->bindings(), dirname( __DIR__ ) );
	}
	public static function misbound_evidence() { return array_map( function ( $v ) { return array( $v ); }, array( 'historical_lines', 'argument', 'callee', 'line', 'receiver' ) ); }
	public function test_both_dependent_compositions_inherit_the_exact_successor() {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$t = new StorageProductCallerTraceV3( $version ); $a = new StorageProductArgumentRetentionV3( $version ); $r = new StorageReceiverNewCompositionV3( $version );
			$b = $a->bindings(); unset( $b['product_files']['includes/class-legacy-state-codec.php'] );
			$this->assertSame( $t->bindings(), $b );
			$this->assertSame( $a->bindings(), $r->bindings()['retained_helpers'] );
			$t->assert_witness( $t->paths(), $b, dirname( __DIR__ ) );
			$this->assertCount( 18, $t->paths() );
		}
	}
	/** @dataProvider receiver_entries */
	public function test_fresh_actual_builtin_receiver_caller_suffix_at_begin_without_sql( $operation, $handle ) {
		$names = array( 'wpdb', 'wp_version', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce', 'foogallery_migrate_test_filters' );
		$saved = array(); foreach ( $names as $name ) { $saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null ); }
		$post = $_POST; $server = $_SERVER;
		try {
			$db = new CurrentDiagnosticSuccessorNoSqlWpdb(); $GLOBALS['wpdb'] = $db;
			$GLOBALS['wp_version'] = '6.9-test'; $GLOBALS['foogallery_migrate_test_blog_id'] = 1;
			$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
			$purpose = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'option_reimport' : 'option_import' );
			$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_' . $purpose . '_1', 'value' => 'valid', 'result' => 1 );
			$request = array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'recover' === $operation ? 'recover-storage' : ( 'replace' === $operation ? 'replace-retained-with-table' : 'switch-to-table' ), 'nonce' => 'valid' );
			// Existing synthetic calibration pattern, never an approved runtime profile.
			$profile = array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(), 'evidence_sha256' => str_repeat( 'a', 64 ),
				'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
			$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function () use ( $profile ) { return $profile; };
			if ( $handle ) {
				$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array_merge( array( 'action' => 'foogallery_migrate_storage', 'operation' => $operation ), $request );
				try { StorageOperations::handle(); $this->fail( 'Receiver did not terminate' ); }
				catch ( \FooGalleryMigrateTestJsonResponse $error ) { $this->assertSame( 'foogallery_migrate_storage_recovery_required', $error->data['code'] ); }
			} else {
				$result = ( new StorageOperations( $profile ) )->dispatch( $operation, $request );
				$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
			}
			$m = new StorageProductCallerTraceV3( '5.4.0' );
			$path = array_values( array_filter( $m->paths(), function ( $p ) use ( $operation, $handle ) { return $p['operation'] === $operation && $p['receiver_handle'] === $handle && 'direct' === $p['phase']; } ) )[0];
			$frames = array_slice( $path['frames'], 1 );
			$this->assertSame( array_column( $frames, 'method' ), array_column( $db->trace, 'function' ) );
			foreach ( $frames as $i => $frame ) {
				$actual = $db->trace[$i];
				$this->assertSame( $frame['class'], $actual['class'] );
				$this->assertCount( count( $frame['arguments'] ), $actual['args'] );
				foreach ( $frame['arguments'] as $j => $kind ) {
					$expected = 'request' === $kind ? $request : ( 'operation' === $kind ? $operation : 'replace' === $operation );
					$this->assertSame( $expected, $actual['args'][$j] );
				}
				if ( isset( $path['edges'][$i + 2] ) ) {
					$edge = $path['edges'][$i + 2];
					$this->assertSame( $edge['line'], $actual['line'] );
					$this->assertSame( realpath( dirname( __DIR__ ) . '/' . $edge['file'] ), $actual['file'] );
				}
			}
			$this->assertSame( array(), $db->sql ); // begin refuses a real connection; no fake transaction success.
		} finally {
			foreach ( $saved as $name => $value ) { if ( $value[0] ) { $GLOBALS[$name] = $value[1]; } else { unset( $GLOBALS[$name] ); } }
			$_POST = $post; $_SERVER = $server;
		}
	}
	public static function receiver_entries() {
		$out = array(); foreach ( array( 'switch', 'replace', 'recover' ) as $op ) { foreach ( array( false, true ) as $handle ) { $out[] = array( $op, $handle ); } } return $out;
	}
}
class CurrentDiagnosticSuccessorNoSqlWpdb {
	public $prefix = 'wp_';
	public $sql = array();
	public $trace = array();
	public function __get( $name ) {
		if ( 'dbh' !== $name ) { throw new \LogicException( 'Unexpected database property' ); }
		foreach ( debug_backtrace( 0 ) as $frame ) {
			if ( isset( $frame['class'] ) && in_array( $frame['class'], array( 'FooPlugins\\FooGalleryMigrate\\StorageConversion', 'FooPlugins\\FooGalleryMigrate\\StorageOperations' ), true ) &&
				in_array( $frame['function'], array( 'switch_storage', 'switch_backend', 'replace_retained', 'recover', 'dispatch', 'handle' ), true ) ) { $this->trace[] = $frame; }
		}
		return null;
	}
	public function __call( $method, $args ) { $this->sql[] = $method; throw new \LogicException( 'Unexpected SQL' ); }
}
