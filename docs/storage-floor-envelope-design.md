# Supported-floor storage envelope: proposed evidence contract

Status: DESIGN / MEASUREMENT ONLY. No profile or public policy is approved.
Controlling authority: OPS `ops-stale-owner-receiving.md` section 6 (2026-10-14),
SAME implementation card `t_396d60fe`. Received production checkpoint:
`00b0c40df260e72ef5b50410d6d102380c844c73`, `feature/migrated-store-scalability`.
This document does not amend the committed proposal, PHP/WordPress declarations,
PHP<7 guard, v1 ownership, public assertions or stale-owner admission.

## 1. Exact path and what an envelope must cover

* `StorageOperations::dispatch` (includes/class-storage-operations.php:17-34)
  validates exact operation/capability/site/confirmation/nonce BEFORE fetching the
  trusted filter/profile. A missing/mismatched profile returns before SQL.
  `handle`:38-57 accepts exact POST only; success exposes four control scalars;
  errors expose a code and translated message, not raw payloads/digests.
* `StoragePreflight::option_import`:34-62, `option_size`:66-81 do current-site
  fixed-option LENGTH only. No get_option/alloptions/decode. Public preflight
  cannot return conversion_ready=true. Direct uncalibrated preflight is not the
  same no-SQL contract as the public profile-gated receiver.
* `StorageConversion::switch_storage`:43-90 / `recover`:96-112 gate first,
  verify authoritative controls/schema, snapshot, stage, verify, then CAS.
  `option_raw`:284-292 size-checks before fetching CONCAT('v:', raw), then
  substr creates another raw string. `option_snapshot`:268-281 additionally
  scans, hydrates, reserializes for canonical parity, hashes and rechecks source.
* `advance`:156-226 can retain source while constructing changes, prepared
  roots, destination snapshots and backup/SQL buffers. Reverse serializes the
  complete source BEFORE its serialized size budget check. Postflip recovery
  reads only recorded destination authority. This order is part of the proof;
  a per-record size check after allocation cannot bound earlier whole-graph cost.
* `LegacyTableSnapshot::preflight`:242-283, `stage`:286-328,
  `read_generation`:340-387 retain prepared values, rows, roots, children,
  visited/tree/pending indexes and reconstruction at overlapping times.
  `LegacyRecordCodec` preflights then flattens and rebuilds; individual row
  emission does NOT mean whole-option/reconstruction allocations are streamed.
* `StorageRetirement::capture`:43-72 retains two fixed-column hash manifests
  plus JSON and backup identity. Include inactive/orphan generations and SQL
  server work over their payloads, not only visible heads or active payload_bytes.
* The existing completed migration-output/death receipts remain separate proof.
  A conversion envelope starts after a quiescent output drain; it does not bound
  FooGallery/media/third-party destination effects or prove stale fencing.

## 2. Admitted graph domain, without inventing a narrower public domain

Reuse `LegacyOptionDecoder` scan, NOT a second parser/streaming importer.
Current raw-option domain: canonical PHP serialized array; integer/string keys;
null, bool, integer, finite float, binary string and array values. Full input is
consumed, then native decoding is reserialized byte-identically. Object/custom
serialization/enum/reference tokens, duplicate/coercing keys and noncanonical
encodings fail closed. Binary payloads are opaque bytes, never searched for tokens.

Current structural ceilings: root entries <=100; depth <=64; total tokens
(including keys and root) <=30000; raw bytes <= explicit reviewed policy ceiling.
For a tree this gives entries <=14999 and array nodes <=15000. Use these full
ceilings BEFORE hydration, even if the source is short. A raw-byte-only benchmark
with long strings cannot cover node-dense tiny-scalar graphs. The scanner itself
requires bounded raw-buffer/stack admission before it can reject bad graphs.

Record envelopes additionally impose <=10000 tagged nodes, depth <=64 and
<=1048576 encoded bytes per codec value. Snapshot bounds are <=10000 physical
records and <=16777216 encoded bytes, with <=100 heads. Retirement counts each
physical table up to 10000 rows and bounds combined column bytes; it is NOT
bounded by the active-record count alone. Table-origin graphs must be separately
bounded from encoded per-row trees/heads; the raw option token ceiling does not
apply to a reconstructed table snapshot. No whole-job streaming/OOM claim.

The codec's wrapper, base64, tagged keys/values and SQL driver escaping need
explicit accounting. For B binary bytes spread over N scalar/key strings, use
sum ceil(length_i/3) <= ceil(B/3)+N (intentionally loose); base64 bytes are four
times that sum. JSON may double slash bytes. Add a separately audited tag/key/
number/fixed-wrapper cost per node. A record may fail its final encoded-size check
only AFTER building tags and JSON, so the construction bound must include this
pre-rejection peak, not just successful encoded bytes. Audited transient caps may
be larger than stored snapshot caps; neither is an admission restriction here.

