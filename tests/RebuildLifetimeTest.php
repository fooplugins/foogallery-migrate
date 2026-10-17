<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

class RebuildLifetimeTest extends TestCase {
 private function refused($call) {
  try { $call(); } catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); return; }
  $this->fail('Expected scoped refusal.');
 }
 private function budget(&$used) { return StorageScaleAdmissionTest::admission(null,null,function()use(&$used){return array('128M',$used);}); }
 public function test_completed_indexes_consume_only_their_planned_growth_and_resample(): void {
  $used=1; $b=$this->budget($used); $owner=new \stdClass(); $s=$b->open_retaining_phase($owner,0);
  $b->plan_retaining_phase($s,$owner,20,70,10,2,30);
  $b->admit_additional(134217728-33554432-$used-100);
  $this->refused(function()use($b,$s,$owner){$b->realize_retaining_event($s,$owner,0,10);});
  $this->refused(function()use($b,$s,$owner){$b->close_retaining_phase($s,$owner);});
  $b->complete_retaining_indexes($s,$owner);
  $b->admit_additional(30);
  $this->refused(function()use($b){$b->admit_memory(1);});
  $this->refused(function()use($b,$s,$owner){$b->complete_retaining_indexes($s,$owner);});
  $b->realize_retaining_event($s,$owner,0,10); $b->realize_retaining_event($s,$owner,1,10);
  $b->close_retaining_phase($s,$owner); $b->admit_additional(60);
  $this->refused(function()use($b){$b->admit_memory(1);}); // external 10 remains.
 }
 public function test_wrong_domain_owner_unplanned_and_interrupted_indexes_cannot_release(): void {
  $used=1; $b=$this->budget($used); $other=$this->budget($used); $owner=new \stdClass(); $wrong=new \stdClass();
  $s=$b->open_retaining_phase($owner,0);
  $this->refused(function()use($b,$s,$owner){$b->complete_retaining_indexes($s,$owner);});
  $this->refused(function()use($b,$s,$owner){$b->plan_retaining_phase($s,$owner,0,10,0,0,11);});
  $this->refused(function()use($b,$s,$owner){$b->plan_retaining_phase($s,$owner,0,10,0,0,PHP_INT_MAX);});
  $b->plan_retaining_phase($s,$owner,20,70,10,1,30);
  $this->refused(function()use($other,$s,$owner){$other->complete_retaining_indexes($s,$owner);});
  $this->refused(function()use($b,$s,$wrong){$b->complete_retaining_indexes($s,$wrong);});
  $t=$b->open_retaining_phase($wrong,10); $b->plan_retaining_phase($t,$wrong,20,10,0,1);
  $b->admit_additional(134217728-33554432-$used-130);
  $this->refused(function()use($b){$b->admit_memory(1);}); // construction interrupted: no completion event.
  $b->realize_retaining_event($t,$wrong,0,20); $b->close_retaining_phase($t,$wrong);
  $b->admit_additional(30);
  $this->refused(function()use($b){$b->admit_memory(1);}); // other producer cannot release indexes.
  $used=32;
  $this->refused(function()use($b,$s,$owner){$b->complete_retaining_indexes($s,$owner);}); // current live indexes still sampled.
  $this->refused(function()use($b,$s,$owner){$b->complete_retaining_indexes($s,$owner);});
 }
 public function test_deferred_hydration_is_added_without_releasing_workspace_or_other_scopes(): void {
  $used=1; $b=$this->budget($used); $owner=new \stdClass(); $other=new \stdClass();
  $s=$b->open_retaining_phase($owner,0); $t=$b->open_retaining_phase($other,10);
  $b->plan_retaining_phase($s,$owner,20,40,10,1,30,50);
  $b->plan_retaining_phase($t,$other,20,10,0,1);
  $b->admit_additional(134217728-33554432-$used-100);
  $this->refused(function()use($b,$s,$owner){$b->complete_retaining_indexes($s,$owner);}); // deferred growth needs 20 more.
  $b->realize_retaining_event($t,$other,0,20); $b->close_retaining_phase($t,$other);
  $b->admit_additional(10); // now exactly enough for the transition, not a blanket reset.
  $b->complete_retaining_indexes($s,$owner);
  $this->refused(function()use($b){$b->admit_memory(1);});
  $this->refused(function()use($b,$s,$owner){$b->complete_retaining_indexes($s,$owner);});
  $b->realize_retaining_event($s,$owner,0,20); $b->close_retaining_phase($s,$owner);
  $b->admit_additional(80);
  $this->refused(function()use($b){$b->admit_memory(1);});
 }
 public function test_sorted_bucket_layout_reused_index_and_retained_exports_have_exact_parity(): void {
  $b=StorageScaleAdmissionTest::admission();
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $tree=array('title'=>'root','children'=>array('z'=>array('children'=>array('last'=>'L','first'=>'F')),'a'=>'A'));
  $rows=array(); $nested=array();
  $c->flatten_tree('galleries','k',$tree,0,function($row)use(&$rows,&$nested,$c){
   if(!$rows) {$c->flatten_tree('galleries','nested',array('children'=>array('n'=>'value')),0,function($r)use(&$nested){$nested[]=$r;});}
   $rows[]=$row;
  });
  $root=$rows[0]['node_hash']; $reordered=array();
  foreach(array_reverse($rows) as $i=>$row){$reordered['caller-position-'.$i]=$row;}
  $before=serialize($reordered); $export=$reordered;
  $result=$c->rebuild_tree('galleries',$root,$reordered);
  $this->assertSame(serialize($tree),serialize($result));
  $this->assertSame($before,serialize($reordered)); $this->assertSame($before,serialize($export));
  $this->assertSame(array('children'=>array('n'=>'value')),$c->rebuild_tree('galleries',$nested[0]['node_hash'],$nested));
  $result['children']['z']['children']['last']='changed';
  $this->assertSame($before,serialize($export));
  $this->assertSame(serialize($tree),serialize($c->rebuild_tree('galleries',$root,$reordered)));
 }
 public function test_interrupted_index_construction_keeps_all_uncompleted_growth_pending(): void {
  $b=StorageScaleAdmissionTest::admission();
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $state=new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1048576,64,10000,$b);
  if(!Model::supports($c,$state,$b)){$this->markTestSkipped('Finite retaining host unsupported.');}
  $rows=array();$c->flatten_tree('galleries','k',array('children'=>array('one'=>'v')),0,function($r)use(&$rows){$rows[]=$r;});
  $rows[1]['parent_node_hash']=str_repeat('f',64);
  $this->refused(function()use($c,$rows){$c->rebuild_tree('galleries',$rows[0]['node_hash'],$rows);});
  $phases=(new \ReflectionProperty(Admission::class,'retaining_phases'))->getValue($b);
  $this->assertCount(1,$phases);$s=reset($phases);
  $this->assertGreaterThan(0,$s['indexes']); $this->assertSame(0,$s['next']);
  $this->assertGreaterThan(0,$s['future']);
 }
 public function test_small_string_forecast_and_untightened_size_class_boundary(): void {
  foreach(array_merge(range(0,4096),array(65537,393217,1048576)) as $n) {
   $s=str_repeat('x',$n); $bound=Model::string_bytes($n);
   $this->assertSame(64+$bound,Model::own_record_bytes($s,false,0));
   memory_reset_peak_usage(); $before=memory_get_usage(false);
   $copy=substr('!'.$s,1); $growth=memory_get_usage(false)-$before;
   $this->assertLessThanOrEqual($bound,$growth);
   $this->assertSame($s,$copy); unset($copy);
   if($n<232) {
    $codec=new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(); $payload=$codec->encode($s);
    $before=memory_get_usage(false); $decoded=$codec->decode($payload); $growth=memory_get_usage(false)-$before;
    $this->assertLessThanOrEqual($bound,$growth); $this->assertSame($s,$decoded); unset($decoded);
   }
  }
 }
 public function test_finite_private_index_resize_and_presort_allocations_fit_the_bound(): void {
  foreach(array(array(9,false),array(1025,false),array(3073,true),array(20208,false),array(20208,true)) as $case) {
   list($n,$chain)=$case; $rows=array();
   for($i=0;$i<$n;++$i){$rows[]=array('hash'=>hash('sha256',(string)$i),'parent'=>$i?($chain?$rows[$i-1]['hash']:$rows[0]['hash']):null,'order'=>$chain?0:$i-1);}
   $parents=$chain?$n-1:1; $width=$chain?1:$n-1;
   $siblings=$parents*Model::container($width);
   $index=Model::container($n)+Model::container($parents)+$siblings;
   $bound=$index+max(Model::container($n),Model::container($parents),Model::container($width));
   $nodes=array();$children=array(); memory_reset_peak_usage();
   $before=memory_get_usage(false);$true_before=memory_get_usage(true);
   foreach($rows as $i=>$row){$nodes[$row['hash']]=$i;}
   foreach($rows as $i=>$row){if(null!==$row['parent']){$children[$row['parent']][$row['order']]=$i;}}
   foreach($children as &$bucket){$order=0;$ordered=true;foreach($bucket as $key=>$v){if($key!==$order++){$ordered=false;break;}}if(!$ordered){ksort($bucket,SORT_NUMERIC);}}unset($bucket);
   $live_growth=memory_get_peak_usage(false)-$before; $true_growth=memory_get_peak_usage(true)-$true_before;
   $this->assertLessThanOrEqual($bound,$live_growth);
   $this->assertLessThanOrEqual($bound+2097152,$true_growth);
   foreach($nodes as $hash=>$position){$nodes[$hash]=null;}
   $this->assertCount($n,$nodes);$this->assertSame($n-1,array_sum(array_map('count',$children)));
   unset($nodes,$children,$rows);
  }
 }
}
