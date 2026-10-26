<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Migrators\AlbumMigrator;
use FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator;
use FooPlugins\FooGalleryMigrate\Migrators\GalleryMigrator;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Plugins\WpPhotoAlbumPlus;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CollectionFormReadTest.php';
require_once __DIR__ . '/ContentIndependentRecordRegressionTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReleaseCompatibilityTest extends TestCase {
    public static function page_requests(): array {
        return array(
            'no referer' => array( array(), false, 1 ),
            'referer without query' => array( array(), 'https://example.test/admin.php', 1 ),
            'invalid referer' => array( array(), 'https://example.test:bad/admin.php', 1 ),
            'referer page' => array( array(), 'https://example.test/admin.php?gallery_paged=2&album_paged=2&content_paged=2', 2 ),
            'posted page without url' => array( array('page'=>2), false, 2 ),
            'array url' => array( array('page'=>2, 'url'=>array('bad')), false, 2 ),
            'zero page' => array( array('page'=>0), false, 1 ),
            'array page' => array( array('page'=>array(2)), false, 1 ),
        );
    }

    /** @dataProvider page_requests */
    public function test_all_forms_handle_missing_or_malformed_pagination_without_notices( $request, $referer, $page ): void {
        define( 'DOING_AJAX', true );
        $GLOBALS['foogallery_migrate_test_referer'] = $referer;
        $_GET = array();
        foreach ( array('galleries', 'albums', 'content') as $kind ) {
            $prefix = array('galleries'=>'foogallery_migrate_', 'albums'=>'foogallery_album_migrate_', 'content'=>'foogallery_content_migrate_')[$kind];
            $_POST = array();
            if ( array_key_exists('page', $request) ) { $_POST[$prefix . 'paged'] = $request['page']; }
            if ( array_key_exists('url', $request) ) { $_POST[$prefix . 'url'] = $request['url']; }
            if ( 'content' === $kind ) {
                $item = array('post_id'=>71, 'post_title'=>'Occurrence', 'post_type'=>'post', 'plugin_name'=>'Example',
                    'type'=>'shortcode', 'gallery_id'=>'9', 'migrated_foogallery_id'=>0, 'original_content'=>'[old]',
                    'replacement_content'=>'[new]', 'match_offset'=>0);
                $repository = new ContentRegressionRepository($item, 41);
                $engine = new ContentRegressionEngine($repository); $engine->page_size = 20;
                $migrator = new ContentMigrator($engine, 'block-shortcode');
                $method = 'render_content_form';
            } else {
                $objects = array(); $plugin = new CollectionFormPlugin();
                for ($i=0; $i<41; ++$i) {
                    $object = 'galleries' === $kind ? new Gallery($plugin) : new Album($plugin);
                    $object->ID=$i; $object->title='Item '.$i; $object->children_count=2; $objects[]=$object;
                }
                $repository = new CollectionFormRepository($objects);
                $engine = new CollectionFormEngine($repository, $objects, $kind, 20);
                $migrator = 'galleries' === $kind ? new GalleryMigrator($engine,$kind) : new AlbumMigrator($engine,$kind);
                $method = 'galleries' === $kind ? 'render_gallery_form' : 'render_album_form';
            }
            ob_start();
            try { $migrator->$method(); } finally { $html=ob_get_clean(); }
            $this->assertStringContainsString('name="'.$prefix.'paged" value="'.$page.'"', $html, $kind);
            $this->assertStringNotContainsString('Warning:', $html);
            if ('content' !== $kind) { $this->assertSame(array(array(($page-1)*20,20)), $repository->pages); }
        }
    }

    public function test_numeric_photo_dates_do_not_shift_with_process_timezone_and_exif_is_preserved(): void {
        $plugin = new WpPhotoAlbumPlus();
        $method = new \ReflectionMethod($plugin, 'get_valid_photo_date'); $method->setAccessible(true);
        $timezone = date_default_timezone_get();
        try {
            foreach (array('UTC','Pacific/Auckland','America/New_York') as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame('2026-06-01 10:00:00', $method->invoke($plugin, (object)array('timestamp'=>'1780308000')));
                $this->assertSame('2026-05-31 09:08:07', $method->invoke($plugin, (object)array('exifdtm'=>'2026:05:31 09:08:07')));
                $this->assertSame('', $method->invoke($plugin, (object)array('exifdtm'=>'2026-02-31 12:00:00')));
            }
        } finally { date_default_timezone_set($timezone); }
    }

    public static function temp_failures(): array {
        return array('remote sideload'=>array(false,false), 'local copy'=>array(true,true), 'local sideload'=>array(true,false));
    }

    /** @dataProvider temp_failures */
    public function test_image_import_failure_deletes_only_its_temporary_copy( $local, $copy_failure ): void {
        require __DIR__ . '/fixtures/release-compatibility.php';
        $source = tempnam(ABSPATH, 'fgm-source-');
        $temp = tempnam(sys_get_temp_dir(), 'fgm-copy-');
        file_put_contents($source, 'source must survive');
        $GLOBALS['foogallery_migrate_test_temp_file']=$temp;
        $GLOBALS['foogallery_migrate_test_copy_failure']=$copy_failure;
        $GLOBALS['foogallery_migrate_test_deleted_files']=array();
        $GLOBALS['foogallery_migrate_test_options']=array();
        $GLOBALS['foogallery_migrate_test_attachment_url_to_postid']=array();
        $GLOBALS['foogallery_migrate_test_imported_attachments']=array();
        $GLOBALS['foogallery_migrate_test_posts']=array();
        $GLOBALS['foogallery_migrate_engine_instance']=new MigratorEngine(new MigratedStore(false,new MigratorSettings()));
        $image = new Image();
        $image->source_url=$local ? 'https://example.test/'.basename($source) : 'https://remote.example.test/new.jpg';
        $GLOBALS['foogallery_migrate_test_attachment_url_to_postid'][$image->source_url]=0;
        try {
            $image->create_new_migrated_object();
            $this->assertSame(array($temp), $GLOBALS['foogallery_migrate_test_deleted_files']);
            $this->assertFileDoesNotExist($temp);
            $this->assertSame('source must survive', file_get_contents($source));
            $this->assertInstanceOf(\WP_Error::class, $image->error);
            $this->assertSame($copy_failure ? 'foogallery_migrate_local_copy_failed' : 'foogallery_migrate_sideload_failed', $image->error->get_error_code());
        } finally {
            if (is_file($temp)) { unlink($temp); }
            unlink($source);
        }
    }

    public function test_wordpress_alternatives_are_used_without_changing_marker_shape(): void {
        $source=file_get_contents(FOOGM_DIR.'/includes/class-legacy-table-repository.php');
        $this->assertStringContainsString("hash( 'sha256', uniqid( '', true ) . ':' . wp_rand()", $source);
        $this->assertStringNotContainsString('mt_rand(', $source);
        $content=file_get_contents(FOOGM_DIR.'/includes/migrators/class-content-migrator.php');
        $this->assertStringContainsString('return wp_strip_all_tags( $caption );', $content);
    }
}
