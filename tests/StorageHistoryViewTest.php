<?php
namespace {
	if ( ! function_exists( 'foogallery_migrate_admin_url' ) ) {
		function foogallery_migrate_admin_url( $tab = '' ) { return 'https://example.test/wp-admin/edit.php?tab=' . $tab; }
	}
	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( $key, $value, $url ) {
			$parts = parse_url( $url ); $query = array();
			if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
			$query[$key] = $value;
			return strtok( $url, '?' ) . '?' . http_build_query( $query );
		}
	}
	if ( ! function_exists( 'selected' ) ) {
		function selected( $selected, $current = true, $echo = true ) {
			$result = $selected == $current ? ' selected="selected"' : '';
			if ( $echo ) { echo $result; }
			return $result;
		}
	}
	if ( ! function_exists( 'esc_textarea' ) ) {
		function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
}

namespace FooPlugins\FooGalleryMigrate\Tests {
	use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
	use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
	use FooPlugins\FooGalleryMigrate\MigratorEngine;
	use FooPlugins\FooGalleryMigrate\MigratorSettings;
	use PHPUnit\Framework\TestCase;

	class StorageHistoryViewColdEngine extends MigratorEngine {
		public $initializations = 0;
		public function __construct() {}
		protected function migrated_store() {
			$this->initializations++;
			if ( ! isset( $this->migrated_store ) ) {
				$backend = ( new \ReflectionClass( LegacyTableBackend::class ) )->newInstanceWithoutConstructor();
				$this->migrated_store = new MigratedHistoryRepository( $backend, new MigratorSettings() );
			}
			return $this->migrated_store;
		}
	}
	class StorageHistoryViewPageEngine extends MigratorEngine {
		private $page_store;
		public function __construct( $page_store ) { $this->page_store = $page_store; }
		protected function migrated_store() { return $this->page_store; }
	}
	class StorageHistoryViewPageStore {
		private $record;
		public function __construct( $record ) { $this->record = $record; }
		public function page_log( $type, $status, $limit, $cursor ) {
			return array( 'rows' => array( array( 'key' => '09', 'value' => $this->record ) ), 'cursor' => null, 'revision' => 1 );
		}
		public function log_summary() { return array( 'total' => 1, 'by_type' => array( 'image' => 1 ) ); }
	}

	class StorageHistoryViewFakeEngine {
		public $records = array();
		public $scoped = true;
		public $page_calls = array();
		public $all_calls = 0;
		public $revision = 7;
		public $bad_page = false;
		public $change_after_page = false;
		public $change_during_summary = false;
		public function __construct( $records, $scoped = true ) { $this->records = $records; $this->scoped = $scoped; }
		public function uses_scoped_history() { return $this->scoped; }
		public function get_migrated_objects() {
			$this->all_calls++;
			if ( $this->scoped ) { throw new \RuntimeException( 'Whole table history read attempted.' ); }
			return $this->records;
		}
		public function get_migrated_objects_summary() {
			$summary = array();
			foreach ( $this->records as $record ) {
				if ( ! is_object( $record ) || ! isset( $record->type ) ) { continue; }
				if ( ! isset( $summary[$record->type] ) ) { $summary[$record->type] = array( 'count' => 0, 'errors' => 0 ); }
				$summary[$record->type]['count']++;
			}
			return $summary;
		}
		public function get_migrated_log_summary() {
			$types = array(); $total = 0;
			foreach ( $this->records as $record ) {
				if ( ! is_object( $record ) || ! isset( $record->type ) || '' === $record->type ) { continue; }
				$total++;
				$types[$record->type] = isset( $types[$record->type] ) ? $types[$record->type] + 1 : 1;
			}
			if ( $this->change_during_summary ) { $this->revision++; }
			return array( 'total' => $total, 'by_type' => $types );
		}
		public function get_migrated_revision() { return $this->revision; }
		public function get_migrated_log_page( $type, $status, $limit, $cursor ) {
			if ( $limit > 100 ) { throw new \RuntimeException( 'Unbounded page request.' ); }
			$this->page_calls[] = array( $type, $status, $limit, $cursor );
			if ( $this->bad_page ) { return array( 'rows' => array( array( 'value' => new \stdClass() ) ), 'cursor' => null, 'revision' => $this->revision ); }
			$rows = array();
			foreach ( $this->records as $key => $record ) {
				if ( '' !== $type && ( ! is_object( $record ) || $record->type !== $type ) ) { continue; }
				$rows[] = array( 'key' => $key, 'value' => $record );
			}
			$offset = null === $cursor ? 0 : $cursor['offset'];
			$page = array_slice( $rows, $offset, $limit );
			$next = $offset + count( $page ) < count( $rows ) ? array( 'offset' => $offset + count( $page ) ) : null;
			$revision = $this->revision;
			if ( $this->change_after_page ) { $this->revision++; }
			return array( 'rows' => $page, 'cursor' => $next, 'revision' => $revision );
		}
	}

