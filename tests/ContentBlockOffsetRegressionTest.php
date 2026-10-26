<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ContentBlockOffsetRegressionTest extends TestCase {
    private $engine;
    private $plugin;
    protected function setUp(): void {
        require_once __DIR__ . '/fixtures/content-block-parser.php';
        $GLOBALS['foogallery_migrate_test_options'] = array();
        $GLOBALS['foogallery_migrate_test_posts'] = array();
        $GLOBALS['fgm_test_block_parses'] = array();
        $this->engine = new BlockOffsetEngine(new MigratedStore(false, new MigratorSettings()));
        $GLOBALS['foogallery_migrate_engine_instance'] = $this->engine;
        $this->plugin = new BlockOffsetPlugin(); $this->plugin->is_detected = true;
        $GLOBALS['foogallery_migrate_test_plugins'] = array($this->plugin);
        $gallery = new Gallery($this->plugin); $gallery->ID = 7; $gallery->migrated_id = 107;
        $gallery->migrated = true; $gallery->migration_status = 'completed';
        $this->engine->add_migrated_object($gallery);
    }
    private function block($raw, $name='example/gallery', $attrs=array('id'=>7)) {
        $GLOBALS['fgm_test_block_parses'][$raw] = array(array('blockName'=>$name,'attrs'=>$attrs,
            'innerBlocks'=>array(),'innerHTML'=>'','innerContent'=>array(),
            'serialized'=>'<!-- wp:' . $name . ' {"id":7} /-->'));
        return $raw;
    }
    private function discover($content) {
        $post = (object)array('ID'=>71,'post_title'=>'Block fixture','post_type'=>'post','post_status'=>'publish','post_content'=>$content);
        $GLOBALS['foogallery_migrate_test_posts'][71] = $post;
        $method = new \ReflectionMethod($this->engine->get_content_migrator(), 'find_shortcodes_and_blocks_in_content');
        $method->setAccessible(true);
        return $method->invoke($this->engine->get_content_migrator(), $post, array($this->plugin));
    }
    private function save($items, $table) {
        if ($table) { $this->engine->repository = new BlockOffsetRepository(); $this->engine->repository->items = $items; }
        else { $this->engine->set_migrator_setting('block-shortcode_scan_state', array('items'=>$items,'progress'=>array())); }
    }
    public static function modes(): array { return array('options'=>array(false),'table-content'=>array(true)); }
    public static function source_blocks(): array {
        $cases=array();
        foreach(array('envira/envira-gallery','modula/gallery','imagely/main-block','robo/block-robo-gallery','tw/bwg') as $name) {
            foreach(array(false,true) as $table) { $cases[$name . ($table?'-table':'-options')]=array($name,$table); }
        }
        return $cases;
    }
    /** @dataProvider source_blocks */
    public function test_third_party_discovery_to_replacement_preserves_surrounding_text($name,$table): void {
        $this->plugin->block_name=$name;
        $raw=$this->block('<!-- wp:' . $name . ' {"id":7} /-->', $name);
        $before="Before 🖼️\n"; $after="\nAfter"; $items=$this->discover($before.$raw.$after);
        $this->assertCount(1,$items); $this->assertSame(strlen($before),$items[0]['match_offset']);
        $this->save($items,$table);
        $result=$this->engine->get_content_migrator()->migrate_and_replace_content(array(0));
        $this->assertSame(array('success'=>1,'errors'=>array()),$result);
        $this->assertSame($before.'<!-- wp:fooplugins/foogallery {"id":107} /-->'.$after,get_post(71)->post_content);
    }
    /** @dataProvider modes */
    public function test_identical_blocks_have_distinct_offsets_and_only_selected_second_is_replaced($table): void {
        $raw=$this->block('<!-- wp:example/gallery {"id":7} /-->');
        $items=$this->discover('A'.$raw.'B'.$raw.'C');
        $this->assertCount(2,$items); $this->assertSame(1,$items[0]['match_offset']);
        $this->assertSame(strlen('A'.$raw.'B'),$items[1]['match_offset']); $this->save($items,$table);
        $this->assertSame(1,$this->engine->get_content_migrator()->migrate_and_replace_content(array(1))['success']);
        $this->assertSame('A'.$raw.'B<!-- wp:fooplugins/foogallery {"id":107} /-->C',get_post(71)->post_content);
    }
    /** @dataProvider modes */
    public function test_noncanonical_paired_block_nested_in_group_keeps_exact_source_bytes($table): void {
        $raw=$this->block('<!--  wp:example/gallery  { "id" : 7 }  --><div>Keep inner markup</div><!-- /wp:example/gallery -->');
        $prefix='<!-- wp:group --><div class="wp-block-group">'; $suffix='</div><!-- /wp:group -->';
        $items=$this->discover($prefix.$raw.$suffix);
        $this->assertCount(1,$items); $this->assertSame($raw,$items[0]['original_content']);
        $this->assertSame(strlen($prefix),$items[0]['match_offset']); $this->save($items,$table);
        $this->assertSame(1,$this->engine->get_content_migrator()->migrate_and_replace_content(array(0))['success']);
        $this->assertSame($prefix.'<!-- wp:fooplugins/foogallery {"id":107} /-->'.$suffix,get_post(71)->post_content);
    }
    /** @dataProvider modes */
    public function test_changed_block_refuses_whole_selection_including_valid_shortcode($table): void {
        $raw=$this->block('<!-- wp:example/gallery {"id":7} /-->');
        $items=$this->discover($raw.' [example id="7"]'); $this->assertCount(2,$items); $this->save($items,$table);
        $changed='prefix '.$raw.' [example id="7"]'; $GLOBALS['foogallery_migrate_test_posts'][71]->post_content=$changed;
        $result=$this->engine->get_content_migrator()->migrate_and_replace_content(array(0,1));
        $this->assertSame(0,$result['success']); $this->assertNotEmpty($result['errors']);
        $this->assertSame($changed,get_post(71)->post_content);
        if ($table) { $this->assertFalse($this->engine->repository->cleared); }
    }
    /** @dataProvider modes */
    public function test_valid_mixed_block_and_shortcode_selection_replaces_both($table): void {
        $raw=$this->block('<!-- wp:example/gallery {"id":7} /-->');
        $items=$this->discover('A'.$raw.' B [example id="7"] C'); $this->save($items,$table);
        $result=$this->engine->get_content_migrator()->migrate_and_replace_content(array(0,1));
        $this->assertSame(array('success'=>2,'errors'=>array()),$result);
        $this->assertSame('A<!-- wp:fooplugins/foogallery {"id":107} /--> B [foogallery id="107"] C',get_post(71)->post_content);
    }
    public function test_legacy_scan_without_offset_requires_rescan_instead_of_guessing(): void {
        $raw=$this->block('<!-- wp:example/gallery {"id":7} /-->'); $items=$this->discover($raw);
        unset($items[0]['match_offset']); $this->save($items,false);
        $result=$this->engine->get_content_migrator()->migrate_and_replace_content(array(0));
        $this->assertSame(0,$result['success']); $this->assertNotEmpty($result['errors']); $this->assertSame($raw,get_post(71)->post_content);
    }
    public function test_unclosed_or_mismatched_blocks_are_not_offered(): void {
        $this->assertSame(array(),$this->discover('<!-- wp:example/gallery {"id":7} --><div>Unclosed'));
        $this->assertSame(array(),$this->discover('<!-- wp:example/gallery {"id":7} --><!-- /wp:other/block --><!-- /wp:example/gallery -->'));
    }
}
class BlockOffsetPlugin extends Plugin {
    public $block_name='example/gallery';
    public function name(){return 'Example';} public function detect(){return true;}
    public function find_galleries(){return array();} public function find_albums(){return array();}
    public function get_gallery_template($gallery){return 'default';} public function get_gallery_settings($gallery,$settings){return $settings;}
    public function get_block_patterns(){return array($this->block_name=>array());}
    public function get_shortcode_patterns(){return array('/\[example id="(\d+)"\]/');}
}
class BlockOffsetEngine extends MigratorEngine {
    public $repository;
    public function content_scan_repository(){return $this->repository ? $this->repository : parent::content_scan_repository();}
}
class BlockOffsetRepository extends ContentScanRepository {
    public $items=array(); public $cleared=false;
    public function __construct(){}
    public function is_table(){return true;}
    public function window($offset,$limit,$keys=null){return array('items'=>array(),'progress'=>array(),'total'=>count($this->items),'revision'=>1);}
    public function selected_many($keys){return array('items'=>array_intersect_key($this->items,array_fill_keys($keys,true)),'total'=>count($this->items),'revision'=>1);}
    public function clear(){$this->cleared=true;return true;}
}
