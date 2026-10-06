<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

class LegacyHistoryReadContractTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_options'] = array();
		$GLOBALS['foogallery_migrate_test_option_writes'] = array();
	}
	protected function tearDown(): void { unset( $GLOBALS['foogallery_migrate_test_option_writes'] ); }

	private function data() {
		$record = array( 'object_type' => 'image', 'plugin_name' => '', 'ID' => '009', 'source_url' => 'https://example.test/Case.jpg',
			'migrated_id' => 71, 'migrated' => false, 'migration_status' => 'completed', 'migration_parent_key' => 'Parent',
			'settings' => array( '09' => false, 9 => null ), 'unknown' => "\xff\x00" );
		return array( 'migrated' => array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable',
			'items' => array( '09' => $record, 9 => null, 'opaque' => false ) ), 'migrated-revision' => 5 );
	}

	public function test_metadata_requests_are_bounded_before_sql(): void {
		$db = new HistoryReadTestConnection(); $backend = new LegacyTableBackend( $db, 7 );
		foreach ( array( array(), array( 'migrated', 'migrated' ), array( true ), array_fill( 0, 101, 'a' ) ) as $keys ) {
			try { $backend->metadata_many( $keys ); $this->fail( 'Expected metadata refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->queries ); }
		}
	}

	public function test_table_reads_match_real_option_hydration_and_presence(): void {
		$data = $this->data();
		$GLOBALS['foogallery_migrate_test_options'][FOOGALLERY_MIGRATE_OPTION_DATA] = $data;
		$settings = new MigratorSettings();
		$option = new MigratedHistoryRepository( new LegacyOptionBackend(), $settings );
		$table = new HistoryReadTableBackend( $data );
		$history = new MigratedHistoryRepository( $table, $settings );
		$this->assertSame( array(), $table->calls, 'Construction must not read/provision/select authority.' );
		foreach ( array( '09', 9, 'opaque', 'missing' ) as $key ) {
			$this->assertSame( $option->has( $key ), $history->has( $key ) );
			$this->assertSame( serialize( $option->get( $key ) ), serialize( $history->get( $key ) ) );
		}
		$this->assertSame( serialize( $option->get_many( array( 9, 'missing', '09', 'opaque' ) ) ), serialize( $history->get_many( array( 9, 'missing', '09', 'opaque' ) ) ) );
		$this->assertSame( $option->count(), $history->count() );
		$this->assertSame( $option->revision(), $history->revision() );
		$this->assertSame( serialize( $option->find_by_source_identity( 'image', '', '009' ) ), serialize( $history->find_by_source_identity( 'image', '', '009' ) ) );
		$this->assertSame( $data, get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_page_translates_logical_revision_and_terminal_cursor(): void {
		$table = new HistoryReadTableBackend( $this->data() );
		$history = new MigratedHistoryRepository( $table, new MigratorSettings() );
		$this->assertSame( array( 'rows' => array( array( 'key' => 'opaque', 'value' => false ) ), 'cursor' => null, 'revision' => 5 ), $history->iterate_page( 1 ) );
		$this->assertContains( 'history_page', $table->calls );
	}

	public function test_refuses_unadmitted_layout_and_concurrent_change(): void {
		$data = $this->data(); $data['migrated'] = array( 'header-root' => false );
		$table = new HistoryReadTableBackend( $data ); $history = new MigratedHistoryRepository( $table, new MigratorSettings() );
		try { $history->count(); $this->fail( 'Expected unadmitted layout refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'wrapper', $error->getMessage() ); }
		$table->data = $this->data(); $table->change_during_read = true;
		try { $history->get( 'missing' ); $this->fail( 'Expected changed-history refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
	}

	public function test_unimplemented_operations_refuse_without_fallback_or_writes(): void {
		$table = new HistoryReadTableBackend( $this->data() ); $history = new MigratedHistoryRepository( $table, new MigratorSettings() );
		foreach ( array( function () use ( $history ) { $history->find_by_source_url( 'image', 'https://example.test/image.jpg' ); },
			function () use ( $history ) { $history->clear(); }, function () use ( $history ) { $history->summary(); },
			function () use ( $history ) { $history->get_all(); } ) as $call ) {
			try { $call(); $this->fail( 'Expected unsupported operation refusal.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'not admitted', $error->getMessage() ); }
		}
		$this->assertSame( array(), $table->calls );
		$this->assertSame( array(), $GLOBALS['foogallery_migrate_test_option_writes'] );
	}

	public function test_malformed_reads_refuse_before_backend_calls(): void {
		$table = new HistoryReadTableBackend( $this->data() ); $history = new MigratedHistoryRepository( $table, new MigratorSettings() );
		foreach ( array( function () use ( $history ) { $history->get_many( array( '09', '09' ) ); },
			function () use ( $history ) { $history->get_many( array( '9' ) ); }, function () use ( $history ) { $history->has( true ); },
			function () use ( $history ) { $history->page_log( '', '', 101 ); }, function () use ( $history ) { $history->iterate_page( 1, array() ); },
			function () use ( $history ) { $history->find_by_source_identity( 'image', null, '009' ); },
			function () use ( $history ) { $history->find_by_source_identity( 'image', '', '' ); } ) as $call ) {
			try { $call(); $this->fail( 'Expected request refusal.' ); } catch ( \RuntimeException $error ) { $this->assertSame( array(), $table->calls ); }
		}
	}
}

class HistoryReadTestConnection extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
	public $base_prefix = 'wp_'; public $queries = array(); public function __construct() {}
	public function get_results( $sql, $format ) { $this->queries[] = $sql; throw new \RuntimeException( 'Unexpected SQL.' ); }
}

/** Translation double only: SQL/budget evidence comes from the guarded WP harness. */
class HistoryReadTableBackend extends LegacyTableBackend {
	public $data; public $calls = array(); public $change_during_read = false; private $revision = 1;
	public function __construct( $data ) { $this->data = $data; }
	public function history_read( $operation, $request = array() ) {
		$keys = array( 'migrated', 'migrated-revision' ); $context = $this->metadata_many( $keys );
		if ( ! isset( $context[0]['root']['wrapper'] ) ) { throw new \RuntimeException( 'Indexed wrapper required.' ); }
		switch ( $operation ) {
			case 'identifiers': $result = $this->identifiers( 'migrated', $request ); break;
			case 'presence': $result = $this->identifier_presence( 'migrated', $request ); break;
			case 'count': $result = $this->count_roots( 'migrated' ); break;
			case 'page': $result = $this->history_page( 'migrated', $request['limit'], $request['filters'], $request['cursor'] ); break;
			case 'source': $result = $this->history_source_identity( $request['type'], $request['plugin'], $request['id'] ); break;
			default: $result = null;
		}
		if ( $this->metadata_many( $keys ) !== $context ) { throw new \RuntimeException( 'History changed during bounded read.' ); }
		return array( 'context' => $context, 'result' => $result );
	}
	public function metadata_many( $keys ) {
		$this->calls[] = 'metadata_many'; $result = array();
		foreach ( $keys as $key ) {
			$kind = 'migrated' === $key ? 'map' : 'scalar';
			$root = 'scalar' === $kind ? array( 'version' => 1, 'kind' => 'scalar', 'value' => $this->data[$key] )
				: ( new LegacyCollectionEnvelope() )->split( $key, 'map', $this->data[$key] )['root'];
			$result[] = array( 'key' => $key, 'head' => array( 'id' => 'migrated' === $key ? 1 : 2, 'revision' => $this->revision, 'active_generation' => 1, 'state_kind' => $kind ), 'root' => $root );
		}
		return $result;
	}
	public function identifiers( $key, $keys ) {
		$this->calls[] = 'identifiers'; if ( $this->change_during_read ) { $this->revision++; }
		$items = $this->data[$key]['items']; $result = array();
		foreach ( $keys as $k ) { $found = array_key_exists( $k, $items ); $result[] = array( 'found' => $found, 'key' => $k, 'value' => $found ? $items[$k] : null ); }
		return $result;
	}
	public function identifier_presence( $key, $keys ) { $rows = $this->identifiers( $key, $keys ); foreach ( $rows as &$row ) { unset( $row['value'] ); } return $rows; }
	public function count_roots( $key ) { return count( $this->data[$key]['items'] ); }
	public function history_page( $key, $limit, $filters, $cursor = null ) {
		$this->calls[] = 'history_page'; return array( 'rows' => array( array( 'key' => 'opaque', 'value' => false ) ), 'cursor' => null, 'revision' => $this->revision );
	}
	public function history_source_identity( $type, $plugin, $id ) { return array( 'found' => true, 'key' => '09', 'value' => $this->data['migrated']['items']['09'] ); }
}
