<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MigrationFlowTest.php';

class StorageImageTagEngineTest extends TestCase {
	private $backend;
	private $history;
	private $engine;
	protected function setUp(): void {
		$GLOBALS['foogallery_migrate_test_taxonomies'] = array( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG );
		$GLOBALS['foogallery_migrate_test_object_terms'] = array();
		$GLOBALS['foogallery_migrate_test_set_object_terms'] = array();
		$GLOBALS['foogallery_migrate_test_attachment_url_to_postid'] = array();
		$GLOBALS['foogallery_migrate_test_imported_attachments'] = array();
		$this->backend = new TagEngineTableBackend();
		$this->history = new TagEngineHistory( $this->backend, new MigratorSettings( $this->backend ) );
		$this->engine = new TagEngine( $this->history );
		$property = new \ReflectionProperty( MigratorEngine::class, 'production_backend' );
		$property->setAccessible( true ); $property->setValue( $this->engine, $this->backend );
	}
	protected function tearDown(): void { $GLOBALS['foogallery_migrate_test_taxonomies'] = array(); }
	private function image( $id, $target, $url, $tags, $migrated = true ) {
		$plugin = new TagEnginePlugin(); $object = new TagEngineImage( $plugin );
		$object->ID = $id; $object->migrated_id = $target; $object->source_url = $url;
		$object->migrated = $migrated; $object->tags = $tags; return $object;
	}
	public function test_table_build_pages_205_history_images_and_merges_cross_page_duplicate_without_full_history(): void {
		for ( $i = 1; $i <= 205; ++$i ) { $this->history->objects[] = $this->image( $i, $i <= 101 ? $i : $i - 101, 'url-' . $i, array( 'tag-' . $i ) ); }
		$this->engine->turn_size = 150;
		$result = $this->engine->start_image_tag_sync();
		$this->assertSame( 104, $result['total'] );
		$this->assertSame( 104, $result['processed'] );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( 3, $this->history->page_calls );
		$this->assertSame( 2, $this->backend->batch_calls );
		$this->assertSame( 1, $this->backend->checkpoint_calls );
		$this->assertSame( array( 'tag-1', 'tag-102' ), $this->backend->active[0]['tags'] );
		$this->assertSame( 'url-1', $this->backend->active[0]['source_url'] );
		$this->assertSame( 0, $this->history->full_reads );
		$legacy = new TagOptionComparisonEngine(); $legacy->objects = $this->history->objects;
		$builder = new \ReflectionMethod( MigratorEngine::class, 'build_image_tag_sync_state' );
		$builder->setAccessible( true );
		$this->assertSame( $builder->invoke( $legacy )['items'], $this->backend->active );
	}
	public function test_fallback_resolution_precedence_numeric_false_flag_url_firstmatch_and_ignored_empty_tags(): void {
		$this->history->objects = array(
			$this->image( '009', 90, 'Exact', array(), false ),
			$this->image( 10, 91, 'Case', array(), true ),
			$this->image( 11, 92, 'Case', array(), true ),
		);
		$GLOBALS['foogallery_migrate_test_attachment_url_to_postid']['case'] = 0;
		$this->engine->plugins = array( new TagEngineAdapter( array(
			array( 'attachment_id' => 50, 'plugin_name' => 'source', 'source_image_id' => 9, 'source_url' => 'Case', 'tags' => array( 'explicit' ) ),
			array( 'plugin_name' => 'source', 'source_image_id' => '009', 'source_url' => 'Case', 'tags' => array( 'numeric' ) ),
			array( 'plugin_name' => 'missing', 'source_image_id' => 1, 'source_url' => 'Case', 'tags' => array( 'url' ) ),
			array( 'plugin_name' => 'missing', 'source_image_id' => 2, 'source_url' => 'case', 'tags' => array( 'not-found' ) ),
			array( 'plugin_name' => 'missing', 'source_image_id' => 3, 'source_url' => 'irrelevant', 'tags' => array() ),
		) ) );
		$result = $this->engine->start_image_tag_sync();
		$this->assertSame( array( 50, 90, 91 ), array_column( $this->backend->active, 'attachment_id' ) );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 1, $result['unmatched'] );
		$this->assertSame( array( 50, 90, 91 ), array_column( $this->backend->active, 'attachment_id' ) );
		$this->assertSame( array( 'numeric' ), $this->backend->active[1]['tags'] );
		$this->assertSame( array( 'url' ), $this->backend->active[2]['tags'] );
		$this->assertNotContains( 'irrelevant', $this->history->urls_seen );
		$legacy = new TagOptionComparisonEngine(); $legacy->objects = $this->history->objects; $legacy->plugins = $this->engine->plugins;
		$builder = new \ReflectionMethod( MigratorEngine::class, 'build_image_tag_sync_state' ); $builder->setAccessible( true );
		$option = $builder->invoke( $legacy );
		$this->assertSame( $option['items'], $this->backend->active );
		$this->assertSame( $option['unmatched'], $result['unmatched'] );
	}
	public function test_interrupted_build_keeps_old_queue_and_late_checkpoint_retry_does_not_republish(): void {
		$this->backend->active = array( array( 'attachment_id' => 77, 'tags' => array( 'old' ) ) );
		$this->backend->headers = array( 'total' => 1, 'processed' => 0 );
		$this->history->objects = array( $this->image( 1, 88, 'new', array( 'new' ) ) );
		$this->history->change_on_page = true;
		try { $this->engine->start_image_tag_sync(); $this->fail( 'Changed history published a queue.' ); }
		catch ( \RuntimeException $expected ) { $this->assertSame( 1, $this->backend->abort_calls ); }
		$this->assertSame( 77, $this->backend->active[0]['attachment_id'] );
		$this->history->change_on_page = false;
		$this->backend->fail_checkpoint = true;
		try { $this->engine->start_image_tag_sync(); $this->fail( 'Checkpoint failure reported success.' ); }
		catch ( \RuntimeException $expected ) { $this->assertSame( 0, $this->backend->headers['processed'] ); }
		$this->backend->fail_checkpoint = false;
		$result = $this->engine->continue_image_tag_sync();
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $this->backend->headers['processed'] );
	}
	public function test_past_total_saved_cursor_matches_option_completion_without_output(): void {
		$this->backend->active = array( array( 'attachment_id' => 7, 'tags' => array( 'tag' ) ) );
		$this->backend->headers = array( 'total' => 1, 'processed' => 2 );
		$result = $this->engine->continue_image_tag_sync();
		$this->assertSame( 2, $result['processed'] );
		$this->assertTrue( $result['complete'] );
		$this->assertCount( 0, $GLOBALS['foogallery_migrate_test_set_object_terms'] );
	}
	public function test_url_lookup_scans_fixed_history_pages_first_exact_match_and_refuses_stale_head(): void {
		for ( $i = 1; $i <= 205; ++$i ) { $this->history->objects[] = $this->image( $i, 1000 + $i, 'other-' . $i, array() ); }
		$this->history->objects[101]->source_url = 'Exact';
		$this->history->objects[202]->source_url = 'Exact';
		$this->assertSame( array( 1102, 0 ), $this->history->tag_source_urls( array( 'Exact', 'exact' ), $this->history->tag_build_identity() ) );
		$this->assertSame( 3, $this->history->page_calls );
		$this->history->change_on_page = true;
		try { $this->history->tag_source_urls( array( 'Exact' ), $this->history->tag_build_identity() ); $this->fail( 'Stale URL scan was accepted.' ); }
		catch ( \RuntimeException $expected ) { $this->assertSame( 4, $this->history->page_calls ); }
	}
	public function test_empty_history_can_publish_explicit_adapter_queue_without_whole_read(): void {
		$this->engine->plugins = array( new TagEngineAdapter( array( array( 'attachment_id' => 41, 'tags' => array( 'tag' ), 'source_url' => '' ) ) ) );
		$result = $this->engine->start_image_tag_sync();
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 41, $this->backend->active[0]['attachment_id'] );
		$this->assertSame( 0, $this->history->full_reads );
	}
	public function test_public_url_lookup_keeps_strict_raw_string_while_tag_fallback_casts_scalars(): void {
		$this->history->objects = array( $this->image( 9, 42, 123, array() ) );
		$this->assertFalse( $this->history->find_by_source_url( 'image', '123' ) );
		$this->assertSame( array( 42 ), $this->history->tag_source_urls( array( '123' ), $this->history->tag_build_identity() ) );
	}
}

