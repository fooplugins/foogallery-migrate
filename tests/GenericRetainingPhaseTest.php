<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

class GenericRetainingPhaseTest extends TestCase {
 private function refused($call) { try {$call();} catch (\RuntimeException $e) {$this->assertNotEmpty($e->getMessage());return;} $this->fail('Expected scoped refusal.'); }
 private function budget(&$used) {return StorageScaleAdmissionTest::admission(null,null,function()use(&$used){return array('128M',$used);});}
 public function test_exact_event_realization_not_sample_credit_and_external_liability_survives_close(): void {
  $used=1;$b=$this->budget($used);$owner=new \stdClass();
  $s=$b->open_retaining_phase($owner,10);
  $b->plan_retaining_phase($s,$owner,20,10,30,2);
  $b->admit_additional(100663235); // 1 + reserve + 60 + legacy = limit.
  $this->assertTrue($b->admit_memory(0));
  $used=2;$this->refused(function()use($b){$b->admit_memory(0);});$used=1;
  $b->realize_retaining_event($s,$owner,0,10);
  $b->admit_additional(10);
  $this->refused(function()use($b,$s,$owner){$b->realize_retaining_event($s,$owner,0,10);});
  $this->refused(function()use($b,$s,$owner){$b->close_retaining_phase($s,$owner);});
  $b->realize_retaining_event($s,$owner,1,10);
  $b->close_retaining_phase($s,$owner);
  $b->admit_additional(20); // only own future + destroyed private released, NOT external 30.
  $this->refused(function()use($b){$b->admit_memory(1);});
  $this->refused(function()use($b,$s,$owner){$b->close_retaining_phase($s,$owner);});
 }
 public function test_wrong_owner_wrong_domain_interruption_nested_legacy_and_overflow(): void {
  $used=1;$b=$this->budget($used);$other=$this->budget($used);$owner=new \stdClass();$wrong=new \stdClass();
  $s=$b->open_retaining_phase($owner,100663295);
  $this->refused(function()use($other,$s,$owner){$other->plan_retaining_phase($s,$owner,0,1,0,0);});
  $this->refused(function()use($b,$s,$wrong){$b->plan_retaining_phase($s,$wrong,0,1,0,0);});
  $this->refused(function()use($b){$b->admit_memory(1);});
  $this->refused(function()use($b){$b->decoder(6)->decode('a:0:{}');});
  $this->refused(function()use($b,$owner){$b->open_retaining_phase($owner,1);});
  $this->refused(function()use($b,$s,$owner){$b->plan_retaining_phase($s,$owner,PHP_INT_MAX,PHP_INT_MAX,0,1);});
  $this->refused(function()use($b){$b->admit_memory(1);}); // failed planning cannot clear interrupted liability.
 }
 public function test_only_own_completed_forecast_and_bounded_events(): void {
  $used=1;$b=$this->budget($used);$owner=new \stdClass();$s=$b->open_retaining_phase($owner,1);
  $b->plan_retaining_phase($s,$owner,3,1,0,2);
  $this->refused(function()use($b,$s,$owner){$b->realize_retaining_event($s,$owner,1,1);});
  $this->refused(function()use($b,$s,$owner){$b->realize_retaining_event($s,$owner,0,4);});
  $b->realize_retaining_event($s,$owner,0,1);
  $this->refused(function()use($b,$s,$owner){$b->realize_retaining_event($s,$owner,1,1);});
  $b->realize_retaining_event($s,$owner,1,2);$b->close_retaining_phase($s,$owner);
  $this->assertTrue($b->admit_memory(0));
 }
 public function test_other_scope_remains_pending_when_one_producer_completes(): void {
  $used=1; $b=$this->budget($used); $a=new \stdClass(); $other=new \stdClass();
  $s=$b->open_retaining_phase($a,1); $t=$b->open_retaining_phase($other,1);
  $b->plan_retaining_phase($s,$a,20,30,10,2);
  $b->plan_retaining_phase($t,$other,20,20,0,1);
  $b->admit_additional(134217728-33554432-$used-100);
  $this->refused(function()use($b){$b->admit_memory(1);});
  $b->realize_retaining_event($s,$a,0,10);
  $b->realize_retaining_event($s,$a,1,10); $b->close_retaining_phase($s,$a);
  $b->admit_additional(50); // A's future/private only; external 10 and B remain charged.
  $this->refused(function()use($b){$b->admit_memory(1);});
  $b->realize_retaining_event($t,$other,0,20); $b->close_retaining_phase($t,$other);
  $b->admit_additional(40);
  $this->refused(function()use($b){$b->admit_memory(1);});
 }
 public function test_sample_reduction_does_not_realize_an_event(): void {
  $used=2; $b=$this->budget($used); $owner=new \stdClass();
  $s=$b->open_retaining_phase($owner,1); $b->plan_retaining_phase($s,$owner,20,10,0,1);
  $b->admit_additional(134217728-33554432-$used-30);
  $used=1; $b->admit_additional(1);
  $this->refused(function()use($b){$b->admit_memory(1);});
  $this->refused(function()use($b,$s,$owner){$b->close_retaining_phase($s,$owner);});
 }
 public function test_capability_and_event_counters_are_finite(): void {
  $used=1; $b=$this->budget($used); $owner=new \stdClass(); $tokens=array();
  for($i=0;$i<64;++$i){$tokens[]=$b->open_retaining_phase($owner,0);}
  $this->refused(function()use($b,$owner){$b->open_retaining_phase($owner,0);});
  foreach($tokens as $s){$b->plan_retaining_phase($s,$owner,0,0,0,0);$b->close_retaining_phase($s,$owner);}
  $s=$b->open_retaining_phase($owner,0);
  $this->refused(function()use($b,$s,$owner){$b->plan_retaining_phase($s,$owner,0,0,0,24577);});
  $this->refused(function()use($b,$s,$owner){$b->plan_retaining_phase($s,$owner,0,-1,0,0);});
  $b->plan_retaining_phase($s,$owner,0,0,0,0); $b->close_retaining_phase($s,$owner);
  for($i=65;$i<1024;++$i){$s=$b->open_retaining_phase($owner,0);$b->plan_retaining_phase($s,$owner,0,0,0,0);$b->close_retaining_phase($s,$owner);}
  $this->refused(function()use($b,$owner){$b->open_retaining_phase($owner,0);});
 }
 public function test_original_shared_mid_preflight_deadline_remains_before_emission(): void {
  $value=StorageA7Fixture::value(20000); $tree=$value['galleries']['items']['gallery-009'];
  $now=100.0; $calls=0; $emitted=0;
  $clock=function()use(&$now,&$calls){if(++$calls>40){$now=111.0;}return $now;};
  $b=StorageScaleAdmissionTest::admission(null,$clock);
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $this->refused(function()use($c,$tree,&$emitted){$c->flatten_tree('galleries','gallery-009',$tree,0,function()use(&$emitted){++$emitted;});});
  $this->assertSame(0,$emitted); $this->assertGreaterThan(40,$calls);
 }
 public function test_interrupted_callback_keeps_remaining_scope_and_existing_legacy_pending(): void {
  $b=StorageScaleAdmissionTest::admission();
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $codec=new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1048576,64,10000,$b);
  if(!\FooPlugins\FooGalleryMigrate\GenericRetainingModel::supports($c,$codec,$b)){$this->markTestSkipped('Finite native retaining profile unsupported.');}
  $b->admit_additional(100);
  $retained=array();
  $this->refused(function()use($b,$c,&$retained){
   $c->flatten_tree('galleries','k',array('children'=>array('one'=>'x','two'=>'y')),0,function($row)use($b,&$retained){
    $retained[]=$row; $b->admit_additional(50); throw new \RuntimeException('Caller interruption.');
   });
  });
  $pending=new \ReflectionProperty(Admission::class,'retaining_pending');
  $phases=new \ReflectionProperty(Admission::class,'retaining_phases');
  $legacy=new \ReflectionProperty(Admission::class,'additional_bytes');
  $this->assertSame(1,count($retained)); $this->assertSame(1,count($phases->getValue($b)));
  $this->assertGreaterThan(0,$pending->getValue($b)); $this->assertSame(150,$legacy->getValue($b));
 }
 public function test_declared_topology_is_authenticated_before_child_bucket_growth(): void {
  $b=StorageScaleAdmissionTest::admission();
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $codec=new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1048576,64,10000,$b);
  if(!\FooPlugins\FooGalleryMigrate\GenericRetainingModel::supports($c,$codec,$b)){$this->markTestSkipped('Finite native retaining profile unsupported.');}
  $rows=array(); $tree=array('children'=>array('one'=>'x','two'=>'y'));
  $c->flatten_tree('galleries','k',$tree,0,function($row)use(&$rows){$rows[]=$row;});
  $reordered=array_reverse($rows);
  $this->assertSame(serialize($tree),serialize($c->rebuild_tree('galleries',$rows[0]['node_hash'],$reordered)));
  $base=$codec->decode_record_payload($rows[0]['payload']); $base['child_count']=0;
  $rows[0]['payload']=$codec->encode_record_payload($base);
  $rows[0]['payload_hash']=hash('sha256',$rows[0]['payload']);
  try{$c->rebuild_tree('galleries',$rows[0]['node_hash'],$rows);$this->fail('Untrusted child count admitted growth.');}
  catch(\RuntimeException $e){$this->assertStringContainsString('Child bucket exceeds declared bound',$e->getMessage());}
 }
 public function test_unqualified_codec_sampler_and_encoder_keep_conservative_seam(): void {
  $used=1; $b=$this->budget($used);
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $codec=new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1048576,64,10000,$b);
  $this->assertFalse(\FooPlugins\FooGalleryMigrate\GenericRetainingModel::supports($c,$codec,$b));
  $b=StorageScaleAdmissionTest::admission();
  $c=new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec(null,24576,16777216,$b);
  $custom=new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1024,64,10000,$b);
  $this->assertFalse(\FooPlugins\FooGalleryMigrate\GenericRetainingModel::supports($c,$custom,$b));
 }
}

