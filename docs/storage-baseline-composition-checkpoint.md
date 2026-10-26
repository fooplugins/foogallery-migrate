# Sampled baseline composition prerequisite (run475)

PARTIAL only. This changes no public policy/profile shape, coefficients, PHP7
calibration guard, semantic request fields, authority/recovery phases or admission
claim. Options remain default; there is no approved shipped calibration. This is
not a positive conversion or authoritative recovery receipt.

## Actual production composition

`StorageMemoryBaseline` stores only the maximum of explicit
`memory_get_usage(true)` samples for ONE operation. It is not
`memory_get_peak_usage`, RSS, a sum of live allocations, or a NEW-allocation bound.
The stored integer cannot decrease when originals are released, when a hook
returns, or when a smaller later snapshot is sampled. Nothing infers a free from
`unset`, count, strlen, reconstruction, or a missing historical HT capacity.

`StorageOperations::handle` creates that context before capability checking and
`wp_unslash`; after unslash it samples again and transfers the SAME object to
`dispatch`. Direct PHP `dispatch` creates its own context before authorization;
there is no ingress exclusion. Samples after authorization and calibration carry
the original entry sample into either size-only preflight or the coordinator.
`StoragePreflight::option_import` samples at direct entry and before decode-budget
calculation after size-only SQL. A reused preflight consumes its incoming context
once, rather than keeping unrelated previous operation peaks.

`StorageConversion` consumes the receiver context once or creates a new one for a
direct switch/replacement/recovery. It samples before authorization/budget,
`begin`, `advance` with its already-live record/source, and every `budget` call.
`StorageRetirement` shares that same context for its existing budget checks.
Later sampled source/target backing remains charged at later budget checks; this
does NOT prove every transient target or codec/SQL allocation was sampled.
Old constructor calls remain valid; the extra trailing context parameters are
internal composition, never request/profile bytes or a supplied numeric extent.
A subsequent direct operation starts a new context. Calibration identity now
hashes this implementation too, invalidating old implementation fingerprints.

Existing `decode_budget` receives this conservative sampled baseline in place of
an isolated later current-use sample. Policy arithmetic and output shape do not
change; `memory_used_bytes` can intentionally remain higher after a release. No
public numeric envelope is installed or increased. Object/VM/call overhead of
this new context is an explicit outstanding allocation-proof obligation too.

## Original backing versus NEW material

Original PHP5.4.0 ext/standard/var.c:952-961 / Zend/zend_alloc.c:2640-2650
and PHP5.6.20 var.c:990-999 / zend_alloc.c:2694-2704 map the true sample
to Zend-MM `real_size`. Under the existing calibrated allocator assumptions,
live Zend-managed incoming caller/POST/record/source backing is already charged
at that instant. Disabled MM, driver/native allocations and RSS are not proved.

The existing source-bound `StorageProductCallerTrace` and
`StorageProductArgumentRetention` helpers are preserved, NOT replaced or given
invented capacities. Only product hashes/callsite lines in CallerTrace are
rebound; the finite decoder-reaching suffix, formal arities and formulas are
unchanged. The short-lived `sample`/`start_memory`/constructor calls have returned
before those decoder failure traces. Their own transient material is not thereby
zero. Retained original hash/copy/backtrace evidence distinguishes NEW shallow
outer array copies (current element count, child addrefs) from the original
historical graph. Do not add an absolute original-graph extent to the baseline,
or claim a compact copy frees incoming caller/POST roots. NEW boxes, buckets,
vectors, trace/VM reservations, strings and their overlaps still need a complete
source-derived phase composition with the retained helpers. This checkpoint does
not turn their conditional nominal calculations into approved headroom.

## Test-first evidence and exact open allocation

Supersession (run477): the run475 external eighth-key witness did NOT pass
unslash. Its eight-key root was refused by handle's count7 guard. Preserve that
sealed evidence, but withdraw the transformed-extra-key claim. See
`storage-receiver-new-composition-checkpoint.md` for separate observed seven-key
large/nested transformation and early-eight-key refusal controls. The sampled
baseline implementation claim is unchanged; neither witness proves admission.

Before product edits, `StorageBaselineCompositionTest` fails at actual
`dispatch`/`handle` preflight baseline carry and the absent internal composition.
The first RED also exposed missing success-response bootstrap scaffolding; that
was corrected and RED rerun BEFORE product edits. GREEN witnesses release a real
8MiB Zend-managed allocation during the calibration filter and require the entry
sample survive through size SQL or all five coordinator operation/direction
entries. They keep churned incoming requests alive, exercise direct PHP and the
real receiver, preserve denied scope/blog/nonce/extra-policy/capability outcomes,
and check unchanged exact-budget/one-byte-lower refusal. The advance witness
invokes the actual method reflectively and stops at a deliberately unavailable
control-read boundary; it is NOT a valid record, SQL connection or recovery proof.
Modern core-function replays remain modern, not full WordPress or literal PHP5.
Diagnostics, including intermediate fixture/loader failures, live outside this
repository at `baseline-aware-production-checkpoint/` in the run475 evidence root.

ONE concrete unresolved NEW allocation is the original PHP5 `stripslashes`
INPUT-length buffer at `StorageOperations::handle`'s `wp_unslash($_POST)`:
WP `wp_unslash` -> `stripslashes_deep` -> `map_deep` ->
`stripslashes_from_strings_only` -> `stripslashes`. PHP5.4.0
ext/standard/string.c:3132-3142 copies the INPUT bytes before shortening them
(3028-3065); the retained PHP5.6 source has the corresponding implementation.
It occurs AFTER the new early sample but BEFORE calibration/decode admission;
an after-unslash sample cannot bound its intervening peak. Actual original-WP
nonce semantics accept escaped valid nonce bytes, so output strlen alone cannot
be supplied as this input allocation's extent. Invalid nested/large POST values
also reach unslash before the existing semantic refusal. No new request cap,
refusal-only completion, arbitrary-hook/never-OOM promise, or public ingress
contract narrowing is introduced here. The smallest next evidence route is to
source-charge that transform from the admitted input/output relations and actual
closed receiver traversal, plus its COW/stack overlap, at the now-explicit early
boundary; settle invalid-input coverage under existing calibration separately.
This exact allocation is NOT covered merely by this baseline carry.

## Stop / retained gates

Fresh native INDEPENDENT exact-candidate review is required after these changes;
this author performs implementation tests only, not its own approval. No numeric
safe-memory/useful-fit, complete trace/VM/sharedGC/object/arena, SQL/driver/
transport/decode/output/parity/cleanup or complete record/source/target overlap
grant follows. Actual successful conversion AND authoritative recovery remain
the destination. Exact-final PHP5.4.0/WP5.0 and PHP5.6.20/WP6.0 plus delayed
teardown, direct independent-review/publication edges, combined A+B approval and
actual-merge gates remain. SAME card stays incomplete SCHEDULED PARTIAL_CHECKPOINT;
no child release, push, deployment or next implementation slice in this run.
