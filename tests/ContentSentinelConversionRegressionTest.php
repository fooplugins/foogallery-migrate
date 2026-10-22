<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StorageConversion;
require_once __DIR__ . '/StorageByteDomainAccountingTest.php';
require_once __DIR__ . '/ContentIndependentRecordRegressionTest.php';

/** Public coordinator host stop AFTER snapshot preflight, not DB acceptance. */
class ContentSentinelConversionRegressionTest extends StorageByteDomainAccountingTest {
    public function test_real_selected_reset_publishes_empty_map_before_replacement(): void {
        $db=new SentinelRoundtripConnection(); $GLOBALS['wpdb']=$db; $admitted=array();
        $backend=new ReplacementSelectedBackend($db,7,10000,16777216,10,function() use (&$admitted) {
            $admitted[]=get_post(71)->post_content; return true;
        });
        $repository=(new \FooPlugins\FooGalleryMigrate\MigratorSettings($backend))->content_scan_repository(); $engine=new ContentRegressionEngine($repository);
        $GLOBALS['foogallery_migrate_test_posts'][71]=(object)array('ID'=>71,'post_title'=>'Occurrence','post_type'=>'post','post_status'=>'publish','post_content'=>'[old]');
        $migrator=new \FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator($engine,'block-shortcode');
        $result=$migrator->replace_content(array(0));
        $this->assertSame(1,$result['success']); $this->assertSame(array('[old]'),$admitted);
        $this->assertSame('[new]',get_post(71)->post_content); $this->assertSame(array(),$engine->writes);
        $this->assertSame('map',$db->head['state_kind']); $this->assertSame(0,$db->head['record_count']);
        $state=(new \FooPlugins\FooGalleryMigrate\LegacyTableSnapshot($db,7))->read_bounded(ContentScanRepository::STATE_KEY);
        $this->assertSame(array('items'=>array(),'progress'=>array()),$state);
        $windows=array_values(array_filter($db->queries,function($sql) { return strpos($sql,' AS window_bytes FROM `wp_foogallery_migrate_legacy_records`')!==false; }));
        $this->assertNotEmpty($windows,'The empty map must be read through the real keyset window.');
        $window=end($windows);
        foreach (array(
            str_replace('blog_id = 7','blog_id = 8',$window),
            str_replace('LIMIT 32','LIMIT 31',$window),
            'SELECT id FROM `wp_foogallery_migrate_legacy_records` ORDER BY id ASC',
        ) as $unsupported) {
            try { $db->get_results($unsupported,ARRAY_A); $this->fail('The sentinel SQL double accepted an unsupported or wrong-scope query.'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('Unsupported sentinel host rows',$error->getMessage()); }
        }
        $db->head['record_count']=1;
        try { $db->get_results($window,ARRAY_A); $this->fail('The zero-row sentinel read accepted a nonempty head.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Unsupported sentinel host rows',$error->getMessage()); }
    }
    public function test_real_backend_admission_refuses_before_replacement(): void {
        $db=new SentinelRoundtripConnection(); $GLOBALS['wpdb']=$db;
        $backend=new ReplacementSelectedBackend($db,7,10000,16777216,10,function() { return false; });
        $engine=new ContentRegressionEngine((new \FooPlugins\FooGalleryMigrate\MigratorSettings($backend))->content_scan_repository());
        $GLOBALS['foogallery_migrate_test_posts'][71]=(object)array('ID'=>71,'post_title'=>'Occurrence','post_type'=>'post','post_status'=>'publish','post_content'=>'[old]');
        try { (new \FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator($engine,'block-shortcode'))->replace_content(array(0)); $this->fail('Admission must refuse.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('admission failed',$error->getMessage()); }
        $this->assertSame('[old]',get_post(71)->post_content); $this->assertNull($db->head); $this->assertSame(array(),$engine->writes);
    }
    public function test_false_source_budget_refusal_retains_authority_and_source(): void {
        $db=(new \ReflectionProperty(StorageByteDomainAccountingTest::class,'db'))->getValue($this);
        $db->raw=serialize(array(ContentScanRepository::STATE_KEY=>false));
        $control=$db->control; $raw=$db->raw;
        $result=(new StorageConversion(array('max_raw_bytes'=>strlen($raw)-1,'amplification'=>1,'reserve_bytes'=>1)))->recover(
            array('blog_id'=>7,'scope'=>'site','target'=>'table','confirmation'=>'recover-storage','nonce'=>'valid'));
        $this->assertTrue(is_wp_error($result)); $this->assertSame(0,$db->writes);
        $this->assertSame($raw,$db->raw); $this->assertSame($control,$db->control); $this->assertFalse($db->source_payload_fetched);
    }
    public function test_public_coordinator_sentinel_forward_and_reverse_roundtrip(): void {
        $db = new SentinelRoundtripConnection();
        $value = array(ContentScanRepository::STATE_KEY=>false);
        $db->options[FOOGALLERY_MIGRATE_OPTION_DATA] = serialize($value);
        $record = array('version'=>1,'blog_id'=>7,'backend'=>'option','epoch'=>0,
            'switch_id'=>str_repeat('a',32),'from'=>'option','to'=>'table','phase'=>'copying',
            'table_id'=>str_repeat('a',32),'source_digest'=>hash('sha256','present:'.hash('sha256',serialize($value))),
            'logical_digest'=>(new LegacyStateCodec())->snapshot_digest($value),'schema'=>1,'codec'=>1,'created'=>1,'updated'=>1);
        $db->options[\FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION]=json_encode($record);
        $gate = new SentinelRoundtripGate($db); $GLOBALS['wpdb']=$db;
        (new \ReflectionProperty(\FooPlugins\FooGalleryMigrate\StorageMaintenanceGate::class,'instances'))->setValue(null,array(spl_object_hash($db).':7:wp_'=>$gate));
        $policy=array('max_raw_bytes'=>4096,'amplification'=>1,'reserve_bytes'=>1);
        $request=array('blog_id'=>7,'scope'=>'site','target'=>'table','confirmation'=>'recover-storage','nonce'=>'valid');
        $result=(new StorageConversion($policy))->recover($request);
        $this->assertFalse(is_wp_error($result),is_wp_error($result)?$result->get_error_message().' '.json_encode(array_slice($db->queries,-6)):'');
        $this->assertSame('stable',$result['phase']); $this->assertSame('table',$result['backend']);
        $this->assertArrayNotHasKey(FOOGALLERY_MIGRATE_OPTION_DATA,$db->options);
        $repository=new \FooPlugins\FooGalleryMigrate\LegacyTableRepository($db,7);
        $this->assertSame(array('found'=>true,'value'=>false),$repository->read_content_state());
        // Scalar remains a scalar: bounded collection access still requires reset.
        try { $repository->content_window(0,1); $this->fail('Scalar collection access must fail closed.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Legacy scalar',$error->getMessage()); }
        $record=$result; $record['from']='table'; $record['to']='option'; $record['phase']='copying';
        $record['source_digest']=$db->physical();
        $db->options[\FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION]=json_encode($record);
        $request['target']='option'; $result=(new StorageConversion($policy))->recover($request);
        $this->assertFalse(is_wp_error($result),is_wp_error($result)?$result->get_error_message().' '.json_encode(array_slice($db->queries,-6)):'');
        $this->assertSame('stable',$result['phase']); $this->assertSame('option',$result['backend']);
        $this->assertSame(serialize($value),$db->options[FOOGALLERY_MIGRATE_OPTION_DATA]);
        $this->assertArrayNotHasKey(\FooPlugins\FooGalleryMigrate\StorageAuthority::AUTHORITY_OPTION,$db->options);
        $this->assertContains('COMMIT',$db->queries);
    }
    public function test_public_coordinator_preserves_false_through_forward_snapshot_preflight(): void {
        $db = (new \ReflectionProperty(StorageByteDomainAccountingTest::class,'db'))->getValue($this);
        $value = array(ContentScanRepository::STATE_KEY => false);
        $db->raw = serialize($value); $record = json_decode($db->control,true);
        $record['source_digest'] = hash('sha256','present:'.hash('sha256',$db->raw));
        $record['logical_digest'] = (new LegacyStateCodec())->snapshot_digest($value);
        $db->control = json_encode($record);
        $result = (new StorageConversion(array('max_raw_bytes'=>strlen($db->raw),'amplification'=>1,'reserve_bytes'=>1)))->recover(
            array('blog_id'=>7,'scope'=>'site','target'=>'table','confirmation'=>'recover-storage','nonce'=>'valid'));
        $this->assertSame('foogallery_migrate_storage_recovery_required',$result->get_error_code());
        $this->assertTrue($db->post_preflight_stop, 'false must reach the intentional host stop AFTER snapshot preflight');
        $this->assertSame(0,$db->writes); $this->assertSame(serialize($value),$db->raw);
    }
}

class ReplacementSelectedBackend extends \FooPlugins\FooGalleryMigrate\LegacyTableBackend {
    public function content_selected($keys) {
        if (array(0)!==$keys) { throw new \RuntimeException('Unsupported sentinel selected keys.'); }
        return $this->content_window(0,1,array(0));
    }
    public function content_window($offset,$limit,$keys=null) {
        return array('items'=>array(0=>array('post_id'=>71,'post_title'=>'Occurrence','post_type'=>'post','plugin_name'=>'Example',
            'type'=>'shortcode','gallery_id'=>'9','migrated_foogallery_id'=>0,'original_content'=>'[old]','replacement_content'=>'[new]','match_offset'=>0)),
            'progress'=>array(),'total'=>1,'revision'=>1);
    }
}
/** Finite one-state SQL host double. NOT MySQL/guard/concurrency evidence. */
class SentinelRoundtripGate extends \FooPlugins\FooGalleryMigrate\StorageMaintenanceGate {
    private $db;
    public function __construct($db) { $this->db=$db; }
    public function connection() { return $this->db; }
    public function acquire_maintenance() { return true; }
    public function renew_maintenance() { return true; }
    public function maintenance_ready($verdict) { return true; }
    public function release_maintenance() { return true; }
    public function maintenance_identity() { return json_encode(array('version'=>1,'blog_id'=>7,'token'=>str_repeat('b',32),'expires'=>time()+600)); }
}
class SentinelRoundtripConnection extends CoordinatorSourceConnection {
    public $options=array(); public $head=null; public $insert_id=0; private $bindings=array();
    public function prepare($sql,$args=null) {
        $args=is_array($args)?$args:array_slice(func_get_args(),1);
        $prepared=parent::prepare($sql,$args); $this->bindings[$prepared]=$args; return $prepared;
    }
    public function physical() { return hash('sha256',json_encode(array($this->summary()))); }
    private function summary() {
        $h=$this->head; return array('id'=>$h['id'],'key_value'=>$h['state_key_value'],'state_order'=>$h['state_order'],
            'state_kind'=>$h['state_kind'],'active_generation'=>$h['active_generation'],'revision'=>$h['revision'],
            'record_count'=>$h['record_count'],'payload_bytes'=>$h['payload_bytes'],'digest_status'=>$h['digest_status']);
    }
    public function get_var($sql,$x=0,$y=0) {
        $this->queries[]=$sql;
        if (strpos($sql,'MAX(state_order)')!==false) { return '0'; }
        if (strpos($sql,'@@SESSION.in_transaction')!==false) { return '0'; }
        if (strpos($sql,'@@SESSION.max_allowed_packet')!==false) { return '16777216'; }
        foreach ($this->bindings as $query=>$args) {
            if ($sql!==$query || strpos($sql,'options`')===false) { continue; }
            $name=$args[0]; $raw=isset($this->options[$name])?$this->options[$name]:null;
            if (strpos($sql,'SELECT 1 AS present')===0) { return null===$raw?null:'1'; }
            if (strpos($sql,'LENGTH(option_value)')!==false && strpos($sql,'CONCAT')===false) { return null===$raw?null:(string)strlen($raw); }
            if (strpos($sql,'SHA2(option_value')!==false) { return null===$raw?null:hash('sha256',$raw); }
            if (strpos($sql,'CONCAT')!==false) { return null===$raw?null:'v:'.$raw; }
            if (strpos($sql,'SELECT option_value')===0) { return $raw; }
            if (strpos($sql,'SELECT COUNT(*)')===0) { return null===$raw?'0':'1'; }
        }
        throw new \RuntimeException('Unsupported sentinel host scalar: '.$sql);
    }
    public function get_results($sql,$format=null) {
        $this->queries[]=$sql;
        if (strpos($sql,'SHOW ')===0 || strpos($sql,'SELECT @@SESSION.autocommit')===0 || strpos($sql,'FROM information_schema.TABLES')!==false) { return parent::get_results($sql,$format); }
        if (strpos($sql,'MAX(state_order)')!==false) { return array(array('last_order'=>null)); }
        if (strpos($sql,'SELECT 1 AS present FROM `wp_options`')===0) { $args=$this->bindings[$sql]; return isset($this->options[$args[0]])?array(array('present'=>1)):array(); }
        if (strpos($sql,' AS window_bytes FROM `wp_foogallery_migrate_legacy_records`')!==false) {
            $head=$this->head;
            if (!is_array($head) || 0!==$head['record_count']) { throw new \RuntimeException('Unsupported sentinel host rows: '.$sql); }
            // A new head has active_generation=0 until the empty generation 1 is committed.
            $generation=0===$head['active_generation'] ? 1 : $head['active_generation'];
            $args=array(7,$head['id'],$generation,0,0,0,32);
            $template='SELECT id, item_order, (OCTET_LENGTH(payload) + OCTET_LENGTH(mutable_payload) + OCTET_LENGTH(key_value) + '
                .'COALESCE(OCTET_LENGTH(legacy_identifier_value),0) + COALESCE(OCTET_LENGTH(source_plugin_value),0) + '
                .'COALESCE(OCTET_LENGTH(source_id_value),0)) AS window_bytes FROM `wp_foogallery_migrate_legacy_records` '
                .'WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL '
                .'AND (item_order > %d OR (item_order = %d AND id > %d)) ORDER BY item_order ASC, id ASC LIMIT %d';
            if (!isset($this->bindings[$sql]) || $this->bindings[$sql]!==$args || $sql!==parent::prepare($template,$args)) {
                throw new \RuntimeException('Unsupported sentinel host rows: '.$sql);
            }
            return array();
        }
        if (strpos($sql,'COUNT(*) AS record_count')!==false) { return array(array('record_count'=>'0','payload_bytes'=>'0','encoded_bytes'=>'0')); }
        if (strpos($sql,' AS key_value, state_order')!==false) { return null===$this->head?array():array($this->summary()); }
        if (strpos($sql,'SELECT root_payload')===0) { return array(array('root_payload'=>$this->head['root_payload'])); }
        if (strpos($sql,'HEX(snapshot_digest) AS digest')!==false) { return array(array('digest'=>$this->head['snapshot_digest'],'digest_revision'=>$this->head['digest_revision'],'digest_status'=>$this->head['digest_status'])); }
        if (strpos($sql,'_legacy_state`')!==false) {
            if (null===$this->head) { return array(); }
            $head=$this->head; $head['root_payload_bytes']=strlen($head['root_payload']); $head['snapshot_digest']=strtoupper($head['snapshot_digest']); return array($head);
        }
        throw new \RuntimeException('Unsupported sentinel host rows: '.$sql);
    }
    public function query($sql) {
        $this->queries[]=$sql;
        if (in_array($sql,array('START TRANSACTION','COMMIT','ROLLBACK','SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'),true)) { return 0; }
        $args=null;
        foreach ($this->bindings as $query=>$values) { if (strpos($sql,$query)===0 && (null===$args || strlen($query)>$length)) { $args=$values; $length=strlen($query); } }
        if (strpos($sql,'INSERT INTO `wp_foogallery_migrate_legacy_state`')===0) {
            $this->head=array('id'=>++$this->insert_id,'blog_id'=>$args[0],'state_key_hash'=>$args[1],'state_key_value'=>$args[2],
                'state_order'=>$args[3],'state_kind'=>$args[4],'active_generation'=>$args[5],'revision'=>$args[6],'codec_version'=>$args[7],
                'digest_status'=>$args[11],'root_payload'=>$args[12],'snapshot_digest'=>'','record_count'=>0,'payload_bytes'=>0,'digest_revision'=>0); return 1;
        }
        if (strpos($sql,'UPDATE `wp_foogallery_migrate_legacy_state` SET active_generation')===0) {
            foreach (array('active_generation','revision','state_order','state_kind','codec_version','record_count','payload_bytes','snapshot_digest','digest_revision','root_payload') as $i=>$key) { $this->head[$key]=$args[$i]; } $this->head['digest_status']='current'; return 1;
        }
        if (strpos($sql,'INSERT INTO `wp_options`')===0) { if (isset($this->options[$args[0]])) { return 0; } $this->options[$args[0]]=$args[1]; return 1; }
        if (strpos($sql,'UPDATE `wp_options`')===0) { if (($this->options[$args[1]]??null)!==$args[2]) { return 0; } $this->options[$args[1]]=$args[0]; return 1; }
        if (strpos($sql,'DELETE FROM `wp_options`')===0) { if (($this->options[$args[0]]??null)!==$args[1]) { return 0; } unset($this->options[$args[0]]); return 1; }
        throw new \RuntimeException('Unsupported sentinel host write: '.$sql);
    }
}
