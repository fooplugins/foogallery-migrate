<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;

class LegacyJsonPreflightTest extends TestCase {
	public function test_valid_domain_including_maximum_logical_depth_is_preserved(): void {
		foreach ( array( null, true, false, PHP_INT_MAX, PHP_INT_MIN, 0.1, -0.0, "\xff\x00\"\\[]{}", array(), array( '009' => array( 7 => true ) ) ) as $value ) {
			$codec = new LegacyStateCodec(); $json = $codec->encode( $value );
			$stats = $codec->inspect_json( $json );
			$this->assertSame( strlen( $json ), $stats['bytes_scanned'] );
			$this->assertLessThanOrEqual( 40004, $stats['tokens'] );
			$this->assertSame( $value, $codec->decode( $json ) );
		}
		$value = array();
		for ( $depth = 0; $depth < 64; ++$depth ) { $value = array( $value ); }
		$codec = new LegacyStateCodec(); $json = $codec->encode( $value );
		$this->assertSame( 195, $codec->inspect_json( $json )['max_depth'] );
		$this->assertSame( $value, $codec->decode( $json ) );
	}

	public function test_duplicate_keys_count_all_native_allocation_events_not_final_graph(): void {
		$codec = new LegacyStateCodec( 1000, 64, 1 );
		$this->assertSame( 7, $codec->inspect_json( '{"version":1,"value":["boolean",true]}' )['tokens'] );
		$this->expectException( \RuntimeException::class );
		$codec->inspect_json( '{"version":1,"version":1,"value":["boolean",true]}' );
	}

	public function test_strings_escapes_whitespace_and_existing_noncanonical_json_are_preserved(): void {
		$codec = new LegacyStateCodec( 1000, 64, 1 );
		$json = " \n{\"version\" : 1, \"value\" : [\"str\\u0069ng\", \"YWJj\\/w==\"] }\t";
		$this->assertSame( "abc\xff", $codec->decode( $json ) );
		$this->assertSame( 7, $codec->inspect_json( $json )['tokens'] );
		$json = json_encode( array( 'version' => 1, 'value' => array( 'string', "\"\\[]{}" ) ) );
		$this->assertSame( 7, $codec->inspect_json( $json )['tokens'] );
	}

	/** @dataProvider rejectedPrefixes */
	public function test_pre_rejection_work_is_bounded_without_native_hydration( $json ): void {
		$this->expectException( \RuntimeException::class );
		( new LegacyStateCodec( 2000000, 2, 1 ) )->inspect_json( $json );
	}

	public static function rejectedPrefixes(): array {
		return array(
			array( '[' . str_repeat( 'true,', 9 ) ),
			array( str_repeat( '[', 10 ) ),
			array( '{"value":"unterminated' ),
			array( '{"version":' . str_repeat( '9', 1000000 ) . '}' ),
			array( '{"version":1e999999}' ),
			array( ']' ),
			array( '{"version":NaN}' ),
			array( '{"version":null}' )
		);
	}

	public function test_large_node_budget_does_not_overflow_the_derived_token_limit(): void {
		$codec = new LegacyStateCodec( PHP_INT_MAX, 64, PHP_INT_MAX );
		$this->assertSame( 6, $codec->inspect_json( '{"version":1,"value":["null"]}' )['tokens'] );
		$this->assertNull( $codec->decode( '{"version":1,"value":["null"]}' ) );
	}

	public function test_depth_refusal_is_independent_of_the_token_budget(): void {
		try {
			( new LegacyStateCodec( 2000000, 2, 100 ) )->inspect_json( str_repeat( '[', 1000000 ) );
			$this->fail( 'Deep prefix accepted.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'native JSON depth budget', $error->getMessage() );
		}
	}
}
