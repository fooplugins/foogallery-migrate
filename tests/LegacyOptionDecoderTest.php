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
}

class DecoderHookProbe {
	public static $woke = false;
	public function __wakeup() { self::$woke = true; }
}