## 3. Proposed envelope artifact and identity (not the current v1 profile)

A future reviewed evidence JSON has EXACT named sections:

* schema_version=1, disposition=measurement_only OR independently_reviewed;
* candidate: exact source commit, ZIP digest, relevant file hashes, proposal blob;
* runtime: PHP release/build digest/configure flags, PHP_DEBUG/PHP_ZTS, integer
  width/endianness, OS/architecture/kernel, SAPI, extension versions/builds,
  USE_ZEND_ALLOC, allocator implementation/build, libc, worker/container limits;
* settings: effective PHP memory/execution limit, precision/serialize_precision,
  GC, OPcache/JIT, output buffering/compression, relevant driver/client/server
  versions and DB/lock/socket timeouts; unsupported/unknown fields refuse;
* host_scope: WordPress/FooGallery/plugin/theme versions, MU plugins, drop-ins,
  active hooks, locale/translation/filter and bootstrap/response overhead bounds;
* operations: separate preflight, import, reverse, replace and phase-specific
  recovery entries with resource caps, overlap ledger, memory/time unit upper
  costs, fixed/reserve/cleanup budgets and derivations;
* provenance: evidence manifest hash, measurement commands/raw receipts, source
  derivations and coverage, independent reviewer identity/verdict/time and exact
  artifact reviewed. A self-reported approved label is not approval.

No POST field, filter return assembled from request bytes, finite test corpus or
runtime fingerprint may turn disposition into approval. Any missing dimension,
code/runtime/SAPI/hook-set mismatch or unverifiable provenance must refuse before
SQL. Site identity remains exact request/current-blog matching; a network runtime
identity never grants cross-blog authority. Existing PHP configuration is trusted
code, not a sandbox; approval cannot defend against a malicious trusted filter.

Current `StorageCalibration::fingerprint` covers many relevant product/runtime
bytes but does NOT hash `class-legacy-option-decoder.php` or all transitive
collection/settings/WP/driver/response dependencies. The proposed evidence
manifest must bind these too. This slice changes neither fingerprint nor policy;
any integration is a separate authorized, reviewed invalidation of old profiles.

## 4. Conservative resource algebra and enforcement plan

For each phase p, derive a live-allocation ledger, counting worst-case full copies
rather than relying on copy-on-write/sharing. Count raw/CONCAT/substr/parity/backup,
source+target graphs, tags/base64/JSON, prepared SQL/driver results, maps/indexes,
retirement manifest, errors/response buffers and stack simultaneously where
lifetimes overlap. A safe loose sum is preferable to an unjustified max.

Let caps c = (raw_bytes, nodes, depth, encoded_bytes, rows, response_bytes).
Collapse entries/arrays and repeated traversals/copies into AUDITED per-unit
costs, or extend the reviewed schema before using an unaccounted dimension.
Use the maximum whole-operation caps (including table/rejection peaks), not
fixture-observed counts. No defaults or multiplier values are proposed here.

M_required = memory_used + emergency_reserve + sum(c_i * memory_upper_cost_i).
T_required = elapsed + cleanup_reserve + sum(c_i * time_upper_cost_i).
Each integer product/addition must remain within the literal runtime's integer
ceiling. Unknown/unlimited effective host limit is not an unbounded budget;
require an explicit trusted hard ceiling. Check both limits BEFORE fetch/hydration
and again immediately before each allocating/nonpreemptible step/publication.
Memory upper costs must cover Zend and non-Zend/RSS/driver allocations and
fragmentation/page rounding; memory_get_usage(true) alone is not process memory.

`tests/support/StorageEnvelopeWorksheet.php` executes ONLY the conditional
algebra, refusing absent dimensions/costs, floats/coercions, zero/unproven costs,
overflow and exhausted memory/time/reserves. It ALWAYS returns measurement_only,
conversion_ready=false and no policy. It neither derives coefficients nor runs
in production. Its positive fixture uses intentionally synthetic unit costs.
The 32-bit tests simulate arithmetic ceilings on PHP8.5, not literal PHP5 proof.

