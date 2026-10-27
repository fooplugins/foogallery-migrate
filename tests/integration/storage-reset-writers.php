<?php
/**
 * Isolated native reset regression: wp --user=<admin> eval-file tests/integration/storage-reset-writers.php
 * Creates/removes only a deterministic owned pair of temporary fixture tables.
 * No existing migration state, source posts, galleries or media are reset.
 */
if(!defined('WP_CLI')||!WP_CLI||is_multisite())throw new RuntimeException('Native single-site CLI only.');
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageCleanup;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
require_once dirname( dirname( __DIR__ ) ).'/migrate.php';
global $wpdb;
$root=$wpdb;$engine=isset($GLOBALS['foogallery_migrate_engine_instance'])?$GLOBALS['foogallery_migrate_engine_instance']:null;
if(!current_user_can('manage_options'))throw new RuntimeException('Run with --user=<administrator>.');
$prefix='fgm_reset_'.substr(hash('sha256',__FILE__),0,12).'_';
$make=function()use($prefix){$db=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$db->set_prefix($prefix);return $db;};
$a=$make();$b=$make();$tables=array($prefix.'options',$prefix.'users');$created=array();$checks=array();
$check=function($yes,$label)use(&$checks){if(!$yes)throw new RuntimeException($label);$checks[]=$label;};
try {
 foreach($tables as$t){if($root->get_var($root->prepare('SHOW TABLES LIKE %s',$root->esc_like($t)))!==null)throw new RuntimeException('Owned prefix already exists.');}
 $check(false!==$a->query('CREATE TABLE `'.$tables[0].'` (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL, PRIMARY KEY(option_id), UNIQUE KEY option_name(option_name)) ENGINE=InnoDB'),'owned options provisioned');$created[]=$tables[0];
 $check(false!==$a->query('CREATE TABLE `'.$tables[1].'` (ID BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB'),'owned user enumeration provisioned');$created[]=$tables[1];
 unset($GLOBALS['foogallery_migrate_engine_instance']);$GLOBALS['wpdb']=$a;$gate=StorageMaintenanceGate::current();
 $check(true===$gate->begin_writer(),'actual writer admitted with connection lock');
 $row=$a->get_row($a->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s',$tables[0],$a->esc_like(StorageMaintenanceGate::WRITER_PREFIX).'%'),ARRAY_A);
 $check(is_array($row),'actual writer marker persisted');$raw=json_decode($row['option_value'],true);$raw['expires']=time()-600;$expired=wp_json_encode($raw);
 $check(1===$a->query($a->prepare('UPDATE %i SET option_value=%s WHERE option_name=%s',$tables[0],$expired,$row['option_name'])),'writer lease expired for liveness regression');
 $ref=new ReflectionClass($gate);$ref->getProperty('writer_raw')->setValue($gate,$expired);
 $a->query($a->prepare("INSERT INTO %i(option_name,option_value,autoload)VALUES(%s,%s,'no'),(%s,%s,'no')",$tables[0],FOOGALLERY_MIGRATE_OPTION_DATA,serialize(array('history'=>'owned')),FOOGALLERY_MIGRATE_OPTION_SETTINGS,serialize(array('images_per_turn'=>7))));
 $GLOBALS['wpdb']=$b;$nonce=wp_create_nonce('foogallery_migrate_storage_clear_'.get_current_blog_id());$request=array('blog_id'=>get_current_blog_id(),'scope'=>'site','target'=>'state','confirmation'=>'clear-current-state','nonce'=>$nonce);
 $r=StorageCleanup::dispatch('clear',$request);$check(is_array($r)&&!$r['complete'],'second connection cannot reset an expired but connected writer');
 $check($b->get_var($b->prepare('SELECT option_value FROM %i WHERE option_name=%s',$tables[0],FOOGALLERY_MIGRATE_OPTION_DATA))===serialize(array('history'=>'owned')),'refused reset keeps history');
 $check($b->get_var($b->prepare('SELECT option_value FROM %i WHERE option_name=%s',$tables[0],$row['option_name']))===$expired,'refused reset keeps writer marker');
 $a->close();$r=StorageCleanup::dispatch('clear',$request);$check(is_array($r)&&$r['complete'],'reset succeeds after writer connection closes');
 $check($b->get_var($b->prepare('SELECT option_value FROM %i WHERE option_name=%s',$tables[0],$row['option_name']))===null,'exact expired marker removed');
 $check($b->get_var($b->prepare('SELECT option_value FROM %i WHERE option_name=%s',$tables[0],FOOGALLERY_MIGRATE_OPTION_DATA))===serialize(array()),'history cleared');
 $check($b->get_var($b->prepare('SELECT option_value FROM %i WHERE option_name=%s',$tables[0],FOOGALLERY_MIGRATE_OPTION_SETTINGS))===serialize(array('images_per_turn'=>7)),'preferences retained');
 $GLOBALS['wpdb']=$a;$check(is_wp_error($gate->assert_writer()),'disconnected original writer cannot resume');$GLOBALS['wpdb']=$b;
 $r=StorageCleanup::dispatch('clear',$request);$check(is_array($r)&&$r['complete'],'reset retry is idempotent');
 echo wp_json_encode(array('pass'=>true,'checks'=>$checks,'check_count'=>count($checks),'database'=>$b->get_var('SELECT VERSION()'),'scope'=>'isolated owned tables; real pinned SQL and separate connections'),JSON_PRETTY_PRINT)."\n";
} finally {
 $GLOBALS['wpdb']=$root;if(null!==$engine)$GLOBALS['foogallery_migrate_engine_instance']=$engine;
 foreach(array_reverse($created)as$t){if(false===$root->query('DROP TABLE `'.$t.'`')||null!==$root->get_var($root->prepare('SHOW TABLES LIKE %s',$root->esc_like($t))))throw new RuntimeException('Native fixture cleanup failed.');}
 $b->close();wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
}
