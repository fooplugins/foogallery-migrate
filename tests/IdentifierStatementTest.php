<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\Plugins\WpPhotoAlbumPlus;
use PHPUnit\Framework\TestCase;

class IdentifierStatementTest extends TestCase {
	public function test_all_sixteen_production_templates_preserve_the_previous_sql(): void {
		$expected = array();
		foreach ( require __DIR__ . '/fixtures/identifier-statements.php' as $case ) {
			$expected[$case[0]][] = str_replace( array( '`{{table}}`', '{{table}}' ), '%i', $case[1] );
		}
		foreach ( $expected as $file => $templates ) {
			$actual = array();
			foreach ( token_get_all( file_get_contents( FOOGM_DIR . '/includes/' . $file ) ) as $token ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] || false === strpos( $token[1], '%i' ) ) { continue; }
				// Only literal strings from the owned production source, not input.
				$actual[] = eval( 'return ' . $token[1] . ';' );
			}
			$this->assertSame( $templates, $actual, $file . ': no changed projections, predicates, ordering, limits or locks' );
		}
	}

	/** @dataProvider statement_cases */
	public function test_statement_bytes_are_identical_except_new_wppa_identifier_quotes( $case, $prefix ): void {
		$table = $prefix . $case[2];
		$old = str_replace( '{{table}}', $table, $case[1] );
		$new = str_replace( array( '`{{table}}`', '{{table}}' ), '%i', $case[1] );
		$args = array_merge( array( $table ), $case[3] );
		$new = IdentifierSqlFixture::bind( $new, $args );
		$this->assertSame( $case[3], $args, 'Identifier cannot shift the existing value arguments.' );
		if ( false === strpos( $case[1], '`{{table}}`' ) ) { $new = str_replace( '`' . $table . '`', $table, $new ); }
		$this->assertSame( $old, $new );
	}

	public static function statement_cases(): array {
		$cases = array();
		foreach ( require __DIR__ . '/fixtures/identifier-statements.php' as $index => $case ) {
			foreach ( array( 'wp_', 'wp_7_', 'Custom42_' ) as $prefix ) { $cases[$index . '-' . $prefix] = array( $case, $prefix ); }
		}
		return $cases;
	}

	public function test_wppa_still_rejects_unsafe_identifiers(): void {
		$method = new \ReflectionMethod( WpPhotoAlbumPlus::class, 'is_safe_table_name' ); $method->setAccessible( true );
		foreach ( array( 'wp_wppa_albums', 'Custom42_wppa_photos' ) as $table ) { $this->assertTrue( $method->invoke( new WpPhotoAlbumPlus(), $table ) ); }
		foreach ( array( '', 'wp_`albums', 'wp_albums; DROP TABLE posts', 'db.albums', 'wp albums' ) as $table ) {
			$this->assertFalse( $method->invoke( new WpPhotoAlbumPlus(), $table ) );
		}
	}
}
