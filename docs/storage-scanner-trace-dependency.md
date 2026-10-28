# Scanner retention checkpoint: precise trace dependency, not a headroom bound

This is the single stopping dependency allowed by OPS run464. It does NOT
complete the lexical scanner retained-arena charge or the complete exception
family. Public admission, all production PHP, accepted budgets, support floors,
backend authority, recovery, and v1 ownership are unchanged. The reviewed
six-method descriptors and LIVE-page bounds are reused, not reimplemented.

## Source scope and ordinary allocation sites

Helper SHA256 remains
`cf7ef2038a5395f70d64f9f2702c3735066a74048cb00dfddc0f97202b1777c3`.
Accepted offline context remains raw<=60000, nodes<=8003, arrays<=16, depth<=16;
depth64 is a separate rejection diagnostic, not the accepted envelope. Runtime
sources are from the TWO already retained original PHP5.4.0 and PHP5.6.20
archives, not a modern implementation of their VM. LP64/nondebug/cookies and
protection off/alignment8 are CONDITIONAL source assumptions, not a public
platform/runtime declaration. All inspected originals and extracted member
hashes are in the outside-FooProjects diagnostic `allocation-sources.json`.

Before the first fail/exception branch, helper:93-156 has these material sites:

- `$raw` passes by value unchanged; `$offset` and `$nodes` pass by reference.
  Raw payload backing is an existing alias on this ordinary lexical path.
  `$this`, constant strings, and referenced scalar boxes are not new raw copies.
  Lexical scanning skips string payload bytes; it does not substr the entire
  serialized string field or build a decoded graph.
- Byte index reads at95/145 allocate a zval and a two-byte backing buffer in
  Zend/zend_execute.c1251-1320 (5.4),1284-1351 (5.6). Scalar temporary zvals may
  also become heap boxes on VAR/CV assignment/copy; T slots themselves are
  already VM-frame storage. Do not charge a new whole VM frame per byte/node.
- `token`146 returns substr backing at most128+1 bytes. Its loop stops before a
 129-byte token can be returned; unsigned and integer caps are20/21 respectively.
  Literal155 copies at most two bytes plus NUL. ext/standard/string.c2210-2269
  (5.4),2240-2299 (5.6) uses RETURN_STRINGL(...,1), i.e. a backing copy, not an
  alias. The transient comparison substring and retained `$value` are separate
  sites/lifetimes. Key/field string content is skipped and is not a token copy.
- Integer casts/reconversion at106/138 have signed-LP64 string length <=20;
  the scalar/type delimiter concat at98 is two bytes. The reported23-byte
  request is a deliberately loose short-site inventory ceiling, NOT an arena
  coefficient. Zend/zend_operators.c concat uses safe_emalloc or safe_erealloc
  (5.6:1430-1483). Resize old/new overlap is an unresolved allocator frontier.
- PHP5.6 zend_execute.c1697-1699 allocates run_time_cache only if NULL and
  last_cache_slot is nonzero; the corresponding PHP5.4 execute entry handlers
  in zend_vm_execute.h test the same condition. The inherited SIX fixed method
  cache-slot ceilings give requests224/464/48/48/64/32, sum880; at most one
  block per op_array, not per recursive call or per node. The inherited1120
  inventory includes six40-byte pads. It is NOT a derived retained charge.
- `preg_match`106/110/138 calls use THREE fixed expressions, no matches output
  argument. PCRE driver offsets/compiled-regex cache/system allocator storage
  are distinct from method run_time_cache and must not be silently included in
 1120. php_pcre.c records offset safe_emalloc/free and regex cache paths. This
  checkpoint does NOT prove their complete packing/retention contribution.

The inherited344952 box/short-string PAYLOAD inventory atdepth16 remains an
inventory, not a trustworthy ealloc or retained-segment total. It grants32 per
CV/T/argument box and136 per T short backing; actual boxes, ephemeral strings,
small-block free caches, concat resize and fixed caches still need simultaneous
site/lifetime lowering. Neither344952 nor1120 is installed as a coefficient.

## The precise falsified alias premise

A completion that treats `$raw` as an alias in ALL scanner failure phases is
false on both retained sources. This is a concrete argument-copy dependency,
not a generic demand for arbitrary caller history:

1. Malformed input can reach helper:133/151 -> fail:160 -> new RuntimeException
   while recursive scanner ancestors and their arguments are still live.
2. Zend/zend_exceptions.c147-167 (5.4),155-175 (5.6) creates the exception trace
   with zend_fetch_debug_backtrace(trace, skip, 0, 0): options do NOT ignore args
   and the limit is zero. Construction precedes scanner-frame unwinding.
3. Zend/zend_builtin_functions.c2210-2314 (5.4),2238-2346 (5.6) builds frame
   arrays and captures arguments for FCALL frames. debug_backtrace_get_args at
   2006-2029/2027-2050 calls SEPARATE_ZVAL_TO_MAKE_IS_REF on non-object args.
