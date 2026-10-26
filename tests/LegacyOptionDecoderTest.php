<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

class LegacyOptionDecoderTest extends TestCase {
	public function test_native_compact_roundtrip_preserves_all_bytes_and_types(): void {
		$value = array( 9 => false, '09' => null, 'binary' => "\0\xffO:8:\"stdClass\":0:{}R:1;",
			'nested' => array( -7 => PHP_INT_MIN, '007' => PHP_INT_MAX, 'empty' => array(), 'float' => -0.0, 'scientific' => 1.25e-100 ) );
		$raw = serialize( $value );
		$this->assertSame( $raw, serialize( ( new LegacyOptionDecoder() )->decode( $raw ) ) );
	}

	/** @dataProvider refused_payloads */
	public function test_unsupported_or_malformed_state_is_refused_before_native_decode( $raw ): void {
		$this->expectException( \RuntimeException::class );
		( new LegacyOptionDecoder() )->decode( $raw );
	}

	public static function refused_payloads(): array {
		return array_map( function ( $raw ) { return array( $raw ); }, array(
			'', 'N;', 'b:0;', 'a:0:{}trailing', 'a:0:{', 'a:1:{}', 'a:0:{i:0;N;}',
			'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;C:8:"stdClass":0:{}}',
			'a:1:{i:0;R:1;}', 'a:1:{i:0;r:1;}', 'a:1:{i:0;E:3:"X:Y";}',
			'a:1:{b:1;N;}', 'a:1:{s:1:"0";N;}', 'a:2:{i:0;N;i:0;b:0;}',
			'a:1:{i:00;N;}', 'a:1:{i:+1;N;}', 'a:1:{i:0;b:2;}',
			'a:1:{i:0;s:5:"short"}', 'a:1:{i:0;s:99999999999999999999:"x";}',
			'a:99999999999999999999:{}', 'a:01:{i:0;N;}',
			'a:1:{i:0;i:99999999999999999999;}', 'a:1:{i:0;d:INF;}',
			'a:1:{i:0;d:NAN;}', 'a:1:{i:0;d:1E+9999;}',
			'a:1:{i:0;s:1:"x";O:8:"stdClass":0:{}}',
		) );
	}

	public function test_all_object_hooks_and_autoload_remain_uninvoked(): void {
		DecoderHookProbe::$woke = false;
		$autoloaded = array();
		$loader = function ( $name ) use ( &$autoloaded ) { $autoloaded[] = $name; };
		spl_autoload_register( $loader );
		try {
			foreach ( array( serialize( array( new DecoderHookProbe() ) ), 'a:1:{i:0;O:18:"DecoderAbsentClass":0:{}}' ) as $raw ) {
				try { ( new LegacyOptionDecoder() )->decode( $raw ); $this->fail( 'Object accepted.' ); }
				catch ( \RuntimeException $error ) { $this->assertFalse( DecoderHookProbe::$woke ); }
			}
		} finally { spl_autoload_unregister( $loader ); }
		$this->assertSame( array(), $autoloaded );
	}