	class StorageHistoryViewTest extends TestCase {
		protected function tearDown(): void {
			unset( $GLOBALS['foogallery_migrate_engine_instance'], $GLOBALS['foogallery_migrate_test_filters'] );
			$_GET = array();
		}
		private function records( $count ) {
			$rows = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$object = new \stdClass(); $object->type = 'gallery';
				$object->title = 'Gallery ' . $i; $object->migration_status = 'completed'; $object->migrated_id = $i;
				$rows['source-' . $i] = $object;
			}
			return $rows;
		}
		private function render( $view, $engine, $query = array() ) {
			$GLOBALS['foogallery_migrate_engine_instance'] = $engine; $_GET = $query;
			ob_start();
			try { include dirname( __DIR__ ) . '/includes/views/view-migrate-tab-' . $view . '.php'; return ob_get_clean(); }
			catch ( \Exception $error ) { ob_end_clean(); throw $error; }
		}
		public function test_cold_engine_reports_table_history_before_any_other_read(): void {
			$engine = new StorageHistoryViewColdEngine();
			$this->assertTrue( $engine->uses_scoped_history() );
			$this->assertSame( 1, $engine->initializations );
		}
		public function test_engine_log_page_does_not_fill_migrated_object_cache(): void {
			$record = new \stdClass(); $record->type = 'image';
			$store = new StorageHistoryViewPageStore( $record );
			$engine = new StorageHistoryViewPageEngine( $store );
			$this->assertSame( $record, $engine->get_migrated_log_page( 'image' )['rows'][0]['value'] );
			$this->assertSame( 1, $engine->get_migrated_log_summary()['total'] );
			$cache = new \ReflectionProperty( $engine, 'migrated_object_records' ); $cache->setAccessible( true );
			$this->assertSame( array(), $cache->getValue( $engine ) );
		}
		public function test_log_pages_and_large_ui_page_use_only_fixed_history_reads(): void {
			$records = $this->records( 205 );
			$engine = new StorageHistoryViewFakeEngine( $records );
			$option = new StorageHistoryViewFakeEngine( $records, false );
			$page_one = $this->render( 'log', $engine );
			$this->assertSame( $this->render( 'log', $option ), $page_one );
			$this->assertStringContainsString( 'data-object-id="source-1"', $page_one );
			$this->assertStringContainsString( 'data-object-id="source-100"', $page_one );
			$this->assertStringNotContainsString( 'data-object-id="source-101"', $page_one );
			$page_two = $this->render( 'log', $engine, array( 'log_paged' => '2' ) );
			$this->assertSame( $this->render( 'log', $option, array( 'log_paged' => '2' ) ), $page_two );
			$this->assertStringContainsString( 'data-object-id="source-101"', $page_two );
			$this->assertStringContainsString( 'data-object-id="source-200"', $page_two );
			$this->assertStringNotContainsString( 'data-object-id="source-201"', $page_two );
			$page_last = $this->render( 'log', $engine, array( 'log_paged' => '3' ) );
			$this->assertSame( $this->render( 'log', $option, array( 'log_paged' => '3' ) ), $page_last );
			$this->assertStringContainsString( 'data-object-id="source-205"', $page_last );
			$this->assertStringNotContainsString( 'data-object-id="source-200"', $page_last );
			$GLOBALS['foogallery_migrate_test_filters']['foogallery_migrate_log_page_size'] = function () { return 150; };
			$page_wide = $this->render( 'log', $engine );
			$this->assertSame( $this->render( 'log', $option ), $page_wide );
			$this->assertStringContainsString( 'data-object-id="source-150"', $page_wide );
			$this->assertStringNotContainsString( 'data-object-id="source-151"', $page_wide );
			$this->assertSame( 0, $engine->all_calls );
			foreach ( $engine->page_calls as $call ) { $this->assertSame( 100, $call[2] ); }
		}
		public function test_log_type_order_and_typed_keys_match_option_output(): void {
			$records = $this->records( 2 );
			$image = new \stdClass(); $image->type = 'image'; $image->title = 'First image'; $image->migration_status = 'completed'; $image->migrated_id = 0;
			$records = array( '09' => $image, 9 => $image ) + $records;
			$table = new StorageHistoryViewFakeEngine( $records );
			$option = new StorageHistoryViewFakeEngine( $records, false );
			$query = array( 'log_type' => 'missing' );
			$this->assertSame( $this->render( 'log', $option, $query ), $this->render( 'log', $table, $query ) );
			$this->assertSame( 0, $table->all_calls );
			$this->assertSame( 1, $option->all_calls );
		}
		public function test_filtered_log_page_does_not_hydrate_unrelated_history(): void {
			$records = array();
			for ( $i = 1; $i <= 1200; $i++ ) {
				$object = new \stdClass(); $object->type = 'image'; $object->title = 'Unrelated ' . $i;
				$object->migration_status = 'completed'; $records['image-' . $i] = $object;
			}
			foreach ( $this->records( 3 ) as $key => $object ) { $records[$key] = $object; }
			$engine = new StorageHistoryViewFakeEngine( $records );
			$output = $this->render( 'log', $engine, array( 'log_type' => 'gallery' ) );
			$this->assertSame( 3, substr_count( $output, 'foogallery-log-delete-object' ) - 1 );
			$this->assertCount( 1, $engine->page_calls );
			$this->assertSame( 'gallery', $engine->page_calls[0][0] );
			$this->assertSame( 0, $engine->all_calls );
		}
		public function test_empty_stale_and_malformed_pages_refuse_without_fallback(): void {
			$empty = new StorageHistoryViewFakeEngine( array() );
			$this->assertStringContainsString( 'No migrated objects found.', $this->render( 'log', $empty ) );
			$this->assertStringContainsString( 'No migrated objects found.', $this->render( 'debug', $empty ) );
			$stale = new StorageHistoryViewFakeEngine( $this->records( 101 ) ); $stale->change_after_page = true;
			try { $this->render( 'log', $stale ); $this->fail( 'Stale revision admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
			$bad = new StorageHistoryViewFakeEngine( $this->records( 1 ) ); $bad->bad_page = true;
			try { $this->render( 'debug', $bad ); $this->fail( 'Malformed row admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'row', $error->getMessage() ); }
			$this->assertSame( 0, $stale->all_calls + $bad->all_calls );
		}
		public function test_log_refuses_revision_change_between_summary_and_first_page(): void {
			$engine = new StorageHistoryViewFakeEngine( $this->records( 101 ) );
			$engine->change_during_summary = true;
			try { $this->render( 'log', $engine ); $this->fail( 'Changed summary admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
			$this->assertCount( 0, $engine->page_calls );
			$this->assertSame( 0, $engine->all_calls );
		}
		public function test_debug_preserves_print_r_output_with_fixed_pages(): void {
			$records = $this->records( 205 );
			$first = new \stdClass(); $first->type = 'image'; $first->title = 'Typed first';
			$records = array( '09' => $first, 9 => $first ) + $records;
			$table = new StorageHistoryViewFakeEngine( $records );
			$option = new StorageHistoryViewFakeEngine( $records, false );
			$output = $this->render( 'debug', $table );
			$this->assertSame( $this->render( 'debug', $option ), $output );
			$this->assertLessThan( strpos( $output, '[9]' ), strpos( $output, '[09]' ) );
			$this->assertCount( 3, $table->page_calls );
			$this->assertSame( 0, $table->all_calls );
		}
	}
}
