<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
require_once __DIR__ . '/fixtures/durable-scan-db.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionDurableScan;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use PHPUnit\Framework\TestCase;

class LegacyOptionDurableScanTest extends TestCase {
    private $source;
    protected function setUp(): void {
        $this->source = serialize(array('gallery' => array_fill(0, 10000, null), 'album' => array_fill(0, 10000, false)));
        $GLOBALS['foogallery_migrate_test_blog_id'] = 1;
        unset($GLOBALS['foogallery_migrate_engine_instance']);
        $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => $this->source));
    }
    protected function tearDown(): void { unset($GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id']); }
    // Test-only arithmetic profile, NEVER production calibration approval.
    private function policy() { return array('max_raw_bytes' => 16777216, 'amplification' => 1, 'reserve_bytes' => 1); }
    private function fresh($rows = null) {
        $rows = null === $rows ? $GLOBALS['wpdb']->rows : $rows;
        $GLOBALS['wpdb'] = new DurableScanDb($rows);
        return $GLOBALS['wpdb'];
    }
    private function next($r, $bytes = 257, $nodes = 53) { return LegacyOptionDurableScan::advance($r['switch_id'], $r['generation'], $this->policy(), $bytes, $nodes, 1); }
    private function raw() { return $GLOBALS['wpdb']->rows[StorageAuthority::CONTROL_OPTION]; }
    private function refused($callback) {
        try { $callback(); $this->fail('Expected refusal'); }
        catch (\RuntimeException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
    public function test_twenty_k_checkpoint_resumes_through_fresh_owners(): void {
        $this->refused(function() { (new LegacyOptionDecoder())->decode($this->source); });
        $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1);
        $steps = 1; $bytes = $r['bytes']; $nodes = $r['nodes'];
        while (!$r['done']) {
            $db = $this->fresh(); $old = $r; $r = $this->next($r);
            $this->assertSame($old['generation'] + 1, $r['generation']);
            $this->assertGreaterThanOrEqual($old['offset'], $r['offset']);
            $this->assertLessThanOrEqual(257, $r['bytes']);
            $this->assertLessThanOrEqual(53, $r['nodes']);
            $this->assertSame(1, $db->raw_reads);
            $this->assertSame(strlen($this->source), $db->raw_bytes);
            $this->assertSame(strlen($this->source), $db->sha_bytes);
            $this->assertSame($this->source, $db->rows[FOOGALLERY_MIGRATE_OPTION_DATA]);
            $bytes += $r['bytes']; $nodes += $r['nodes'];
            $this->assertLessThan(5000, ++$steps);
        }
        $this->assertSame(strlen($this->source), $bytes);
        $this->assertSame(strlen($this->source), $r['offset']);
        $this->assertSame(40005, $nodes);
        $this->assertSame($nodes, $r['total_nodes']);
        $this->assertSame($r['generation'], LegacyOptionDurableScan::status($r['switch_id'])['generation']);
        $this->refused(function() use ($r) { $this->next($r); });
    }
    public function test_partially_consumed_binary_string_and_number_tokens_restore_exactly(): void {
        $raw = serialize(array("\xff\0key" => str_repeat("\xff\0", 150), 'integer' => -123456789, 'float' => 1.25));
        $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => $raw));
        $r = LegacyOptionDurableScan::start($this->policy(), 1, 1, 1); $phases = array(); $bytes = $r['bytes'];
        while (!$r['done']) {
            $stored = StorageControl::decode($this->raw(), 1);
            $phases[$stored['scan']['parser']['phase']] = true;
            $this->fresh(); $r = $this->next($r, 1, 1); $bytes += $r['bytes'];
        }
        $this->assertArrayHasKey('body', $phases);
        $this->assertArrayHasKey('token', $phases);
        $this->assertSame(strlen($raw), $bytes);
        $this->assertSame($raw, $GLOBALS['wpdb']->rows[FOOGALLERY_MIGRATE_OPTION_DATA]);
    }
    public function test_stale_foreign_tampered_and_changed_source_refuse_without_advancing(): void {
        $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1);
        $good = $GLOBALS['wpdb']->rows; $old = $this->raw();
        $this->refused(function() use ($r) { LegacyOptionDurableScan::advance(str_repeat('a', 32), $r['generation'], $this->policy()); });
        $this->assertSame($old, $this->raw());
        $next = $this->next($r);
        $current = $this->raw(); $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($current, $this->raw());
        $this->fresh($good); $record = json_decode($old, true); ++$record['scan']['parser']['offset'];
        $tampered = json_encode($record); $GLOBALS['wpdb']->rows[StorageAuthority::CONTROL_OPTION] = $tampered;
        $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($tampered, $this->raw());
        $this->fresh($good); $changed = substr_replace($this->source, 'X', 5, 1);
        $GLOBALS['wpdb']->rows[FOOGALLERY_MIGRATE_OPTION_DATA] = $changed;
        $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($old, $this->raw());
        $this->assertSame($changed, $GLOBALS['wpdb']->rows[FOOGALLERY_MIGRATE_OPTION_DATA]);
        $this->fresh($good); $GLOBALS['wpdb']->prefix = 'wp_foreign_';
        $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($old, $this->raw());
    }
    public function test_interruption_retry_and_uncertain_commit_are_read_back_not_replayed(): void {
        $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1); $old = $this->raw();
        $GLOBALS['wpdb']->fault = 'before';
        $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($old, $this->raw());
        $this->fresh(); $r = $this->next($r); $generation = $r['generation'];
        $GLOBALS['wpdb']->fault = 'after';
        $this->refused(function() use ($r) { $this->next($r); });
        $this->fresh(); $read = LegacyOptionDurableScan::status($r['switch_id']);
        $this->assertSame($generation + 1, $read['generation']);
        $current = $this->raw(); $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($current, $this->raw());
        $this->assertSame($generation + 2, $this->next($read)['generation']);
    }
    public function test_atomic_source_race_and_stale_cas_do_not_commit(): void {
        foreach (array('source', 'cas') as $fault) {
            $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => $this->source));
            $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1); $old = $this->raw();
            $GLOBALS['wpdb']->fault = $fault;
            $this->refused(function() use ($r) { $this->next($r); });
            $this->assertSame($old, $this->raw());
        }
    }
    public function test_scan_completion_is_not_runtime_or_conversion_readiness(): void {
        $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => 'a:0:{}'));
        $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1);
        $this->assertTrue($r['done']);
        $record = StorageControl::decode($this->raw(), 1); $old = $GLOBALS['wpdb']->rows;
        $this->assertFalse(StorageControl::runtime_ready($record, null));
        $this->assertNull($record['logical_digest']);
        $conversion = new \FooPlugins\FooGalleryMigrate\StorageConversion($this->policy());
        $p = new \ReflectionProperty($conversion, 'memory');
        $p->setValue($conversion, new \FooPlugins\FooGalleryMigrate\StorageMemoryBaseline());
        $this->refused(function() use ($conversion, $record) { (new \ReflectionMethod($conversion, 'advance'))->invoke($conversion, $record, null); });
        $this->assertSame($old, $GLOBALS['wpdb']->rows);
    }
    public function test_initial_source_race_and_foreign_blog_refuse(): void {
        $GLOBALS['wpdb']->fault = 'source';
        $this->refused(function() { LegacyOptionDurableScan::start($this->policy(), 257, 53, 1); });
        $this->assertArrayNotHasKey(StorageAuthority::CONTROL_OPTION, $GLOBALS['wpdb']->rows);
        $this->assertNotSame($this->source, $GLOBALS['wpdb']->rows[FOOGALLERY_MIGRATE_OPTION_DATA]);
        $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => $this->source));
        $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1); $old = $this->raw();
        $GLOBALS['foogallery_migrate_test_blog_id'] = 2;
        $this->refused(function() use ($r) { $this->next($r); });
        $this->assertSame($old, $this->raw());
    }
    public function test_deep_partial_capsule_stays_inside_existing_storage_limit(): void {
        $value = "\xff\0leaf";
        for ($i = 0; $i < 64; ++$i) { $value = array($value); }
        $raw = serialize($value); $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => $raw));
        $r = LegacyOptionDurableScan::start($this->policy(), 7, 3, 1);
        while (!$r['done']) {
            $this->assertLessThanOrEqual(StorageControl::MAX_BYTES, strlen($this->raw()));
            $this->fresh(); $r = $this->next($r, 7, 3);
        }
        $this->assertSame(strlen($raw), $r['offset']);
    }
    public function test_durable_receipts_refuse_non_idle_connections_before_any_owned_write(): void {
        foreach (array('autocommit', 'ambient_transaction', 'idle_query_failure') as $mode) {
            foreach (array('start', 'advance', 'status') as $entry) {
                $db = $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => $this->source));
                $r = 'start' === $entry ? null : LegacyOptionDurableScan::start($this->policy(), 257, 53, 1);
                $before = $db->rows; $reads = $db->raw_reads; $db->queries = array();
                $db->$mode = 'autocommit' === $mode ? '0' : true;
                $this->refused(function() use ($entry, $r) {
                    if ('start' === $entry) { LegacyOptionDurableScan::start($this->policy()); }
                    elseif ('advance' === $entry) { $this->next($r); }
                    else { LegacyOptionDurableScan::status($r['switch_id']); }
                });
                $this->assertSame($before, $db->rows);
                $this->assertSame($reads, $db->raw_reads);
                foreach ($db->queries as $sql) { $this->assertDoesNotMatchRegularExpression('/^(INSERT|UPDATE|DELETE|START TRANSACTION|COMMIT|ROLLBACK)/', $sql); }
            }
        }
    }
    public function test_step_and_acquisition_caps_are_not_enlarged(): void {
        $this->refused(function() { LegacyOptionDurableScan::start($this->policy(), 65537, 53, 1); });
        $this->assertArrayNotHasKey(StorageAuthority::CONTROL_OPTION, $GLOBALS['wpdb']->rows);
        $this->fresh(array(FOOGALLERY_MIGRATE_OPTION_DATA => str_repeat('x', 16777217)));
        $this->refused(function() { LegacyOptionDurableScan::start(array('max_raw_bytes' => 16777217, 'amplification' => 1, 'reserve_bytes' => 1)); });
        $this->assertSame(0, $GLOBALS['wpdb']->raw_reads);
    }
    public function test_unadmitted_source_and_existing_owned_state_are_preserved(): void {
        $db = $GLOBALS['wpdb'];
        $this->refused(function() { LegacyOptionDurableScan::start(null); });
        $this->assertSame(0, $db->raw_reads);
        $this->refused(function() { LegacyOptionDurableScan::start(array('max_raw_bytes' => 8, 'amplification' => 1, 'reserve_bytes' => 1)); });
        $this->assertSame(0, $db->raw_reads);
        $r = LegacyOptionDurableScan::start($this->policy(), 257, 53, 1); $old = $this->raw();
        $this->refused(function() { LegacyOptionDurableScan::start($this->policy()); });
        $this->assertSame($old, $this->raw());
    }
}