For native time: bound every indivisible decode/serialize/hash/JSON/driver call
before starting it, including worst supported inputs and failure/cleanup paths.
Current 10-second coordinator and microtime checks are cooperative, not hard
interrupts. PHP5 lacks a portable monotonic hrtime interface; wall-clock reversal
or an unproven maximum blocking call/DB wait must invalidate timing evidence.
A separately reviewed watchdog/SAPI timeout plan may provide a hard execution
bound but must NOT clear ownership on death or authorize stale takeover. Recovery
remains conservative and all source/staging/control/sentinel evidence is retained.
Response time also includes json_send/translation/output buffering/drain. The
four success fields are bounded scalars, but translated/filtered error messages
and arbitrary shutdown hooks are not globally bounded by current plugin code.
The host's approved hook/SAPI contract must supply bounds, otherwise no approval.

If eventually encoding this evidence in the existing three-field memory policy,
choose a proven raw-dependent amplification plus a fixed reserve covering FULL
node/row/depth/staging/response ceilings even at raw_bytes=0. That profile has NO
time field, so it cannot alone enforce this time contract. No such translation or
new evaluator is implemented/authorized by this design. Independently reviewed
proof and separately authorized narrow floor-policy integration are still needed.

## 5. Evidence acquisition / falsification plan

1. Independently review domain, lifetimes, source-derived allocator upper bounds,
   overflow and no-SQL authorization ordering before selecting coefficients.
   If arbitrary installed hooks cannot be bounded, explicitly resolve the admitted
   host contract; do not silently restrict proposal support or lower assertions.
2. On an OPS-designated OWNED disposable native target, bind exact literal
   PHP5.4.0/WP5.0 and PHP5.6.20/WP6.0 source/package to deployment SAPI/runtime.
   Measure allocator/page/RSS baselines and transient peaks in fresh processes,
   controlled cold/warm fragmentation/cache/extension/output configurations.
   Native PHP8.5 CLI and QEMU TCG semantic fixtures cannot establish these costs.
3. Sweep independently varied byte/node/depth/root/key-width/row axes and mixed
   corners: tiny nodes vs large binary, slash-heavy base64, deep arrays, integer
   boundaries, duplicate/coercing keys, object/reference refusals, per-record
   overflow and pre-rejection construction. Preserve complete input generation
   seeds/hashes, not private real source payloads. Measurements falsify proposed
   bounds; source derivation/review must cover unobserved members of the domain.
4. Instrument the exact conversion/recovery path for each phase including reverse
   serialization, staged read-back, retired generations/backup, repeated source
   checks, denied operations, SQL/verification failure and reserved response/error
   handling. Use independent process/DB observation and never guess authority.
5. Prove unknown/proposed/stale/cross-SAPI evidence refuses with zero public SQL,
   authorization precedes filter/calibration, site isolation holds, and near-limit
   requests either stay inside bounds or retain state and refuse. Preserve the
   original public receiver/literal assertions and immutable existing receipts.
6. Only after independent evidence approval request narrow policy integration;
   freeze its exact candidate then use SAME held validator and existing independent
   review/publication graph. This slice does not release any lane or provision any
   host/VM/runtime. Product-visible stale fencing remains a separate open decision.

## 6. Actually exercised in this slice

New worksheet: RED 17 failures (helper absent); GREEN 17 tests/49 assertions.
Full PHP8.5.11 CLI suite: PASS 539 tests/2268 assertions; pre-existing reflection
setAccessible deprecations remain. Three new PHP files lint PASS.

`tests/tools/storage-envelope-observe.php` exercises ACTUAL current decoder and
codec in four fresh modern CLI processes: tiny-integers, binary-slashes,
deep-arrays and root-width. All exact serialized/codec roundtrips PASS, and every
receipt states measurement_only/conversion_ready=false. Raw bytes / encoded
bytes / allocated process peak (includes fixture construction):

* tiny-integers: 43973 / 150511 / 6291456, 8195 nodes;
* binary-slashes: 262173 / 699119 / 4194304, 3 nodes;
* deep-arrays: 622 / 1873 / 2097152, depth61;
* root-width: 36198 / 150123 / 6291456, 8201 nodes.

These observations show distinct graph/encoding cost axes, NOT a safe production
coefficient, staging/response bound, literal-floor execution or SAPI admission.
No SQL/WordPress/native-death replay or machine provisioning was done. Raw logs,
identity/file digests, preservation receipts and seven-part handoff are outside
FooProjects at Dev diagnostics `t_396d60fe/checkpoint-floor-envelope/`.

Next unblock: independent review of THIS envelope contract and an OPS-designated
native supported-floor/deployment-SAPI evidence target. Existing modern CLI and
retained TCG compatibility receipts cannot supply the missing allocator/time/
hook-bound provenance. Smallest safe route: review the contract, authorize owned
disposable measurements on that target, then separately authorize narrow policy
integration ONLY after legitimate evidence. No fabricated profile/guard removal.
