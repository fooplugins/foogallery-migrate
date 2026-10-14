<?php
/**
 * Trusted server calibration identity. Measurements never approve themselves.
 * No profile, filter default, request field or runtime mismatch grants a policy.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageCalibration {
	/** Exact PHP/WordPress/allocator/codec implementation identity, not a SAPI waiver. */
	public static function fingerprint() {
		$runtime = self::identity();
		if ( null === $runtime ) { return null; }
		$encoded = json_encode( $runtime );
		// Raw identity bytes are authoritative. Never repair them or hash false
		// as an empty string: distinct malformed environments would then collide.
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : null;
	}

	/** Read-only evidence identity; current environment is NOT allocator startup attestation. */
	public static function identity() {
		global $wp_version;
		if ( ! is_string( $wp_version ) || '' === $wp_version ) { return null; }
		$extensions = array();
		foreach ( get_loaded_extensions() as $extension ) { $extensions[$extension] = phpversion( $extension ); }
		ksort( $extensions );
		$runtime = array( PHP_VERSION, PHP_SAPI, PHP_INT_SIZE, PHP_OS, php_uname( 'm' ), php_uname( 'r' ), PHP_DEBUG, PHP_ZTS, getenv( 'USE_ZEND_ALLOC' ),
			bin2hex( pack( 'L', 1 ) ), $wp_version, $extensions );
		$runtime['allocator_environment'] = array();
		foreach ( array( 'USE_ZEND_ALLOC', 'ZEND_MM_SEG_SIZE', 'ZEND_MM_MEM_TYPE', 'ZEND_MM_COMPACT' ) as $name ) {
			$runtime['allocator_environment'][$name] = getenv( $name );
		}
		foreach ( array( 'memory_limit', 'max_execution_time', 'precision', 'serialize_precision', 'zend.enable_gc', 'opcache.enable', 'opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size' ) as $setting ) {
			$runtime[$setting] = ini_get( $setting );
		}
		// These bytes define the bounded coordinator and its hydration/staging path.
		foreach ( array( 'storage-calibration', 'storage-operations', 'storage-conversion', 'storage-retirement', 'storage-preflight',
			'legacy-option-decoder', 'legacy-collection-envelope', 'legacy-state-codec', 'legacy-record-codec', 'legacy-table-snapshot', 'legacy-table-reader',
			'legacy-transaction-connection', 'storage-control', 'storage-maintenance-gate', 'storage-authority',
			'legacy-storage-schema', 'migrator-settings', 'content-scan-repository', 'image-tag-queue-repository', 'migrator-engine', 'init' ) as $class ) {
			$path = dirname( __FILE__ ) . '/class-' . $class . '.php';
			if ( ! is_readable( $path ) ) { return null; }
			$hash = hash_file( 'sha256', $path );
			if ( ! is_string( $hash ) ) { return null; }
			$runtime[$class] = $hash;
		}
		return $runtime;
	}

	/**
	 * Profile is supplied by trusted PHP configuration ONLY, after authorization.
	 * approved certifies independently reviewed envelope evidence, not that these
	 * shape checks can prove a benchmark covers every admitted legacy graph.
	 */
	public static function policy( $profile ) {
		if ( ! is_array( $profile ) || 5 !== count( $profile ) ||
			! isset( $profile['version'], $profile['status'], $profile['fingerprint'], $profile['evidence_sha256'], $profile['policy'] ) ||
			1 !== $profile['version'] || 'approved' !== $profile['status'] ||
			! is_string( $profile['fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $profile['fingerprint'] ) ||
			! is_string( $profile['evidence_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $profile['evidence_sha256'] ) ||
			$profile['fingerprint'] !== self::fingerprint() || PHP_VERSION_ID < 70000 ) { return self::required(); }
		$budget = StoragePreflight::decode_budget( 0, '-1', 0, $profile['policy'] );
		return is_wp_error( $budget ) ? self::required() : $profile['policy'];
	}

	private static function required() {
		return new \WP_Error( 'foogallery_migrate_storage_calibration_required',
			__( 'No approved migration storage calibration matches this runtime and implementation. No conversion was started. Retain all storage; administrator-assisted calibration is required.', 'foogallery-migrate' ) );
	}
}
