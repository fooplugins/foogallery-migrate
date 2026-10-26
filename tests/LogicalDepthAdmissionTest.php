<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** The same logical domain at direct, split-payload and child-row seams. */
class LogicalDepthAdmissionTest extends TestCase {
	private function nested( $depth ) {
		$value = true;
		while ( $depth-- > 0 ) { $value = array( $value ); }
		return $value;
	}

	private function budget( $depth ) {
		$limits = StorageScaleAdmissionTest::policy(); $limits['max_depth'] = $depth;
		return StorageScaleAdmissionTest::admission( $limits );
	}

	private function refused( $callback ) {
		try { $callback(); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); return; }
		$this->fail( 'Accepted outside the admitted logical depth.' );
	}

	public static function depths(): array { return array( array( 1 ), array( 2 ), array( 4 ), array( 64 ) ); }

	/** @dataProvider depths */
	public function test_direct_state_encode_decode_digest_exact_and_plus_one( $depth ): void {
		$codec = new LegacyStateCodec( 1048576, 64, 10000, $this->budget( $depth ) );
		$legacy = new LegacyStateCodec();
		$exact = $this->nested( $depth ); $over = $this->nested( $depth + 1 );
		$this->assertSame( $exact, $codec->decode( $codec->encode( $exact ) ) );
		$this->assertSame( $legacy->encode( $exact ), $codec->encode( $exact ) );
		$this->assertSame( $legacy->snapshot_digest( $exact ), $codec->snapshot_digest( $exact ) );
		$this->refused( function () use ( $codec, $over ) { $codec->encode( $over ); } );
		// Wrap the exact tag once to construct a valid +1 JSON fixture even at depth64.
		$envelope = json_decode( $legacy->encode( $exact ), true );
		$envelope['value'] = array( 'array', array( array( array( 'integer', '0' ), $envelope['value'] ) ) );
		$json = json_encode( $envelope );
		$this->refused( function () use ( $codec, $json ) { $codec->decode( $json ); } );
		$this->refused( function () use ( $codec, $over ) { $codec->snapshot_digest( $over ); } );
	}

	public static function records(): array {
		$cases = array();
		foreach ( array( false, true ) as $injected ) {
			foreach ( array( 1, 2, 4, 62 ) as $depth ) {
				$nested = true;
				for ( $i = 1; $i < $depth; ++$i ) { $nested = array( $nested ); }
				foreach ( array( 'unknown', 'error' ) as $field ) {
					$cases[] = array( $depth, $injected, array( $field => $nested ), array( $field => array( $nested ) ) );
				}
			}
			// Child rows add TWO logical edges (children map and child value),
			// not one. Empty arrays still occupy their own logical depth.
			$cases[] = array( 2, $injected, array( 'children' => array( 'k' => array() ) ),
				array( 'children' => array( 'k' => array( 'v' => true ) ) ) );
			$cases[] = array( 4, $injected, array( 'children' => array( 'k' => array( 'v' => array( true ) ) ) ),
				array( 'children' => array( 'k' => array( 'v' => array( array( true ) ) ) ) ) );
			$cases[] = array( 4, $injected, array( 'children' => array( 'k' => array( 'children' => array( 'g' => array() ) ) ) ),
				array( 'children' => array( 'k' => array( 'children' => array( 'g' => array( 'v' => true ) ) ) ) ) );
		}
		return $cases;
	}

	private function rows( $codec, $value ) {
		$rows = array();
		$codec->flatten_tree( 'galleries', 'g', $value, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		return $rows;
	}

	/** @dataProvider records */
	public function test_record_exact_and_plus_one_before_emit_and_on_readback( $depth, $injected, $exact, $over ): void {
		$budget = $this->budget( $depth );
		$state = $injected ? new LegacyStateCodec( 1048576, 64, 10000, $budget ) : null;
		$codec = new LegacyRecordCodec( $state, 24576, 16777216, $budget );
		$legacy = new LegacyRecordCodec();
		$rows = $this->rows( $codec, $exact );
		$this->assertSame( $exact, $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) );
		// Internal split + JSON tagging must not consume logical depth or change bytes.
		$this->assertSame( $this->rows( $legacy, $exact ), $rows );
		$emitted = 0;
		$this->refused( function () use ( $codec, $over, &$emitted ) {
			$codec->flatten_tree( 'galleries', 'g', $over, 0, function () use ( &$emitted ) { ++$emitted; } );
		} );
		$this->assertSame( 0, $emitted );
		// Valid legacy rows, including valid payload/logical hashes, outside this domain.
		$outside = $this->rows( $legacy, $over );
		$this->refused( function () use ( $codec, $outside ) {
			$codec->rebuild_tree( 'galleries', $outside[0]['node_hash'], $outside );
		} );
	}

	public static function constructors(): array { return array( array( false ), array( true ) ); }

	/** @dataProvider constructors */
	public function test_logical_depth64_has_explicit_split_json_allowance_not_a_cap_raise( $injected ): void {
		$budget = $this->budget( 64 );
		$state = $injected ? new LegacyStateCodec( 1048576, 64, 10000, $budget ) : null;
		$codec = new LegacyRecordCodec( $state, 24576, 16777216, $budget );
		$exact = array( 'unknown' => $this->nested( 63 ) );
		$rows = $this->rows( $codec, $exact );
		$this->assertSame( $exact, $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) );
		$this->refused( function () use ( $codec ) { $this->rows( $codec, array( 'unknown' => $this->nested( 64 ) ) ); } );
		// Unchanged no-scale payload budget still counts the split container.
		$this->refused( function () use ( $exact ) { $this->rows( new LegacyRecordCodec(), $exact ); } );
		// Increase the single true leaf's logical depth, retaining valid JSON,
		// payload hash and length. Readback must hit depth, not a stale digest.
		$envelope = json_decode( $rows[0]['payload'], true );
		$envelope = $this->deepen_boolean_tag( $envelope );
		$rows[0]['payload'] = json_encode( $envelope );
		$rows[0]['payload_hash'] = hash( 'sha256', $rows[0]['payload'] );
		$rows[0]['payload_bytes'] = strlen( $rows[0]['payload'] ) + strlen( $rows[0]['mutable_payload'] );
		try { $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'depth', $error->getMessage() ); return; }
		$this->fail( 'Depth65 split JSON accepted.' );
	}

	private function deepen_boolean_tag( $value ) {
		if ( array( 'boolean', true ) === $value ) {
			return array( 'array', array( array( array( 'integer', '0' ), $value ) ) );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) { $value[$key] = $this->deepen_boolean_tag( $item ); }
		}
		return $value;
	}

	/** @dataProvider constructors */
	public function test_empty_array_and_scalar_record_envelope_boundaries( $injected ): void {
		$budget = $this->budget( 1 );
		$state = $injected ? new LegacyStateCodec( 1048576, 64, 10000, $budget ) : null;
		$codec = new LegacyRecordCodec( $state, 24576, 16777216, $budget );
		foreach ( array( true, null, array(), array( 'children' => array() ), array( 'unknown' => array() ) ) as $value ) {
			$rows = $this->rows( $codec, $value );
			$this->assertSame( $value, $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) );
		}
		$over = array( 'children' => array( 'k' => array() ) );
		$this->refused( function () use ( $codec, $over ) { $this->rows( $codec, $over ); } );
		$outside = $this->rows( new LegacyRecordCodec(), $over );
		$this->refused( function () use ( $codec, $outside ) { $codec->rebuild_tree( 'galleries', $outside[0]['node_hash'], $outside ); } );
	}

	public function test_no_scale_and_narrower_explicit_state_limit_are_preserved(): void {
		$legacy = new LegacyStateCodec(); $value = $this->nested( 3 );
		$this->assertSame( $value, $legacy->decode( $legacy->encode( $value ) ) );
		$codec = new LegacyStateCodec( 1048576, 1, 10000, $this->budget( 2 ) );
		$this->refused( function () use ( $codec ) { $codec->encode( $this->nested( 2 ) ); } );
	}
}
