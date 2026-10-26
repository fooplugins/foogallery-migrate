<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Tokenizing large sources must not retain allocator pages in memory witnesses.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DiagnosticMessageContractTest extends TestCase {
	/** @dataProvider helpers */
	public function test_private_diagnostic_calls_cannot_include_payload_values( $file, $labels ): void {
		$tokens = token_get_all( file_get_contents( FOOGM_DIR . '/includes/' . $file ) ); $calls = 0;
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'fail' !== $token[1] ) { continue; }
			$previous = $i - 1; while ( $previous >= 0 && is_array( $tokens[$previous] ) && T_WHITESPACE === $tokens[$previous][0] ) { --$previous; }
			if ( ! is_array( $tokens[$previous] ) || ! in_array( $tokens[$previous][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) { continue; }
			$start = $i + 1; while ( is_array( $tokens[$start] ) && T_WHITESPACE === $tokens[$start][0] ) { ++$start; }
			$this->assertSame( '(', $tokens[$start] ); $argument = array(); $depth = 1;
			for ( $at = $start + 1; $at < count( $tokens ); ++$at ) {
				$t = $tokens[$at]; if ( '(' === $t ) { ++$depth; } if ( ')' === $t && --$depth === 0 ) { break; }
				if ( ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { $argument[] = $t; }
			}
			++$calls;
			$this->assertTrue( isset( $argument[0] ) && is_array( $argument[0] ) && T_CONSTANT_ENCAPSED_STRING === $argument[0][0], $file . ':' . $token[2] );
			if ( 1 === count( $argument ) ) { continue; }
			$this->assertCount( 3, $argument, $file . ':' . $token[2] . ': review any new dynamic diagnostic' );
			$this->assertSame( '.', $argument[1] ); $this->assertSame( T_VARIABLE, $argument[2][0] );
			$this->assertContains( $argument[2][1], $labels, 'Only independently reviewed schema/projection/fixed-loop labels may be appended.' );
		}
		$this->assertGreaterThan( 0, $calls );
	}
	public static function helpers(): array {
		return array(
			array( 'class-table-snapshot.php', array( '$name' ) ),
			array( 'class-legacy-storage-schema.php', array( '$name' ) ),
			array( 'class-legacy-table-repository.php', array() ),
			array( 'class-legacy-record-codec.php', array( '$field' ) ),
			array( 'class-snapshot-read-profile.php', array( '$k', '$seam' ) ),
		);
	}
}
