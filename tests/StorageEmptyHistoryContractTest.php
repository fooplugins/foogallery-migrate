<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Read-only empty-history parity; the owned SQL journey proves first-write publication. */
class StorageEmptyHistoryContractTest extends TestCase {
	public function test_absent_pair_is_read_only_empty_history_even_with_unrelated_site_heads(): void {
		$db = new EmptyHistoryConnection();
		$history = new MigratedHistoryRepository( new LegacyTableBackend( $db, 1 ), new MigratorSettings() );
		$this->assertFalse( $history->get( 'new-gallery' ) );
		$this->assertFalse( $history->has( 'new-gallery' ) );
		$this->assertSame( array( array( 'found' => false, 'key' => 'new-gallery', 'value' => null ) ), $history->get_many( array( 'new-gallery' ) ) );
		$this->assertFalse( $history->find_by_source_identity( 'image', '', 7 ) );
		$this->assertSame( array(), $history->get_children_for_parent( 'gallery', array( 'image' ) ) );
		$this->assertSame( 0, $history->count() );
		$this->assertSame( 0, $history->revision() );
		$this->assertSame( array( 'rows' => array(), 'cursor' => null, 'revision' => 0 ), $history->page_log() );
		$this->assertSame( array( 'version' => 1, 'kind' => 'map', 'root_count' => 0 ), $history->export_page()['root'] );
		$this->assertSame( array( 'total' => 0, 'count' => 0, 'by_type' => array(), 'types' => array(),
			'by_status' => array(), 'statuses' => array(), 'completed' => 0, 'error' => 0 ), $history->log_summary() );
		$this->assertSame( array(), $history->summary() );
		$this->assertSame( 0, $db->writes );
		$this->assertGreaterThan( 0, count( $db->queries ) );
	}

	/** @dataProvider corruptEmptyEvidence */
	public function test_partial_shadow_orphan_and_failed_sql_never_become_empty( $mode ): void {
		$db = new EmptyHistoryConnection(); $db->mode = $mode;
		$history = new MigratedHistoryRepository( new LegacyTableBackend( $db, 1 ), new MigratorSettings() );
		try { $history->get( 'new-gallery' ); $this->fail( 'Corrupt or unavailable SQL must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		$this->assertSame( 0, $db->writes );
	}
	public static function corruptEmptyEvidence(): array {
		return array( array( 'partial' ), array( 'shadow' ), array( 'orphan' ), array( 'sql-failure' ), array( 'malformed' ) );
	}

	public function test_virtual_empty_view_still_rejects_bad_keys_and_stale_pages(): void {
		$db = new EmptyHistoryConnection(); $backend = new LegacyTableBackend( $db, 1 );
		foreach ( array( array( 'same', 'same' ), array( true ), array_fill( 0, 101, 'a' ) ) as $keys ) {
			try { $backend->history_read( 'identifiers', $keys ); $this->fail( 'Invalid key request must refuse.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		}
		try { $backend->history_read( 'page', array( 'limit' => 1, 'filters' => array(), 'cursor' => array() ) ); $this->fail( 'An empty-history cursor must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		try { $backend->history_read( 'page', array( 'limit' => 1, 'filters' => array( 'unindexed' => 'x' ), 'cursor' => null ) ); $this->fail( 'Unindexed empty-history filters must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		try { $backend->history_read( 'children', array( 'parent' => 'gallery', 'keys' => array( 'same', 'same' ) ) ); $this->fail( 'Duplicate empty-history children must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		$small = new LegacyTableBackend( $db, 1, 10000, 64 );
		try { $small->history_read( 'source', array( 'type' => 'image', 'plugin' => str_repeat( 'p', 200 ), 'id' => '7' ) ); $this->fail( 'Oversized empty-history source must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		try { $small->history_read( 'children', array( 'parent' => 'gallery', 'keys' => array( str_repeat( 'k', 200 ) ) ) ); $this->fail( 'Oversized empty-history key must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		$this->assertSame( 0, $db->writes );
	}
}

class EmptyHistoryConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	public $writes = 0;
	public $mode = 'absent';
	private $head_calls = 0;
	public function __construct() {}
	public function prepare( $sql, $args ) { return $sql; }
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql; $this->last_error = '';
		if ( 'sql-failure' === $this->mode ) { $this->last_error = 'Simulated read failure.'; return null; }
		if ( false !== strpos( $sql, 'OCTET_LENGTH(root_payload) AS root_payload_bytes' ) ) {
			$first = 0 === $this->head_calls++ % 2;
			if ( 'partial' === $this->mode && $first || 'malformed' === $this->mode ) {
				return array( array( 'id' => 'malformed' === $this->mode ? '0' : '1', 'blog_id' => '1', 'state_order' => '0',
					'state_kind' => 'map', 'active_generation' => '1', 'revision' => '1', 'codec_version' => '1',
					'record_count' => '0', 'payload_bytes' => '0', 'root_payload_bytes' => '0',
					'state_key_value' => ( new LegacyStateCodec() )->key_value( 'migrated' ) ) );
			}
			return array();
		}
		if ( false !== strpos( $sql, 'state_key_value = ' ) ) { return 'shadow' === $this->mode ? array( array( 'id' => '9' ) ) : array(); }
		if ( false !== strpos( $sql, 'LEFT JOIN' ) ) { return 'orphan' === $this->mode ? array( array( 'id' => '9' ) ) : array(); }
		throw new \RuntimeException( 'Unexpected empty-history SQL.' );
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Empty history read attempted a write.' ); }
}
