<?php
/** Evidence-only synthetic harness. PHP5.4 syntax; never loaded by the plugin. */
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;

/** Observe successful control writes by reading the actual pinned SQL session.
 * No product hook, policy validator override, synthetic phase or commit receipt.
 */
class CalibrationCollectionConnection extends LegacyTransactionConnection {
	public $phases = array();
	public $statements = 0;
	public function query( $sql ) {
		++$this->statements;
		$result = parent::query( $sql );
		if ( 1 === $result && empty( $this->last_error ) &&
			( 0 === strpos( $sql, 'INSERT INTO' ) || 0 === strpos( $sql, 'UPDATE' ) ) &&
			false !== strpos( $sql, "'" . StorageAuthority::CONTROL_OPTION . "'" ) ) {
			$raw = $this->get_var( $this->prepare( 'SELECT option_value FROM `' . $this->prefix . 'options` WHERE option_name = %s', array( StorageAuthority::CONTROL_OPTION ) ) );
			$r = StorageControl::decode( $raw, get_current_blog_id() );
			if ( count( $this->phases ) >= 8 ) { throw new \RuntimeException( 'collection_phase_limit' ); }
			$this->phases[] = array( 'phase' => $r['phase'], 'backend' => $r['backend'], 'epoch' => $r['epoch'],
				'control_sha256' => hash( 'sha256', $raw ), 'producer' => 'pinned SQL readback after affected-row=1' );
		}
		return $result;
	}
}

