<?php
/**
 * Bounded, fresh control evidence and binary compare-and-swap primitives.
 * Never hydrates legacy option values or treats an invalid record as absence.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageControl {
	const MAX_BYTES = 2048;
	private $db;
	private $blog;
	private $prefix;

	public function __construct( $db, $blog ) {
		if ( ! is_int( $blog ) || $blog < 1 || ! is_object( $db ) || ! isset( $db->prefix ) ||
			! preg_match( '/^[A-Za-z0-9_]+$/D', $db->prefix ) ) { self::fail(); }
		$this->db = $db; $this->blog = $blog; $this->prefix = $db->prefix;
	}

	/** Canonical closed JSON domain also rejects duplicate keys/unknown fields. */
	public static function decode( $raw, $blog ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES ) { self::fail(); }
		$r = json_decode( $raw, true, 8 );
		$keys = array( 'version', 'blog_id', 'backend', 'epoch', 'switch_id', 'from', 'to', 'phase', 'table_id',
			'source_digest', 'logical_digest', 'schema', 'codec', 'created', 'updated' );
		$scan = is_array( $r ) && isset( $r['version'] ) && 3 === $r['version'];
		if ( is_array( $r ) && isset( $r['version'] ) && in_array( $r['version'], array( 2, 3 ), true ) ) { $keys[] = 'retained_digest'; }
		if ( $scan ) { $keys[] = 'scan'; }
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $r ) || array_keys( $r ) !== $keys || json_encode( $r ) !== $raw ||
			! in_array( $r['version'], array( 1, 2, 3 ), true ) || $blog !== $r['blog_id'] || 1 !== $r['schema'] || 1 !== $r['codec'] ||
			! is_int( $r['epoch'] ) || $r['epoch'] < 0 || ! is_int( $r['created'] ) || $r['created'] < 1 ||
			! is_int( $r['updated'] ) || $r['updated'] < $r['created'] ||
			! in_array( $r['backend'], array( 'option', 'table' ), true ) ||
			! in_array( $r['from'], array( 'option', 'table' ), true ) ||
			! in_array( $r['to'], array( 'option', 'table' ), true ) || $r['from'] === $r['to'] ||
			! in_array( $r['phase'], $scan ? array( 'scanning', 'scanned' ) : array( 'copying', 'verified', 'flipped', 'stable' ), true ) ) { self::fail(); }
		foreach ( array( 'switch_id', 'table_id', 'source_digest', 'logical_digest' ) as $key ) {
			if ( $scan && 'logical_digest' === $key && null === $r[$key] ) { continue; }
			$length = in_array( $key, array( 'switch_id', 'table_id' ), true ) ? 32 : 64;
			if ( ! is_string( $r[$key] ) || ! preg_match( '/^[a-f0-9]{' . $length . '}$/D', $r[$key] ) ) { self::fail(); }
		}
		if ( 2 === $r['version'] && ( 'table' === $r['to'] ? null !== $r['retained_digest'] :
			( ! is_string( $r['retained_digest'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $r['retained_digest'] ) ) ) ) { self::fail(); }
		if ( $scan && ( 'option' !== $r['from'] || null !== $r['logical_digest'] || null !== $r['retained_digest'] ||
			! is_array( $r['scan'] ) || array_keys( $r['scan'] ) !== array( 'generation', 'done', 'parser', 'mac' ) ||
			! is_int( $r['scan']['generation'] ) || $r['scan']['generation'] < 1 || ! is_bool( $r['scan']['done'] ) ||
			( 'scanned' === $r['phase'] ) !== $r['scan']['done'] || ! is_array( $r['scan']['parser'] ) ||
			! is_string( $r['scan']['mac'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $r['scan']['mac'] ) ) ) { self::fail(); }
		$before_flip = in_array( $r['phase'], array( 'copying', 'verified', 'scanning', 'scanned' ), true );
		if ( $r['backend'] !== ( $before_flip ? $r['from'] : $r['to'] ) ||
			( ! $before_flip && $r['epoch'] < 1 ) || ( 'table' === $r['backend'] && $r['epoch'] < 1 ) ||
			( 'table' === $r['to'] && $r['table_id'] !== $r['switch_id'] ) ) { self::fail(); }
		return $r;
	}

	public static function sentinel( $r ) {
		return array( 'version' => 1, 'blog_id' => $r['blog_id'], 'epoch' => $r['epoch'], 'switch_id' => $r['table_id'] );
	}

	/** Interrupted flips remain unavailable to ordinary readers AND writers. */
	public static function runtime_ready( $r, $sentinel_raw ) {
		if ( 'stable' !== $r['phase'] ) { return false; }
		return 'option' === $r['backend'] ? null === $sentinel_raw : json_encode( self::sentinel( $r ) ) === $sentinel_raw;
	}

	/** Null means absent; empty/corrupt/oversize evidence is present and invalid. */
	public function read( $name ) {
		$span = StorageScalarObserver::begin( 8 );
		try {
			$this->context(); $this->name( $name );
			$raw = $this->db->get_var( $this->db->prepare(
				"SELECT CONCAT('v:', CASE WHEN LENGTH(option_value) <= 2048 THEN option_value ELSE '' END) FROM `" . $this->prefix . "options` WHERE option_name = %s LIMIT 1", array( $name )
			) );
			if ( ! empty( $this->db->last_error ) || ( null !== $raw && ( ! is_string( $raw ) || 0 !== strpos( $raw, 'v:' ) ) ) ) { self::fail(); }
			$result = null === $raw ? null : substr( $raw, 2 );
			StorageScalarObserver::end( $span, 1, null === $result ? 0 : strlen( $result ) );
			return $result;
		} catch ( \Exception $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
		catch ( \Throwable $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
	}

	/** No cached negatives, INSERT IGNORE, collation equality or unowned delete. */
	public function cas( $name, $old, $new, $source_guard = null, $maintenance_raw = null, $deadline = null ) {
		$span = StorageScalarObserver::begin( null === $source_guard ? 11 : 7 );
		try {
			$this->context(); $this->name( $name );
			if ( null !== $deadline && ( ( ! is_float( $deadline ) && ! is_int( $deadline ) ) || ! is_finite( (float) $deadline ) ) ) { self::fail(); }
			if ( ( null !== $old && ( ! is_string( $old ) || strlen( $old ) > self::MAX_BYTES ) ) ||
				( null !== $new && ( ! is_string( $new ) || strlen( $new ) > self::MAX_BYTES ) ) || ( null === $old && null === $new ) ) { self::fail(); }
			if ( null === $old ) {
				$sql = $this->db->prepare( 'INSERT INTO `' . $this->prefix . "options` ( option_name, option_value, autoload )" .
					( null === $maintenance_raw && null === $source_guard ? " VALUES ( %s, %s, 'no' )" : " SELECT %s, %s, 'no' WHERE 1 = 1" ), array( $name, $new ) );
			} elseif ( null === $new ) {
				$sql = $this->db->prepare( 'DELETE FROM `' . $this->prefix . 'options` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $name, $old ) );
			} else {
				$sql = $this->db->prepare( 'UPDATE `' . $this->prefix . "options` SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = BINARY %s", array( $new, $name, $old ) );
			}
			if ( null !== $source_guard ) {
				if ( StorageAuthority::CONTROL_OPTION !== $name || null === $new ||
					( null === $old && ( null === $maintenance_raw || ! is_array( $source_guard ) || ! isset( $source_guard['kind'] ) || 'option' !== $source_guard['kind'] ) ) ) { self::fail(); }
				$sql .= $this->source_guard( $source_guard );
			}
			if ( null !== $maintenance_raw ) { $sql .= $this->maintenance_guard( $maintenance_raw ); }
			// Trusted caller's entry fence, not interruption of query/SHA2/readback.
			if ( null !== $deadline && microtime( true ) >= $deadline ) { self::fail(); }
			$result = $this->db->query( $sql );
			if ( 1 !== $result || ! empty( $this->db->last_error ) ) { self::fail(); }
			wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
			StorageScalarObserver::end( $span );
			$span = 0; // Readback is a separate span, not part of the source-fenced query.
			if ( $this->read( $name ) !== $new ) { self::fail(); }
			return true;
		} catch ( \Exception $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
		catch ( \Throwable $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
	}

	/**
	 * Same-statement conversion dependency, not authority to acquire or take over.
	 * LIMIT prevents derived-table merging into a target-table subquery on MySQL.
	 * All writer markers block, even malformed/expired ones; TTL is not drain proof.
	 */
	public function maintenance_guard( $raw ) {
		$this->context();
		if ( ! is_string( $raw ) || strlen( $raw ) > 512 ) { self::fail(); }
		$r = json_decode( $raw, true, 4 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $r ) ||
			array_keys( $r ) !== array( 'version', 'blog_id', 'token', 'expires' ) || json_encode( $r ) !== $raw ||
			1 !== $r['version'] || $this->blog !== $r['blog_id'] || ! is_string( $r['token'] ) ||
			! preg_match( '/^[A-Za-z0-9]{32}$/D', $r['token'] ) || ! is_int( $r['expires'] ) || $r['expires'] <= time() ) { self::fail(); }
		$table = '`' . $this->prefix . 'options`';
		return $this->db->prepare( ' AND EXISTS (SELECT 1 FROM (SELECT option_value AS maintenance_value FROM ' . $table .
			' WHERE option_name = %s LIMIT 1) AS maintenance_owner WHERE BINARY maintenance_value = BINARY %s)' .
			' AND UNIX_TIMESTAMP() < %d AND NOT EXISTS (SELECT 1 FROM (SELECT option_name FROM ' . $table .
			' WHERE option_name LIKE %s LIMIT 1) AS storage_writers)',
			array( StorageAuthority::MAINTENANCE_OPTION, $raw, $r['expires'], $this->db->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) );
	}

	/** Atomic flip dependency, closed shapes and fixed source names only. */
	private function source_guard( $guard, $nested = false ) {
		if ( ! is_array( $guard ) || array_keys( $guard ) !== array( 'kind', 'value' ) ) { self::fail(); }
		if ( 'all' === $guard['kind'] ) {
			if ( $nested || ! is_array( $guard['value'] ) || count( $guard['value'] ) < 2 || count( $guard['value'] ) > 3 || array_keys( $guard['value'] ) !== range( 0, count( $guard['value'] ) - 1 ) ) { self::fail(); }
			$sql = ''; foreach ( $guard['value'] as $dependency ) { $sql .= $this->source_guard( $dependency, true ); } return $sql;
		}
		if ( 'sentinel' === $guard['kind'] ) {
			$raw = $guard['value'];
			if ( null !== $raw && ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES ) ) { self::fail(); }
			$select = '(SELECT sentinel_value FROM (SELECT option_value AS sentinel_value FROM `' . $this->prefix . 'options` WHERE option_name = %s) AS target_sentinel)';
			return $this->db->prepare( ' AND ' . $select . ( null === $raw ? ' IS NULL' : ' = BINARY %s' ), null === $raw ? array( StorageAuthority::AUTHORITY_OPTION ) : array( StorageAuthority::AUTHORITY_OPTION, $raw ) );
		}
		if ( 'option' === $guard['kind'] ) {
			$hash = $guard['value'];
			if ( null !== $hash && ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) ) { self::fail(); }
			// Derived table avoids MySQL's target-table subquery restriction.
			$select = '(SELECT source_hash FROM (SELECT SHA2(option_value, 256) AS source_hash FROM `' . $this->prefix . 'options` WHERE option_name = %s) AS source_option)';
			return $this->db->prepare( ' AND ' . $select . ( null === $hash ? ' IS NULL' : ' = %s' ), null === $hash ? array( FOOGALLERY_MIGRATE_OPTION_DATA ) : array( FOOGALLERY_MIGRATE_OPTION_DATA, $hash ) );
		}
		if ( 'table' !== $guard['kind'] || ! is_array( $guard['value'] ) || count( $guard['value'] ) > 100 ||
			! isset( $this->db->base_prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $this->db->base_prefix ) ) { self::fail(); }
		$table = $this->db->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
		$sql = $this->db->prepare( ' AND (SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id = %d) = %d', array( $this->blog, count( $guard['value'] ) ) );
		foreach ( $guard['value'] as $head ) {
			if ( ! is_array( $head ) || array_keys( $head ) !== array( 'id', 'revision', 'generation' ) ) { self::fail(); }
			foreach ( $head as $value ) { if ( ! is_int( $value ) || $value < 1 ) { self::fail(); } }
			$sql .= $this->db->prepare( ' AND EXISTS (SELECT 1 FROM `' . $table . '` WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d)', array( $this->blog, $head['id'], $head['revision'], $head['generation'] ) );
		}
		return $sql;
	}

	private function name( $name ) {
		if ( ! in_array( $name, array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION ), true ) ) { self::fail(); }
	}

	private function context() {
		if ( $this->blog !== get_current_blog_id() || $this->prefix !== $this->db->prefix ) { self::fail(); }
	}

	private static function fail() { throw new \RuntimeException( 'Storage control requires authoritative recovery; no fallback or unowned replacement.' ); }
}
