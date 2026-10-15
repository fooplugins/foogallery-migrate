<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\LegacyOptionStructuralScan;
use PHPUnit\Framework\TestCase;

class LegacyOptionStructuralScanTest extends TestCase {
	public function test_twenty_thousand_flat_scalars_continue_without_raising_decode_cap(): void {
		$raw = serialize( array( 'gallery' => array_fill( 0, 10000, null ), 'album' => array_fill( 0, 10000, false ) ) );
		try { ( new LegacyOptionDecoder() )->decode( $raw ); $this->fail( 'Aggregate decoder cap was bypassed.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'unbounded', $error->getMessage() ); }
		$scanner = new LegacyOptionStructuralScan( $raw );
		$cursor = null; $steps = 0; $bytes = 0; $nodes = 0;
		do {
			$result = $scanner->step( $cursor, 257, 53, 0.05 );
			$this->assertLessThanOrEqual( 257, $result['bytes'] );
			$this->assertLessThanOrEqual( 53, $result['nodes'] );
			$this->assertLessThanOrEqual( 65, $result['stack_depth'] );
			$bytes += $result['bytes']; $nodes += $result['nodes']; ++$steps;
			$cursor = $result['cursor'];
			$this->assertLessThan( 10000, $steps );
		} while ( ! $result['done'] );
		$this->assertGreaterThan( 100, $steps );
		$this->assertSame( strlen( $raw ), $bytes );
		$this->assertSame( 40005, $nodes );
		$this->assertSame( hash( 'sha256', $raw ), $cursor['source_digest'] );
		$this->assertSame( strlen( $raw ), $cursor['source_length'] );
	}

	private function scan_all( $raw, $bytes = 1, $nodes = 1, $depth = 64 ): array {
		$scanner = new LegacyOptionStructuralScan( $raw, 16777216, $depth );
		$cursor = null; $total = 0;
		do {
			$result = $scanner->step( $cursor, $bytes, $nodes, 1 );
			$this->assertLessThanOrEqual( $bytes, $result['bytes'] );
			$this->assertLessThanOrEqual( $nodes, $result['nodes'] );
			$this->assertLessThanOrEqual( $depth + 1, $result['stack_depth'] );
			$cursor = $result['cursor']; $total += $result['bytes'];
		} while ( ! $result['done'] );
		$this->assertSame( strlen( $raw ), $total );
		return $result;
	}

	public function test_single_byte_steps_preserve_native_scalar_tokens_and_binary_strings(): void {
		$raw = serialize( array( PHP_INT_MIN => PHP_INT_MAX, '009' => "\0\xffO:8:\"stdClass\":0:{}R:1;",
			'values' => array( false, true, null, -0.0, 1.25e-100, '', array(), '09', '-0' ) ) );
		$this->assertTrue( $this->scan_all( $raw )['done'] );
		$this->assertSame( $raw, serialize( ( new LegacyOptionDecoder() )->decode( $raw ) ) );
	}

	public function test_long_string_and_key_are_sliced_without_buffering_the_payload(): void {
		$raw = serialize( array( str_repeat( 'k', 10000 ) => str_repeat( 'O:8:"stdClass":0:{}R:1;', 2000 ) ) );
		$scanner = new LegacyOptionStructuralScan( $raw ); $cursor = null;
		do {
			$result = $scanner->step( $cursor, 31, 1, 1 ); $cursor = $result['cursor'];
			$this->assertLessThanOrEqual( 31, $result['bytes'] );
			$this->assertLessThanOrEqual( 128, strlen( $cursor['parser']['token'] ) );
			$this->assertLessThanOrEqual( 22, strlen( $cursor['parser']['key_prefix'] ) );
			$this->assertLessThan( 2048, strlen( serialize( $cursor ) ) );
		} while ( ! $result['done'] );
		$this->assertTrue( $result['done'] );
	}

	/** @dataProvider invalid_tokens */
	public function test_malformed_noncanonical_and_unsupported_tokens_refuse( $raw ): void {
		$this->expectException( \RuntimeException::class );
		$this->scan_all( $raw );
	}

	public static function invalid_tokens(): array {
		$cases = array_map( function ( $raw ) { return array( $raw ); }, array(
			'', 'N;', 'b:0;', 'a:0:{}trailing', 'a:0:{', 'a:1:{}', 'a:0:{i:0;N;}',
			'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;C:8:"stdClass":0:{}}',
			'a:1:{i:0;R:1;}', 'a:1:{i:0;r:1;}', 'a:1:{i:0;E:3:"X:Y";}',
			'a:1:{b:1;N;}', 'a:1:{s:1:"0";N;}', 'a:1:{i:00;N;}', 'a:1:{i:+1;N;}', 'a:1:{i:0;b:2;}',
			'a:1:{i:0;s:5:"short"}', 'a:1:{i:0;s:99999999999999999999:"x";}',
			'a:99999999999999999999:{}', 'a:01:{i:0;N;}', 'a:1:{i:0;i:99999999999999999999;}',
			'a:1:{i:0;d:INF;}', 'a:1:{i:0;d:NAN;}', 'a:1:{i:0;d:1E+9999;}',
			'a:1:{i:0;s:1:"x";O:8:"stdClass":0:{}}'
		) );
		foreach ( array( 'a:1:{i:-0;N;}', 'a:1:{s:01:"x";N;}', 'a:1:{i:0;d:01;}',
			'a:1:{i:0;d:1.0;}', 'a:1:{i:0;d:+1;}', 'a:1:{i:0;d:1e0;}',
			'a:1:{i:0;d:' . str_repeat( '1', 129 ) . ';}',
			'a:1:{i:0;s:0:"x";}', 'a:1:{a:0:{}N;}', 'a:1:{i:0;N;}}',
			'a:1:{i:0;i:1\n;}', 'a:1:{i:0;a:999999999:{}}' ) as $raw ) { $cases[] = array( $raw ); }
		return $cases;
	}

	public function test_every_truncation_refuses_and_depth_is_exact(): void {
		$raw = serialize( array( 'k' => array( 'binary' => "\0\xff", 'value' => 1.25 ) ) );
		for ( $i = 0; $i < strlen( $raw ); ++$i ) {
			try { $this->scan_all( substr( $raw, 0, $i ) ); $this->fail( 'Truncation accepted at ' . $i ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$this->assertTrue( $this->scan_all( serialize( array( array( null ) ) ), 1, 1, 2 )['done'] );
		$this->expectException( \RuntimeException::class );
		$this->scan_all( serialize( array( array( null ) ) ), 1, 1, 1 );
	}

	public function test_default_depth_stack_remains_bounded_and_root_head_limit_is_unchanged(): void {
		$this->assertTrue( $this->scan_all( str_repeat( 'a:1:{i:0;', 64 ) . 'a:0:{}' . str_repeat( '}', 64 ) )['done'] );
		foreach ( array( str_repeat( 'a:1:{i:0;', 65 ) . 'N;' . str_repeat( '}', 65 ), serialize( array_fill( 0, 101, null ) ) ) as $raw ) {
			try { $this->scan_all( $raw ); $this->fail( 'Envelope bypassed.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}

	public function test_cursors_reject_edits_staleness_foreign_sources_and_terminal_reuse(): void {
		$raw = serialize( array( 'key' => 'value' ) );
		$scanner = new LegacyOptionStructuralScan( $raw );
		$first = $scanner->step( null, 1, 1, 1 )['cursor'];
		$second = $scanner->step( $first, 1, 1, 1 )['cursor'];
		$bad = array( null, $first, array(), 'offset:2', (object) $second );
		foreach ( array( 'scan_id', 'sequence', 'source_length', 'max_depth' ) as $field ) {
			$edited = $second; ++$edited[$field]; $bad[] = $edited;
		}
		$edited = $second; $edited['source_digest'] = str_repeat( '0', 64 ); $bad[] = $edited;
		foreach ( array( 'offset', 'nodes', 'phase', 'frames', 'remaining', 'type', 'token', 'key', 'started' ) as $field ) {
			$edited = $second; $edited['parser'][$field] = 'tampered'; $bad[] = $edited;
		}
		foreach ( $bad as $cursor ) {
			try { $scanner->step( $cursor ); $this->fail( 'Invalid resumption accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		foreach ( array( $raw, serialize( array( 'key' => 'other' ) ), substr( $raw, 0, -1 ) ) as $source ) {
			try { ( new LegacyOptionStructuralScan( $source ) )->step( $second ); $this->fail( 'Foreign source/instance accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$cursor = $second;
		do { $result = $scanner->step( $cursor ); $cursor = $result['cursor']; } while ( ! $result['done'] );
		$this->expectException( \RuntimeException::class ); $scanner->step( $cursor );
	}

	public function test_source_is_immutable_and_cursor_cannot_restore_or_serialize_scanner(): void {
		$raw = serialize( array( 'key' => 'value' ) ); $digest = hash( 'sha256', $raw );
		$scanner = new LegacyOptionStructuralScan( $raw ); $raw[0] = 'O';
		$result = $scanner->step( null, 65536, 30000, 1 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $digest, $result['cursor']['source_digest'] );
		$this->expectException( \RuntimeException::class ); serialize( $scanner );
	}

	public function test_deadline_yields_before_work_and_bounds_are_validated(): void {
		$scanner = new LegacyOptionStructuralScan( 'a:0:{}' );
		$result = $scanner->step( null, 65536, 30000, 1.0e-300 );
		$this->assertFalse( $result['done'] ); $this->assertSame( 0, $result['bytes'] ); $this->assertSame( 0, $result['nodes'] );
		foreach ( array( array( 0, 1, 1 ), array( 65537, 1, 1 ), array( 1.0, 1, 1 ), array( 1, 0, 1 ),
			array( 1, 30001, 1 ), array( 1, 1, 0 ), array( 1, 1, INF ), array( 1, 1, NAN ), array( 1, 1, '1' ), array( 1, 1, 1.01 ) ) as $limits ) {
			try { $scanner->step( $result['cursor'], $limits[0], $limits[1], $limits[2] ); $this->fail( 'Invalid step limits accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$this->assertTrue( $scanner->step( $result['cursor'], 6, 1, 1 )['done'] );
	}

	public function test_byte_admission_and_depth_limits_are_checked_before_scanning(): void {
		foreach ( array( array( 'a:0:{}', 5, 64 ), array( 'a:0:{}', 16777217, 64 ), array( 'a:0:{}', 6, 65 ),
			array( 'a:0:{}', 6, 0 ), array( array(), 6, 64 ) ) as $args ) {
			try { new LegacyOptionStructuralScan( $args[0], $args[1], $args[2] ); $this->fail( 'Invalid source admission accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$this->assertTrue( ( new LegacyOptionStructuralScan( 'a:0:{}', 6 ) )->step( null, 6, 1, 1 )['done'] );
	}

	public function test_active_deadline_yields_inside_a_long_string_and_then_resumes(): void {
		$raw = serialize( array( 'payload' => str_repeat( 'x', 120000 ) ) );
		$scanner = new LegacyOptionStructuralScan( $raw );
		$result = $scanner->step( null, 65536, 30000, 0.000001 );
		$this->assertFalse( $result['done'] );
		$this->assertLessThan( 65536, $result['bytes'] );
		$cursor = $result['cursor']; $total = $result['bytes'];
		do {
			$result = $scanner->step( $cursor, 65536, 30000, 0.001 );
			$this->assertLessThan( 65536, $result['bytes'] );
			$cursor = $result['cursor']; $total += $result['bytes'];
		} while ( ! $result['done'] );
		$this->assertSame( strlen( $raw ), $total );
	}

	public function test_object_and_reference_refusals_never_invoke_autoload_or_wakeup(): void {
		StructuralScanHookProbe::$woke = false; $autoloaded = array();
		$loader = function ( $name ) use ( &$autoloaded ) { $autoloaded[] = $name; };
		spl_autoload_register( $loader );
		try {
			foreach ( array( serialize( array( new StructuralScanHookProbe() ) ), 'a:1:{i:0;O:19:"MissingScanHookClass":0:{}}',
				'a:1:{i:0;C:8:"stdClass":0:{}}', 'a:1:{i:0;R:1;}', 'a:1:{i:0;r:1;}' ) as $raw ) {
				try { $this->scan_all( $raw ); $this->fail( 'Unsafe token accepted.' ); }
				catch ( \RuntimeException $error ) { $this->assertFalse( StructuralScanHookProbe::$woke ); }
			}
		} finally { spl_autoload_unregister( $loader ); }
		$this->assertSame( array(), $autoloaded );
	}

	public function test_refused_parse_cannot_be_retried_from_the_pre_error_cursor(): void {
		$scanner = new LegacyOptionStructuralScan( 'a:1:{i:0;O:8:"stdClass":0:{}}' );
		$cursor = $scanner->step( null, 1, 1, 1 )['cursor'];
		for ( $i = 0; $i < 2; ++$i ) {
			try { $scanner->step( $cursor, 65536, 30000, 1 ); $this->fail( 'Refused parser resumed.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}

	public function test_structural_success_is_not_whole_graph_canonicality_or_materialization_admission(): void {
		$raw = 'a:2:{i:0;N;i:0;b:0;}';
		$this->assertTrue( $this->scan_all( $raw )['done'] );
		$this->expectException( \RuntimeException::class ); ( new LegacyOptionDecoder() )->decode( $raw );
	}
}

class StructuralScanHookProbe {
	public static $woke = false;
	public function __wakeup() { self::$woke = true; }
}