	public function test_exact_structural_limits_and_reuse_after_refusal(): void {
		$raw = serialize( array( 'key' => array( 'value' => 'x' ) ) );
		$decoder = new LegacyOptionDecoder( strlen( $raw ), 2, 5 );
		$this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) );
		foreach ( array( new LegacyOptionDecoder( strlen( $raw ) - 1, 2, 5 ), new LegacyOptionDecoder( strlen( $raw ), 1, 5 ), new LegacyOptionDecoder( strlen( $raw ), 2, 4 ) ) as $limited ) {
			try { $limited->decode( $raw ); $this->fail( 'Budget overflow accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		try { $decoder->decode( 'a:1:{i:0;O:8:"stdClass":0:{}}' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) ); }
	}

	public function test_top_level_key_limit_is_checked_before_hydration(): void {
		$this->assertCount( 100, ( new LegacyOptionDecoder() )->decode( serialize( array_fill( 0, 100, null ) ) ) );
		$this->expectException( \RuntimeException::class );
		( new LegacyOptionDecoder() )->decode( serialize( array_fill( 0, 101, null ) ) );
	}

	public function test_deep_and_high_node_payloads_refuse(): void {
		foreach ( array( str_repeat( 'a:1:{i:0;', 66 ) . 'N;' . str_repeat( '}', 66 ), 'a:30000:{}' ) as $raw ) {
			try { ( new LegacyOptionDecoder() )->decode( $raw ); $this->fail( 'Unbounded structure accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}

	public function test_reverse_encoding_admits_exact_size_before_native_serialization(): void {
		$value = array( 'binary' => "\0\xff", 'nested' => array( 9 => PHP_INT_MIN, '09' => -0.0, 'float' => 1.25e-100 ) );
		$raw = serialize( $value ); $sizes = array();
		$decoder = new LegacyOptionDecoder( strlen( $raw ) );
		$this->assertSame( $raw, $decoder->encode( $value, function ( $bytes ) use ( &$sizes ) { $sizes[] = $bytes; } ) );
		$this->assertSame( array( strlen( $raw ) ), $sizes );
		$this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) );
	}

	public function test_reverse_encoding_checks_bytes_nodes_depth_and_heads_before_admission(): void {
		$value = array( 'key' => array( 'value' => 'x' ) ); $raw = serialize( $value );
		$called = false;
		foreach ( array( array( new LegacyOptionDecoder( strlen( $raw ) - 1 ), $value ),
			array( new LegacyOptionDecoder( 1000, 1 ), $value ), array( new LegacyOptionDecoder( 1000, 2, 4 ), $value ),
			array( new LegacyOptionDecoder(), array_fill( 0, 101, null ) ), array( new LegacyOptionDecoder(), array( new DecoderHookProbe() ) ) ) as $case ) {
			try { $case[0]->encode( $case[1], function () use ( &$called ) { $called = true; } ); $this->fail( 'Unbounded reverse serialization accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertFalse( $called ); }
		}
	}

	public function test_reverse_encoding_propagates_admission_refusal_and_reuses_safely(): void {
		$decoder = new LegacyOptionDecoder();
		try { $decoder->encode( array( 'x' ), function () { throw new \RuntimeException( 'Memory admission refused.' ); } ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'Memory admission refused.', $error->getMessage() ); }
		$this->assertSame( serialize( array( 'x' ) ), $decoder->encode( array( 'x' ), function () {} ) );
	}

	public function test_reverse_encoding_matches_native_bytes_at_structural_and_integer_boundaries(): void {
		$decoder = new LegacyOptionDecoder( PHP_INT_MAX );
		foreach ( array( array(), array_fill( 0, 100, null ), array( array_fill( 0, 14998, false ) ),
			array( PHP_INT_MIN => PHP_INT_MAX, '009' => "\0\xff\\\"", 'empty' => array(), 'float' => -0.0, 'tiny' => 1.25e-100 ) ) as $value ) {
			$raw = serialize( $value );
			$this->assertSame( $raw, $decoder->encode( $value, function ( $bytes ) use ( $raw ) { $this->assertSame( strlen( $raw ), $bytes ); } ) );
		}
	}

	public function test_reverse_encoding_refuses_aliases_cycles_and_nonfinite_scalars(): void {
		$item = array( 'key' => 'value' ); $aliases = array( &$item, &$item );
		$cycle = array(); $cycle['cycle'] =& $cycle;
		foreach ( array( $aliases, $cycle, array( INF ), array( NAN ) ) as $value ) {
			try { ( new LegacyOptionDecoder() )->encode( $value, function () {} ); $this->fail( 'Unsupported graph accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}
}

class DecoderHookProbe {
	public static $woke = false;
	public function __wakeup() { self::$woke = true; }
}
