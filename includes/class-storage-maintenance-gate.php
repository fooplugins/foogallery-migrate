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
	private $connection;
	private $blog_id;
	private $prefix;
	private $table;
	private $writer_name;
	private $writer_raw;
	private $writer_lock;
	private $database;
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
			if ( null !== $this->read( StorageAuthority::MAINTENANCE_OPTION ) ||
				null !== $this->read( StorageAuthority::CLEANUP_OPTION ) ) { return self::busy(); }
			$record = $this->record();
			$name = self::WRITER_PREFIX . $record['token'];
			$raw = wp_json_encode( $record );
			if ( ! is_string( $raw ) ) { return self::unhealthy(); }
			$lock = $this->writer_lock_name( $name );
			if ( ! $this->lock_query( 'GET_LOCK', $lock ) ) { return self::busy(); }
			if ( ! $this->insert( $name, $raw ) ) { $this->release_writer_lock( $lock ); return self::busy(); }
			$this->writer_lock = $lock;
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
			$this->release_writer_lock( $this->writer_lock );
			$this->writer_lock = null;
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

	/** Explicit reset only: retire expired admissions after liveness checks.
	 * The maintenance claim blocks new admissions. Expired requests cannot renew
	 * or pass assert_writer(); exact-byte deletion also rejects changed owners.
	 * Conversion/provisioning continue to use maintenance_ready() without repair.
	 */
	public function reset_expired_writers( $engine_verdict, &$removed = null ) {
		$removed = array();
		try {
			$this->context();
			if ( ! is_callable( $engine_verdict ) || null === $this->maintenance_raw ) { return self::busy(); }
			$this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
			$db = $this->connection();
			$rows = $db->get_results( $db->prepare(
				"SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= 512 THEN option_value ELSE '' END AS marker_value FROM %i WHERE option_name LIKE %s ORDER BY option_name LIMIT 101",
				array( $this->table, $db->esc_like( self::WRITER_PREFIX ) . '%' )
			), ARRAY_A );
			if ( ! is_array( $rows ) || ! empty( $db->last_error ) || count( $rows ) > 100 ) { return self::unhealthy(); }
			// Classify the complete bounded set before deleting any marker.
			foreach ( $rows as $row ) {
				if ( ! isset( $row['option_name'], $row['marker_value'] ) || ! is_string( $row['marker_value'] ) ) { return self::unhealthy(); }
				$r = json_decode( $row['marker_value'], true );
				if ( ! is_array( $r ) || array_keys( $r ) !== array( 'version', 'blog_id', 'token', 'expires' ) ||
					1 !== $r['version'] || $this->blog_id !== $r['blog_id'] || ! is_string( $r['token'] ) ||
					! preg_match( '/^[A-Za-z0-9]{32}$/D', $r['token'] ) || $row['option_name'] !== self::WRITER_PREFIX . $r['token'] ||
					! is_int( $r['expires'] ) || $r['expires'] < 1 || wp_json_encode( $r ) !== $row['marker_value'] ) { return self::unhealthy(); }
				if ( $r['expires'] > time() || $row['option_name'] === $this->writer_name ||
					! $this->lock_query( 'IS_FREE_LOCK', $this->writer_lock_name( $row['option_name'] ) ) ) { return self::busy(); }
			}
			// Recheck the engine and the owned gate before touching the saved set.
			for ( $check = 0; $check < 2; $check++ ) {
				if ( true !== call_user_func( $engine_verdict ) ) { return self::busy(); }
				$this->context(); $this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
			}
			foreach ( $rows as $row ) {
				$this->owned_live( StorageAuthority::MAINTENANCE_OPTION, $this->maintenance_raw );
				$lock = $this->writer_lock_name( $row['option_name'] );
				if ( ! $this->lock_query( 'GET_LOCK', $lock ) ) { return self::busy(); }
				try { $this->remove_owned( $row['option_name'], $row['marker_value'] ); $removed[] = $row['option_name']; }
				catch ( \Exception $error ) { $this->release_writer_lock( $lock ); throw $error; }
				catch ( \Throwable $error ) { $this->release_writer_lock( $lock ); throw $error; }
				$this->release_writer_lock( $lock );
			}
			return $removed;
		} catch ( \Exception $error ) { return self::unhealthy(); }
		catch ( \Throwable $error ) { return self::unhealthy(); }
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
		if ( null !== $this->connection && ! $this->connection->is_current() ) { throw new \RuntimeException( 'Storage barrier connection changed; retain ownership evidence.' ); }
	}

	/** Capture once, before the first marker statement. Never reconnect/reacquire. */
	public function connection() {
		$this->context();
		if ( null === $this->connection ) {
			$this->connection = $this->db instanceof LegacyTransactionConnection ? $this->db : new LegacyTransactionConnection( $this->db );
		}
		if ( ! $this->connection->is_current() ) { throw new \RuntimeException( 'Storage barrier connection unavailable.' ); }
		return $this->connection;
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
		$db = $this->connection();
		$value = $db->get_var( $db->prepare(
			"SELECT CONCAT('v:', IF(LENGTH(option_value) <= 512, option_value, '')) AS marker FROM `" . $this->table . '` WHERE option_name = %s LIMIT 1', array( $name )
		) );
		if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Storage marker read failed.' ); }
		// wpdb::get_var maps an empty string to null. A nonempty envelope makes
		// empty/corrupt/oversized existing rows distinguishable from absence.
		return null === $value ? null : substr( $value, 2 );
	}

	private function insert( $name, $raw ) {
		$db = $this->connection();
		// WP add_option uses ON DUPLICATE KEY UPDATE, which is not a lock claim.
		// IGNORE refuses a competing row without overwriting its owner. Inputs
		// are fixed bounded ASCII names/JSON; exact raw readback verifies the claim.
		$result = $db->query( $db->prepare(
			'INSERT IGNORE INTO `' . $this->table . '` (option_name, option_value, autoload) VALUES ( %s, %s, \'no\' )', array( $name, $raw )
		) );
		$this->invalidate( $name );
		if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Storage marker insertion failed.' ); }
		return 1 === $result;
	}

	private function writers_present() {
		$db = $this->connection();
		$found = $db->get_var( $db->prepare(
			'SELECT 1 AS present FROM `' . $this->table . '` WHERE option_name LIKE %s LIMIT 1',
			array( $db->esc_like( self::WRITER_PREFIX ) . '%' )
		) );
		if ( ! empty( $db->last_error ) ) { throw new \RuntimeException( 'Storage writer probe failed.' ); }
		// Even malformed/expired markers block. Recovery must prove safe removal.
		return null !== $found;
	}

	/** Locks are per marker and released by the server when its connection dies. */
	private function writer_lock_name( $name ) {
		$db = $this->connection();
		if ( null === $this->database ) {
			$database = $db->get_var( 'SELECT DATABASE()' );
			if ( ! empty( $db->last_error ) || ! is_string( $database ) || '' === $database ) { throw new \RuntimeException( 'Storage database identity unavailable.' ); }
			$this->database = $database;
		}
		return 'foogm-write-' . substr( hash( 'sha256', $this->database . ':' . $this->table . ':' . $name ), 0, 48 );
	}
	private function lock_query( $operation, $name ) {
		$db = $this->connection();
		if ( 'GET_LOCK' === $operation ) { $sql = $db->prepare( 'SELECT GET_LOCK(%s, 0)', array( $name ) ); }
		elseif ( 'IS_FREE_LOCK' === $operation ) { $sql = $db->prepare( 'SELECT IS_FREE_LOCK(%s)', array( $name ) ); }
		else { $sql = $db->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', array( $name ) ); }
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each branch above prepares a fixed lock query with the bounded, internally generated lock name.
		$result = $db->get_var( $sql );
		if ( ! empty( $db->last_error ) || null === $result ) { throw new \RuntimeException( 'Storage writer liveness unavailable.' ); }
		return '1' === (string) $result;
	}
	private function release_writer_lock( $name ) {
		$db = $this->connection();
		$result = $db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', array( $name ) ) );
		if ( ! empty( $db->last_error ) || '1' !== (string) $result ) { throw new \RuntimeException( 'Storage writer liveness release failed.' ); }
	}

	private function owned_live( $name, $raw ) {
		if ( $name === $this->writer_name && null !== $this->writer_lock && ! $this->lock_query( 'IS_USED_LOCK', $this->writer_lock ) ) { throw new \RuntimeException( 'Storage writer connection ownership lost.' ); }
		if ( ! is_string( $raw ) || $this->read( $name ) !== $raw ) { throw new \RuntimeException( 'Storage ownership lost.' ); }
		$record = json_decode( $raw, true );
		if ( ! is_array( $record ) || ! isset( $record['expires'] ) || $record['expires'] <= time() ) {
			throw new \RuntimeException( 'Expired storage ownership requires recovery.' );
		}
	}

	private function renew( $name, &$raw ) {
		$db = $this->connection();
		$this->owned_live( $name, $raw );
		$record = json_decode( $raw, true );
		$record['expires'] = time() + self::TTL;
		$next = wp_json_encode( $record );
		if ( ! is_string( $next ) ) { throw new \RuntimeException( 'Storage marker encoding failed.' ); }
		$result = $db->query( $db->prepare(
			'UPDATE `' . $this->table . '` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
			array( $next, $name, $raw )
		) );
		$this->invalidate( $name );
		if ( false === $result || ! empty( $db->last_error ) || ( 0 === $result && $next !== $raw ) ) {
			throw new \RuntimeException( 'Storage marker renewal failed.' );
		}
		// Save a successful write's raw owner BEFORE readback: if readback fails,
		// a later owner-checked release must match the bytes actually persisted.
		$raw = $next;
		$this->owned_live( $name, $raw );
	}

	private function remove_owned( $name, $raw ) {
		$db = $this->connection();
		if ( ! is_string( $raw ) ) { throw new \RuntimeException( 'No storage ownership.' ); }
		$result = $db->query( $db->prepare(
			'DELETE FROM `' . $this->table . '` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $name, $raw )
		) );
		$this->invalidate( $name );
		if ( 1 !== $result || ! empty( $db->last_error ) || null !== $this->read( $name ) ) {
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