class StorageCalibrationCollection {
	private static function check( $yes, $label ) { if ( true !== $yes ) { throw new \RuntimeException( $label ); } }
	private static function request( $target ) {
		$blog = get_current_blog_id();
		return array( 'blog_id' => $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => 'switch-to-' . $target,
			'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . ( 'table' === $target ? 'option_import' : 'table_export' ) . '_' . $blog ) );
	}
	/** Binding is trusted owned bootstrap input, NOT POST/configuration/profile.
	 * Caller/runner retains whole-process deadline, reap, drains and peak accounting.
	 * One case per fresh process. No reset/delta/terminal allocator invention.
	 */
	public static function run( $binding, $case ) {
		self::check( 'cli' === PHP_SAPI && defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) && true === FOOGM_STORAGE_TEST_DISPOSABLE &&
			defined( 'FOOGM_STORAGE_CALIBRATION_EVIDENCE_ONLY' ) && true === FOOGM_STORAGE_CALIBRATION_EVIDENCE_ONLY, 'collection_authority_required' );
		self::check( in_array( $case, array( 'representative', 'held-out', 'refuse-object', 'refuse-size' ), true ), 'collection_case_refused' );
		global $wpdb;
		$root = dirname( dirname( __DIR__ ) );
		self::check( is_array( $binding ) && isset( $binding['candidate'], $binding['files'], $binding['producers'], $binding['database'], $binding['prefix'], $binding['blog_id'] ) &&
			is_string( $binding['candidate'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/D', $binding['candidate'] ) &&
			defined( 'DB_NAME' ) && DB_NAME === $binding['database'] && $wpdb->prefix === $binding['prefix'] &&
			$wpdb->base_prefix === $wpdb->prefix && get_current_blog_id() === $binding['blog_id'] &&
			1 === preg_match( '/^(fgm_cal_[a-z0-9_]+|g1pa_20261016_off_)$/D', $wpdb->prefix ), 'collection_identity_refused' );
		$required = array( 'includes/class-storage-calibration.php', 'includes/class-storage-operations.php', 'includes/class-storage-conversion.php',
			'tests/support/StorageCalibrationCollection.php', 'tests/integration/storage-calibration-entry.php' );
		self::check( is_array( $binding['files'] ) && array_keys( $binding['files'] ) === $required && is_array( $binding['producers'] ) &&
			array_keys( $binding['producers'] ) === array( 'bootstrap', 'worker', 'phase', 'authority', 'database', 'output' ), 'collection_producer_binding_required' );
		foreach ( $binding['files'] as $file => $hash ) { self::check( is_string( $hash ) && hash_file( 'sha256', $root . '/' . $file ) === $hash, 'collection_source_mismatch' ); }
		foreach ( $binding['producers'] as $producer ) {
			self::check( is_array( $producer ) && isset( $producer['path'], $producer['sha256'] ) && is_file( $producer['path'] ) &&
				hash_file( 'sha256', $producer['path'] ) === $producer['sha256'], 'collection_producer_mismatch' );
		}
		self::check( current_user_can( 'manage_options' ) && true === StorageConversion::authorize_request( self::request( 'table' ) ), 'collection_authorization_refused' );
		$runtime = StorageCalibration::identity();
		self::check( is_array( $runtime ), 'collection_runtime_identity_required' );
		// This policy bounds the experiment ONLY. Never an approved-shaped profile,
		// installed filter, runtime exemption or recommendation-tier envelope claim.
		$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
		$pin = new CalibrationCollectionConnection( $wpdb );
		self::check( ( new LegacyStorageSchema( $pin ) )->verify(), 'collection_existing_schema_required' );
		$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION,
			StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
		foreach ( $names as $name ) { self::check( null === self::option( $pin, $name ), 'collection_nonempty_options' ); }
		self::check( null === $pin->get_var( $pin->prepare( 'SELECT 1 FROM `' . $wpdb->prefix . 'options` WHERE option_name LIKE %s LIMIT 1', array( $pin->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) ) ), 'collection_writer_present' );
		foreach ( array_keys( ( new LegacyStorageSchema( $pin ) )->definitions() ) as $table ) {
			self::check( '0' === (string) $pin->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ), 'collection_nonempty_shared_table' );
		}
		self::check( empty( $pin->last_error ), 'collection_database_read_failed' );
		// Only the confined test gate is instrumented. Product authorization,
		// admission, byte/depth limits, deadline, transactions and CAS stay intact.
		$gate = StorageMaintenanceGate::current();
		$property = new \ReflectionProperty( $gate, 'connection' );
		if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
		$property->setValue( $gate, $pin );
		$unapproved = array( 'version' => 1, 'status' => 'measurement_only', 'fingerprint' => StorageCalibration::fingerprint(),
			'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => $policy );
		$wrong = $unapproved; $wrong['status'] = 'approved'; // deliberately wrong negative control, never installed/returned as a profile.
		$wrong['fingerprint'] = str_repeat( '0', 64 );
		$refusals = array();
		foreach ( array( 'missing' => null, 'unapproved' => $unapproved, 'wrong-runtime' => $wrong ) as $label => $profile ) {
			$result = ( new StorageOperations( $profile ) )->dispatch( 'switch', self::request( 'table' ) );
			self::check( is_wp_error( $result ) && 'foogallery_migrate_storage_calibration_required' === $result->get_error_code(), 'collection_public_profile_admitted' );
			$refusals[$label] = $result->get_error_code();
		}
		self::check( 0 === $pin->statements, 'collection_public_refusal_wrote_sql' );
		$value = array( 'checkpoint' => array( 'cursor' => '009', 'done' => false ), 'revision' => 7, 'items' => array(),
			'binary' => "\0雪\\\"", 9 => false, '09' => null, 'float' => -0.0 );
		$count = 'held-out' === $case ? 17 : 128;
		for ( $i = 0; $i < $count; ++$i ) {
			$value['items'][ 'id-' . $i ] = array( 'ID' => (string) $i, 'type' => 'gallery', 'children' => array( null, false, '009', $i ), 'unknown' => str_repeat( 'x', 31 + ( $i % 7 ) ) );
		}
		if ( 'held-out' === $case ) { $value['deep'] = array( 'a' => array( 'b' => array( 'c' => array( true, null, 1.25 ) ) ) ); }
		$expected = ( new LegacyStateCodec() )->snapshot_digest( $value );
		$raw = serialize( $value ); unset( $value );
		if ( 'refuse-object' === $case ) { $raw = 'a:1:{i:0;O:8:"stdClass":0:{}}'; }
		if ( 'refuse-size' === $case ) { $raw = serialize( array( 'oversize' => str_repeat( 'x', 1048576 ) ) ); }
		$raw_hash = hash( 'sha256', $raw ); $raw_bytes = strlen( $raw );
		self::check( 1 === $pin->query( $pin->prepare( 'INSERT INTO `' . $wpdb->prefix . "options` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", array( FOOGALLERY_MIGRATE_OPTION_DATA, $raw ) ) ), 'collection_source_insert_failed' );
		unset( $raw );
		$start = microtime( true );
		$result = ( new StorageConversion( $policy ) )->switch_backend( self::request( 'table' ) );
		$refused = 0 === strpos( $case, 'refuse-' );
		$authority = array();
		if ( $refused ) {
			self::check( is_wp_error( $result ) && 'foogallery_migrate_storage_recovery_required' === $result->get_error_code() && array() === $pin->phases, 'collection_expected_refusal_missing' );
			self::check( $raw_hash === hash( 'sha256', self::option( $pin, FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'collection_refusal_changed_source' );
			foreach ( array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION ) as $name ) {
				self::check( null === self::option( $pin, $name ), 'collection_refusal_published_authority' );
			}
			foreach ( array_keys( ( new LegacyStorageSchema( $pin ) )->definitions() ) as $table ) {
				self::check( '0' === (string) $pin->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ), 'collection_refusal_staged_data' );
			}
		} else {
			foreach ( array( 'table', 'option' ) as $target ) {
				if ( 'option' === $target ) { $result = ( new StorageConversion( $policy ) )->switch_backend( self::request( $target ) ); }
				self::check( ! is_wp_error( $result ) && 'stable' === $result['phase'] && $target === $result['backend'], 'collection_conversion_failed' );
				$control = new StorageControl( $pin, get_current_blog_id() );
				$r = StorageControl::decode( $control->read( StorageAuthority::CONTROL_OPTION ), get_current_blog_id() );
				$sentinel = $control->read( StorageAuthority::AUTHORITY_OPTION );
				self::check( $r === $result && StorageControl::runtime_ready( $r, $sentinel ) && $expected === $r['logical_digest'], 'collection_authority_parity_failed' );
				self::check( $raw_hash === hash( 'sha256', self::option( $pin, StorageAuthority::STORAGE_BACKUP_OPTION ) ), 'collection_backup_changed' );
				self::check( 'table' !== $target || null === self::option( $pin, FOOGALLERY_MIGRATE_OPTION_DATA ), 'collection_forward_source_retained' );
				$authority[] = array( 'backend' => $r['backend'], 'epoch' => $r['epoch'], 'phase' => $r['phase'], 'logical_sha256' => $r['logical_digest'],
					'control_sha256' => hash( 'sha256', json_encode( $r ) ), 'sentinel_sha256' => null === $sentinel ? null : hash( 'sha256', $sentinel ) );
			}
			$decoded = ( new \FooPlugins\FooGalleryMigrate\LegacyOptionDecoder( $policy['max_raw_bytes'] ) )->decode( self::option( $pin, FOOGALLERY_MIGRATE_OPTION_DATA ) );
			self::check( $expected === ( new LegacyStateCodec() )->snapshot_digest( $decoded ), 'collection_reverse_parity_failed' ); unset( $decoded );
			self::check( array( 'copying', 'verified', 'flipped', 'stable', 'copying', 'verified', 'flipped', 'stable' ) === array_map( function ( $r ) { return $r['phase']; }, $pin->phases ), 'collection_phase_coverage_failed' );
		}
		self::check( null === self::option( $pin, StorageAuthority::MAINTENANCE_OPTION ), 'collection_owner_not_released' );
		return array( 'status' => 'evidence_only', 'public_acceptance' => false, 'profile_approved' => false, 'method_approved' => false,
			'case' => $case, 'binding' => $binding, 'runtime_identity' => $runtime, 'runtime_binary_sha256' => hash_file( 'sha256', PHP_BINARY ),
			'policy_domain' => 'fixed synthetic collection only; not a measured production envelope', 'experimental_policy' => $policy,
			'raw_bytes' => $raw_bytes, 'source_sha256' => $raw_hash, 'expected_logical_sha256' => $refused ? null : $expected,
			'public_refusals' => $refusals, 'conversion_refused' => $refused, 'conversion_error' => $refused ? $result->get_error_code() : null, 'phases' => $pin->phases, 'authority' => $authority,
			'operation_seconds' => microtime( true ) - $start,
			'fresh_process_peaks' => array( 'scope' => 'bootstrap-inclusive process so far, NOT reset/operation delta/terminal',
				'php_used_bytes' => memory_get_peak_usage( false ), 'php_allocated_bytes' => memory_get_peak_usage( true ), 'terminal_allocator' => null ),
			'cleanup' => 'owned outer harness responsibility; collector retains source/backup/table evidence',
			'limits' => 'finite synthetic CLI forward/reverse only; not real WP/public-path/floor/large-state/whole-worker teardown acceptance' );
	}
	private static function option( $pin, $name ) {
		$value = $pin->get_var( $pin->prepare( 'SELECT option_value FROM `' . $pin->prefix . 'options` WHERE option_name = %s LIMIT 1', array( $name ) ) );
		self::check( empty( $pin->last_error ), 'collection_option_read_failed' ); return $value;
	}
}
