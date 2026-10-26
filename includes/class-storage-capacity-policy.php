<?php
/** Fixed release data, never a request/profile approval mechanism (PHP 5.4). */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StorageCapacityPolicy {
	const MAX_BYTES = 65536;
	const MAX_CLASSES = 16;

	public static function phases() {
		return array( 'fetch_scan', 'option_decode', 'stage', 'table_verify', 'reverse_encode_write', 'option_verify', 'commit_cleanup_response' );
	}
	public static function dimensions() { return array( 'R', 'T', 'N', 'E', 'S', 'M', 'H', 'D', 'W' ); }
	public static function ceilings() {
		return array( 'max_raw_bytes' => 16777216, 'max_tokens' => 262144, 'max_depth' => 64, 'max_heads' => 100,
			'max_rows' => 24576, 'max_bytes' => 16777216, 'max_scalar_bytes' => 1048576, 'max_row_bytes' => 1048576,
			'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
			'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10, 'max_work' => 16777216,
			'max_response_bytes' => 65536, 'copy_batch_rows' => 1024, 'copy_batch_bytes' => 1048576 );
	}

	/** One shipped release policy. Host identity and customer certificates are not admission inputs. */
	public static function resolve() {
		$site = StorageAuthority::table_mode_error();
		if ( is_wp_error( $site ) ) { return $site; }
		try {
			$path = dirname( __FILE__ ) . '/storage-capacity-policy.json';
			if ( ! is_file( $path ) || ! is_readable( $path ) ) { self::fail(); }
			$raw = file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
			return self::release_policy( $raw );
		} catch ( \Exception $error ) { return self::required(); }
		catch ( \Throwable $error ) { return self::required(); }
	}

	/** Strict release-owned data. Old per-host class methods remain diagnostic only. */
	public static function release_policy( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_BYTES ) { self::fail(); }
		$p = json_decode( $raw, true, 8 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $p ) || json_encode( $p ) !== trim( $raw ) ) { self::fail(); }
		self::keys( $p, array( 'version', 'max_raw_bytes', 'decode_amplification', 'reserve_bytes', 'phase_headroom_bytes' ) );
		if ( 1 !== $p['version'] ) { self::fail(); }
		self::integer( $p['max_raw_bytes'], 1, 3145728 );
		self::integer( $p['decode_amplification'], 16, 64 );
		self::integer( $p['reserve_bytes'], 134217728, 268435456 );
		self::integer( $p['phase_headroom_bytes'], 33554432, 67108864 );
		$limits = self::ceilings();
		$limits['max_raw_bytes'] = $p['max_raw_bytes'];
		$limits['memory_ceiling_bytes'] = 536870912;
		// The native 20K SQL fixture showed a 512-row turn at 9.898 seconds
		// with fixed query work. Yield at 256 for commit margin; source, byte,
		// structural and total row caps remain unchanged.
		$limits['copy_batch_rows'] = 256;
		return array( 'release_version' => 1, 'limits' => $limits, 'reserve_bytes' => $p['reserve_bytes'],
			'amplification' => $p['decode_amplification'], 'phase_headroom_bytes' => $p['phase_headroom_bytes'] );
	}

	/** Pure validation is not release receiving or permission to execute storage. */
	public static function parse( $raw, $implementation ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES || '' === $raw ) { self::fail(); }
		$p = json_decode( $raw, true, 16 );
		// Canonical JSON is intentional: duplicate keys, numeric coercion and
		// malformed Unicode cannot disappear in decode/re-encode normalization.
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $p ) || json_encode( $p ) !== trim( $raw ) ) { self::fail(); }
		self::keys( $p, array( 'schema_version', 'model_version', 'implementation_sha256', 'training_evidence_sha256', 'classes' ) );
		if ( 2 !== $p['schema_version'] || 1 !== $p['model_version'] || ! self::digest( $implementation ) ||
			$p['implementation_sha256'] !== $implementation || ! self::digest( $p['training_evidence_sha256'] ) ||
			! is_array( $p['classes'] ) || ! count( $p['classes'] ) || count( $p['classes'] ) > self::MAX_CLASSES ) { self::fail(); }
		foreach ( $p['classes'] as $id => $class ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id ) ) { self::fail(); }
			self::validate_class( $class );
		}
		return $p;
	}

	public static function validate_class( $c ) {
		self::keys( $c, array( 'eligibility', 'limits', 'reserve_bytes', 'phase_envelopes', 'qualification_id' ) );
		self::keys( $c['limits'], array_keys( self::ceilings() ) );
		foreach ( self::ceilings() as $key => $maximum ) { self::integer( $c['limits'][$key], 1, $maximum ); }
		self::integer( $c['reserve_bytes'], 1, $c['limits']['memory_ceiling_bytes'] );
		if ( ! is_string( $c['qualification_id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,96}$/D', $c['qualification_id'] ) ) { self::fail(); }
		self::keys( $c['phase_envelopes'], self::phases() );
		foreach ( $c['phase_envelopes'] as $phase ) {
			self::keys( $phase, array( 'zend', 'native' ) );
			foreach ( $phase as $envelope ) {
				self::keys( $envelope, array( 'fixed_bytes', 'per_dimension' ) );
				self::integer( $envelope['fixed_bytes'], 0, PHP_INT_MAX );
				// Explicit coefficients for ALL dimensions: omitted terms cannot
				// silently acquire zero cost. Numeric qualification remains external.
				self::keys( $envelope['per_dimension'], self::dimensions() );
				foreach ( $envelope['per_dimension'] as $n ) { self::integer( $n, 0, PHP_INT_MAX ); }
			}
		}
		$e = $c['eligibility'];
		self::keys( $e, array( 'php_versions', 'wp_versions', 'sapi', 'word_bytes', 'endian', 'os_family', 'allocator', 'debug', 'zts', 'driver_family', 'driver_versions', 'required_ini', 'required_capabilities' ) );
		foreach ( array( 'php_versions', 'wp_versions', 'sapi', 'driver_versions', 'required_capabilities' ) as $key ) { self::strings( $e[$key] ); }
		if ( ! in_array( $e['word_bytes'], array( 4, 8 ), true ) || ! in_array( $e['endian'], array( 'little', 'big' ), true ) ||
			! is_bool( $e['debug'] ) || ! is_bool( $e['zts'] ) || 'mysqli' !== $e['driver_family'] ||
			! in_array( $e['os_family'], array( 'Linux', 'Darwin', 'Windows' ), true ) ||
			! in_array( $e['allocator'], array( 'zend', 'system' ), true ) ) { self::fail(); }
		if ( ! is_array( $e['required_ini'] ) || ! count( $e['required_ini'] ) || count( $e['required_ini'] ) > 16 ) { self::fail(); }
		$settings = array( 'precision', 'serialize_precision', 'zend.enable_gc', 'opcache.enable', 'opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size' );
		foreach ( $settings as $name ) { if ( ! array_key_exists( $name, $e['required_ini'] ) ) { self::fail(); } }
		foreach ( $e['required_ini'] as $name => $value ) {
			if ( ! in_array( $name, $settings, true ) || ( ! is_string( $value ) && false !== $value ) || ( is_string( $value ) && strlen( $value ) > 64 ) ) { self::fail(); }
		}
		return $c;
	}

	/**
	 * Native effective Zend-MM state, not the mutable USE_ZEND_ALLOC environment.
	 * phpinfo(INFO_GENERAL) calls is_zend_mm() in the engine (including PHP 5.4).
	 * "system" means Zend MM disabled, NOT proof of libc/backing allocator identity.
	 * Bound and discard ALL output, including on unsupported/ambiguous formats.
	 */
	private static function allocator() {
		if ( ! class_exists( 'ReflectionFunction', false ) ) { return null; }
		foreach ( array( 'phpinfo', 'ob_start', 'ob_get_level', 'ob_end_flush' ) as $name ) {
			if ( ! function_exists( $name ) ) { return null; }
			$function = new \ReflectionFunction( $name );
			if ( ! $function->isInternal() ) { return null; }
		}
		$raw = ''; $overflow = false; $level = \ob_get_level();
		$discard = function ( $chunk ) use ( &$raw, &$overflow ) {
			if ( ! $overflow && strlen( $chunk ) <= 65536 - strlen( $raw ) ) { $raw .= $chunk; }
			else { $overflow = true; $raw = ''; }
			return '';
		};
		if ( ! \ob_start( $discard, 4096 ) ) { return null; }
		$ok = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_phpinfo -- The bounded discard handler above returns no output; only the allocator enum leaves this method.
		try { $ok = \phpinfo( INFO_GENERAL ); }
		catch ( \Exception $error ) { $ok = false; }
		catch ( \Throwable $error ) { $ok = false; }
		// PHP 5.4 clean discards an unflushed short buffer before the callback.
		// Flush into our discard handler instead; it always returns empty output.
		$closed = \ob_end_flush();
		if ( ! $ok || ! $closed || $overflow || \ob_get_level() !== $level ) { return null; }
		return self::allocator_row( $raw );
	}

	/** Native CLI text and web-SAPI HTML only; no guessed fallback. */
	private static function allocator_row( $raw ) {
		$pattern = '~^(?:Zend Memory Manager => (enabled|disabled)|<tr><td class="e">Zend Memory Manager </td><td class="v">(enabled|disabled) </td></tr>)[\r]?$~mD';
		if ( 1 !== preg_match_all( $pattern, $raw, $matches, PREG_SET_ORDER ) ) { return null; }
		$mode = '' !== $matches[0][1] ? $matches[0][1] : $matches[0][2];
		return 'enabled' === $mode ? 'zend' : 'system';
	}

	/** Only observed allocation facts participate; diagnostic host identity does not. */
	public static function facts() {
		global $wp_version;
		$ini = array();
		foreach ( array( 'precision', 'serialize_precision', 'zend.enable_gc', 'opcache.enable', 'opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size' ) as $name ) { $ini[$name] = ini_get( $name ); }
		// Observe the engine, never a request/filter/constant or current getenv().
		$capabilities = array();
		foreach ( array( 'hash_init', 'json_decode', 'mysqli_query', 'getrusage' ) as $name ) { if ( function_exists( $name ) ) { $capabilities[] = $name; } }
		$instrumented = false;
		foreach ( array( 'xdebug', 'xhprof', 'tideways', 'blackfire', 'uopz', 'runkit' ) as $name ) { if ( extension_loaded( $name ) ) { $instrumented = true; } }
		return array( 'php_versions' => PHP_VERSION, 'wp_versions' => $wp_version, 'sapi' => PHP_SAPI, 'word_bytes' => PHP_INT_SIZE,
			'endian' => '01000000' === bin2hex( pack( 'L', 1 ) ) ? 'little' : 'big', 'os_family' => 0 === strpos( PHP_OS, 'WIN' ) ? 'Windows' : PHP_OS,
			'allocator' => self::allocator(), 'debug' => (bool) PHP_DEBUG, 'zts' => (bool) PHP_ZTS, 'driver_family' => function_exists( 'mysqli_query' ) ? 'mysqli' : null,
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_get_client_info -- Reads client-library version metadata only; does not connect to or query a database.
			'driver_versions' => function_exists( 'mysqli_get_client_info' ) ? mysqli_get_client_info() : null, 'required_ini' => $ini,
			'required_capabilities' => $capabilities, 'instrumented' => $instrumented );
	}

	public static function select( $policy, $facts ) {
		if ( ! is_array( $facts ) || ! isset( $facts['instrumented'] ) || false !== $facts['instrumented'] ) { self::fail(); }
		$matches = array();
		foreach ( $policy['classes'] as $id => $class ) {
			$match = true;
			foreach ( $class['eligibility'] as $name => $expected ) {
				if ( ! array_key_exists( $name, $facts ) ) { self::fail(); }
				if ( 'required_capabilities' === $name ) { $ok = is_array( $facts[$name] ) && ! array_diff( $expected, $facts[$name] ); }
				elseif ( in_array( $name, array( 'php_versions', 'wp_versions', 'sapi', 'driver_versions' ), true ) ) { $ok = in_array( $facts[$name], $expected, true ); }
				else { $ok = $facts[$name] === $expected; }
				if ( ! $ok ) { $match = false; }
			}
			if ( $match ) { $matches[$id] = $class; }
		}
		if ( 1 !== count( $matches ) ) { self::fail(); }
		return reset( $matches );
	}

	public static function tighten( $original, $restricted ) {
		self::validate_class( $restricted );
		if ( $original['eligibility'] !== $restricted['eligibility'] || $original['qualification_id'] !== $restricted['qualification_id'] ||
			$restricted['reserve_bytes'] < $original['reserve_bytes'] ) { self::fail(); }
		foreach ( $original['limits'] as $name => $value ) { if ( $restricted['limits'][$name] > $value ) { self::fail(); } }
		foreach ( $original['phase_envelopes'] as $phase => $domains ) {
			foreach ( $domains as $domain => $envelope ) {
				$r = $restricted['phase_envelopes'][$phase][$domain];
				if ( $r['fixed_bytes'] < $envelope['fixed_bytes'] ) { self::fail(); }
				foreach ( $envelope['per_dimension'] as $dim => $n ) { if ( $r['per_dimension'][$dim] < $n ) { self::fail(); } }
			}
		}
		return $restricted;
	}

	/** Fixed implementation paths: no package/commit/data digest cycle. */
	public static function implementation_digest() {
		$names = array( 'storage-capacity-policy', 'storage-capacity-budget', 'storage-calibration', 'storage-admin', 'storage-cli', 'storage-operations',
			'storage-preflight', 'storage-conversion', 'storage-copy-cursor', 'storage-control', 'storage-maintenance-gate', 'storage-memory-baseline', 'storage-authority', 'storage-retirement',
			'storage-scale-admission', 'storage-provisioning', 'generic-retaining-model', 'repository-flatten-model', 'snapshot-stats-model',
			'legacy-option-decoder', 'legacy-option-structural-scan', 'legacy-option-durable-scan', 'legacy-state-codec', 'legacy-record-codec', 'legacy-collection-envelope',
			'legacy-table-snapshot', 'legacy-table-reader', 'legacy-snapshot-row-reader', 'legacy-transaction-connection', 'legacy-storage-schema',
			'storage-scalar-observer', 'snapshot-read-plan', 'snapshot-read-phase', 'snapshot-read-profile', 'snapshot-page-witness', 'snapshot-owned-event-plan',
			'migrator-settings', 'migrator-engine', 'content-scan-repository', 'image-tag-queue-repository', 'init' );
		sort( $names, SORT_STRING ); $hashes = array();
		foreach ( $names as $name ) {
			$path = dirname( __FILE__ ) . '/class-' . $name . '.php';
			if ( ! is_file( $path ) || ! is_readable( $path ) ) { self::fail(); }
			$hash = hash_file( 'sha256', $path ); if ( ! self::digest( $hash ) ) { self::fail(); }
			$hashes['includes/class-' . $name . '.php'] = $hash;
		}
		return hash( 'sha256', json_encode( $hashes ) );
	}
	private static function strings( $value ) {
		if ( ! is_array( $value ) || ! count( $value ) || count( $value ) > 64 || array_keys( $value ) !== range( 0, count( $value ) - 1 ) || count( array_unique( $value, SORT_REGULAR ) ) !== count( $value ) ) { self::fail(); }
		foreach ( $value as $s ) { if ( ! is_string( $s ) || '' === $s || strlen( $s ) > 128 ) { self::fail(); } }
	}
	private static function keys( $value, $keys ) { if ( ! is_array( $value ) || array_keys( $value ) !== $keys ) { self::fail(); } }
	private static function integer( $n, $min, $max ) { if ( ! is_int( $n ) || $n < $min || $n > $max ) { self::fail(); } }
	private static function digest( $s ) { return is_string( $s ) && preg_match( '/^[a-f0-9]{64}$/D', $s ); }
	private static function fail() { throw new \RuntimeException( 'Release storage capacity policy is absent, invalid, ineligible or ambiguous.' ); }
	public static function required() { return new \WP_Error( 'foogallery_migrate_storage_policy_unavailable', __( 'The bundled release storage policy is missing or invalid. Retain storage; no conversion was started.', 'foogallery-migrate' ) ); }
}
