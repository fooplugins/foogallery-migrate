<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\GenericRetainingModel;
use FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Executable host contracts only; not native WordPress/MySQL acceptance. */
class ContentIndependentRecordRegressionTest extends TestCase {
    private function item() {
        return array( 'post_id' => 71, 'post_title' => 'Occurrence', 'post_type' => 'post',
            'plugin_name' => 'Example', 'type' => 'shortcode', 'gallery_id' => '9', 'migrated_foogallery_id' => 0,
            'original_content' => '[old]', 'replacement_content' => '[new]', 'match_offset' => 0 );
    }
    private function setup_case( $total = 1 ) {
        $repository = new ContentRegressionRepository( $this->item(), $total );
        $engine = new ContentRegressionEngine( $repository );
        $GLOBALS['foogallery_migrate_test_posts'][71] = (object) array( 'ID' => 71,
            'post_title' => 'Occurrence', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => '[old]' );
        return array( $repository, new ContentMigrator( $engine, 'block-shortcode' ), $engine );
    }
    public function test_selected_replacement_invalidates_before_post_update_without_scalar_fallback(): void {
        list( $repository, $migrator, $engine ) = $this->setup_case();
        $result = $migrator->replace_content( array( '0' ) );
        $this->assertSame( 1, $result['success'] );
        $this->assertTrue( $repository->cleared );
        $this->assertSame( '[old]', $repository->post_at_clear );
        $this->assertSame( '[new]', get_post(71)->post_content );
        $this->assertSame( array(), $engine->writes );
    }
    public function test_empty_replacement_uses_valid_selected_reset(): void {
        list( $repository, $migrator ) = $this->setup_case();
        $this->assertSame( 0, $migrator->replace_content( array() )['success'] );
        $this->assertTrue( $repository->cleared );
    }
    public function test_reset_refusal_precedes_any_post_side_effect(): void {
        list( $repository, $migrator ) = $this->setup_case(); $repository->refuse = true;
        try { $migrator->replace_content( array( '0' ) ); $this->fail( 'Reset must refuse.' ); }
        catch ( \RuntimeException $error ) { $this->assertSame( '[old]', get_post(71)->post_content ); }
    }
    public function test_reset_between_status_windows_cannot_publish_old_progress_with_new_revision(): void {
        list( $repository, $migrator ) = $this->setup_case( 151 ); $repository->interleave = true;
        try { $migrator->refresh_migration_status_batch(); }
        catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
        $this->assertSame( array(), $repository->patches, 'No patch may carry the pre-reset progress.' );
    }
    public function test_stale_url_page_clamps_to_actual_last_page_before_empty_render(): void {
        list( $repository, $migrator ) = $this->setup_case( 151 ); $_GET['content_paged'] = 900;
        ob_start(); try { $migrator->render_content_form(); } finally { $html = ob_get_clean(); unset($_GET['content_paged']); }
        $this->assertStringContainsString( '151 gallery occurrences found.', $html );
        $this->assertStringContainsString( 'name="foogallery_content_migrate_paged" value="2"', $html );
        $this->assertStringContainsString( 'content_paged=2', $html );
        $this->assertStringNotContainsString( 'No gallery shortcodes or blocks found', $html );
        $this->assertSame( array( array(0, 1), array(100, 100) ), $repository->windows );
    }
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_stale_ajax_page_after_reset_clamps_without_full_collection(): void {
        define('DOING_AJAX', true);
        list($repository,$migrator) = $this->setup_case(151);
        $repository->clear(); $repository->reset_total(4);
        $_POST = array('foogallery_content_migrate_paged'=>900,
            'foogallery_content_migrate_url'=>'https://example.test/admin.php?content_paged=900');
        ob_start(); try { $migrator->render_content_form(); } finally { $html=ob_get_clean(); $_POST=array(); }
        $this->assertStringContainsString('4 gallery occurrences found.', $html);
        $this->assertStringContainsString('name="foogallery_content_migrate_paged" value="1"', $html);
        $this->assertStringNotContainsString('No gallery shortcodes or blocks found', $html);
        $this->assertSame(array(array(0,1),array(0,100)), $repository->windows);
    }
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ajax_referer_preserves_last_page_navigation(): void {
        define('DOING_AJAX', true);
        $this->test_stale_url_page_clamps_to_actual_last_page_before_empty_render();
    }
    public function test_render_refuses_reset_between_header_and_page(): void {
        list($repository,$migrator) = $this->setup_case(151); $repository->interleave=true;
        $this->expectException(\RuntimeException::class);
        $migrator->render_content_form();
    }
    public function test_content_descriptor_matches_all_emitted_columns_and_qualified_path(): void {
        $tree = $this->item(); $tree['plugin_name'] = str_repeat('x', 2300);
        $tree['children'] = array( 'child' => array( 'post_id' => 9, 'ID' => '09' ) );
        $plain = new LegacyRecordCodec(); $rows = array();
        $stats = $plain->flatten_tree( ContentScanRepository::STATE_KEY, 0, $tree, 0, function($row) use (&$rows) { $rows[] = $row; } );
        $d = $plain->describe_tree( 0, $tree, 'retaining-row', ContentScanRepository::STATE_KEY );
        $this->assertSame( $stats['encoded_bytes'], $d['encoded_bytes'] );
        $max = 0; $odd = 0; $large = 0; $projection = 0;
        foreach ($rows as $row) { $size = 0;
            foreach (array('payload','mutable_payload','key_value','source_plugin_value','source_id_value','legacy_identifier_value') as $field) {
                $bytes = isset($row[$field]) ? strlen($row[$field]) : 0; $size += $bytes; $large += (int)($bytes > 3072);
                if (in_array($field, array('source_plugin_value','source_id_value','legacy_identifier_value'), true)) { $projection += $bytes; }
            }
            $max = max($max, $size); $odd += $size & 1;
        }
        $this->assertSame( $max, $d['max_row_bytes'] ); $this->assertSame( $odd, $d['encoded_odd_rows'] );
        $this->assertSame( $large, $d['encoded_large_columns'] ); $this->assertSame( $projection, $d['projection_bytes'] );
        $scale = StorageScaleAdmissionTest::admission(); $qualified = new LegacyRecordCodec(null, 24576, 16777216, $scale);
        $this->assertTrue( GenericRetainingModel::supports($qualified, new LegacyStateCodec(1048576,64,10000,$scale), $scale), 'No guard bypass or skip.' );
        $actual = array(); $this->assertSame($stats, $qualified->flatten_tree(ContentScanRepository::STATE_KEY,0,$tree,0,function($row) use (&$actual) { $actual[]=$row; }));
        $this->assertSame($rows, $actual);
        $count = 0; $limited = new LegacyRecordCodec(null, 100, $stats['encoded_bytes'] - 1, $scale);
        try { $limited->flatten_tree(ContentScanRepository::STATE_KEY,0,$tree,0,function() use (&$count) { ++$count; }); $this->fail('Byte cap must refuse.'); }
        catch (\RuntimeException $error) { $this->assertSame(0, $count); }
    }
}