class TagEngine extends MigratorEngine {
	public $plugins = array();
	public $turn_size = 5;
	public function get_plugins() { return $this->plugins; }
	public function get_images_per_turn() { return $this->turn_size; }
}
class TagOptionComparisonEngine extends MigratorEngine {
	public $objects = array();
	public $plugins = array();
	public function __construct() {}
	public function get_migrated_objects() { return $this->objects; }
	public function get_plugins() { return $this->plugins; }
}
class TagEnginePlugin {
	public function name() { return 'source'; }
	public function get_image_tags( $object ) { return $object->tags; }
}
class TagEngineImage {
	public $plugin, $ID, $migrated_id, $source_url, $migrated, $tags;
	public function __construct( $plugin ) { $this->plugin = $plugin; }
	public function type() { return 'image'; }
}
class TagEngineAdapter {
	public $is_detected = true;
	private $items;
	public function __construct( $items ) { $this->items = $items; }
	public function find_image_tag_sync_items() { return $this->items; }
}
class TagEngineHistory extends MigratedHistoryRepository {
	public $objects = array();
	public $revision = 1;
	public $page_calls = 0;
	public $full_reads = 0;
	public $change_on_page = false;
	public $urls_seen = array();
	public function get_all( $type = '' ) { ++$this->full_reads; throw new \RuntimeException( 'Whole history read forbidden.' ); }
	public function tag_build_identity() { return array( 'logical_revision' => $this->revision ); }
	public function page_log( $type = '', $status = '', $limit = 100, $cursor = null ) {
		++$this->page_calls; $offset = null === $cursor ? 0 : $cursor;
		$objects = array_slice( $this->objects, $offset, $limit ); $rows = array();
		foreach ( $objects as $object ) { $rows[] = array( 'value' => $object ); }
		if ( $this->change_on_page ) { ++$this->revision; }
		return array( 'rows' => $rows, 'cursor' => $offset + count( $rows ) < count( $this->objects ) ? $offset + count( $rows ) : null, 'revision' => $this->revision );
	}
	public function tag_numeric_targets( $identities, $pin ) {
		if ( $pin !== $this->tag_build_identity() ) { throw new \RuntimeException( 'Stale numeric identity.' ); }
		$result = array(); foreach ( $identities as $identity ) {
			$value = 0; foreach ( $this->objects as $object ) {
				if ( $object->plugin->name() === $identity['plugin'] && absint( $object->ID ) === $identity['id'] && $object->migrated_id > 0 ) { $value = $object->migrated_id; break; }
			} $result[] = $value;
		} return $result;
	}
	public function tag_source_urls( $urls, $pin ) {
		$this->urls_seen = array_merge( $this->urls_seen, $urls );
		return parent::tag_source_urls( $urls, $pin );
	}
}
class TagEngineTableBackend extends LegacyTableBackend {
	public $active = array();
	public $headers = array();
	public $staged = null;
	public $revision = 1;
	public $batch_calls = 0;
	public $checkpoint_calls = 0;
	public $abort_calls = 0;
	public $fail_checkpoint = false;
	public function __construct() {}
	public function read( $key ) { return array( 'found' => false, 'value' => null ); }
	public function image_tag_queue_begin() { $this->staged = array(); return true; }
	public function image_tag_queue_abort() { ++$this->abort_calls; $this->staged = null; return true; }
	public function image_tag_queue_build_rows( $keys ) {
		$result = array(); foreach ( $keys as $key ) { $result[] = array( 'found' => isset( $this->staged[$key] ), 'key' => $key,
			'value' => isset( $this->staged[$key] ) ? $this->staged[$key] : null ); } return $result;
	}
	public function image_tag_queue_build_page( $rows ) {
		foreach ( $rows as $row ) { $this->staged[$row['attachment_id']] = $row; } return true;
	}
	public function image_tag_queue_finalize( $summary ) {
		$this->active = array_values( $this->staged ); $this->headers = $summary; $this->staged = null; ++$this->revision; return true;
	}
	public function image_tag_queue_summary() {
		return array( 'found' => count( $this->headers ) > 0, 'summary' => $this->headers, 'revision' => $this->revision, 'total' => count( $this->active ) );
	}
	public function image_tag_queue_page( $limit, $cursor = null ) {
		++$this->batch_calls; $start = null === $cursor ? 0 : $cursor;
		$rows = array(); for ( $i = $start; $i < min( count( $this->active ), $start + $limit ); ++$i ) {
			$rows[] = array( 'key' => $i, 'value' => $this->active[$i] );
		}
		$end = $start + count( $rows ); return array( 'rows' => $rows, 'cursor' => $end < count( $this->active ) ? $end : null, 'revision' => $this->revision );
	}
	public function image_tag_queue_checkpoint( $changes, $summary, $revision ) {
		++$this->checkpoint_calls; if ( $this->fail_checkpoint || $revision !== $this->revision ) { return false; }
		$this->headers = array_replace( $this->headers, $summary ); ++$this->revision; return true;
	}
}
