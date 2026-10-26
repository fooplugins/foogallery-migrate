<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\Plugins\Envira;
use FooPlugins\FooGalleryMigrate\Plugins\Modula;
use FooPlugins\FooGalleryMigrate\Plugins\Robo;
use PHPUnit\Framework\TestCase;

/** Exact SQL/output characterization, independent of the source discovery loops. */
class GallerySourceSqlTest extends TestCase {
	/** @dataProvider query_cases */
	public function test_gallery_statement_and_returned_rows_are_unchanged( $class, $method, $type, $prefix, $rows ): void {
		$had_db = array_key_exists( 'wpdb', $GLOBALS );
		$previous_db = $had_db ? $GLOBALS['wpdb'] : null;
		$db = new GallerySourceSqlWpdb( $prefix, $rows );
		$GLOBALS['wpdb'] = $db;
		try {
			$query = new \ReflectionMethod( $class, $method );
			$query->setAccessible( true );
			$this->assertSame( $rows, $query->invoke( new $class() ) );
			// The exact statement emitted before the placeholder cleanup: no
			// changed columns, filtering, ordering, casing, spacing or row limit.
			$this->assertSame( array( "select * from {$prefix}posts WHERE post_type = '{$type}' AND post_status = 'publish'" ), $db->queries );
			$this->assertSame( array( array(
				"select * from {$prefix}posts WHERE post_type = %s AND post_status = %s",
				array( $type, 'publish' ),
			) ), $db->preparations, 'Both values must pass through prepare exactly once.' );
		} finally {
			if ( $had_db ) { $GLOBALS['wpdb'] = $previous_db; }
			else { unset( $GLOBALS['wpdb'] ); }
		}
	}

	public static function query_cases(): array {
		$cases = array();
		foreach ( array(
			array( Envira::class, 'get_nextgen_galleries', 'envira' ),
			array( Modula::class, 'get_modula_galleries', 'modula-gallery' ),
			array( Robo::class, 'get_robo_galleries', 'robo_gallery_table' ),
		) as $plugin ) {
			foreach ( array( 'wp_', 'wp_7_', 'Custom42_' ) as $prefix ) {
				foreach ( array(
					array(),
					array( (object) array( 'ID' => '11', 'post_title' => "Snow 雪 & O'Brien", 'post_type' => $plugin[2], 'post_status' => 'publish' ) ),
					array( (object) array( 'ID' => '91', 'post_title' => 'First' ), (object) array( 'ID' => '7', 'post_title' => 'Second' ) ),
				) as $count => $rows ) {
					$cases[$plugin[2] . '-' . $prefix . '-' . $count] = array_merge( $plugin, array( $prefix, $rows ) );
				}
			}
		}
		return $cases;
	}
}

/** SQL capture only, not a SQL engine or native WordPress escaping test. */
class GallerySourceSqlWpdb {
	public $prefix;
	public $posts;
	public $queries = array();
	public $preparations = array();
	private $rows;
	public function __construct( $prefix, $rows ) {
		$this->prefix = $prefix; $this->posts = $prefix . 'posts'; $this->rows = $rows;
	}
	public function prepare( $template, ...$args ) {
		$this->preparations[] = array( $template, $args );
		foreach ( $args as $arg ) {
			$template = preg_replace( '/%s/', "'" . str_replace( "'", "''", $arg ) . "'", $template, 1 );
		}
		return $template;
	}
	public function get_results( $sql ) { $this->queries[] = $sql; return $this->rows; }
}
