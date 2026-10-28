# Effective allocator observation (production admission prerequisite)

`StorageCapacityPolicy::facts()` observes the engine's effective Zend memory
manager state, not `getenv('USE_ZEND_ALLOC')`. PHP reads that variable during
memory-manager startup; changing it later does not switch the heap.

The observer calls the native internal `phpinfo(INFO_GENERAL)` and consumes only
its single exact `Zend Memory Manager` row. In PHP 5.4.0 and PHP 8.5.0,
`ext/standard/info.c` renders this row from `is_zend_mm()`. In PHP 5.4.0,
`Zend/zend_alloc.c` implements that predicate from the active heap's
`use_zend_alloc` flag. This also avoids inferring identity from memory counters,
which can be zero/unavailable and do not prove a particular allocator.

- `zend`: the engine reports Zend MM enabled.
- `system`: the engine reports Zend MM disabled. This existing eligibility label
  means the non-Zend allocation domain, NOT proof of libc, jemalloc, native
  extension allocator identity, or a complete process/native allocation bound.
  Custom engine allocation handlers can also disable Zend MM. Exact release
  runtime/profile qualification remains independently required.
- `null`: missing/disabled/non-internal observation or buffering functions,
  unsupported format, duplicate rows, capture failure, or output over 64 KiB.
  Strict eligibility matching refuses unknown observations.

Both native CLI text and web-SAPI HTML rows are recognized. A private nested
output buffer discards every chunk and retains at most 64 KiB for parsing. It
never sends phpinfo to the response, logs or caller buffers. No environment,
request, filter, user constant, or injected observation can approve facts. No
process-global startup environment is changed by shipping code, and no stale
mode cache is used. Instrumentation restrictions remain unchanged. Native
extensions replacing PHP internals or changing heaps are not universally proven
safe by this observer.

Budget semantics are unchanged: `memory_get_usage(false/true)` measures Zend
live/allocated bytes; it does not include all native allocations. With Zend MM
disabled those counters may both be zero and PHP's memory_limit does not enforce
a native/process ceiling. `StorageCapacityBudget` separately observes Linux RSS
and ru_maxrss, takes a conservative high-water maximum (not RSS minus Zend), and
adds owned Zend AND native forecasts/reserve under the finite policy ceiling.
These observations/forecasts are not kernel enforcement or a never-OOM promise.
Non-Linux budget observation and unsupported runtime profiles still refuse.

This change does not qualify numeric coefficients, activate a profile or approve
a release class. Shipping `storage-capacity-policy.json` intentionally retains
`classes: []`; public production conversion still refuses until separate
methodology/profile receiving. Source/package observer tests are prerequisites,
not production-conversion acceptance.

Engine references (immutable tags):
https://github.com/php/php-src/blob/php-5.4.0/ext/standard/info.c
https://github.com/php/php-src/blob/php-5.4.0/Zend/zend_alloc.c
https://github.com/php/php-src/blob/php-8.5.0/ext/standard/info.c
https://github.com/php/php-src/blob/php-8.5.0/Zend/zend_alloc.c