4. Zend/zend.h756-777/779-800 separates a non-reference zval if refcount>1,
   allocating a new box and invoking zval_copy_ctor. zend_variables.c118-122/
   123-127 DUPLICATES noninterned string backing with estrndup_rel. It is not
   just a refcount increment of the original raw string.

A dynamically obtained noninterned serialized option shared by value into
scanner frames supplies the reachable precondition !is_ref && refcount>1.
Already-reference, sole-owner, and interned controls are different branches;
the executable witness checks them and does not claim every argument copies.
Referenced offset/nodes scalars do not justify applying the no-copy control to
raw. Native decoder execution is NOT required to reach this failure branch.

The precise unmet local premise is:

    new_backing_request <= 136 for each modeled short backing site

For just ONE shared noninterned raw argument capture at raw=60000, the actual
source request is60001, nominal Zend-MM true size60024 under the stated build
assumptions. Equality raw135 -> request136 and just-over raw136 -> request137
are executable controls. This does NOT assert one copy exceeds the WHOLE
344952 inventory, nor a precise total number of trace copies. It identifies a
missing raw-sized backing FAMILY that a trace capacity/count proof must cover.
The previous reviewed source explicitly already listed that family missing;
this is not a discovered regression in product admission or the reviewed caps.

## Connection to repeated page sites and the stopping inequality

Reviewed PHP5.4 transfer-time extra page and PHP5.6 reservation R/retained C tail
remain distinct. Free restores the parent top, so subsequent sibling helpers
can reacquire pages at the same residual. LIVE pages are not historical debt.
A freed VM request130968 makes a nominal130984-byte free block on BOTH branches.

If the allocator's large-block search selects an isolated former VM hole for
the60001-byte trace backing request, `_zend_mm_alloc_int` splits it into USED
60024 + FREE70960: zend_alloc.c2022-2040 (5.4),2026-2044 (5.6). The remaining
hole cannot fit a130984-byte VM block while the trace backing stays live. The
used raw backing prevents coalescing across it. Other eligible holes/rest
blocks can still serve the next request: this conditional arithmetic proves
neither actual helper placement nor additional segment acquisition. No
per-acquisition permanent segment or arbitrary exact caller history is charged.
Small-block cache hit/free paths and rest/resize branches are separate; their
presence cannot repair an omitted large raw argument-copy family.

The requested retained-capacity charge must establish, phase by phase:

    scanner-caused retained arena <= justified simultaneous ordinary material
      + fixed method and regex caches + TRACE ARGUMENT/CAPACITY material
      + justified free-cache/rest/resize frontiers + exceptional LIVE-page debt

The trace argument term is indispensable even in the decode-only malformed
lexical phase. Without it the ordinary-only charging inventory excludes a
reachable allocation that can consume a freed large hole. Stop here rather
than assuming this dependency discharged or silently expanding into the full
exception/HashTable/caller-frame/string-name/cleanup capacity proof. The witness
returns arena_retention_proven=false and fits_justified_helper_budget=false.

## Test-first implementation and evidence boundary

`tests/support/StorageScannerTraceWitness.php` evaluates this exact conditional
argument branch and the conditional nominal-hole subtraction, source binds its
four trace dependencies for each original version, and reports ordinary site
requests/fixed cache inventory separately from retention. It is deliberately
NOT a PHP VM/compiler/allocator emulator. `storage-scanner-trace-probe.php`
sweeps backing equality/just-over and allocation-size alignment transitions
across the admitted raw bound. It emits reproducible numeric witness results,
not a synthetic legacy runtime result.

Initial RED ran before the support witness existed: six missing-class errors,
seven expected-domain failures caused by that missing class, and one erroneous
modern test expectation. That expectation guessed distinct refusal messages;
source inspection showed one generic failure message and parity rejection.
It was corrected BEFORE implementation to test token128/129 directly without
native decoding. Final implementation GREEN:15 tests/162 assertions on
PHP8.5.11/PHPUnit9.6.34. Modern malformed nested input records17 live scanner
frames with raw arguments and reuses the same decoder successfully; this
behavioral receipt is NOT evidence of PHP5 backing-copy/allocator execution.
The same trace-site check passes at the exact60000-byte raw ceiling.
Ordinary/dense4000/sibling/depth16 cases pass. Inherited focused129/653 and
full652/2974 pass (three preexisting ReflectionMethod deprecations). Tests and
origin/immutable archive receipts are recorded with exact candidate SHA in the
outside-source seven-part handoff.

No complete trace family, useful ordinary budget fit at128MiB/16MiB or
256MiB/64MiB, native-decode graph/parity overlap, SQL/driver/transport, endpoint
conversion/recovery, literal PHP5 floors or delayed teardown is accepted here.
OPS must receive this selective checkpoint and authorize a fresh independent
exact-candidate review before a separate next implementation slice. No children
are released and this SAME task stays incomplete.
