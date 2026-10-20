<?php
/** Explicit lazy shared-schema provisioning. Never called by normal bootstrap. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageProvisioning {
	private $db;
	private $pin;
	private $gate;
	private $blog;
	private $prefix;
	private $options;
	private $main;
	private $deadline;
	private $lock_name;
	private $owner;

	/** Public schema-only operation. Never creates or updates backend authority. */
	public static function provision( $request ) {
		$authorized = self::authorize_request( $request );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
		$gate = StorageMaintenanceGate::current(); $owns = false;
		try {
			$result = $gate->acquire_maintenance();
			if ( is_wp_error( $result ) ) { return $result; }
			$owns = true;
			if ( true !== $gate->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) ) ) { throw new \RuntimeException( 'Storage is busy.' ); }
			$control = new StorageControl( $gate->connection(), get_current_blog_id() );
			$raw = $control->read( StorageAuthority::CONTROL_OPTION );
			if ( null === $raw ) {
				if ( is_wp_error( StorageAuthority::option_mode_error( $gate, true ) ) ) { throw new \RuntimeException( 'Storage authority needs attention.' ); }
			} else {
				$r = StorageControl::decode( $raw, get_current_blog_id() );
				if ( 'option' !== $r['backend'] || ! StorageControl::runtime_ready( $r, $control->read( StorageAuthority::AUTHORITY_OPTION ) ) ) { throw new \RuntimeException( 'Stable option authority required.' ); }
			}
			( new self( $gate, microtime( true ) + 20 ) )->ensure();
			$result = array( 'blog_id' => get_current_blog_id(), 'schema' => 'verified', 'backend' => 'option', 'message' => __( 'Shared schema provisioned. This site still uses options; switching requires a separately authorized operation.', 'foogallery-migrate' ) );
		} catch ( \Exception $error ) { $result = self::error( 'provision_failed' ); }
		catch ( \Throwable $error ) { $result = self::error( 'provision_failed' ); }
		if ( $owns && true !== $gate->release_maintenance() ) { return self::error( 'provision_failed' ); }
		return $result;
	}

	/** Exact purpose and installation scope; all denials precede storage SQL. */
	public static function authorize_request( $r ) {
		if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) { return self::error( 'forbidden' ); }
		$blog = get_current_blog_id();
		if ( ! is_array( $r ) || 5 !== count( $r ) || ! isset( $r['blog_id'], $r['scope'], $r['target'], $r['confirmation'], $r['nonce'] ) ||
			( $r['blog_id'] !== $blog && $r['blog_id'] !== (string) $blog ) || 'installation' !== $r['scope'] || 'table' !== $r['target'] ||
			'provision-shared-schema' !== $r['confirmation'] || ! is_string( $r['nonce'] ) ) { return self::error( 'invalid_request' ); }
		if ( ! wp_verify_nonce( $r['nonce'], 'foogallery_migrate_storage_provision_schema_' . $blog ) ) { return self::error( 'invalid_nonce' ); }
		$site = StorageAuthority::table_mode_error();
		if ( is_wp_error( $site ) ) { return $site; }
		return true;
	}

	private static function error( $code ) {
		return new \WP_Error( 'foogallery_migrate_storage_' . $code, __( 'Schema provisioning refused. Check permission, confirmation, schema ownership and storage health; no backend switch was requested.', 'foogallery-migrate' ) );
	}

	/** Caller already owns the current site's quiescent maintenance gate. */
	public function __construct( $gate, $deadline ) {
		global $wpdb;
		if ( is_wp_error( StorageAuthority::table_mode_error() ) ) { $this->fail(); }
		$this->db = $wpdb; $this->gate = $gate; $this->pin = $gate->connection();
		$this->blog = get_current_blog_id(); $this->prefix = $wpdb->prefix; $this->deadline = $deadline;
		$this->main = is_multisite() ? get_main_site_id( get_main_network_id() ) : $this->blog;
		$this->options = $wpdb->get_blog_prefix( $this->main ) . 'options';
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $this->options ) ) { $this->fail(); }
	}

	/** Missing-table repair is additive. Never alter/drop an existing table. */
	public function ensure() {
		$this->context();
		if ( is_wp_error( StorageAuthority::table_mode_error() ) ) { $this->fail(); }
		$schema = new LegacyStorageSchema( $this->db );
		$statements = $schema->statements();
		$database = $this->pin->get_var( 'SELECT DATABASE()' );
		if ( ! is_string( $database ) || '' === $database || $this->pin->last_error ) { $this->fail(); }
		$this->lock_name = 'foogm-schema-' . substr( hash( 'sha256', $database . ':' . $this->options ), 0, 48 );
		if ( '1' !== (string) $this->pin->get_var( $this->pin->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name ) ) || $this->pin->last_error ) { $this->fail(); }
		try {
			$version = $this->read_option( LegacyStorageSchema::VERSION_OPTION );
			if ( null !== $version && (string) LegacyStorageSchema::VERSION !== $version ) { $this->fail(); }
			// Exact names/shape alone are not ownership. Check every existing
			// table before adding anything, including partial-schema repairs.
			foreach ( array_keys( $schema->definitions() ) as $table ) {
				$found = $this->pin->get_var( $this->pin->prepare( 'SHOW TABLES LIKE %s', $this->pin->esc_like( $table ) ) );
				if ( $this->pin->last_error || ( null !== $found && $table !== $found ) ) { $this->fail(); }
				if ( null !== $found ) {
					if ( null === $version ) { $this->fail(); }
					( new LegacyStorageSchema( $this->pin ) )->verify( $table );
				}
			}
			$this->claim();
			if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
			foreach ( array_keys( $schema->definitions() ) as $index => $table ) {
				$this->owned();
				$found = $this->pin->get_var( $this->pin->prepare( 'SHOW TABLES LIKE %s', $this->pin->esc_like( $table ) ) );
				if ( $this->pin->last_error || ( null !== $found && $table !== $found ) ) { $this->fail(); }
				if ( null === $found ) {
					// DDL autocommits. Retain any partial result on failure; authority
					// is untouched and the next explicit attempt may add missing tables.
					dbDelta( $statements[$index] );
					$this->owned();
				}
			}
			( new LegacyStorageSchema( $this->pin ) )->verify(); $this->owned();
			$sql = $this->pin->prepare( 'INSERT INTO `' . $this->options . '` (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', array( LegacyStorageSchema::VERSION_OPTION, (string) LegacyStorageSchema::VERSION, 'no' ) );
			if ( false === $this->pin->query( $sql ) || $this->pin->last_error ) { $this->fail(); }
			$this->owned();
			$this->release();
		} catch ( \Exception $error ) { $this->release(); throw $error; }
		catch ( \Throwable $error ) { $this->release(); throw $error; }
		// Invalidate only installation-main-site option caches after pinned writes.
		$switched = is_multisite() && $this->main !== get_current_blog_id();
		if ( $switched ) { switch_to_blog( $this->main ); }
		foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION, 'notoptions', 'alloptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
		if ( $switched ) { restore_current_blog(); }
		$this->context();
		return true;
	}

	private function claim() {
		$this->context();
		$previous = $this->read_option( LegacyStorageSchema::LOCK_OPTION );
		if ( null !== $previous ) {
			$r = json_decode( $previous, true );
			if ( ! is_array( $r ) || 2 !== count( $r ) || ! isset( $r['token'], $r['expires'] ) || ! is_string( $r['token'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $r['token'] ) || ! is_int( $r['expires'] ) || $r['expires'] >= time() ) { $this->fail(); }
		}
		$raw = wp_json_encode( array( 'token' => hash( 'sha256', wp_generate_password( 64, true, true ) ), 'expires' => time() + 300 ) );
		if ( ! is_string( $raw ) ) { $this->fail(); }
		if ( null === $previous ) {
			$sql = $this->pin->prepare( 'INSERT IGNORE INTO `' . $this->options . '` (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( LegacyStorageSchema::LOCK_OPTION, $raw, 'no' ) );
		} else {
			// Advisory ownership proves no live provisioner retains this DB lock.
			// Expiry alone is never permission to steal an active schema operation.
			$sql = $this->pin->prepare( 'UPDATE `' . $this->options . '` SET option_value=%s, autoload=%s WHERE option_name=%s AND BINARY option_value=BINARY %s', array( $raw, 'no', LegacyStorageSchema::LOCK_OPTION, $previous ) );
		}
		if ( 1 !== $this->pin->query( $sql ) || $this->pin->last_error ) { $this->fail(); }
		$this->owner = $raw; $this->owned();
	}

	private function owned() {
		$this->context();
		if ( null === $this->owner || $this->owner !== $this->read_option( LegacyStorageSchema::LOCK_OPTION ) ||
			'1' !== (string) $this->pin->get_var( $this->pin->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->lock_name ) ) || $this->pin->last_error ) { $this->fail(); }
	}

	private function context() {
		global $wpdb;
		if ( $wpdb !== $this->db || get_current_blog_id() !== $this->blog || $wpdb->prefix !== $this->prefix || ! $this->pin->is_current() || microtime( true ) > $this->deadline ||
			! current_user_can( 'manage_options' ) || true !== $this->gate->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) ) ) { $this->fail(); }
	}

	private function read_option( $name ) {
		$rows = $this->pin->get_results( $this->pin->prepare( 'SELECT CASE WHEN OCTET_LENGTH(option_value) <= 256 THEN option_value ELSE NULL END AS value FROM `' . $this->options . '` WHERE option_name=%s', $name ), ARRAY_A );
		if ( ! is_array( $rows ) || $this->pin->last_error || count( $rows ) > 1 || ( count( $rows ) && ! is_string( $rows[0]['value'] ) ) ) { $this->fail(); }
		return count( $rows ) ? $rows[0]['value'] : null;
	}

	private function release() {
		// On transport loss leave durable ownership evidence for explicit recovery.
		if ( ! $this->pin->is_current() ) { return; }
		if ( null !== $this->owner ) {
			$this->pin->query( $this->pin->prepare( 'DELETE FROM `' . $this->options . '` WHERE option_name=%s AND BINARY option_value=BINARY %s', array( LegacyStorageSchema::LOCK_OPTION, $this->owner ) ) );
			$this->owner = null;
		}
		if ( null !== $this->lock_name ) { $this->pin->get_var( $this->pin->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) ); $this->lock_name = null; }
	}

	private function fail() { throw new \RuntimeException( 'Shared schema provisioning failed; retain current storage and retry only after resolving schema or ownership health.' ); }
}
