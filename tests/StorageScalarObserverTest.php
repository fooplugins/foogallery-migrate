<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
require_once __DIR__ . '/fixtures/durable-scan-db.php';
use FooPlugins\FooGalleryMigrate\StorageScalarObserver as Observer;
use FooPlugins\FooGalleryMigrate\LegacyOptionDurableScan as Scan;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use PHPUnit\Framework\TestCase;

class ScalarObserverDb extends DurableScanDb {
    public $expire = false;
    public $seen = array();
    public function get_var($sql) {
        if (0 === strpos($sql, 'SELECT LENGTH')) { $this->seen['length'] = \FooPlugins\FooGalleryMigrate\scalar_test_phases(); }
        if (0 === strpos($sql, "SELECT CONCAT('v:', option_value)")) { $this->seen['acquisition'] = \FooPlugins\FooGalleryMigrate\scalar_test_phases(); }
        $r = parent::get_var($sql);
        if ($this->expire && 0 === strpos($sql, "SELECT CONCAT('v:', option_value)")) { $GLOBALS['scalar_clock'] += 2; }
        return $r;
    }
    public function query($sql) {
        if (false !== strpos($sql, 'source_option')) { $this->seen['source_cas'] = \FooPlugins\FooGalleryMigrate\scalar_test_phases(); }
        return parent::query($sql);
    }
}
class ScalarPinnedDouble extends LegacyTransactionConnection {
    public function __construct() {
        $p = new \ReflectionProperty(LegacyTransactionConnection::class, 'handle');
        $p->setValue($this, (object) array('errno' => 0, 'insert_id' => 7, 'affected_rows' => 1));
    }
    public function is_current() { return true; }
}
class ScalarControlPinnedDouble extends ScalarPinnedDouble {
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public function prepare($sql, $args) { return $sql; }
    public function get_var($sql) { return 'v:new'; }
}
/**
 * Deterministic UNIT doubles only, no native SQL or calibration claim.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StorageScalarObserverTest extends TestCase {
    protected function setUp(): void {
        require_once __DIR__ . '/fixtures/scalar-observer-functions.php';
        $GLOBALS['foogallery_migrate_test_blog_id'] = 1;
        $this->reset(false);
    }
    private function state($name, $value = null, $write = false) {
        $p = new \ReflectionProperty(Observer::class, $name);
        if ($write) { $p->setValue(null, $value); }
        return $p->getValue();
    }
    private function reset($enabled) {
        foreach (array('enabled' => $enabled, 'valid' => true, 'records' => array(), 'stack' => array(), 'sequence' => 0, 'last_time' => 0.0) as $k => $v) { $this->state($k, $v, true); }
        $GLOBALS['scalar_clock'] = 1700000000.0;
        $GLOBALS['scalar_hashes'] = 0;
        $GLOBALS['scalar_queries'] = 0;
        $GLOBALS['scalar_throw'] = false;
        $GLOBALS['scalar_false'] = false;
        $GLOBALS['scalar_hash_throw'] = false;
    }
    private function policy() { return array('max_raw_bytes' => 16777216, 'amplification' => 1, 'reserve_bytes' => 1); }
    private function exercise($enabled, $fault = '', $advance = false, $source = null) {
        $this->reset($enabled);
        unset($GLOBALS['foogallery_migrate_engine_instance']);
        $GLOBALS['scalar_source'] = null === $source ? serialize(array('gallery' => array_fill(0, 100, null))) : $source;
        $db = new ScalarObserverDb(array(FOOGALLERY_MIGRATE_OPTION_DATA => $GLOBALS['scalar_source']));
        $GLOBALS['wpdb'] = $db;
        $result = null; $error = null;
        if ($advance) {
            $result = Scan::start($this->policy(), 7, 3, 1);
            $this->reset($enabled); $db->queries = array(); $db->raw_reads = 0; $db->sha_bytes = 0;
        }
        $db->fault = $fault; $db->expire = 'deadline' === $fault;
        $GLOBALS['scalar_hash_throw'] = 'hash_exception' === $fault;
        try {
            $result = $advance ? Scan::advance($result['switch_id'], $result['generation'], 'admission' === $fault ? null : $this->policy(), 7, 3, 1) : Scan::start('admission' === $fault ? null : $this->policy(), 7, 3, 1);
        } catch (\RuntimeException $e) { $error = array(get_class($e), $e->getMessage()); }
        return array($result, $error, $db->rows, $db->queries, $db->raw_reads, $db->sha_bytes, $GLOBALS['scalar_hashes']);
    }
    public function test_default_noop_and_scalar_bounded_retention(): void {
        $this->assertFalse($this->state('enabled'));
        $this->assertSame(0, Observer::begin(1)); Observer::end(0);
        $this->assertSame(array(), $this->state('records'));
        $this->reset(true);
        for ($i = 0; $i < 400; ++$i) { $id = Observer::begin(5, 6); Observer::end($id); }
        $this->assertFalse($this->state('valid'), 'Overflow invalidates evidence');
        $this->assertCount(256, $this->state('records'));
        foreach ($this->state('records') as $row) { foreach ($row as $v) { $this->assertTrue(is_int($v) || is_float($v)); } }
        $this->assertLessThanOrEqual(128, count($this->state('stack')));
    }
    public function test_backward_clock_and_bad_inputs_only_invalidate_evidence(): void {
        $this->reset(true); Observer::begin(1);
        $this->state('last_time', PHP_FLOAT_MAX, true);
        Observer::end(1); $this->assertFalse($this->state('valid'));
        $this->reset(true); Observer::begin(5, -1); $this->assertFalse($this->state('valid'));
        $this->reset(true); Observer::begin(new \stdClass()); $this->assertFalse($this->state('valid'));
        $this->reset(true); $id = Observer::begin(1); Observer::end($id, 99); $this->assertFalse($this->state('valid'));
        $this->reset(true); $this->state('sequence', PHP_INT_MAX, true);
        $this->assertSame(0, Observer::begin(1)); $this->assertFalse($this->state('valid'));
    }
    public function test_actual_wiring_and_exact_off_on_semantics(): void {
        foreach (array(false, true) as $advance) {
            foreach (array('', 'before', 'after', 'cas', 'source', 'deadline', 'admission', 'hash_exception') as $fault) {
                $off = $this->exercise(false, $fault, $advance);
                $on = $this->exercise(true, $fault, $advance);
                $this->assertSame($off, $on, 'Exact output/error/rows/query sequence/hash count/release/deadline: ' . $fault);
                $this->assertTrue($this->state('valid'));
                $this->assertSame(array(), $this->state('stack'), 'Return/exception closes every span');
                $rows = $this->state('records');
                $this->assertSame($advance ? 2 : 1, $rows[0]['phase']);
                $this->assertSame(2, $rows[count($rows)-1]['event']);
                if ('' !== $fault) { $this->assertNotNull($on[1]); }
                if ('' === $fault) {
                    $phases = array_column($rows, 'phase');
                    foreach (array(3,4,5,6,7,8,10) as $phase) { $this->assertContains($phase, $phases, 'Actual call missing phase ' . $phase); }
                    $this->assertSame(1, $on[6], 'Existing full-source PHP hash exactly once');
                    $this->assertSame(1, $on[4]);
                    $this->assertSame(array($advance ? 2 : 1, 5), $GLOBALS['scalar_hash_stack']);
                    $this->assertSame(array($advance ? 2 : 1, 3), $GLOBALS['wpdb']->seen['length']);
                    $this->assertSame(array($advance ? 2 : 1, 4), $GLOBALS['wpdb']->seen['acquisition']);
                    $this->assertSame(array($advance ? 2 : 1, 7), $GLOBALS['wpdb']->seen['source_cas']);
                    foreach ($rows as $row) {
                        if (5 === $row['phase'] && 1 === $row['event']) { $this->assertSame(strlen($GLOBALS['scalar_source']), $row['bytes']); }
                    }
                    $begins = array_values(array_filter($rows, function($r) { return 1 === $r['event']; }));
                    $this->assertLessThan(array_search(4, array_column($begins, 'phase')), array_search(3, array_column($begins, 'phase')));
                }
                if ('deadline' === $fault) { $this->assertSame(0, $on[5], 'Expired acquisition cannot enter source CAS'); }
                $this->assertArrayNotHasKey(StorageAuthority::MAINTENANCE_OPTION, $on[2]);
            }
        }
    }
    public function test_actual_mysqli_call_span_success_false_exception_and_readback_separation(): void {
        foreach (array('success', 'false', 'throw') as $mode) {
            $this->reset(true);
            $GLOBALS['scalar_false'] = 'false' === $mode; $GLOBALS['scalar_throw'] = 'throw' === $mode;
            $parent = Observer::begin(7);
            $db = new ScalarPinnedDouble(); $r = $db->query('UNIT DOUBLE ONLY');
            Observer::end($parent);
            $this->assertSame('success' === $mode ? 1 : false, $r);
            $this->assertSame(1, $GLOBALS['scalar_queries']);
            $this->assertSame(array(7, 9), $GLOBALS['scalar_query_stack'], 'Actual @mysqli_query executes INSIDE child span');
            $rows = $this->state('records');
            $this->assertSame(9, $rows[1]['phase']);
            $this->assertSame($parent, $rows[1]['parent']);
            $this->assertSame(2, $rows[2]['event']);
            $this->assertSame('success' === $mode ? 1 : ('false' === $mode ? 3 : 2), $rows[2]['outcome']);
            $this->assertSame(array(), $this->state('stack'));
        }
        $this->exercise(true);
        $rows = $this->state('records'); $casEnd = null; $readAfter = null;
        foreach ($rows as $i => $row) {
            if (7 === $row['phase'] && 2 === $row['event']) { $casEnd = $i; }
            if (null !== $casEnd && 8 === $row['phase'] && 1 === $row['event']) { $readAfter = $i; $this->assertSame(1, $row['parent']); }
        }
        $this->assertNotNull($casEnd); $this->assertNotNull($readAfter); $this->assertGreaterThan($casEnd, $readAfter);
    }
    public function test_composed_source_cas_parent_pinned_child_and_separate_readback(): void {
        $observed = array();
        foreach (array(false,true) as $on) {
            $this->reset($on);
            $db = new ScalarControlPinnedDouble();
            $controls = new StorageControl($db,1);
            $observed[] = array($controls->cas(StorageAuthority::CONTROL_OPTION,'old','new',array('kind'=>'option','value'=>str_repeat('a',64))), $GLOBALS['scalar_queries'], $db->last_error, $db->insert_id);
        }
        $this->assertSame($observed[0],$observed[1]);
        $this->assertSame(array(7,9),$GLOBALS['scalar_query_stack']);
        $rows = $this->state('records');
        $this->assertSame(array(7,9,9,7,8,8),array_column($rows,'phase'));
        $this->assertSame(array(0,1,1,0,0,0),array_column($rows,'parent'));
    }
    public function test_empty_completion_and_parser_exceptions_are_equivalent(): void {
        foreach (array('a:0:{}', 'invalid', 'a:1:{bad') as $source) {
            $off = $this->exercise(false, '', false, $source);
            $on = $this->exercise(true, '', false, $source);
            $this->assertSame($off, $on);
            $this->assertTrue($this->state('valid'));
            $this->assertSame(array(), $this->state('stack'));
            if ('a:0:{}' === $source) { $this->assertTrue($on[0]['done']); $this->assertSame(6, $on[0]['source_bytes_acquired']); }
            else { $this->assertNotNull($on[1]); }
            $this->assertArrayNotHasKey(StorageAuthority::MAINTENANCE_OPTION, $on[2]);
        }
    }
    public function test_overflow_and_backward_clock_do_not_change_operation(): void {
        foreach (array('overflow', 'backward') as $failure) {
            $off = $this->exercise(false);
            $this->exercise(true);
            if ('backward' === $failure) { $this->state('last_time', PHP_FLOAT_MAX, true); }
            else { for ($i=0; $i<400; ++$i) { $id=Observer::begin(5); Observer::end($id); } }
            $before = $GLOBALS['wpdb']->rows;
            $r = $off[0];
            $next = Scan::advance($r['switch_id'], $r['generation'], $this->policy(), 7, 3, 1);
            $this->assertSame(2, $next['generation']); $this->assertFalse($this->state('valid'));
            $this->assertNotSame($before, $GLOBALS['wpdb']->rows);
        }
    }
}
