<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\OptionMigratedStore;
use PHPUnit\Framework\TestCase;

class HistoryStoreRetirementTest extends TestCase {
    public function test_option_store_has_no_parent_database_or_schema_api(): void {
        $class = new \ReflectionClass(OptionMigratedStore::class);
        $this->assertFalse($class->getParentClass());
        foreach (array('wpdb','memory_rows') as $property) { $this->assertFalse($class->hasProperty($property)); }
        foreach (array('install_schema','migrate_legacy','table_name','uninstall_schema','storage_key') as $method) {
            $this->assertFalse($class->hasMethod($method));
        }
        $this->assertFileDoesNotExist(FOOGM_DIR.'/includes/class-migrated-store.php');
    }

    public function test_current_table_names_come_from_base_prefix(): void {
        $schema = new LegacyStorageSchema((object)array('prefix'=>'network_7_', 'base_prefix'=>'network_'));
        $this->assertSame(array('network_foogallery_migrate_state','network_foogallery_migrate_records'), array_keys($schema->definitions()));
    }

    public function test_engine_accepts_standalone_option_store_without_rewriting_state(): void {
        $GLOBALS['foogallery_migrate_test_options']=array(FOOGALLERY_MIGRATE_OPTION_DATA=>array('unknown'=>array(false,null,"\xff")));
        $store=new OptionMigratedStore();
        $engine=new MigratorEngine($store);
        $this->assertSame(array(),$engine->get_migrated_objects());
        $this->assertSame(0,$engine->get_migrated_revision());
        $this->assertSame(array('unknown'=>array(false,null,"\xff")),get_option(FOOGALLERY_MIGRATE_OPTION_DATA));
    }

    /** @dataProvider uninstall_modes */
    public function test_real_uninstall_entrypoint_preserves_network_scope_and_refuses_failures($mode): void {
        $out=array(); $exit=0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/retirement-uninstall-regression.php').' '.
            escapeshellarg(FOOGM_DIR).' '.escapeshellarg($mode).' 2>&1',$out,$exit);
        $this->assertSame(0,$exit,implode("\n",$out));
        $result=json_decode(implode("\n",$out),true);
        $this->assertSame('pass',$result['status']);
    }
    public static function uninstall_modes(): array {
        return array(array('success'),array('failed-delete'),array('thrown-delete'));
    }
}
