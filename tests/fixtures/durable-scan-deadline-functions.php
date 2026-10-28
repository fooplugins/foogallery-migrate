<?php
// TEST ONLY: loaded in isolated PHPUnit children, never by production bootstrap.
namespace FooPlugins\FooGalleryMigrate;
function hash($algorithm, $data, $binary = false) {
    $digest = \hash($algorithm, $data, $binary);
    $probe = \FooPlugins\FooGalleryMigrate\Tests\DurableScanDeadlineProbe::$active;
    if (null !== $probe && 'sha256' === $algorithm && $data === $probe->source) {
        $probe->php_hash_bytes += strlen($data);
        $probe->delay('hash'); // Delay AFTER the real whole-value hash, not instead of it.
    }
    return $digest;
}
function microtime($as_float = false) {
    $probe = \FooPlugins\FooGalleryMigrate\Tests\DurableScanDeadlineProbe::$active;
    if (null !== $probe) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        if (isset($trace[1]['class'], $trace[1]['function']) &&
            __NAMESPACE__ . '\\LegacyOptionStructuralScan' === $trace[1]['class'] && 'step' === $trace[1]['function']) {
            ++$probe->parser_clock_calls;
        }
    }
    return \microtime($as_float); // Real clock only; no caller-supplied production bypass.
}
