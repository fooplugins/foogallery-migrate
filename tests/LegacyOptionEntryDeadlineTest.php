<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
require_once __DIR__ . '/fixtures/durable-scan-db.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionDurableScan;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use PHPUnit\Framework\TestCase;

class DurableScanDeadlineProbe {
    public static $active = null;
    public $source;
    public $phase;
    public $php_hash_bytes = 0;
    public $parser_clock_calls = 0;
    public $delayed = false;
    public function delay($phase) {
        if ($phase === $this->phase && !$this->delayed) {
            $this->delayed = true;
            usleep(100000); // Twice the real entry budget; no fake production clock.
        }
    }
}
class EntryDeadlineDb extends DurableScanDb {
    public $probe;
    public $idle_checks = 0;
    public $checkpoint_queries = 0;
    public function prepare($sql, $args) {
        $prepared = parent::prepare($sql, $args);
        if (0 === strpos($sql, 'UPDATE') && false !== strpos($sql, 'SET option_value')) { $this->probe->delay('pre_sql'); }
        return $prepared;
    }
    public function get_var($sql) {
        $raw = parent::get_var($sql);
        if (0 === strpos($sql, "SELECT CONCAT('v:', option_value)")) { $this->probe->delay('acquisition'); }
        return $raw;
    }
    public function query($sql) {
        if ('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' === $sql) {
            ++$this->idle_checks;
            $this->probe->delay(1 === $this->idle_checks ? 'constructor' : (3 === $this->idle_checks ? 'pre_cas' : 'initial_ready'));
        }
        if (false !== strpos($sql, 'source_option')) { ++$this->checkpoint_queries; }
        return parent::query($sql);
    }
}
/**
 * SQL/storage DOUBLE ONLY, not native/integrated conversion or a hard runtime bound.
 * Namespace observation/delay functions exist ONLY in isolated test processes.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LegacyOptionEntryDeadlineTest extends TestCase {
    private $source;
    private $policy = array('max_raw_bytes' => 16777216, 'amplification' => 1, 'reserve_bytes' => 1);
    protected function setUp(): void {
        require_once __DIR__ . '/fixtures/durable-scan-deadline-functions.php';
        $galleries = array();
        for ($i = 1; $i <= 10001; ++$i) {
            $galleries[$i] = array('id' => $i, 'name' => 'Imported gallery ' . $i, 'attachments' => array($i, $i + 1), 'description' => 'Legacy gallery metadata');
        }
        $this->source = serialize(array('gallery' => $galleries, 'album' => array()));
        $GLOBALS['foogallery_migrate_test_blog_id'] = 1;
        unset($GLOBALS['foogallery_migrate_engine_instance']);
        $GLOBALS['wpdb'] = new DurableScanDb(array(FOOGALLERY_MIGRATE_OPTION_DATA => $this->source));
    }
    protected function tearDown(): void {
        DurableScanDeadlineProbe::$active = null;
        unset($GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id']);
    }
    public static function expiry_phases(): array {
        return array('acquisition' => array('acquisition'), 'hash' => array('hash'), 'constructor' => array('constructor'), 'pre_cas' => array('pre_cas'), 'pre_sql' => array('pre_sql'));
    }
    private function arm($phase) {
        $probe = new DurableScanDeadlineProbe(); $probe->phase = $phase; $probe->source = $this->source;
        $db = new EntryDeadlineDb($GLOBALS['wpdb']->rows); $db->probe = $probe;
        $GLOBALS['wpdb'] = $db; DurableScanDeadlineProbe::$active = $probe;
        return $db;
    }
    private function record($measurement) {
        $path = getenv('FOOGALLERY_MIGRATE_ENTRY_DEADLINE_RECEIPTS');
        if (false !== $path && '' !== $path) { file_put_contents($path, json_encode($measurement) . "\n", FILE_APPEND); }
    }
    /** @dataProvider expiry_phases */
    public function test_advance_expiry_is_fenced_from_entry($phase): void {
        $r = LegacyOptionDurableScan::start($this->policy, 257, 53, 1);
        $db = $this->arm($phase); $before = $db->rows; $probe = $db->probe;
        $result = null; $refused = false; $entry = microtime(true);
        try { $result = LegacyOptionDurableScan::advance($r['switch_id'], $r['generation'], $this->policy, 257, 53, 0.05); }
        catch (\RuntimeException $error) { $refused = true; $this->assertNotSame('', $error->getMessage()); }
        $elapsed = microtime(true) - $entry;
        $measurement = array('phase' => $phase, 'records' => 10001, 'source_bytes' => strlen($this->source),
            'entry_elapsed_seconds' => $elapsed, 'budget_seconds' => 0.05, 'delay_fired' => $probe->delayed,
            'acquisition_bytes' => $db->raw_bytes, 'php_hash_bytes' => $probe->php_hash_bytes, 'sql_sha_bytes' => $db->sha_bytes,
            'parser_entered' => $probe->parser_clock_calls > 0, 'returned_parser_bytes' => null === $result ? null : $result['bytes'],
            'committed_parser_bytes' => null === $result ? 0 : $result['bytes'], 'checkpoint_queries' => $db->checkpoint_queries,
            'refused' => $refused, 'rows_unchanged' => $before === $db->rows);
        $this->record($measurement);
        $this->assertTrue($probe->delayed);
        $this->assertGreaterThanOrEqual(0.05, $elapsed);
        $this->assertSame($this->source, $db->rows[FOOGALLERY_MIGRATE_OPTION_DATA]);
        $this->assertSame(0, $db->checkpoint_queries, 'No checkpoint/SQL-SHA CAS may begin after entry expiry');
        $this->assertSame(0, $db->sha_bytes);
        $this->assertSame($before, $db->rows, 'Exact source/control and transient-owner restoration');
        $this->assertTrue($refused, 'Expired advance must refuse, not renew the parser budget');
        $expected_acquisition = 'constructor' === $phase ? 0 : strlen($this->source);
        $this->assertSame($expected_acquisition, $db->raw_bytes);
        $this->assertSame(in_array($phase, array('hash', 'pre_cas', 'pre_sql'), true) ? strlen($this->source) : 0, $probe->php_hash_bytes);
        $this->assertSame(in_array($phase, array('pre_cas', 'pre_sql'), true), $probe->parser_clock_calls > 0);
    }
    public function test_realistic_source_makes_useful_progress_without_delay(): void {
        $r = LegacyOptionDurableScan::start($this->policy, 257, 53, 1);
        $db = $this->arm('none');
        $next = LegacyOptionDurableScan::advance($r['switch_id'], $r['generation'], $this->policy, 257, 53, 1);
        $this->record(array('phase' => 'normal', 'records' => 10001, 'source_bytes' => strlen($this->source),
            'acquisition_bytes' => $db->raw_bytes, 'php_hash_bytes' => $db->probe->php_hash_bytes,
            'sql_sha_bytes' => $db->sha_bytes, 'parser_bytes' => $next['bytes']));
        $this->assertSame($r['generation'] + 1, $next['generation']);
        $this->assertGreaterThan($r['offset'], $next['offset']);
        $this->assertSame(257, $next['bytes']);
        $this->assertSame(strlen($this->source), $db->raw_bytes);
        $this->assertSame(strlen($this->source), $db->probe->php_hash_bytes);
        $this->assertSame(strlen($this->source), $db->sha_bytes);
        $this->assertSame($this->source, $db->rows[FOOGALLERY_MIGRATE_OPTION_DATA]);
    }
}
