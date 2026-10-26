<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use PHPUnit\Framework\TestCase;

class LegacyStorageSchemaTest extends TestCase {
	public function test_schema_is_inert_network_shared_and_matches_the_approved_model(): void {
		$db = new LegacySchemaTestWpdb();
		$db->prefix = 'wp_7_';
		$schema = new LegacyStorageSchema( $db );
		$definitions = $schema->definitions();
		$this->assertSame( array( 'wp_foogallery_migrate_state', 'wp_foogallery_migrate_records' ), array_keys( $definitions ) );
		$this->assertSame( array(), $db->reads );
		$this->assertCount( 2, $schema->statements() );
		foreach ( $schema->statements() as $sql ) {
			$this->assertStringContainsString( 'ENGINE=InnoDB', $sql );
			$this->assertStringContainsString( 'blog_id bigint unsigned NOT NULL', $sql );
			$this->assertStringContainsString( 'binary(32)', $sql );
		}
		$db->definitions = $definitions;
		$this->assertTrue( $schema->verify() );
	}

	/** @dataProvider malformedSchema */
	public function test_health_verifies_real_types_engine_and_exact_index_order( $fault ): void {
		$db = new LegacySchemaTestWpdb();
		$schema = new LegacyStorageSchema( $db );
		$db->definitions = $schema->definitions();
		$db->fault = $fault;
		$this->expectException( \RuntimeException::class );
		$schema->verify();
	}

	public static function malformedSchema(): array {
		return array( array( 'engine' ), array( 'type' ), array( 'nullable' ), array( 'index-order' ), array( 'index-unique' ), array( 'missing-column' ), array( 'prefix-index' ), array( 'query' ) );
	}

	public function test_untrusted_or_missing_base_prefix_is_refused(): void {
		$db = new LegacySchemaTestWpdb();
		$db->base_prefix = 'wp_`';
		$this->expectException( \RuntimeException::class );
		( new LegacyStorageSchema( $db ) )->statements();
	}
}

class LegacySchemaTestWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $reads = array();
	public $definitions = array();
	public $fault = '';
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, $value ) { return str_replace( '%s', "'" . $value . "'", $sql ); }
	public function get_results( $sql, $format ) {
		$this->reads[] = $sql;
		if ( 'query' === $this->fault ) { $this->last_error = 'fixture query failure'; return null; }
		foreach ( $this->definitions as $table => $definition ) {
			if ( false === strpos( stripslashes( $sql ), $table ) ) { continue; }
			if ( 0 === strpos( $sql, 'SHOW TABLE STATUS' ) ) { return array( array( 'Name' => $table, 'Engine' => 'engine' === $this->fault ? 'MyISAM' : 'InnoDB' ) ); }
			if ( 0 === strpos( $sql, 'SHOW COLUMNS' ) ) {
				$rows = array();
				foreach ( $definition['columns'] as $field => $spec ) {
					if ( 'missing-column' === $this->fault && 'blog_id' === $field ) { continue; }
					$rows[] = array( 'Field' => $field, 'Type' => 'type' === $this->fault && 'blog_id' === $field ? 'int unsigned' : $spec[0],
						'Null' => 'nullable' === $this->fault && 'blog_id' === $field ? 'YES' : ( $spec[1] ? 'YES' : 'NO' ),
						'Extra' => 'id' === $field ? 'auto_increment' : '' );
				}
				return $rows;
			}
			$rows = array();
			foreach ( $definition['indexes'] as $name => $index ) {
				$columns = 'index-order' === $this->fault ? array_reverse( $index[1] ) : $index[1];
				foreach ( $columns as $seq => $column ) {
					$rows[] = array( 'Key_name' => $name, 'Seq_in_index' => $seq + 1, 'Column_name' => $column,
						'Non_unique' => 'index-unique' === $this->fault ? 1 : ( $index[0] ? 0 : 1 ),
						'Sub_part' => 'prefix-index' === $this->fault ? 4 : null, 'Index_type' => 'BTREE' );
				}
			}
			return $rows;
		}
		return array();
	}
}
