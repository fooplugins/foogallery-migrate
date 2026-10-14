<?php
/**
 * Request-scoped storage barrier, NOT an engine worker lease or backend selector.
 * Callers must use current(), enter before any output/state mutation, and leave
 * on normal returns. PHP exit/wp_die paths have an owner-checked shutdown fallback.
 * Production option-mode mutations retain one admission until request shutdown.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageMaintenanceGate {
	const WRITER_PREFIX = 'foogallery_migrate_storage_writer_';
	const TTL = 300;
	private static $instances = array();
	private $db;
	private $blog_id;
	private $prefix;
	private $table;
	private $writer_name;
	private $writer_raw;
	private $writer_depth = 0;
	private $writer_admitted = false;
	private $maintenance_raw;

	/** One re-entrant owner per database/current-blog context in this request. */
	public static function current() {
		global $wpdb;
		$blog_id = get_current_blog_id();
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ||
			! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) || ! is_int( $blog_id ) || $blog_id < 1 ) {
			throw new \RuntimeException( 'Invalid storage barrier context.' );
		}
		$key = spl_object_hash( $wpdb ) . ':' . $blog_id . ':' . $wpdb->prefix;
		if ( ! isset( self::$instances[$key] ) ) {
			self::$instances[$key] = new self( $wpdb, $blog_id );
		}
		return self::$instances[$key];
	}

	private function __construct( $db, $blog_id ) {
		$this->db = $db;
		$this->blog_id = $blog_id;
		$this->prefix = $db->prefix;
		$this->table = $this->prefix . 'options';
		register_shutdown_function( array( $this, 'shutdown_writer' ) );
	}

	/** Check -> atomic marker insertion -> check closes check-then-start races. */
	public function admit_request_writer() {
		// Never turn a failed/expired previous admission into a new owner.
		return null !== $this->writer_raw ? $this->renew_writer() : $this->begin_writer();
	}

	public function begin_writer() {
		try {
			$this->context();
			if ( $this->writer_depth ) {
				if ( ! $this->writer_admitted ) { return self::busy(); }
				$this->owned_live( $this->writer_name, $this->writer_raw );
				$this->writer_depth++;
				return true;
			}
			if ( null !== $this->read( StorageAuthority::MAINTENANCE_OPTION ) ) { return self::busy(); }
			$record = $this->record();
			$name = self::WRITER_PREFIX . $record['token'];
			$raw = wp_json_encode( $record );
			if ( ! is_string( $raw ) || ! $this->insert( $name, $raw ) ) { return self::busy(); }
			// Retain ownership on verification failure: never blindly delete evidence.
			$this->writer_name = $name;
			$this->writer_raw = $raw;
			$this->writer_depth = 1;
			$this->owned_live( $name, $raw );
			if ( null !== $this->read( StorageAuthority::MAINTENANCE_OPTION ) ) {
				$released = $this->end_writer();
				return true === $released ? self::busy() : $released;
			}
			$this->writer_admitted = true;
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	/** Existing writers may finish behind a newly acquired gate; it waits for them. */
	public function assert_writer() {
		try {
			$this->context();
			if ( ! $this->writer_depth || ! $this->writer_admitted ) { return self::busy(); }
			$this->owned_live( $this->writer_name, $this->writer_raw );
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	public function end_writer() {
		try {
			$this->context();
			if ( ! $this->writer_depth ) { return true; }
			if ( $this->writer_depth > 1 ) {
				$this->owned_live( $this->writer_name, $this->writer_raw );
				$this->writer_depth--;
				return true;
			}
			$this->remove_owned( $this->writer_name, $this->writer_raw );
			$this->writer_depth = 0;
			$this->writer_admitted = false;
			$this->writer_name = null;
			$this->writer_raw = null;
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	/** Shutdown never removes maintenance: an interrupted switch stays blocked. */
	public function shutdown_writer() {
		global $wpdb;
		if ( ! $this->writer_depth || $wpdb !== $this->db ) { return; }
		$switched = false;
		// A wp_die/exit in a switched blog must still release the captured owner.
		// Normal public operations continue to reject stale cross-blog handles.
		if ( get_current_blog_id() !== $this->blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $this->blog_id );
			$switched = true;
		}
		$this->writer_depth = 1;
		$this->end_writer();
		if ( $switched ) { restore_current_blog(); }
	}

	public function renew_writer() {
		try {
			$this->context();
			if ( ! $this->writer_depth || ! $this->writer_admitted ) { return self::busy(); }
			$this->renew( $this->writer_name, $this->writer_raw );
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	/** Acquisition does not imply readiness, authorization, or stale takeover. */
	public function acquire_maintenance() {
		try {
			$this->context();
			if ( null !== $this->maintenance_raw || null !== $this->read( StorageAuthority::MAINTENANCE_OPTION ) ) {
				return self::busy();
			}
			$raw = wp_json_encode( $this->record() );
			if ( ! is_string( $raw ) || ! $this->insert( StorageAuthority::MAINTENANCE_OPTION, $raw ) ) {
				return self::busy();
			}
			$this->maintenance_raw = $raw;
			$this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $raw );
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	/**
	 * Consume only a trusted engine integration's strict yes/no verdict twice,
	 * after the gate and writer drain. Never inspect queued/paused engine state.
	 * The future coordinator must repeat this immediately before every flip.
	 */
	public function maintenance_ready( $engine_verdict ) {
		try {
			$this->context();
			if ( ! is_callable( $engine_verdict ) || null === $this->maintenance_raw ) { return self::busy(); }
			for ( $check = 0; $check < 2; $check++ ) {
				$this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
				if ( $this->writers_present() || true !== call_user_func( $engine_verdict ) ) { return self::busy(); }
				$this->context();
			}
			$this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
			return $this->writers_present() ? self::busy() : true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	public function renew_maintenance() {
		try {
			$this->context();
			if ( null === $this->maintenance_raw ) { return self::busy(); }
			$this->renew( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	public function maintenance_identity() {
		$this->context();
		if ( null === $this->maintenance_raw ) { throw new \RuntimeException( 'No owned storage maintenance identity.' ); }
		$this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
		return $this->maintenance_raw;
	}

	/** Only this request's exact raw ownership may release, including after expiry. */
	public function release_maintenance() {
		try {
			$this->context();
			if ( null === $this->maintenance_raw ) { return self::busy(); }
			$this->remove_owned( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
			$this->maintenance_raw = null;
			return true;
		} catch ( \Exception $error ) { return self::unhealthy(); }
	}

	private function context() {
		global $wpdb;
		if ( $wpdb !== $this->db || get_current_blog_id() !== $this->blog_id || $wpdb->prefix !== $this->prefix ) {
			throw new \RuntimeException( 'Storage barrier context changed.' );
		}
	}

	private function record() {
		$token = wp_generate_password( 32, false, false );
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) ) {
			throw new \RuntimeException( 'Invalid storage ownership token.' );
		}
		return array( 'version' => 1, 'blog_id' => $this->blog_id, 'token' => $token, 'expires' => time() + self::TTL );
	}

	/** Fresh bounded SQL reads bypass persistent-cache negative/stale entries. */
	private function read( $name ) {
		$value = $this->db->get_var( $this->db->prepare(
			"SELECT CONCAT('v:', IF(LENGTH(option_value) <= 512, option_value, '')) AS marker FROM `" . $this->table . '` WHERE option_name = %s LIMIT 1', $name
		) );
		if ( ! empty( $this->db->last_error ) ) { throw new \RuntimeException( 'Storage marker read failed.' ); }
		// wpdb::get_var maps an empty string to null. A nonempty envelope makes
		// empty/corrupt/oversized existing rows distinguishable from absence.
		return null === $value ? null : substr( $value, 2 );
	}

	private function insert( $name, $raw ) {
		// WP add_option uses ON DUPLICATE KEY UPDATE, which is not a lock claim.
		// IGNORE refuses a competing row without overwriting its owner. Inputs
		// are fixed bounded ASCII names/JSON; exact raw readback verifies the claim.
		$result = $this->db->query( $this->db->prepare(
			'INSERT IGNORE INTO `' . $this->table . '` (option_name, option_value, autoload) VALUES ( %s, %s, \'no\' )', $name, $raw
		) );
		$this->invalidate( $name );
		if ( ! empty( $this->db->last_error ) ) { throw new \RuntimeException( 'Storage marker insertion failed.' ); }
		return 1 === $result;
	}

	private function writers_present() {
		$found = $this->db->get_var( $this->db->prepare(
			'SELECT 1 AS present FROM `' . $this->table . '` WHERE option_name LIKE %s LIMIT 1',
			$this->db->esc_like( self::WRITER_PREFIX ) . '%'
		) );
		if ( ! empty( $this->db->last_error ) ) { throw new \RuntimeException( 'Storage writer probe failed.' ); }
		// Even malformed/expired markers block. Recovery must prove safe removal.
		return null !== $found;
	}

	private function owned_live( $name, $raw ) {
		if ( ! is_string( $raw ) || $this->read( $name ) !== $raw ) { throw new \RuntimeException( 'Storage ownership lost.' ); }
		$record = json_decode( $raw, true );
		if ( ! is_array( $record ) || ! isset( $record['expires'] ) || $record['expires'] <= time() ) {
			throw new \RuntimeException( 'Expired storage ownership requires recovery.' );
		}
	}

	private function renew( $name, &$raw ) {
		$this->owned_live( $name, $raw );
		$record = json_decode( $raw, true );
		$record['expires'] = time() + self::TTL;
		$next = wp_json_encode( $record );
		if ( ! is_string( $next ) ) { throw new \RuntimeException( 'Storage marker encoding failed.' ); }
		$result = $this->db->query( $this->db->prepare(
			'UPDATE `' . $this->table . '` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
			$next, $name, $raw
		) );
		$this->invalidate( $name );
		if ( false === $result || ! empty( $this->db->last_error ) || ( 0 === $result && $next !== $raw ) ) {
			throw new \RuntimeException( 'Storage marker renewal failed.' );
		}
		// Save a successful write's raw owner BEFORE readback: if readback fails,
		// a later owner-checked release must match the bytes actually persisted.
		$raw = $next;
		$this->owned_live( $name, $raw );
	}

	private function remove_owned( $name, $raw ) {
		if ( ! is_string( $raw ) ) { throw new \RuntimeException( 'No storage ownership.' ); }
		$result = $this->db->query( $this->db->prepare(
			'DELETE FROM `' . $this->table . '` WHERE option_name = %s AND BINARY option_value = BINARY %s', $name, $raw
		) );
		$this->invalidate( $name );
		if ( 1 !== $result || ! empty( $this->db->last_error ) || null !== $this->read( $name ) ) {
			throw new \RuntimeException( 'Storage marker release failed.' );
		}
	}

	private function invalidate( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function busy() {
		return new \WP_Error( 'foogallery_migrate_storage_busy', __( 'Migration storage is busy or requires recovery. No new storage operation was admitted.', 'foogallery-migrate' ) );
	}

	private static function unhealthy() {
		return new \WP_Error( 'foogallery_migrate_storage_barrier_failed', __( 'Migration storage ownership could not be verified. Retain storage markers and retry or request recovery.', 'foogallery-migrate' ) );
	}
}
