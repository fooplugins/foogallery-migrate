<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

class CertifiedPreorderRebuildTest extends TestCase {
 private function subject($budget = null) {
  $budget = null === $budget ? StorageScaleAdmissionTest::admission() : $budget;
  return new LegacyRecordCodec(null,24576,16777216,$budget);
 }
 private function supported() {
  $b=StorageScaleAdmissionTest::admission(); $c=$this->subject($b);
  if (!Model::supports($c,new LegacyStateCodec(1048576,64,10000,$b),$b)) { $this->markTestSkipped('Original finite host unsupported; no synthetic qualification.'); }
 }
 private function rows($tree) {
  $rows=array(); (new LegacyRecordCodec())->flatten_tree('galleries','root',$tree,7,function($r)use(&$rows){$rows[]=$r;}); return $rows;
 }
 private function eligible($c,&$rows,$root) {
  $m=new \ReflectionMethod(LegacyRecordCodec::class,'certify_preorder'); if(PHP_VERSION_ID<80100){$m->setAccessible(true);}
  return $m->invokeArgs($c,array(&$rows,$root));
 }
 private function sparse($rows) {
  $out=array(); foreach($rows as $i=>$row){$out['position-'.$i]=$row;} return $out;
 }
 private function refused($call) {
  try {$call();} catch (\RuntimeException $e) {$this->assertNotEmpty($e->getMessage()); return;}
  $this->fail('Malformed rows accepted.');
 }
 public function test_dense_position_certificate_and_indexed_fallback_preserve_order_pointer_exports(): void {
  foreach(array(0,1,8,9,16,17) as $n) {
   $children=array();for($i=0;$i<$n;++$i){$children[$i%2?'key-'.$i:$i]=array('title'=>'v'.$i,'unknown'=>array(false,null,"\xff"));}
   $tree=array('children'=>$children,'title'=>'root');$rows=$this->rows($tree);$root=$rows[0]['node_hash'];
   $variants=array($rows,array_reverse($rows,true),array_reverse($rows),$this->sparse($rows));
   $negative=array();$mixed=array();$int_string=array();
   foreach($rows as $i=>$row){$negative[-1-$i]=$row;$mixed[$i?'x'.$i:0]=$row;$int_string['0'.$i]=$row;}
   $variants[]=$negative;$variants[]=$mixed;$variants[]=$int_string;
   foreach($variants as $v=>$input) {
    $c=$this->subject();$before=serialize($input);$export=$input;next($input);$pointer=key($input);
    // A single row reversed is still dense; PHP canonical numeric strings are
    // already integer keys, whereas "00" stays a string and must fall back.
    $expected=0===$v || (1===count($rows) && ($v===1 || $v===2 || $v===5));
    $this->assertSame($expected,$this->eligible($c,$input,$root),'variant '.$v.' n '.$n);
    $result=$c->rebuild_tree('galleries',$root,$input);
    $this->assertSame(serialize($tree),serialize($result));$this->assertSame($pointer,key($input));
    $this->assertSame($before,serialize($input));$this->assertSame($before,serialize($export));
    $result['children']['new']='changed';$this->assertSame($before,serialize($export));
   }
  }
  $empty=array();$this->refused(function()use($empty){$this->subject()->rebuild_tree('galleries',str_repeat('0',64),$empty);});
 }
 public function test_deep_nested_wide_and_database_scalars_match_fallback(): void {
  $tree=array('children'=>array('wide'=>array('children'=>array_fill(0,17,'v')),'empty'=>array('children'=>array()),'scalar'=>false));
  for($i=0;$i<20;++$i){$tree=array('children'=>array('next'=>$tree,'sibling'=>'s'));}
  $rows=$this->rows($tree);$root=$rows[0]['node_hash'];
  foreach($rows as &$r){foreach(array('depth','item_order','payload_bytes') as $f){$r[$f]=(string)$r[$f];}foreach(array('node_hash','parent_node_hash','logical_hash','payload_hash') as $f){if(null!==$r[$f]){$r[$f]=strtoupper($r[$f]);}}}unset($r);
  $this->assertTrue($this->eligible($this->subject(),$rows,$root));
  foreach(array($rows,$this->sparse(array_reverse($rows))) as $input){$this->assertSame(serialize($tree),serialize($this->subject()->rebuild_tree('galleries',$root,$input)));}
 }
 public function test_breadth_first_dense_input_is_not_preorder_and_is_not_rejected(): void {
  $tree=array('children'=>array('a'=>array('children'=>array('nested'=>'n')),'b'=>'b'));
  $rows=$this->rows($tree);$root=$rows[0]['node_hash'];$breadth=array($rows[0],$rows[1],$rows[3],$rows[2]);
  $this->assertFalse($this->eligible($this->subject(),$breadth,$root));
  $this->assertSame($tree,$this->subject()->rebuild_tree('galleries',$root,$breadth));
 }
 public function test_bad_topology_envelopes_keys_and_integrity_remain_refused_on_both_paths(): void {
  $original=$this->rows(array('children'=>array('a'=>'A','b'=>'B'),'title'=>'root'));$root=$original[0]['node_hash'];$s=new LegacyStateCodec();
  foreach(array('duplicate','orphan','cycle','missing','extra','unrelated-root','order','depth','checksum','digest','projection','coercible','duplicate-key','envelope','count','fields') as $fault) {
   $rows=$original;
   switch($fault) {
    case 'duplicate': $rows[]=$rows[1];break;
    case 'orphan': $rows[1]['parent_node_hash']=str_repeat('a',64);break;
    case 'cycle': $rows[0]['parent_node_hash']=$rows[1]['node_hash'];$rows[0]['row_kind']='child';break;
    case 'missing': array_pop($rows);break;
    case 'extra': $extended=$this->rows(array('children'=>array('a'=>'A','b'=>'B','c'=>'C'),'title'=>'root'));$rows[]=$extended[3];break;
    case 'unrelated-root': (new LegacyRecordCodec())->flatten_tree('galleries','other','orphan',0,function($r)use(&$rows){$rows[]=$r;});break;
    case 'order': $rows[2]['item_order']=0;break;
    case 'depth': $rows[1]['depth']=2;break;
    case 'checksum': $rows[2]['payload_hash']=str_repeat('0',64);break;
    case 'digest': $rows[1]['logical_hash']=str_repeat('0',64);break;
    case 'projection': $rows[1]['is_migrated']=1;break;
    case 'coercible': $rows[1]['key_type']='string';$rows[1]['key_value']=base64_encode('1');break;
    case 'duplicate-key': $rows[2]['key_value']=$rows[1]['key_value'];$rows[2]['node_hash']=$rows[1]['node_hash'];break;
    default:
     $base=$s->decode_record_payload($rows[0]['payload']);
     if('envelope'===$fault){$base['extra']=true;}else if('count'===$fault){$base['child_count']=1;}else{$base['field_order'][]='title';}
     $rows[0]['payload']=$s->encode_record_payload($base);$rows[0]['payload_hash']=hash('sha256',$rows[0]['payload']);$rows[0]['payload_bytes']=strlen($rows[0]['payload'])+strlen($rows[0]['mutable_payload']);
   }
   foreach(array($rows,$this->sparse($rows)) as $input){$before=serialize($input);$this->refused(function()use($root,$input){$this->subject()->rebuild_tree('galleries',$root,$input);});$this->assertSame($before,serialize($input));}
  }
 }
 public function test_plan_only_omits_absent_adjacency_and_explicitly_adds_metadata(): void {
  $rows=$this->rows(array('children'=>array_fill(0,17,'v')));$b=StorageScaleAdmissionTest::admission();$c=$this->subject($b);$s=new LegacyStateCodec(1048576,64,10000,$b);$d=$c->describe_rows($rows,'caller-rows-rebuild');
  $old=Model::rebuild_plan($rows,$d,$s,$b);$new=Model::rebuild_plan($rows,$d,$s,$b,true);
  foreach(array('future','external','events','after_indexes') as $f){$this->assertSame($old[$f],$new[$f]);}
  foreach(array('row_workspace','output_children_growth','visited_growth','sibling_sort_cow') as $f){$this->assertSame($old['parts'][$f],$new['parts'][$f]);}
  $this->assertSame(2*Model::container(count($rows)),$new['parts']['index_growth']);
  $this->assertSame(Model::preorder_metadata_bytes(),$new['parts']['preorder_metadata']);
  $this->assertSame($new['parts']['row_workspace']+$new['parts']['index_growth']+Model::preorder_metadata_bytes(),$new['index_stage']);
 }
 public function test_certifier_deadline_and_interruption_do_not_restart_domain(): void {
  $rows=$this->rows(array('children'=>array_fill(0,17,'v')));$root=$rows[0]['node_hash'];$now=100.0;$calls=0;
  $clock=function()use(&$now,&$calls){if(++$calls>10){$now=111.0;}return $now;};
  $b=StorageScaleAdmissionTest::admission(null,$clock);$c=$this->subject($b);
  $this->refused(function()use($c,&$rows,$root){$this->eligible($c,$rows,$root);});
  $this->refused(function()use($c,$rows,$root){$c->rebuild_tree('galleries',$root,$rows);});
  $this->assertGreaterThan(10,$calls);
 }
 /** @group optional_host_diagnostic */
 public function test_actual_private_index_allocation_savings_and_certifier_metadata_bound(): void {
  $this->supported();$rows=$this->rows(array('children'=>array_fill(0,4097,'v')));$root=$rows[0]['node_hash'];$sparse=$this->sparse($rows);
  $c=$this->subject();memory_reset_peak_usage();$before=memory_get_usage(false);
  $this->assertTrue($this->eligible($c,$rows,$root));
  $certifier=memory_get_peak_usage(false)-$before;$this->assertLessThanOrEqual(Model::preorder_metadata_bytes(),$certifier);
  $peaks=array();foreach(array($rows,$sparse) as $input){$c=$this->subject();memory_reset_peak_usage();$before=memory_get_usage(false);$result=$c->rebuild_tree('galleries',$root,$input);$peaks[]=memory_get_peak_usage(false)-$before;$this->assertCount(4097,$result['children']);unset($result);}
  $this->assertLessThan($peaks[1],$peaks[0],'Actual live allocated bytes must fall; no source-byte credit.');
  if(getenv('FOOGM_PREORDER_MEASUREMENT')){file_put_contents(getenv('FOOGM_PREORDER_MEASUREMENT'),json_encode(array('dense_peak_live_delta'=>$peaks[0],'fallback_peak_live_delta'=>$peaks[1],'certifier_peak_live_delta'=>$certifier,'scope'=>'finite host case, not native-byte or total-memory theorem')));}
 }
}
