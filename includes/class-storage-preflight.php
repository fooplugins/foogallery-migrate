<?php
/**
 * Read-only option-import admission. This is not a converter or authority selector.
 * No request hook is registered, no payload is loaded and no state is changed.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StoragePreflight {
	private $wpdb;
	private $policy;
	private $incoming_memory;

	/**
	 * Policy must come from trusted, benchmarked server configuration, never POST.
	 * There is deliberately no default amplification factor or raw-size ceiling.
	 */
	private $capacity;
	public function __construct( $database = null, $policy = null, $memory = null, $capacity = null ) {
		global $wpdb;
		$this->wpdb = null === $database ? $wpdb : $database;
		$this->policy = $policy;
		$this->incoming_memory = $memory;
		$this->capacity = $capacity;
	}

	/**
	 * Authorize a current-site option-to-table preflight before inspecting storage.
	 * The future manager must separately prove authority, acquire its writer gate,
	 * recheck the budget immediately before decoding, and validate the snapshot.
	 * decode_admitted alone NEVER permits a switch or schema provisioning.
	 *
	 * @return array|\WP_Error
	 */
	public function option_import( $request ) {
		$memory = null === $this->incoming_memory ? new StorageMemoryBaseline() : $this->incoming_memory;
		$this->incoming_memory = null; $memory->sample();
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::error( 'forbidden', 'You are not authorized to inspect migration storage.' );
		}
		$blog_id = get_current_blog_id();
		if ( ! is_int( $blog_id ) || $blog_id < 1 || ! is_array( $request ) || 5 !== count( $request ) ) {
			return self::error( 'invalid_request', 'Invalid migration storage request.' );
		}
		foreach ( array( 'blog_id', 'scope', 'target', 'confirmation', 'nonce' ) as $key ) {
			if ( ! array_key_exists( $key, $request ) ) {
				return self::error( 'invalid_request', 'Invalid migration storage request.' );
			}
		}
		if ( ( $blog_id !== $request['blog_id'] && (string) $blog_id !== $request['blog_id'] ) ||
			'site' !== $request['scope'] || 'table' !== $request['target'] ||
			'switch-to-table' !== $request['confirmation'] || ! is_string( $request['nonce'] ) || '' === $request['nonce'] ) {
			return self::error( 'invalid_request', 'Invalid migration storage request.' );
		}
		$nonce = wp_verify_nonce( $request['nonce'], 'foogallery_migrate_storage_option_import_' . $blog_id );
		if ( 1 !== $nonce && 2 !== $nonce ) {
			return self::error( 'invalid_nonce', 'The migration storage request has expired or is invalid.' );
		}
		$bytes = $this->option_size();
		if ( is_wp_error( $bytes ) ) { return $bytes; }
		if ( null !== $this->capacity ) {
			try {
				$token = $this->capacity->open( $this, 'fetch_scan', array( 'R' => $bytes ) );
				$this->capacity->close( $this, $token );
				$budget = array( 'decode_admitted' => false, 'raw_bytes' => $bytes, 'structural_admission_required' => true );
			} catch ( \Exception $error ) { return self::refused_error(); }
			catch ( \Throwable $error ) { return self::refused_error(); }
		} else { $budget = self::decode_budget( $bytes, ini_get( 'memory_limit' ), $memory->sample(), $this->policy ); }
		if ( is_wp_error( $budget ) ) { return $budget; }
		$budget['blog_id'] = $blog_id;
		$budget['conversion_ready'] = false;
		return $budget;
	}

	/** Size-only SQL bypasses get_option(), alloptions and object deserialization. */
	private function option_size() {
		$db = $this->wpdb;
		if ( ! is_object( $db ) || ! isset( $db->prefix, $db->options ) ||
			! is_string( $db->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $db->prefix ) ||
			$db->options !== $db->prefix . 'options' ||
			! is_callable( array( $db, 'prepare' ) ) || ! is_callable( array( $db, 'get_var' ) ) ) {
			return self::error( 'size_unavailable', 'Migration storage size could not be safely inspected.' );
		}
		// Only this fixed plugin option in WordPress's current-blog options table.
		$bytes = $db->get_var( $db->prepare( 'SELECT LENGTH(option_value) AS option_bytes FROM `' . $db->options . '` WHERE option_name = %s LIMIT 1', FOOGALLERY_MIGRATE_OPTION_DATA ) );
		if ( ! empty( $db->last_error ) ) {
			return self::error( 'size_unavailable', 'Migration storage size could not be safely inspected.' );
		}
		if ( null === $bytes ) { return 0; }
		$bytes = self::nonnegative_integer( $bytes );
		return false === $bytes ? self::error( 'size_unavailable', 'Migration storage size could not be safely inspected.' ) : $bytes;
	}

	/**
	 * Bounded admission arithmetic, also usable by a future authenticated CLI.
	 * Optional phase only labels an already-authorized private scalar diagnostic;
	 * it cannot arm observation or alter the returned admission decision.
	 * Unlimited PHP memory still obeys the calibrated hard raw-byte ceiling.
	 * reserve_bytes covers retained data, staging, validation and response work;
	 * the factor must include the raw string AND decoded graph peak allocation.
	 */
	public static function decode_budget( $raw_bytes, $memory_limit, $memory_used, $policy, $diagnostic_phase = null ) {
		if ( ! is_array( $policy ) || 3 !== count( $policy ) ) {
			return self::policy_error();
		}
		foreach ( array( 'max_raw_bytes', 'amplification', 'reserve_bytes' ) as $key ) {
			if ( ! isset( $policy[$key] ) || ! is_int( $policy[$key] ) || $policy[$key] < 1 ) {
				return self::policy_error();
			}
		}
		$limit = self::memory_bytes( $memory_limit );
		if ( false === $limit || ! is_int( $raw_bytes ) || $raw_bytes < 0 || ! is_int( $memory_used ) || $memory_used < 0 ) {
			return self::error( 'memory_unknown', 'The effective migration storage decode budget could not be determined.' );
		}
		$ceiling = -1 === $limit ? PHP_INT_MAX : $limit;
		if ( $raw_bytes > $policy['max_raw_bytes'] ) {
			StorageScalarObserver::refusal( 'raw-budget', $diagnostic_phase );
			return self::refused_error();
		}
		if ( $policy['reserve_bytes'] > $ceiling || $memory_used > $ceiling - $policy['reserve_bytes'] ) {
			StorageScalarObserver::refusal( 'decode-memory', $diagnostic_phase );
			return self::refused_error();
		}
		$available = $ceiling - $policy['reserve_bytes'] - $memory_used;
		// Overflow becomes a float on PHP; reject it rather than converting back.
		$estimate = $raw_bytes * $policy['amplification'];
		if ( ! is_int( $estimate ) || $estimate > $available ) { StorageScalarObserver::refusal( 'decode-memory', $diagnostic_phase ); return self::refused_error(); }
		return array( 'decode_admitted' => true, 'raw_bytes' => $raw_bytes, 'memory_limit_bytes' => $limit,
			'memory_used_bytes' => $memory_used, 'reserve_bytes' => $policy['reserve_bytes'],
			'amplification' => $policy['amplification'], 'max_raw_bytes' => $policy['max_raw_bytes'],
			'estimated_decode_bytes' => $estimate, 'available_decode_bytes' => $available );
	}

	/** Normalize a trusted PHP memory quantity; false is unknown, -1 unlimited. */
	public static function memory_bytes( $value ) {
		if ( ! is_string( $value ) ) { return false; }
		$value = trim( $value );
		if ( '-1' === $value ) { return -1; }
		if ( ! preg_match( '/^([1-9][0-9]*)([KMG]?)$/iD', $value, $match ) ) { return false; }
		$bytes = self::nonnegative_integer( $match[1] );
		if ( false === $bytes ) { return false; }
		$unit = strtoupper( $match[2] );
		$steps = 'G' === $unit ? 3 : ( 'M' === $unit ? 2 : ( 'K' === $unit ? 1 : 0 ) );
		for ( $step = 0; $step < $steps; $step++ ) {
			$bytes *= 1024;
			if ( ! is_int( $bytes ) ) { return false; }
		}
		return $bytes;
	}

	private static function nonnegative_integer( $value ) {
		if ( is_int( $value ) ) { return $value >= 0 ? $value : false; }
		if ( ! is_string( $value ) || ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) { return false; }
		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) { return false; }
		return (int) $value;
	}

	private static function policy_error() {
		return self::error( 'decode_policy_required', 'A benchmarked migration storage decode policy is required. No conversion was started.' );
	}

	private static function refused_error() {
		return self::error( 'decode_refused', 'Migration storage is too large for the available conversion budget. Retain the database; use an administrator-assisted, higher-memory conversion path. No state was changed.' );
	}

	private static function error( $suffix, $message ) {
		return new \WP_Error( 'foogallery_migrate_storage_' . $suffix, __( $message, 'foogallery-migrate' ) );
	}
}
