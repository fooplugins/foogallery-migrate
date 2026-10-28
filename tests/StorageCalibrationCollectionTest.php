<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use PHPUnit\Framework\TestCase;

/** Native behavior tests, not literal54 or production calibration approval. */
class StorageCalibrationCollectionTest extends TestCase {
	public function test_collection_entry_exists_and_refuses_unowned_execution() {
		$path = __DIR__ . '/support/StorageCalibrationCollection.php';
		$this->assertFileExists( $path, 'Missing evidence-only actual conversion collection entry' );
		require_once $path;
		$this->expectExceptionMessage( 'collection_authority_required' );
		StorageCalibrationCollection::run( array(), 'representative' );
	}

	/** @dataProvider confinedRefusals
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_confined_collection_guards_refuse_before_database_calls( $kind, $expected ) {
		require_once __DIR__ . '/support/StorageCalibrationCollection.php';
		define( 'FOOGM_STORAGE_TEST_DISPOSABLE', true );
		define( 'FOOGM_STORAGE_CALIBRATION_EVIDENCE_ONLY', true );
		if ( ! defined( 'DB_NAME' ) ) { define( 'DB_NAME', 'synthetic-no-database-connection' ); }
		$db = new CollectionNoSqlWpdb(); $GLOBALS['wpdb'] = $db;
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => 'unauthorized' !== $kind );
		$root = dirname( __DIR__ );
		$b = array( 'candidate' => str_repeat( '0', 40 ), 'files' => array(), 'producers' => array(), 'database' => DB_NAME, 'prefix' => $db->prefix, 'blog_id' => 1 );
		foreach ( array( 'includes/class-storage-calibration.php', 'includes/class-storage-operations.php', 'includes/class-storage-conversion.php', 'tests/support/StorageCalibrationCollection.php', 'tests/integration/storage-calibration-entry.php' ) as $f ) { $b['files'][$f] = hash_file( 'sha256', $root . '/' . $f ); }
		foreach ( array( 'bootstrap', 'worker', 'phase', 'authority', 'database', 'output' ) as $p ) { $b['producers'][$p] = array( 'path' => __FILE__, 'sha256' => hash_file( 'sha256', __FILE__ ) ); }
		$case = 'representative';
		if ( 'unknown-case' === $kind ) { $case = 'request-selected-policy'; }
		if ( 'missing-binding' === $kind ) { $b = array(); }
		if ( 'foreign-prefix' === $kind ) { $db->prefix = $db->base_prefix = 'wp_'; $b['prefix'] = 'wp_'; }
		if ( 'source-mismatch' === $kind ) { $b['files']['includes/class-storage-conversion.php'] = str_repeat( '0', 64 ); }
		if ( 'producer-mismatch' === $kind ) { $b['producers']['worker']['sha256'] = str_repeat( '0', 64 ); }
		$caught = null;
		try { StorageCalibrationCollection::run( $b, $case ); } catch ( \RuntimeException $error ) { $caught = $error->getMessage(); }
		$this->assertSame( $expected, $caught );
		$this->assertSame( 0, $db->calls );
	}
	public function confinedRefusals() {
		return array(
			array( 'unknown-case', 'collection_case_refused' ), array( 'missing-binding', 'collection_identity_refused' ),
			array( 'foreign-prefix', 'collection_identity_refused' ), array( 'source-mismatch', 'collection_source_mismatch' ),
			array( 'producer-mismatch', 'collection_producer_mismatch' ), array( 'unauthorized', 'collection_authorization_refused' )
		);
	}

	/** A fresh process can lower the real limit even after another suite retained memory.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_untrusted_profiles_cannot_bypass_release_memory_refusal_before_sql() {
		$names = array( 'wpdb', 'wp_version', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce', 'foogallery_migrate_test_filters' );
		$saved = array();
		foreach ( $names as $name ) { $saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null ); }
		$old_limit = ini_get( 'memory_limit' );
		try {
			$this->assertNotFalse( ini_set( 'memory_limit', '128M' ) );
			$this->assertSame( '128M', ini_get( 'memory_limit' ) );
			$db = new CollectionNoSqlWpdb(); $GLOBALS['wpdb'] = $db;
			$GLOBALS['wp_version'] = 'native-test-not-wordpress';
			$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
			$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
			$GLOBALS['foogallery_migrate_test_filters'] = array();
			$release = StorageCapacityPolicy::resolve();
			$this->assertIsArray( $release );
			$this->assertSame( 1, $release['release_version'] );
			$this->assertSame( 3145728, $release['limits']['max_raw_bytes'] );
			$this->assertSame( 134217728, $release['reserve_bytes'] );
			$profile = array( 'version' => 1, 'status' => 'measurement_only', 'fingerprint' => StorageCalibration::fingerprint(),
				'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 1, 'reserve_bytes' => 1 ) );
			$wrong = $profile; $wrong['status'] = 'approved'; $wrong['fingerprint'] = str_repeat( '0', 64 );
			foreach ( array( null, $profile, $wrong ) as $p ) {
				$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_storage_calibration'] = function () use ( $p ) { return $p; };
				$this->assertSame( $release, StorageCapacityPolicy::resolve(), 'Legacy filter data must not change the shipped policy.' );
				foreach ( array( 'preflight', 'switch', 'recover', 'replace' ) as $op ) {
					$purpose = 'recover' === $op ? 'recover' : ( 'replace' === $op ? 'option_reimport' : 'option_import' );
					$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_' . $purpose . '_1', 'value' => 'valid', 'result' => 1 );
					$r = array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'recover' === $op ? 'recover-storage' : ( 'replace' === $op ? 'replace-retained-with-table' : 'switch-to-table' ), 'nonce' => 'valid' );
					$this->assertSame( 'foogallery_migrate_storage_decode_refused', ( new StorageOperations() )->dispatch( $op, $r )->get_error_code() );
				}
			}
			$this->assertSame( 0, $db->calls );
		} catch ( \Exception $error ) {
			ini_set( 'memory_limit', $old_limit );
			foreach ( $saved as $name => $value ) { if ( $value[0] ) { $GLOBALS[$name] = $value[1]; } else { unset( $GLOBALS[$name] ); } }
			throw $error;
		} catch ( \Throwable $error ) {
			ini_set( 'memory_limit', $old_limit );
			foreach ( $saved as $name => $value ) { if ( $value[0] ) { $GLOBALS[$name] = $value[1]; } else { unset( $GLOBALS[$name] ); } }
			throw $error;
		}
		ini_set( 'memory_limit', $old_limit );
		foreach ( $saved as $name => $value ) { if ( $value[0] ) { $GLOBALS[$name] = $value[1]; } else { unset( $GLOBALS[$name] ); } }
	}
}
class CollectionNoSqlWpdb {
	public $prefix = 'fgm_cal_unit_';
	public $base_prefix = 'fgm_cal_unit_';
	public $calls = 0;
	public function __call( $name, $args ) { ++$this->calls; throw new \LogicException( 'Unexpected collection SQL: ' . $name ); }
}
