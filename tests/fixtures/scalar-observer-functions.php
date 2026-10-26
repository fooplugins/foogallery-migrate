<?php
// Isolated deterministic UNIT doubles; no database, runtime or calibration evidence.
namespace FooPlugins\FooGalleryMigrate\Tests;
function time() { return 1700000000; }
namespace FooPlugins\FooGalleryMigrate;
function time() { return 1700000000; }
function wp_generate_password($length = 12, $special = true, $extra = false) { return str_repeat('s', $length); }
function microtime($float = false) { return $GLOBALS['scalar_clock']; }
function scalar_test_phases() {
    $p = new \ReflectionProperty(__NAMESPACE__ . '\\StorageScalarObserver', 'stack');
    return array_column($p->getValue(), 'phase');
}
function hash($algorithm, $data, $binary = false) {
    if (isset($GLOBALS['scalar_source']) && $data === $GLOBALS['scalar_source']) {
        ++$GLOBALS['scalar_hashes']; $GLOBALS['scalar_hash_stack'] = scalar_test_phases();
        if (!empty($GLOBALS['scalar_hash_throw'])) { throw new \RuntimeException('synthetic hash exception'); }
    }
    return \hash($algorithm, $data, $binary);
}
function mysqli_query($handle, $sql) {
    ++$GLOBALS['scalar_queries'];
    $GLOBALS['scalar_query_stack'] = scalar_test_phases();
    if (!empty($GLOBALS['scalar_throw'])) { throw new \RuntimeException('synthetic transport exception'); }
    return empty($GLOBALS['scalar_false']);
}
