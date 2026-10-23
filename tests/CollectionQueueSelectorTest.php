<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use PHPUnit\Framework\TestCase;

/** Queue identity selection never fetches unrelated root payloads. */
class CollectionQueueSelectorTest extends TestCase {
	private function select( $db, $selection, $bytes = 16777216 ) {
		$repo = new LegacyTableRepository( $db, 1, 10000, $bytes );
		$begin = new \ReflectionMethod( $repo, 'start_budget' ); $begin->setAccessible( true ); $begin->invoke( $repo );
		$method = new \ReflectionMethod( $repo, 'queue_root_selector' ); $method->setAccessible( true );
		return $method->invoke( $repo, 'galleries', array( 'id' => 1, 'active_generation' => 1 ), array( 'root_count' => count( $db->roots ) ), $selection );
	}
	public function test_one_of_121_and_previous_selection_use_two_projection_pages_without_tree_payload(): void {
		$db = new QueueSelectorConnection( 121 );
		$db->set_mutable( 0, array( 'part_of_migration' => true, 'migration_status' => 'not_started', 'migrated' => false, 'migrated_id' => 0 ) );
		$db->set_mutable( 1, array( 'part_of_migration' => false, 'migration_status' => 'queued', 'migrated' => false, 'migrated_id' => 0 ) );
		$matches = $this->select( $db, array( 'gallery_P_A_1_2_120' => array( 'title' => 'Last' ) ) );
		$this->assertSame( array( 100, 21 ), $db->metadata_pages );
		$this->assertSame( array( 100, 21 ), $db->raw_pages );
		$this->assertSame( array( 0, 1, 120 ), array_column( $matches, 'order' ) );
		$this->assertSame( array( false, false, true ), array_column( $matches, 'matched' ) );
		$this->assertSame( 0, $db->payload_reads );
		$this->assertSame( 0, $db->writes );
	}
	public function test_duplicate_uid_matches_both_roots_without_splitting_underscores(): void {
		$db = new QueueSelectorConnection( 2 );
		$db->roots[1]['source_id_value'] = $db->roots[0]['source_id_value'];
		$db->roots[1]['identity_hash'] = $db->roots[0]['identity_hash'];
		$matches = $this->select( $db, array( 'gallery_P_A_1_2_0' => array( 'title' => 'Both' ) ) );
		$this->assertSame( array( 0, 1 ), array_column( $matches, 'order' ) );
	}
	public function test_false_like_and_missing_mutable_defaults_are_selected_for_legacy_normalization(): void {
		$db = new QueueSelectorConnection( 6 );
		$db->set_mutable( 0, array( 'part_of_migration' => 0, 'migration_status' => 'not_started', 'migrated' => false, 'migrated_id' => 0 ) );
		$db->set_mutable( 1, array( 'part_of_migration' => null, 'migration_status' => 'not_started', 'migrated' => false, 'migrated_id' => 0 ) );
		$db->set_mutable( 2, array( 'part_of_migration' => '', 'migration_status' => 'not_started', 'migrated' => false, 'migrated_id' => 0 ) );
		$db->set_mutable( 3, array( 'migration_status' => 'not_started', 'migrated' => false, 'migrated_id' => 0 ) );
		$db->set_mutable( 4, array( 'part_of_migration' => false ) );
		$matches = $this->select( $db, array() );
		$this->assertSame( array( 0, 1, 2, 3, 4 ), array_column( $matches, 'order' ) );
		$this->assertSame( array( false, false, false, false, false ), array_column( $matches, 'matched' ) );
		$this->assertSame( 0, $db->payload_reads );
	}
	public function test_bad_projection_missing_raw_and_aggregate_bytes_refuse_before_writes(): void {
		foreach ( array( 'collision', 'missing' ) as $mode ) {
			$db = new QueueSelectorConnection( 2 ); $db->mode = $mode;
			try { $this->select( $db, array() ); $this->fail( 'Expected projection refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( 0, $db->writes ); }
		}
		$db = new QueueSelectorConnection( 121 );
		try { $this->select( $db, array(), 1000 ); $this->fail( 'Expected aggregate refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->raw_pages ); $this->assertSame( 0, $db->writes ); }
	}
	public function test_second_metadata_page_exhausts_one_byte_budget_before_second_raw_fetch(): void {
		$db = new QueueSelectorConnection( 121 ); $bytes = 0;
		foreach ( $db->roots as $row ) {
			$bytes += strlen( $row['source_plugin_value'] ) + strlen( $row['source_id_value'] ) + strlen( $row['mutable_payload'] );
		}
		try { $this->select( $db, array(), $bytes - 1 ); $this->fail( 'Expected cross-page byte refusal.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertSame( array( 100, 21 ), $db->metadata_pages );
			$this->assertSame( array( 100 ), $db->raw_pages );
			$this->assertSame( 0, $db->payload_reads );
			$this->assertSame( 0, $db->writes );
		}
	}
}

class QueueSelectorConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $roots = array();
	public $metadata_pages = array();
	public $raw_pages = array();
	public $payload_reads = 0;
	public $writes = 0;
	public $mode = '';
	private $args;
	public function __construct( $count ) {
		$codec = new LegacyStateCodec();
		for ( $i = 0; $i < $count; ++$i ) {
			$source_id = '1_2_' . $i; $plugin = 'P_A';
			$this->roots[$i] = array( 'id' => (string) ( $i + 10 ), 'item_order' => (string) $i,
				'node_hash' => $codec->node_hash( $codec->key_hash( 'galleries' ), null, $i ), 'object_type' => 'gallery',
				'migration_status' => 'not_started', 'is_selected' => '0',
				'source_plugin_value' => $codec->encode( $plugin ), 'source_id_value' => $codec->encode( $source_id ),
				'plugin_hash' => $codec->key_hash( $plugin ),
				'identity_hash' => $codec->snapshot_digest( array( 'source-exact-v1', 'gallery', $plugin, $source_id ) ) );
			$this->set_mutable( $i, array( 'part_of_migration' => false, 'migration_status' => 'not_started', 'migrated' => false, 'migrated_id' => 0 ) );
		}
	}
	public function set_mutable( $i, $mutable ) {
		$this->roots[$i]['mutable_payload'] = ( new LegacyStateCodec() )->encode( $mutable );
		$this->roots[$i]['is_selected'] = empty( $mutable['part_of_migration'] ) ? '0' : '1';
		$this->roots[$i]['migration_status'] = isset( $mutable['migration_status'] ) ? $mutable['migration_status'] : '';
	}
	public function prepare( $sql, $args ) { $this->args = $args; return $sql; }
	public function get_results( $sql, $format ) {
		if ( false !== strpos( $sql, 'SELECT id, item_order, HEX(node_hash)' ) ) {
			$rows = array(); foreach ( $this->roots as $root ) { if ( (int) $root['item_order'] > $this->args[3] ) { $rows[] = $root; } }
			$rows = array_slice( $rows, 0, 100 ); $this->metadata_pages[] = count( $rows );
			$result = array(); foreach ( $rows as $row ) {
				$result[] = array( 'id' => $row['id'], 'item_order' => $row['item_order'], 'node_hash' => $row['node_hash'],
					'object_type' => $row['object_type'], 'migration_status' => $row['migration_status'], 'is_selected' => $row['is_selected'],
					'plugin_bytes' => (string) strlen( $row['source_plugin_value'] ), 'id_bytes' => (string) strlen( $row['source_id_value'] ),
					'mutable_bytes' => (string) strlen( $row['mutable_payload'] ),
					'plugin_hash' => 'collision' === $this->mode ? str_repeat( '0', 64 ) : $row['plugin_hash'], 'identity_hash' => $row['identity_hash'] );
			} return $result;
		}
		if ( false !== strpos( $sql, 'SELECT id, source_plugin_value, source_id_value' ) ) {
			$ids = array(); for ( $i = 3; $i < count( $this->args ); $i += 4 ) { $ids[] = $this->args[$i]; }
			$this->raw_pages[] = count( $ids ); $result = array();
			foreach ( $this->roots as $row ) { if ( in_array( (int) $row['id'], $ids, true ) ) {
				$result[] = array( 'id' => $row['id'], 'source_plugin_value' => $row['source_plugin_value'], 'source_id_value' => $row['source_id_value'], 'mutable_payload' => $row['mutable_payload'] );
			} }
			if ( 'missing' === $this->mode ) { array_pop( $result ); }
			return $result;
		}
		++$this->payload_reads; throw new \RuntimeException( 'Unexpected payload query.' );
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Selector attempted a write.' ); }
}