class ContentRegressionRepository extends ContentScanRepository {
    public $windows = array(); public $patches = array(); public $cleared = false;
    public $refuse = false; public $interleave = false; public $post_at_clear; private $item; private $total; private $revision = 1;
    public function __construct($item, $total) { $this->item=$item; $this->total=$total; }
    public function reset_total($total) { $this->total=$total; ++$this->revision; }
    public function is_table() { return true; }
    public function get_many($keys) { return count($keys) ? array(0=>$this->item) : array(); }
    public function clear() {
        if ($this->refuse) { throw new \RuntimeException('Admission refused'); }
        $this->post_at_clear=get_post(71)->post_content; $this->cleared=true; ++$this->revision; $this->total=0; return true;
    }
    public function window($offset,$limit,$keys=null) {
        $this->windows[]=array($offset,$limit);
        if ($this->interleave && count($this->windows)===2) { $this->clear(); }
        return array('items'=>array() === $keys ? array() : ($offset < $this->total ? array($offset=>$this->item) : array()),
            'progress'=>array('started'=>true,'complete'=>true,'scanned'=>$this->total), 'total'=>$this->total, 'revision'=>$this->revision);
    }
    public function patch_batch($items,$progress,$revision=null) { $this->patches[]=array($items,$progress,$revision); return true; }
}
class ContentRegressionEngine {
    public $writes=array(); private $repository;
    public function __construct($repository) { $this->repository=$repository; }
    public function assert_storage_writer() {}
    public function content_scan_repository() { return $this->repository; }
    public function get_migrator_setting($key,$default=false) { return $default; }
    public function set_migrator_setting($key,$value) { $this->writes[]=array($key,$value); throw new \RuntimeException('Unexpected scalar/fallback write'); }
    public function get_plugins($detected=true) { return array(); }
    public function get_migrated_revision() { return 0; }
    public function get_migrated_objects() { return array(); }
    public function get_page_size() { return 100; }
}

namespace FooPlugins\FooGalleryMigrate;
function add_query_arg($key,$value,$url) { return \FooPlugins\FooGalleryMigrate\Migrators\add_query_arg($key,$value,$url); }
function remove_query_arg($key,$url) { return preg_replace('/([?&])'.preg_quote($key,'/').'=[^&#]*&?/', '$1', $url); }

namespace FooPlugins\FooGalleryMigrate\Migrators;
// URL functions absent from the dependency-free WordPress bootstrap.
function foogallery_migrate_admin_url($tab) { return 'https://example.test/admin.php?tab='.$tab; }
function add_query_arg($key,$value,$url) { return preg_replace('/([?&])'.preg_quote($key,'/').'=[^&#]*&?/', '$1', $url).(strpos($url,'?')===false?'?':'&').$key.'='.$value; }
function esc_url_raw($url) { return $url; }
function wp_get_referer() { return 'https://example.test/admin.php?content_paged=900'; }
