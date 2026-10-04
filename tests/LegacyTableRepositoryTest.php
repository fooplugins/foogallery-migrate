<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use PHPUnit\Framework\TestCase;

class LegacyTableRepositoryTest extends TestCase {
	public function test_construction_is_inert(): void {
		$db = new RepositoryTestConnection();
		new LegacyTableRepository( $db, 7 );
		$this->assertSame( array(), $db->queries );
	}

	/** @dataProvider invalidChanges */
	public function test_patch_preflight_refuses_before_sql( $changes ): void {
		$db = new RepositoryTestConnection();
		try { ( new LegacyTableRepository( $db, 7 ) )->patch_many( $changes ); $this->fail( 'Expected refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
	}

	public static function invalidChanges(): array {
		$change = array( 'key' => 'history', 'expected_revision' => 1, 'patches' => array( array( 'key' => 'a', 'value' => false ) ) );
		$cases = array( array(), array( $change, $change ) );
		foreach ( array( 'key' => true, 'expected_revision' => 0, 'patches' => array() ) as $field => $value ) {
			$bad = $change; $bad[$field] = $value; $cases[] = array( $bad );
		}
		foreach ( array( array( 'key' => true, 'value' => false ), array( 'key' => 'a', 'value' => new \stdClass() ),
			array( 'key' => 'a', 'delete' => false ), array( 'key' => 'a', 'value' => false, 'delete' => true ),
			array( 'key' => 'a' ) ) as $patch ) {
			$bad = $change; $bad['patches'] = array( $patch ); $cases[] = array( $bad );
		}
		$bad = $change; $bad['patches'][] = $bad['patches'][0]; $cases[] = array( $bad );
		return array_map( function ( $case ) { return array( $case ); }, $cases );
	}

	public function test_aggregate_affected_budget_precedes_sql(): void {
		$db = new RepositoryTestConnection();
		$repo = new LegacyTableRepository( $db, 7, 1, 10000 );
		try {
			$repo->patch_many( array( array( 'key' => 'history', 'expected_revision' => 1, 'patches' => array(
				array( 'key' => 'a', 'value' => false ), array( 'key' => 'b', 'value' => false ) ) ) ) );
			$this->fail();
		} catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
	}

	public function test_invalid_page_and_typed_root_keys_precede_sql(): void {
		$db = new RepositoryTestConnection(); $repo = new LegacyTableRepository( $db, 7 );
		foreach ( array( function () use ( $repo ) { $repo->page( 'history', 101 ); },
			function () use ( $repo ) { $repo->root( 'history', true ); } ) as $call ) {
			try { $call(); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
	}

	public function test_invalid_multi_root_requests_precede_sql(): void {
		$db = new RepositoryTestConnection(); $repo = new LegacyTableRepository( $db, 7 );
		foreach ( array( array( 'a', 'a' ), array( true ), array( '9' ), array_fill( 0, 101, 'a' ), 'not-an-array' ) as $keys ) {
			try { $repo->roots( 'history', $keys ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
		$this->assertSame( array(), $repo->roots( 'history', array() ) );
		$this->assertSame( array(), $db->queries );
	}
}

class RepositoryTestConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $queries = array();
	public function __construct() {}
	public function get_results( $sql, $format ) { $this->queries[] = $sql; throw new \RuntimeException( 'Unexpected SQL in preflight.' ); }
}
