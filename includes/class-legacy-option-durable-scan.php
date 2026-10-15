<?php
/**
 * Internal trusted structural-scan owner. No endpoint, conversion or publication.
 * Each request separately admits/reacquires the WHOLE option (<=16MiB), hashes it
 * in PHP and again in the source-fenced SQL CAS. These O(N) setup/commit costs
 * are NOT scanner step work or covered by its cooperative deadline. No graph is
 * decoded. Only parser work is sliced; total request work is not O(step bytes).
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LegacyOptionDurableScan {
	private $gate;
	private $controls;
	private $connection;
	private $prefix;
	private $blog;
	private $policy;
	private $memory;
	private $record;
	private $length;
	private $digest;
	private $taking = false;

	private function __construct( $policy ) {
		global $wpdb;
		$this->gate = StorageMaintenanceGate::current();
		$this->connection = $this->gate->connection();
		$this->prefix = $wpdb->prefix; $this->blog = get_current_blog_id();
		$this->controls = new StorageControl( $this->connection, $this->blog );
		$this->policy = $policy; $this->memory = new StorageMemoryBaseline();
		$this->idle_connection();
	}
	private function __clone() {}
	public function __sleep() { self::fail(); }
	public function __wakeup() { self::fail(); }

	/** Internal caller supplies trusted calibration, never request policy/parser. */
	public static function start( $policy, $bytes = 4096, $nodes = 256, $seconds = 0.01 ) {
		return ( new self( $policy ) )->run( null, null, $bytes, $nodes, $seconds );
	}
	public static function advance( $id, $generation, $policy, $bytes = 4096, $nodes = 256, $seconds = 0.01 ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || ! is_int( $generation ) || $generation < 1 ) { self::fail(); }
		return ( new self( $policy ) )->run( $id, $generation, $bytes, $nodes, $seconds );
	}

	/** Read-after-uncertain-write receipt: does not replay or advance a checkpoint. */
	public static function status( $id ) {
		$owner = new self( null );
		$r = $owner->read_verified();
		if ( ! is_string( $id ) || $id !== $r['switch_id'] ) { self::fail(); }
		return $owner->receipt( $r );
	}

	private function run( $id, $generation, $bytes, $nodes, $seconds ) {
		$this->budget( 0 ); // Fail closed before a source read, even with unlimited PHP memory.
		if ( true !== $this->gate->acquire_maintenance() ) { self::fail(); }
		try {
			$this->ready();
			$old = $this->controls->read( StorageAuthority::CONTROL_OPTION );
			if ( null === $id ) {
				if ( null !== $old || false !== StorageAuthority::option_mode_error( $this->gate, true ) ) { self::fail(); }
				$now = time(); $switch = substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 32 );
				$this->record = array( 'version' => 3, 'blog_id' => $this->blog, 'backend' => 'option', 'epoch' => 0,
					'switch_id' => $switch, 'from' => 'option', 'to' => 'table', 'phase' => 'scanning', 'table_id' => $switch,
					'source_digest' => null, 'logical_digest' => null, 'schema' => 1, 'codec' => 1,
					'created' => $now, 'updated' => $now, 'retained_digest' => null, 'scan' => null );
			} else {
				$this->record = $this->verify( $old );
				if ( $id !== $this->record['switch_id'] || $generation !== $this->record['scan']['generation'] || $this->record['scan']['done'] ) { self::fail(); }
			}
			if ( null !== $this->controls->read( StorageAuthority::AUTHORITY_OPTION ) ) { self::fail(); }
			$raw = $this->source();
			$this->taking = true;
			$scanner = LegacyOptionStructuralScan::owned( $raw, $this );
			$result = $scanner->step( null, $bytes, $nodes, $seconds );
			$parser = $result['cursor']['parser'];
			$parser['key_prefix'] = base64_encode( $parser['key_prefix'] );
			$this->record['updated'] = max( time(), $this->record['updated'] );
			$this->record['phase'] = $result['done'] ? 'scanned' : 'scanning';
			$this->record['scan'] = array( 'generation' => $result['cursor']['sequence'], 'done' => $result['done'], 'parser' => $parser, 'mac' => '' );
			$this->record['scan']['mac'] = $this->mac( $this->record );
			$new = json_encode( $this->record );
			StorageControl::decode( $new, $this->blog ); // Includes unchanged 2048-byte storage cap.
			$this->ready();
			$this->controls->cas( StorageAuthority::CONTROL_OPTION, $old, $new, array( 'kind' => 'option', 'value' => $this->digest ), $this->gate->maintenance_identity() );
			if ( true !== $this->gate->release_maintenance() ) { self::fail(); }
			$receipt = $this->receipt( $this->record );
			$receipt['bytes'] = $result['bytes']; $receipt['nodes'] = $result['nodes'];
			$receipt['source_bytes_acquired'] = $this->length;
			$receipt['source_php_hash_passes'] = 1;
			$receipt['source_sql_sha_expressions'] = 1; // Query shape, not a measured physical DB pass count.
			return $receipt;
		} catch ( \Exception $error ) {
			$this->taking = false; $this->gate->release_maintenance(); throw $error;
		} catch ( \Throwable $error ) {
			$this->taking = false; $this->gate->release_maintenance(); throw $error;
		}
	}

	/** Capability is never returned to callers; consumed synchronously only once. */
	public function take_state( $digest, $length ) {
		if ( ! $this->taking || $length !== $this->length || ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) { self::fail(); }
		$this->taking = false; $this->digest = $digest;
		$physical = hash( 'sha256', 'present:' . $digest );
		if ( null === $this->record['scan'] ) { $this->record['source_digest'] = $physical; return null; }
		if ( $physical !== $this->record['source_digest'] ) { self::fail(); }
		$scan = $this->record['scan'];
		$scan['parser']['key_prefix'] = base64_decode( $scan['parser']['key_prefix'], true );
		if ( false === $scan['parser']['key_prefix'] ) { self::fail(); }
		return $scan;
	}

	private function source() {
		$db = $this->connection;
		$length = $db->get_var( $db->prepare( 'SELECT LENGTH(option_value) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
		if ( ! empty( $db->last_error ) || ! is_numeric( $length ) || (string) (int) $length !== (string) $length || (int) $length < 0 || (int) $length > 16777216 ) { self::fail(); }
		$this->length = (int) $length; $this->budget( $this->length );
		$raw = $db->get_var( $db->prepare( "SELECT CONCAT('v:', option_value) FROM `" . $this->prefix . 'options` WHERE option_name = %s AND LENGTH(option_value) = %d LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA, $this->length ) ) );
		if ( ! empty( $db->last_error ) || ! is_string( $raw ) || 'v:' !== substr( $raw, 0, 2 ) || strlen( $raw ) !== $this->length + 2 ) { self::fail(); }
		return substr( $raw, 2 );
	}
	private function budget( $bytes ) {
		$r = StoragePreflight::decode_budget( $bytes, ini_get( 'memory_limit' ), $this->memory->sample(), $this->policy );
		if ( is_wp_error( $r ) || true !== $r['decode_admitted'] ) { self::fail(); }
	}
	/** A same-session readback inside someone else's transaction is not durable.
	 * Match the existing repository guard: SET without SESSION/GLOBAL refuses
	 * active transactions without committing or rolling back the caller's work.
	 */
	private function idle_connection() {
		$db = $this->connection;
		$mode = $db->get_var( 'SELECT @@SESSION.autocommit' );
		if ( ! empty( $db->last_error ) || '1' !== (string) $mode ||
			false === $db->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || ! empty( $db->last_error ) ) { self::fail(); }
	}
	private function ready() {
		$this->idle_connection();
		if ( true !== $this->gate->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) ) ) { self::fail(); }
	}
	private function read_verified() { return $this->verify( $this->controls->read( StorageAuthority::CONTROL_OPTION ) ); }
	private function verify( $raw ) {
		$r = StorageControl::decode( $raw, $this->blog );
		if ( 3 !== $r['version'] || ! $this->equal( $r['scan']['mac'], $this->mac( $r ) ) ) { self::fail(); }
		return $r;
	}
	private function mac( $r ) {
		if ( ! function_exists( 'wp_salt' ) ) { self::fail(); }
		$key = wp_salt( 'auth' );
		if ( ! is_string( $key ) || strlen( $key ) < 32 ) { self::fail(); }
		$r['scan']['mac'] = '';
		return hash_hmac( 'sha256', 'foogallery-structural-scan-v1:' . $this->prefix . ':' . json_encode( $r ), $key );
	}
	private function equal( $a, $b ) {
		if ( strlen( $a ) !== strlen( $b ) ) { return false; }
		$diff = 0; for ( $i = 0; $i < strlen( $a ); ++$i ) { $diff |= ord( $a[$i] ) ^ ord( $b[$i] ); }
		return 0 === $diff;
	}
	private function receipt( $r ) {
		return array( 'switch_id' => $r['switch_id'], 'generation' => $r['scan']['generation'], 'done' => $r['scan']['done'],
			'offset' => $r['scan']['parser']['offset'], 'total_nodes' => $r['scan']['parser']['nodes'], 'source_digest' => $r['source_digest'] );
	}
	private static function fail() { throw new \RuntimeException( 'Durable structural scan refused; retain source authority and current checkpoint.' ); }
}
