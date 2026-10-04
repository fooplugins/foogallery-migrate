<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/legacy-runtime-contract-fixtures.php';

class LegacyTableRuntimeTest extends TestCase {
	public function test_new_capabilities_are_explicit_and_inert(): void {
		$db = new RepositoryTestConnection();
		$backend = new LegacyTableBackend( $db, 7 );
		foreach ( array( 'identifiers', 'identifier_presence', 'source_identities', 'filtered_page', 'aggregate', 'projection_summary', 'patch_lists_and_scalars', 'export_page' ) as $method ) {
			$this->assertTrue( method_exists( $backend, $method ), $method );
		}
		$this->assertFalse( method_exists( $backend, 'read_all' ) );
		$this->assertSame( array(), $db->queries );
	}

	public function test_indexed_requests_validate_before_sql(): void {
		$db = new RepositoryTestConnection(); $repo = new LegacyTableRepository( $db, 7 );
		foreach ( array(
			function () use ( $repo ) { $repo->source_identities( 'migrated', array( array( 'type' => 'image', 'plugin' => false, 'id' => '09' ) ) ); },
			function () use ( $repo ) { $repo->identifiers( 'migrated', array( '9' ) ); },
			function () use ( $repo ) { $repo->filtered_page( 'migrated', 1, array( 'raw_sql' => '1=1' ) ); },
			function () use ( $repo ) { $repo->aggregate( 'migrated', array( 'is_migrated' => 2 ) ); },
			function () use ( $repo ) { $repo->filtered_page( 'migrated', 101, array() ); },
		) as $call ) {
			try { $call(); $this->fail( 'Expected request refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
		$this->assertSame( array(), $repo->source_identities( 'migrated', array() ) );
	}

	public function test_list_patches_refuse_deletes_sparse_keys_and_objects_before_sql(): void {
		$db = new RepositoryTestConnection(); $repo = new LegacyTableRepository( $db, 7 );
		$fixture = \FooPlugins\FooGalleryMigrate\Tests\Fixtures\legacy_runtime_contract_fixture();
		foreach ( array( array( 'key' => 0, 'delete' => true ), array( 'key' => '09', 'value' => false ),
			array( 'key' => 0, 'value' => $fixture['historical'] ) ) as $patch ) {
			try {
				$repo->patch_lists_and_scalars( array( array( 'key' => 'queue', 'expected_revision' => 1, 'patches' => array( $patch ) ) ) );
				$this->fail( 'Expected unsupported list mutation refusal.' );
			} catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
	}
}
