<?php
/** Operation-owned finite Zend/native forecasts. Observation is not enforcement. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StorageCapacityBudget {
	private $class;
	private $deadline;
	private $clock;
	private $memory;
	private $highwater = 0;
	private $work = 0;
	private $stack = array();
	private $serial = 0;
	private $failed = false;
	private $sampling = false;
	private $ticking = false;

	/** Injected observers are private harness dependencies, never request fields. */
	public function __construct( $class, $clock = null, $memory = null ) {
		$this->class = isset( $class['release_version'] ) ? self::release_class( $class ) : StorageCapacityPolicy::validate_class( $class );
		if ( ( null !== $clock && ! is_callable( $clock ) ) || ( null !== $memory && ! is_callable( $memory ) ) ) { self::fail(); }
		$this->clock = $clock; $this->memory = $memory;
		$this->deadline = $this->now() + $this->limit( 'max_seconds' );
		$execution = ini_get( 'max_execution_time' );
		if ( false === $execution || ! preg_match( '/^[0-9]+$/D', $execution ) || ( 0 !== (int) $execution && (int) $execution < $this->limit( 'max_seconds' ) ) ) { self::fail(); }
		$this->checkpoint();
	}
	public function __clone() { self::fail(); }
	public function __sleep() { self::fail(); }
	public function __wakeup() { self::fail(); }
	public function limit( $name ) { if ( ! isset( $this->class['limits'][$name] ) ) { self::fail(); } return $this->class['limits'][$name]; }
	public function deadline() { return $this->deadline; }
	public function max_dimensions() {
		return array( 'R' => $this->limit( 'max_raw_bytes' ), 'T' => $this->limit( 'max_tokens' ), 'N' => $this->limit( 'max_rows' ),
			'E' => $this->limit( 'max_bytes' ), 'S' => $this->limit( 'max_scalar_bytes' ), 'M' => $this->limit( 'max_row_bytes' ),
			'H' => $this->limit( 'max_heads' ), 'D' => $this->limit( 'max_depth' ), 'W' => $this->limit( 'copy_batch_bytes' ) );
	}
	public function dimensions( $values = array() ) {
		$d = array_fill_keys( StorageCapacityPolicy::dimensions(), 0 );
		foreach ( $values as $key => $n ) { if ( ! array_key_exists( $key, $d ) || ! is_int( $n ) || $n < 0 ) { self::fail(); } $d[$key] = $n; }
		$limits = array( 'R' => 'max_raw_bytes', 'T' => 'max_tokens', 'N' => 'max_rows', 'E' => 'max_bytes', 'S' => 'max_scalar_bytes', 'M' => 'max_row_bytes', 'H' => 'max_heads', 'D' => 'max_depth', 'W' => 'copy_batch_bytes' );
		foreach ( $limits as $key => $limit ) { if ( $d[$key] > $this->limit( $limit ) ) { self::fail(); } }
		return $d;
	}
	public function open( $owner, $phase, $dimensions ) {
		if ( ! is_object( $owner ) || ! in_array( $phase, StorageCapacityPolicy::phases(), true ) || count( $this->stack ) >= 65 || $this->serial >= 65536 ) { self::fail(); }
		$d = $this->dimensions( $dimensions ); $pending = 0;
		if ( isset( $this->class['release_version'] ) ) { $pending = $this->class['phase_headroom_bytes']; }
		else {
			foreach ( $this->class['phase_envelopes'][$phase] as $domain ) {
				$pending = self::add( $pending, $domain['fixed_bytes'] );
				foreach ( $domain['per_dimension'] as $key => $coefficient ) { $pending = self::add( $pending, self::multiply( $coefficient, $d[$key] ) ); }
			}
		}
		$this->checkpoint( $pending );
		$token = new \stdClass();
		$this->stack[] = array( 'token' => $token, 'owner' => $owner, 'pending' => $pending ); ++$this->serial;
		$this->checkpoint(); return $token;
	}
	/** Only an unwound owner's topmost helper can release its forecast. */
	public function close( $owner, $token ) {
		if ( $this->failed || ! count( $this->stack ) ) { self::fail(); }
		$s = $this->stack[count( $this->stack ) - 1];
		if ( $s['owner'] !== $owner || $s['token'] !== $token ) { self::fail(); }
		$this->checkpoint(); array_pop( $this->stack ); $this->checkpoint();
	}
	public function abort() { $this->failed = true; }
	public function tick( $work = 1 ) {
		$this->ticking && self::fail(); $this->ticking = true; $now = $this->now(); $this->ticking = false;
		if ( $this->failed || ! is_int( $work ) || $work < 0 || $this->work > $this->limit( 'max_work' ) - $work || $now >= $this->deadline ) { self::fail(); }
		$this->work += $work;
	}
	public function response( $bytes ) { if ( ! is_int( $bytes ) || $bytes < 0 || $bytes > $this->limit( 'max_response_bytes' ) ) { self::fail(); } $this->checkpoint( $bytes ); }
	public function checkpoint( $scratch = 0 ) {
		if ( $this->sampling ) { self::fail(); } $this->sampling = true;
		$this->tick();
		if ( ! is_int( $scratch ) || $scratch < 0 ) { self::fail(); }
		$sample = null === $this->memory ? $this->observe() : call_user_func( $this->memory );
		$this->sampling = false;
		if ( ! is_array( $sample ) || array_keys( $sample ) !== array( 'limit', 'zend', 'live', 'rss', 'peak' ) ) { self::fail(); }
		$limit = StoragePreflight::memory_bytes( $sample['limit'] );
		if ( false === $limit ) { self::fail(); }
		foreach ( array( 'zend', 'live', 'rss', 'peak' ) as $name ) { if ( ! is_int( $sample[$name] ) || $sample[$name] < 0 ) { self::fail(); } $this->highwater = max( $this->highwater, $sample[$name] ); }
		$ceiling = -1 === $limit ? $this->limit( 'memory_ceiling_bytes' ) : min( $limit, $this->limit( 'memory_ceiling_bytes' ) );
		$pending = self::add( $this->highwater, $this->class['reserve_bytes'] ); $pending = self::add( $pending, $scratch );
		foreach ( $this->stack as $scope ) { $pending = self::add( $pending, $scope['pending'] ); }
		if ( $pending > $ceiling ) { self::fail(); }
	}
	private function observe() {
		if ( isset( $this->class['release_version'] ) ) {
			// Portable real-size peak plus the release reserve for untracked native
			// allocations; not a claim of allocator-specific peak proof.
			$zend = memory_get_usage( true ); $live = memory_get_usage( false ); $peak = memory_get_peak_usage( true );
			if ( ! is_int( $zend ) || ! is_int( $live ) || ! is_int( $peak ) || $zend < 0 || $live < 0 || $peak < 0 ) { self::fail(); }
			return array( 'limit' => ini_get( 'memory_limit' ), 'zend' => $zend, 'live' => $live, 'rss' => $zend, 'peak' => $peak );
		}
		// The old host-qualified envelope remains a diagnostic-only dependency.
		if ( 'Linux' !== PHP_OS || ! is_readable( '/proc/self/status' ) || ! function_exists( 'getrusage' ) ) { self::fail(); }
		$raw = file_get_contents( '/proc/self/status', false, null, 0, 8193 );
		if ( ! is_string( $raw ) || strlen( $raw ) > 8192 || ! preg_match( '/^VmRSS:\s+([0-9]+) kB$/m', $raw, $match ) || (string) (int) $match[1] !== $match[1] ) { self::fail(); }
		$rss = self::multiply( (int) $match[1], 1024 ); $usage = getrusage();
		if ( ! is_array( $usage ) || ! isset( $usage['ru_maxrss'] ) || ! is_int( $usage['ru_maxrss'] ) || $usage['ru_maxrss'] < 0 ) { self::fail(); }
		return array( 'limit' => ini_get( 'memory_limit' ), 'zend' => memory_get_usage( true ), 'live' => memory_get_usage( false ), 'rss' => $rss, 'peak' => self::multiply( $usage['ru_maxrss'], 1024 ) );
	}
	private static function release_class( $class ) {
		if ( ! is_array( $class ) || array_keys( $class ) !== array( 'release_version', 'limits', 'reserve_bytes', 'amplification', 'phase_headroom_bytes' ) ||
			1 !== $class['release_version'] || ! is_array( $class['limits'] ) || ! isset( $class['limits']['max_raw_bytes'] ) ||
			$class['limits'] !== array_replace( StorageCapacityPolicy::ceilings(), array(
				'max_raw_bytes' => $class['limits']['max_raw_bytes'], 'memory_ceiling_bytes' => 536870912, 'copy_batch_rows' => 256 ) ) ||
			! is_int( $class['reserve_bytes'] ) || $class['reserve_bytes'] < 134217728 || $class['reserve_bytes'] > 268435456 ||
			! is_int( $class['amplification'] ) || $class['amplification'] < 16 || $class['amplification'] > 64 ||
			! is_int( $class['phase_headroom_bytes'] ) || $class['phase_headroom_bytes'] < 33554432 || $class['phase_headroom_bytes'] > 67108864 ||
			! is_int( $class['limits']['max_raw_bytes'] ) || $class['limits']['max_raw_bytes'] < 1 || $class['limits']['max_raw_bytes'] > 3145728 ) { self::fail(); }
		return $class;
	}
	public static function add( $a, $b ) { if ( ! is_int( $a ) || ! is_int( $b ) || $a < 0 || $b < 0 || $b > PHP_INT_MAX - $a ) { self::fail(); } return $a + $b; }
	public static function multiply( $a, $b ) { if ( ! is_int( $a ) || ! is_int( $b ) || $a < 0 || $b < 0 ) { self::fail(); } $n = $a * $b; if ( ! is_int( $n ) ) { self::fail(); } return $n; }
	private function now() { $now = null === $this->clock ? microtime( true ) : call_user_func( $this->clock ); if ( ( ! is_int( $now ) && ! is_float( $now ) ) || ! is_finite( (float) $now ) ) { self::fail(); } return $now; }
	private static function fail() { throw new \RuntimeException( 'Finite migration capacity budget refused; retain authority and durable state.' ); }
}
