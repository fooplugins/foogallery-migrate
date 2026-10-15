<?php
/**
 * Internal trusted structural-scan owner. No endpoint, conversion or publication.
 * Each request separately admits/reacquires the WHOLE option (<=16MiB), hashes it
 * in PHP and again in the source-fenced SQL CAS. These O(N) setup/commit costs
 * are NOT parser bytes. Entry-deadline fences stop subsequent work; they do not
 * interrupt blocking acquisition/hash/SQL or bound their runtime. No graph is
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
	private $deadline;

	private function __construct( $policy, $deadline = null ) {
		global $wpdb;
		$this->deadline = $deadline;
		$this->gate = StorageMaintenanceGate::current();
		$this->connection = $this->gate->connection();
		$this->prefix = $wpdb->prefix; $this->blog = get_current_blog_id();
		$this->controls = new StorageControl( $this->connection, $this->blog );
		$this->policy = $policy; $this->memory = new StorageMemoryBaseline();
		$this->idle_connection();
		$this->time();
	}
	private function __clone() {}
	public function __sleep() { self::fail(); }
	public function __wakeup() { self::fail(); }

	/** Internal caller supplies trusted calibration, never request policy/parser. */
	public static function start( $policy, $bytes = 4096, $nodes = 256, $seconds = 0.01 ) {
		$span = StorageScalarObserver::begin( 1 );
		try {
			$deadline = self::entry_deadline( $seconds );
			$result = ( new self( $policy, $deadline ) )->run( null, null, $bytes, $nodes );
			StorageScalarObserver::end( $span );
			return $result;
		} catch ( \Exception $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
		catch ( \Throwable $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
	}
	public static function advance( $id, $generation, $policy, $bytes = 4096, $nodes = 256, $seconds = 0.01 ) {
		$span = StorageScalarObserver::begin( 2 );
		try {
			$deadline = self::entry_deadline( $seconds );
			if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || ! is_int( $generation ) || $generation < 1 ) { self::fail(); }
			$result = ( new self( $policy, $deadline ) )->run( $id, $generation, $bytes, $nodes );
			StorageScalarObserver::end( $span );
			return $result;
		} catch ( \Exception $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
		catch ( \Throwable $error ) { StorageScalarObserver::end( $span, 2 ); throw $error; }
	}

	/** Read-after-uncertain-write receipt: does not replay or advance a checkpoint. */
	public static function status( $id ) {
		$owner = new self( null );
		$r = $owner->read_verified();
		if ( ! is_string( $id ) || $id !== $r['switch_id'] ) { self::fail(); }
		return $owner->receipt( $r );
	}

	private function run( $id, $generation, $bytes, $nodes ) {
		$parser_span = 0; $release_span = 0;
		$this->time();
		$this->budget( 0 ); // Fail closed before a source read, even with unlimited PHP memory.
		$this->time();
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
			$this->time();
			$this->taking = true;
			$scanner = LegacyOptionStructuralScan::owned( $raw, $this );
			$this->time();
			$parser_span = StorageScalarObserver::begin( 6 );
			$result = $scanner->step( null, $bytes, $nodes, $this->deadline - microtime( true ) );
			StorageScalarObserver::end( $parser_span, 1, $result['bytes'] );
			$parser_span = 0;
			$this->time();
			$parser = $result['cursor']['parser'];
			$parser['key_prefix'] = base64_encode( $parser['key_prefix'] );
			$this->record['updated'] = max( time(), $this->record['updated'] );
			$this->record['phase'] = $result['done'] ? 'scanned' : 'scanning';
			$this->record['scan'] = array( 'generation' => $result['cursor']['sequence'], 'done' => $result['done'], 'parser' => $parser, 'mac' => '' );
			$this->record['scan']['mac'] = $this->mac( $this->record );
			$new = json_encode( $this->record );
			StorageControl::decode( $new, $this->blog ); // Includes unchanged 2048-byte storage cap.
			$this->ready();
			$maintenance = $this->gate->maintenance_identity();
			$this->time();
			$this->controls->cas( StorageAuthority::CONTROL_OPTION, $old, $new, array( 'kind' => 'option', 'value' => $this->digest ), $maintenance, $this->deadline );
			$release_span = StorageScalarObserver::begin( 10 );
			$released = $this->gate->release_maintenance();
			StorageScalarObserver::end( $release_span, true === $released ? 1 : 3 );
			$release_span = 0;
			if ( true !== $released ) { self::fail(); }
			$receipt = $this->receipt( $this->record );
			$receipt['bytes'] = $result['bytes']; $receipt['nodes'] = $result['nodes'];
			$receipt['source_bytes_acquired'] = $this->length;
			$receipt['source_php_hash_passes'] = 1;
			$receipt['source_sql_sha_expressions'] = 1; // Query shape, not a measured physical DB pass count.
			return $receipt;
		} catch ( \Exception $error ) {
			StorageScalarObserver::end( $parser_span, 2 );
			StorageScalarObserver::end( $release_span, 2 );
			$this->taking = false;
			$release_span = StorageScalarObserver::begin( 10 );
			$released = $this->gate->release_maintenance();
			StorageScalarObserver::end( $release_span, true === $released ? 1 : 3 );
			throw $error;
		} catch ( \Throwable $error ) {
			StorageScalarObserver::end( $parser_span, 2 );
			StorageScalarObserver::end( $release_span, 2 );
			$this->taking = false;
			$release_span = StorageScalarObserver::begin( 10 );
			$released = $this->gate->release_maintenance();
			StorageScalarObserver::end( $release_span, true === $released ? 1 : 3 );
			throw $error;
		}
	}

	/** Capability is never returned to callers; consumed synchronously only once. */
	public function take_state( $digest, $length ) {
		$this->time();
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
		$this->time();
		$db = $this->connection;
		$admission_span = StorageScalarObserver::begin( 3 );
		$acquisition_span = 0;
		try {
			$length = $db->get_var( $db->prepare( 'SELECT LENGTH(option_value) FROM `' . $this->prefix . 'options` WHERE option_name = %s LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA ) ) );
			$this->time();
			if ( ! empty( $db->last_error ) || ! is_numeric( $length ) || (string) (int) $length !== (string) $length || (int) $length < 0 || (int) $length > 16777216 ) { self::fail(); }
			$this->length = (int) $length; $this->budget( $this->length );
			$this->time();
			StorageScalarObserver::end( $admission_span, 1, $this->length );
			$admission_span = 0;
			$acquisition_span = StorageScalarObserver::begin( 4, $this->length );
			$raw = $db->get_var( $db->prepare( "SELECT CONCAT('v:', option_value) FROM `" . $this->prefix . 'options` WHERE option_name = %s AND LENGTH(option_value) = %d LIMIT 1', array( FOOGALLERY_MIGRATE_OPTION_DATA, $this->length ) ) );
			$this->time();
			if ( ! empty( $db->last_error ) || ! is_string( $raw ) || 'v:' !== substr( $raw, 0, 2 ) || strlen( $raw ) !== $this->length + 2 ) { self::fail(); }
			$result = substr( $raw, 2 );
			StorageScalarObserver::end( $acquisition_span, 1, $this->length );
			return $result;
		} catch ( \Exception $error ) {
			StorageScalarObserver::end( $acquisition_span, 2 ); StorageScalarObserver::end( $admission_span, 2 ); throw $error;
		} catch ( \Throwable $error ) {
			StorageScalarObserver::end( $acquisition_span, 2 ); StorageScalarObserver::end( $admission_span, 2 ); throw $error;
		}
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
		$this->time();
		$this->idle_connection();
		$this->time();
		if ( true !== $this->gate->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) ) ) { self::fail(); }
		$this->time();
	}
	private static function entry_deadline( $seconds ) {
		$entry = microtime( true );
		if ( ( ! is_int( $seconds ) && ! is_float( $seconds ) ) || ! is_finite( (float) $seconds ) || $seconds <= 0 || $seconds > 1 ) { self::fail(); }
		return $entry + $seconds;
	}
	private function time() {
		if ( null !== $this->deadline && microtime( true ) >= $this->deadline ) { self::fail(); }
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
