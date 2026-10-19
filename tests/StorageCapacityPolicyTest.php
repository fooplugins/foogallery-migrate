<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy as Policy;
use FooPlugins\FooGalleryMigrate\StorageCapacityBudget as Budget;
use PHPUnit\Framework\TestCase;

class StorageCapacityPolicyTest extends TestCase {
	public static function capacity_class() {
		$e = array( 'php_versions' => array( '5.4.0' ), 'wp_versions' => array( '5.0' ), 'sapi' => array( 'cli-server' ),
			'word_bytes' => 8, 'endian' => 'little', 'os_family' => 'Linux', 'allocator' => 'zend', 'debug' => false, 'zts' => false,
			'driver_family' => 'mysqli', 'driver_versions' => array( 'test-driver' ),
			'required_ini' => array( 'precision' => '14', 'serialize_precision' => false, 'zend.enable_gc' => '1', 'opcache.enable' => false,
				'opcache.enable_cli' => false, 'opcache.jit' => false, 'opcache.jit_buffer_size' => false ), 'required_capabilities' => array( 'hash_init', 'mysqli_query' ) );
		$envelope = array( 'fixed_bytes' => 10, 'per_dimension' => array_fill_keys( Policy::dimensions(), 1 ) );
		return array( 'eligibility' => $e, 'limits' => Policy::ceilings(), 'reserve_bytes' => 10,
			'phase_envelopes' => array_fill_keys( Policy::phases(), array( 'zend' => $envelope, 'native' => $envelope ) ), 'qualification_id' => 'TEST_ONLY_NOT_RECEIVED' );
	}
	public static function policy() { return array( 'schema_version' => 2, 'model_version' => 1, 'implementation_sha256' => str_repeat( 'a', 64 ), 'training_evidence_sha256' => str_repeat( 'b', 64 ), 'classes' => array( 'test' => self::capacity_class() ) ); }
	private function refused( $callback ) {
		try { $callback(); $this->fail( 'Invalid capacity input admitted.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
	}
	public function test_unreceived_release_is_fail_closed_and_data_is_not_executed(): void {
		$this->assertInstanceOf( \WP_Error::class, Policy::resolve() );
		$this->refused( function () { Policy::parse( '<?php exit;', str_repeat( 'a', 64 ) ); } );
		$this->refused( function () { Policy::parse( str_repeat( ' ', Policy::MAX_BYTES + 1 ), str_repeat( 'a', 64 ) ); } );
	}
	public function test_strict_schema_duplicate_unknown_missing_bad_numeric_binding_and_utf8(): void {
		$p = self::policy(); $raw = json_encode( $p );
		$this->assertSame( $p, Policy::parse( $raw, str_repeat( 'a', 64 ) ) );
		foreach ( array( '{"schema_version":2,' . substr( $raw, 1 ), str_replace( '"schema_version":2', '"schema_version":2.0', $raw ),
			str_replace( '"schema_version":2', '"schema_version":2,"unknown":true', $raw ), substr( $raw, 0, -1 ), "\xff" . $raw ) as $invalid ) {
			$this->refused( function () use ( $invalid ) { Policy::parse( $invalid, str_repeat( 'a', 64 ) ); } );
		}
		$this->refused( function () use ( $raw ) { Policy::parse( $raw, str_repeat( 'c', 64 ) ); } );
		foreach ( Policy::ceilings() as $key => $n ) {
			foreach ( array( 0, -1, (string) $n, (float) $n, null, PHP_INT_MAX ) as $bad ) {
				$c = self::capacity_class(); $c['limits'][$key] = $bad;
				$this->refused( function () use ( $c ) { Policy::validate_class( $c ); } );
			}
		}
		$c = self::capacity_class(); unset( $c['phase_envelopes']['stage']['native']['per_dimension']['M'] );
		$this->refused( function () use ( $c ) { Policy::validate_class( $c ); } );
	}
	private function facts() {
		$f = self::capacity_class()['eligibility'];
		foreach ( array( 'php_versions', 'wp_versions', 'sapi', 'driver_versions' ) as $key ) { $f[$key] = $f[$key][0]; }
		$f['instrumented'] = false; return $f;
	}
	public function test_server_facts_unknown_allocator_instrumentation_and_ambiguous_class_refuse(): void {
		$p = self::policy(); $f = $this->facts();
		$this->assertSame( $p['classes']['test'], Policy::select( $p, $f ) );
		foreach ( array( 'php_versions' => '5.4.1', 'wp_versions' => '5.0.1', 'sapi' => 'cli', 'allocator' => null,
			'word_bytes' => 4, 'instrumented' => true, 'required_ini' => array(), 'required_capabilities' => array() ) as $key => $bad ) {
			$invalid = $f; $invalid[$key] = $bad;
			$this->refused( function () use ( $p, $invalid ) { Policy::select( $p, $invalid ); } );
		}
		$p['classes']['duplicate'] = $p['classes']['test'];
		$this->refused( function () use ( $p, $f ) { Policy::select( $p, $f ); } );
	}
	public function test_restrictions_cannot_lower_any_phase_native_zend_dimension_or_reserve(): void {
		$c = self::capacity_class(); $tight = $c; $tight['limits']['max_rows']--; $tight['reserve_bytes']++;
		$this->assertSame( $tight, Policy::tighten( $c, $tight ) );
		foreach ( Policy::phases() as $phase ) { foreach ( array( 'zend', 'native' ) as $domain ) {
			foreach ( Policy::dimensions() as $dim ) {
				$t = $c; $t['phase_envelopes'][$phase][$domain]['per_dimension'][$dim] = 0;
				$this->refused( function () use ( $c, $t ) { Policy::tighten( $c, $t ); } );
			}
		} }
		$t = $c; $t['reserve_bytes']--;
		$this->refused( function () use ( $c, $t ) { Policy::tighten( $c, $t ); } );
	}
	public function test_non_self_referential_implementation_binding_is_repeatable(): void {
		$digest = Policy::implementation_digest(); $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $digest );
		$this->assertSame( $digest, Policy::implementation_digest() );
	}
	public function test_checked_math_overflow_and_unknown_dimensions(): void {
		$this->refused( function () { Budget::add( PHP_INT_MAX, 1 ); } );
		$this->refused( function () { Budget::multiply( PHP_INT_MAX, 2 ); } );
		$b = $this->budget();
		$this->refused( function () use ( $b ) { $b->dimensions( array( 'unknown' => 1 ) ); } );
		$this->refused( function () use ( $b ) { $b->dimensions( array( 'T' => 262145 ) ); } );
	}
	private function budget( $ceiling = 1000, $memory = null, $clock = null ) {
		$c = self::capacity_class(); $c['limits']['memory_ceiling_bytes'] = $ceiling;
		if ( null === $memory ) { $memory = function () { return array( 'limit' => '-1', 'zend' => 100, 'live' => 80, 'rss' => 100, 'peak' => 100 ); }; }
		return new Budget( $c, null === $clock ? function () { return 1.0; } : $clock, $memory );
	}
	public function test_parent_and_sibling_forecasts_survive_samples_and_cannot_be_released_by_other_helper(): void {
		$b = $this->budget( 200 ); $parent = new \stdClass(); $child = new \stdClass();
		$p = $b->open( $parent, 'option_decode', array( 'R' => 10 ) ); // 40
		$s = $b->open( $child, 'stage', array( 'N' => 10 ) ); // 40
		$b->checkpoint();
		$this->refused( function () use ( $b, $parent, $p ) { $b->close( $parent, $p ); } );
		$this->refused( function () use ( $b, $child ) { $b->open( $child, 'table_verify', array() ); } );
		$b->close( $child, $s ); $b->close( $parent, $p ); $b->checkpoint();
		$this->assertTrue( true );
	}
	public function test_actual_limit_highwater_deadline_response_and_aborted_scope_are_finite(): void {
		$peak = 100;
		$memory = function () use ( &$peak ) { return array( 'limit' => '150', 'zend' => $peak, 'live' => 80, 'rss' => 100, 'peak' => 100 ); };
		$b = $this->budget( 1000, $memory ); $owner = new \stdClass(); $t = $b->open( $owner, 'stage', array() );
		$peak = 130;
		$this->refused( function () use ( $b ) { $b->checkpoint(); } );
		$peak = 100;
		$this->refused( function () use ( $b ) { $b->checkpoint(); } ); // no sample credit
		$time = 1.0; $clock = function () use ( &$time ) { return $time; }; $b = $this->budget( 1000, null, $clock ); $time = 11.0;
		$this->refused( function () use ( $b ) { $b->tick(); } );
		$b = $this->budget();
		$this->refused( function () use ( $b ) { $b->response( 65537 ); } );
		$b->abort(); $this->refused( function () use ( $b ) { $b->checkpoint(); } );
	}
}
