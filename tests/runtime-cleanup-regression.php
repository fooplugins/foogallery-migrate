<?php
// Standalone PHP 5.4-compatible cleanup contract; no PHPUnit/WordPress/DB claim.
$root = isset($argv[1]) ? $argv[1] : dirname(__DIR__);
define('ABSPATH', $root . '/');
define('ARRAY_A', 'ARRAY_A');
foreach (array('storage-authority', 'legacy-transaction-connection', 'legacy-state-codec', 'legacy-record-codec', 'table-reader', 'legacy-storage-schema', 'scalar-state-repository', 'content-scan-repository', 'image-tag-queue-repository', 'legacy-table-repository') as $file) {
    require_once $root . '/includes/class-' . $file . '.php';
}
class CleanupContractConnection extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
    public $base_prefix = 'wp_';
    public $last_error = '';
    public $failure;
    public $nested;
    public $queries = array();
    public function __construct() {}
    public function prepare($query, $args) { return $query; }
    public function get_results($sql, $format) {
        $this->queries[] = $sql;
        if ($this->nested) { call_user_func($this->nested); }
        if ($this->failure) { throw $this->failure; }
        return array(); // Missing heads only, never fabricated state/transaction success.
    }
}
$checks = 0;
function check_cleanup($value, $label) {
    global $checks;
    if (!$value) { throw new RuntimeException($label); }
    $checks++;
}
function active_cleanup($repo) {
    $flag = new ReflectionProperty('FooPlugins\\FooGalleryMigrate\\LegacyTableRepository', 'history_read_active');
    if (PHP_VERSION_ID < 80100) { $flag->setAccessible(true); }
    return $flag->getValue($repo);
}
$calls = array(
    'history_read' => function($r) { return $r->history_read('count'); },
    'history_mutate' => function($r) { return $r->history_mutate('clear', array()); },
    'mutate_scalars' => function($r) { return $r->mutate_scalars(array('checkpoint' => 1)); },
    'content_scan_state' => function($r) { return $r->content_scan_state(); },
    'change_content_scan' => function($r) { return $r->change_content_scan(function($v) { return false; }); },
    'change_image_tag_queue' => function($r) { return $r->change_image_tag_queue(function($v) { return false; }); },
    'read_scalars' => function($r) { return $r->read_scalars(array('checkpoint')); },
    'read_collection' => function($r) { return $r->read_collection('galleries'); },
    'replace_collection' => function($r) { return $r->replace_collection('galleries', array()); },
    'update_collection_record' => function($r) { return $r->update_collection_record('galleries', array('root' => 0, 'path' => array()), array()); }
);
$failures = array(new RuntimeException('sentinel'));
if (class_exists('Error')) { $failures[] = new Error('error-sentinel'); $failures[] = new TypeError('type-sentinel'); }
foreach ($failures as $failure) {
    foreach ($calls as $name => $call) {
        $db = new CleanupContractConnection(); $db->failure = $failure;
        $repo = new \FooPlugins\FooGalleryMigrate\LegacyTableRepository($db, 7);
        try { $call($repo); throw new RuntimeException('Expected failure: ' . $name); }
        catch (Exception $error) { check_cleanup($error === $failure, $name . ' exception identity'); }
        catch (Throwable $error) { check_cleanup($error === $failure, $name . ' throwable identity'); }
        check_cleanup(!active_cleanup($repo), $name . ' failure reset');
        $db->failure = null;
        check_cleanup($repo->history_mutate('upsert', array()) === 0, $name . ' retry after failure');
        check_cleanup(!active_cleanup($repo), $name . ' early-return reset');
    }
}
$db = new CleanupContractConnection(); $repo = new \FooPlugins\FooGalleryMigrate\LegacyTableRepository($db, 7);
check_cleanup($repo->read_scalars(array()) === array(), 'empty scalar success');
check_cleanup(!active_cleanup($repo), 'empty scalar reset');
check_cleanup($repo->mutate_scalars(array()) === true, 'empty scalar mutation');
check_cleanup(!active_cleanup($repo), 'empty scalar mutation reset');
check_cleanup($repo->update_collection_record('galleries', array('root' => 0, 'path' => array()), array()) === false, 'missing collection early return');
check_cleanup(!active_cleanup($repo), 'missing collection reset');
check_cleanup($repo->change_image_tag_queue(function($v) { return false; }) === false, 'callback false');
check_cleanup(!active_cleanup($repo), 'callback false reset');
foreach ($failures as $failure) {
    try {
        $repo->change_image_tag_queue(function($v) use ($repo, $failure) {
            check_cleanup(active_cleanup($repo), 'flag active inside callback');
            try { $repo->read_scalars(array()); throw new LogicException('Reentrant scalar read admitted'); }
            catch (RuntimeException $error) { check_cleanup(strpos($error->getMessage(), 'Invalid scalar read keys') !== false, 'callback read refused'); }
            check_cleanup(active_cleanup($repo), 'callback refusal preserves outer guard');
            throw $failure;
        });
        throw new LogicException('Expected callback failure');
    }
    catch (Exception $error) { check_cleanup($error === $failure, 'callback exception identity'); }
    catch (Throwable $error) { check_cleanup($error === $failure, 'callback throwable identity'); }
    check_cleanup(!active_cleanup($repo), 'callback failure reset');
}
$db->failure = new RuntimeException('outer-sentinel');
$db->nested = function() use ($repo) {
    check_cleanup(active_cleanup($repo), 'flag active during SQL');
    try { $repo->history_mutate('upsert', array()); throw new LogicException('Reentrancy admitted'); }
    catch (RuntimeException $error) { check_cleanup(strpos($error->getMessage(), 'Unsupported history mutation') !== false, 'nested mutation refused'); }
    check_cleanup(active_cleanup($repo), 'nested refusal preserves outer flag');
};
try { $repo->history_read('count'); }
catch (RuntimeException $error) { check_cleanup($error === $db->failure, 'outer identity after reentrant refusal'); }
check_cleanup(!active_cleanup($repo), 'outer reset after reentrant refusal');
echo json_encode(array('php' => PHP_VERSION, 'checks' => $checks, 'status' => 'pass', 'scope' => 'real repository cleanup with fault-injection connection; not SQL/WP acceptance')) . "\n";
