<?php
// PHP 5.4-compatible production uninstall loadgraph with isolated WP doubles.
// No Composer, plugin bootstrap, SQL, real site or filesystem deletion.
$root=isset($argv[1]) ? realpath($argv[1]) : false;
$mode=isset($argv[2]) ? $argv[2] : '';
if (!$root || !in_array($mode,array('success','failed-delete','thrown-delete'),true)) { throw new RuntimeException('Invalid probe'); }
define('ABSPATH',$root.'/'); define('WP_UNINSTALL_PLUGIN','foogallery-migrate/migrate.php');
$current=77; $stack=array(); $deleted=array();
$sites=array();
foreach(array(1,7) as $site) {
    $sites[$site]=array('foogallery-migrate-data'=>array('migrated'=>array('preserved-until-uninstall')),
        'foogallery-migrate-settings'=>array('page_size'=>20),'foogallery_migrate_discovery_summary'=>array('images'=>100),
        'unrelated'=>'keep','foogallery-migrate-store-schema-version'=>'unreleased prototype: ignore');
}
function plugin_dir_path($file) { return dirname($file).'/'; }
function is_multisite() { return true; }
function get_sites($args) { return array(1,7); }
function switch_to_blog($site) { global $current,$stack; $stack[]=$current; $current=$site; }
function restore_current_blog() { global $current,$stack; $current=array_pop($stack); }
function delete_option($name) {
    global $mode,$current,$sites,$deleted;
    $deleted[]=array($current,$name);
    if ($current===1 && $name==='foogallery-migrate-data') {
        if ($mode==='thrown-delete') { throw new RuntimeException('Owned test failure'); }
        if ($mode==='failed-delete') { return false; }
    }
    unset($sites[$current][$name]); return true;
}
function get_option($name,$default=false) { global $current,$sites; return array_key_exists($name,$sites[$current]) ? $sites[$current][$name] : $default; }
function esc_html__($message,$domain) { return htmlspecialchars($message,ENT_QUOTES,'UTF-8'); }
function wp_die($message) { throw new LogicException($message); }
class RetirementNoDatabase {
    public function __call($name,$args) { throw new RuntimeException('Unexpected database call: '.$name); }
}
$wpdb=new RetirementNoDatabase(); $error=null;
try { require $root.'/uninstall.php'; } catch (LogicException $caught) { $error=$caught->getMessage(); }
if ($current!==77 || $stack) { throw new RuntimeException('Blog context was not restored'); }
if (($mode==='success') !== ($error===null)) { throw new RuntimeException('Uninstall refusal mismatch'); }
foreach($sites as $options) {
    if ($options['unrelated']!=='keep' || $options['foogallery-migrate-store-schema-version']!=='unreleased prototype: ignore') { throw new RuntimeException('Unowned data changed'); }
    if ($mode==='success' && count($options)!==2) { throw new RuntimeException('Current plugin options remain'); }
}
if (class_exists('FooPlugins\\FooGalleryMigrate\\MigratedStore',false)) { throw new RuntimeException('Retired class loaded'); }
echo json_encode(array('status'=>'pass','mode'=>$mode,'scope'=>'production entrypoint with isolated WordPress doubles'))."\n";
