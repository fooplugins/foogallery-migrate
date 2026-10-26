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
		$cases[] = array( array( 'key' => 'galleries-state', 'expected_revision' => null, 'value' => array() ) );
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
			function () use ( $repo ) { $repo->root( 'history', true ); },
			function () use ( $repo ) { $repo->collection_view_page( 'galleries', -1, 1 ); },
			function () use ( $repo ) { $repo->collection_view_page( 'albums', 0, 101 ); },
			function () use ( $repo ) { $repo->collection_view_page( 'albums', 0, 0 ); } ) as $call ) {
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
	public function test_invalid_queue_selection_refuses_before_sql(): void {
		$db = new RepositoryTestConnection(); $repo = new LegacyTableRepository( $db, 7, 100 );
		foreach ( array( 'not-an-array', array_fill( 0, 101, array( 'title' => 'x' ) ) ) as $selection ) {
			try { $repo->queue_collection( 'galleries', $selection, function () {}, function () {} ); $this->fail( 'Expected selection refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
	}
	public function test_queue_validation_retains_only_preexisting_null_status_without_writes(): void {
		$db = new RepositoryTestConnection(); $repo = new LegacyTableRepository( $db, 7 );
		$validate = new \ReflectionMethod( $repo, 'validate_queue_transition' ); $validate->setAccessible( true );
		$matched_migrated = array( 'migration_status' => null, 'part_of_migration' => true, 'migrated' => true, 'opaque' => 'keep' );
		$this->assertNull( $validate->invoke( $repo, $matched_migrated,
			array( 'value' => $matched_migrated, 'selected' => true, 'completed' => true, 'error' => false ) ) );
		$unmatched = array( 'migration_status' => null, 'part_of_migration' => true, 'migrated' => false, 'opaque' => 'keep' );
		$after = $unmatched; $after['part_of_migration'] = false;
		$this->assertNull( $validate->invoke( $repo, $unmatched,
			array( 'value' => $after, 'selected' => false, 'completed' => false, 'error' => false ) ) );
		$changed = $after; $changed['migration_status'] = null;
		try { $validate->invoke( $repo, array( 'migration_status' => 'not_started', 'part_of_migration' => true, 'migrated' => false, 'opaque' => 'keep' ),
			array( 'value' => $changed, 'selected' => false, 'completed' => false, 'error' => false ) ); $this->fail( 'New null status must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
	}
}

class RepositoryTestConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $queries = array();
	public function __construct() {}
	public function get_results( $sql, $format ) { $this->queries[] = $sql; throw new \RuntimeException( 'Unexpected SQL in preflight.' ); }
}
