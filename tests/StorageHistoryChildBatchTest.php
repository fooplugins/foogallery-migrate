<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Fixed-page child selectors on a coherent, populated pair of history heads. */
class StorageHistoryChildBatchTest extends TestCase {
	private function read( $db, $keys, $bytes = 16777216, $rows = 10000 ) {
		return ( new LegacyTableBackend( $db, 1, $rows, $bytes ) )->history_read( 'children',
			array( 'parent' => 'Gallery', 'keys' => $keys ) );
	}
	private function refuses( $db, $keys, $bytes = 16777216, $rows = 10000 ) {
		try { $this->read( $db, $keys, $bytes, $rows ); $this->fail( 'Expected bounded child read to refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		$this->assertSame( 0, $db->writes );
	}
	public function test_missing_keys_use_two_fixed_queries_per_hundred_not_per_key(): void {
		$db = new ChildBatchConnection(); $keys = array();
		for ( $i = 0; $i < 250; ++$i ) { $keys[] = 'image-' . $i; }
		$this->assertSame( array(), $this->read( $db, $keys )['result'] );
		$this->assertSame( array( 100, 100, 50 ), $db->selector_sizes );
		$this->assertSame( array( 100, 100, 50 ), $db->missing_sizes );
		$this->assertFalse( $db->selector_payload_fetched );
		$this->assertSame( 0, $db->tree_reads );
		$this->assertSame( 0, $db->writes );
	}
	public function test_exact_typed_missing_keys_and_invalid_or_duplicate_keys(): void {
		$db = new ChildBatchConnection();
		$this->assertSame( array(), $this->read( $db, array( '09', 9, 'missing' ) )['result'] );
		$this->assertSame( array( 3 ), $db->selector_sizes );
		$this->refuses( new ChildBatchConnection(), array( 'same', 'same' ) );
		$this->refuses( new ChildBatchConnection(), array( '9' ) );
		$this->refuses( new ChildBatchConnection(), array( true ) );
	}
	public function test_oversized_input_and_selector_overflow_refuse_without_tree_hydration(): void {
		$this->refuses( new ChildBatchConnection(), array( str_repeat( 'x', 2000 ) ), 512 );
		$this->refuses( new ChildBatchConnection(), array_fill( 0, 101, 'duplicate' ), 16777216, 100 );
		$db = new ChildBatchConnection(); $db->mode = 'overflow'; $this->refuses( $db, array( 'one' ) );
		$this->assertSame( 0, $db->tree_reads );
	}
	public function test_missing_projection_collision_stale_head_and_sql_failure_refuse(): void {
		foreach ( array( 'unprojected', 'collision', 'stale', 'sql_failure' ) as $mode ) {
			$db = new ChildBatchConnection(); $db->mode = $mode;
			$this->refuses( $db, array( 'one', 'two' ) );
			$this->assertSame( 0, $db->tree_reads );
		}
	}
}

class ChildBatchConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $mode = 'missing';
	public $selector_sizes = array();
	public $missing_sizes = array();
	public $tree_reads = 0;
	public $writes = 0;
	public $selector_payload_fetched = false;
	private $args = array();
	private $selected = false;
	private $codec;
	private $roots;
	public function __construct() {
		$this->codec = new LegacyStateCodec();
		$root = ( new LegacyCollectionEnvelope( $this->codec ) )->split( 'migrated', 'map',
			array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array() ) )['root'];
		$this->roots = array( 1 => $this->codec->encode_collection_root( $root ),
			2 => $this->codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => 0 ) ) );
	}
	public function prepare( $sql, $args ) { $this->args = $args; return $sql; }
	public function get_results( $sql, $format ) {
		$this->last_error = '';
		if ( false !== strpos( $sql, 'OCTET_LENGTH(root_payload) AS root_payload_bytes' ) ) {
			$key = $this->args[2] === $this->codec->key_hash( 'migrated' ) ? 'migrated' : 'migrated-revision';
			$id = 'migrated' === $key ? 1 : 2; $revision = $this->selected && 'stale' === $this->mode && 1 === $id ? 2 : 1;
			return array( array( 'id' => (string) $id, 'blog_id' => '1', 'state_order' => (string) ( $id - 1 ),
				'state_kind' => 1 === $id ? 'map' : 'scalar', 'active_generation' => '1', 'revision' => (string) $revision,
				'codec_version' => '1', 'record_count' => '0', 'payload_bytes' => (string) strlen( $this->roots[$id] ),
				'root_payload_bytes' => (string) strlen( $this->roots[$id] ), 'state_key_value' => $this->codec->key_value( $key ) ) );
		}
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { return array( array( 'root_payload' => $this->roots[$this->args[1]] ) ); }
		if ( false !== strpos( $sql, 'HEX(snapshot_digest)' ) ) { return array( array( 'digest' => $this->codec->snapshot_digest( 0 ), 'digest_revision' => '1', 'digest_status' => 'current' ) ); }
		if ( false !== strpos( $sql, 'legacy_identifier_hash IN (' ) ) {
			$this->selected = true; $count = count( $this->args ) - 3; $this->selector_sizes[] = $count;
			$this->selector_payload_fetched = false !== strpos( $sql, 'legacy_identifier_value' ) || false !== strpos( $sql, 'key_value' );
			if ( 'sql_failure' === $this->mode ) { $this->last_error = 'Injected SQL failure'; return false; }
			if ( 'overflow' === $this->mode ) { return array_fill( 0, 101, array() ); }
			if ( 'collision' === $this->mode ) { return array( array( 'identifier_hash' => $this->args[3], 'node_hash' => str_repeat( '0', 64 ) ) ); }
			return array();
		}
		if ( false !== strpos( $sql, 'node_hash IN (' ) ) {
			$this->missing_sizes[] = count( $this->args ) - 3;
			return 'unprojected' === $this->mode ? array( array( 'id' => '7' ) ) : array();
		}
		++$this->tree_reads; throw new \RuntimeException( 'Unexpected child SQL.' );
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Read attempted write.' ); }
}
