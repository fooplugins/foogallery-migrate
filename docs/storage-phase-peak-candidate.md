# Executable phase-peak candidate — partial, disabled; NOT admission

## Boundary

SAME `t_396d60fe`, received `ops-phase-estimator-authorization.md`, base
`965d3cadf3a53bff905ab975e4c4ec45148d0733`. F1 is fixed in the production
fingerprint: identity absence or failed JSON encoding returns NULL, preserving
raw bytes and ordinary exact identities. Public policy/PHP<7 guards are unchanged.

`tests/support/StoragePhasePeakCandidate.php` is explicitly loaded only by local
experiments/tests. It is not registered, autoloaded by the plugin, shipped in the
plugin ZIP, called by policy, or supplied as an approved profile. It is executable
source-informed allocation analysis, not the previous arbitrary-unit-cost
worksheet: it scans actual option bytes without native decoding, and uses the
production pre-native JSON lexical inspection. No SQL or whole-graph native
serialization/decoding occurs. Duplicate/discarded values count, not only the
surviving graph. Malformed JSON that passes lexical inspection is NOT validated.

IMPORTANT: a modeled-budget fit is NOT a safe admission estimate. Every result
has `admitted=false`, `real_usage_peak_bytes=null`, and a precise missing-headroom
refusal. The wider authorized useful safe-admission candidate is not complete.
This document does not redefine that acceptance criterion as live-block algebra.

## Explicit evidence domain

The descriptor selects retained PHP5.4.0 or5.6.20 source, conditional LP64,
nondebug/NTS, alignment8, no allocator protection/cookies, Zend-MM segment262144.
It models sources; it never asserts that PHP8.5 running the calculator is that
runtime. B is accepted only in the previously controlled CLI launches, not
inferred from current getenv. Original source/member/startup receipts remain
untouched in `checkpoint-conditional-safety`. The prior source/ABI ledger remains
conditional, not compiled sizeof or32bit/SAPI approval.

Only resident option bytes or one table JSON record are modeled. Driver must be
`none`: mysqli/mysqlnd/libmysql, SQL prepare/escape/hooks/result buffering,
collection row/parent/manifest assembly and full conversion/recovery phases are
REFUSED, not zero-costed. Source and verification/COW graph overlaps are modeled,
but not a complete database-operation peak. No cooperative timing certification.

Option domain: <=16MiB,30000 nodes INCLUDING keys,depth<=64,root<=100 entries;
null/bool/in-range integer/byte string/reference-free arrays. Decimal floats,
references/objects/custom tokens and malformed/trailing inputs refuse before
native work. JSON domain: existing <=1MiB,40004-token/195-container lexical
bounds, unquoted1/true/false only. Packed/base64 table floats are allowed in this
analysis; reverse native decimal serialization remains unsupported. Precision
inputs must be explicit integers0..17, including serialize_precision; this does
NOT certify decimal workspace at those settings. Larger/unknown precision and
other unknown domains refuse. No support-floor change.

## Numeric live-block terms and derivations

Paths below are relative to retained release sources in
`checkpoint-conditional-safety/runtime-source/php-{5.4.0,5.6.20}/`.

For native request s, `U(s)=max(32,align8(s+16))+24`: conditional header/minimum
block/alignment plus largest aligned unsplittable tail below32. This is allocated
block occupancy, NOT arena real usage. `Zend/zend_alloc.c:336-404,528-539` and
5.4 `:2022-2026` /5.6 `:2026-2030`. Zval GC wrapper32->72; HashTable72->112;
numeric Bucket72->112. `zend.h:307-324`, `zend_gc.h:81-97`, `zend_hash.h:54-82`.
String request length+1 is bounded by length+56. String-key bucket adds key
length and <=8 padding/terminator bytes to numeric bucket occupancy.
`zend_hash.c:233-270`; pointer-sized bucket data are inline.

For A arrays/E insertions, sum of power-of-two capacities is <=8A+4E. Charge
TWO complete vectors, each8 bytes per slot plus56 per-array allocation allowance,
for moving realloc, no reuse credit. `zend_hash.c:139-165,440-458`. This is a
loose aggregate capacity bound, not a segment charged to each historical node.
Complete source graph counts all parsed key/value zvals, strings/key copies,
containers and buckets, including overwritten duplicate subgraphs. PHP5.6 keeps
key zvals until var_destroy (`var_unserializer.re:311-361`); no PHP5.4 early-key-
release credit. Tracking: ref list<=N pointers, destructor list<=2N pointers
(data+keys+duplicates), each block1024 pointers+two LP64 fields=8208; add two
32-byte list-header allowances (`:26-117`, `php_var.h:43-48,86-90`). Canonical
serialization also retains a reference hash: <=N entries, each identity key<=32
bytes (`var.c:546-577` in5.6 /`:536-575` in5.4). Its generic graph charge
intentionally overcounts inline scalar data with allocated zvals.

Smart string capacity<=max(79,L+129), charge TWO U(capacities) during moving
realloc (`ext/standard/php_smart_str.h:37-66`). Keep a distinct final L+1 copy
beside parity buffers. Raw option overlap charges TWO L+3 blocks for raw and
CONCAT/substr lifetime, without driver approval. Native decode phase retains raw,
source graph and tracking; parity retains raw,source/full-COW graph,reference
hash and buffers; verification overlap retains source,copy,target,tracking and
parity. Phases are maximized, not summed as historical allocation events.

JSON graph conservatively treats EACH lexical token as container,inserted key,
scalar zval and string; aggregate key/string payload lengths each<=input L.
This bounds native/rejected-prefix allocations without assuming tag validity or
surviving duplicate keys. Distinct partial logical/source/COW/target graphs get
this same larger census. UTF16 input request2L+3 (`json.c:605-631`/5.6`:683-709`).
Parser struct4128 includes512 pointer slots, stack4*512, extra32-byte zval
allowance (`JSON_parser.h:12-20`, `JSON_parser.c:242-254`). Both parser smart
strings retain capacity, with realloc overlap (`:386-430,543-560`). Three
full-census validation arrays cover array_keys,range and typed-key probe
(`class-legacy-state-codec.php:207,239,251-253`). Base64 decode allocates encoded
L+1, parity encode4*ceil(L/3)+1, retained together with buffers (`base64.c:69,
143-150`; codec`:263-271`). JSON parity also charges UTF16/output growth/final
copy; includes retained native/logical/COW/target graphs at verification.

All aggregates, sums, multiplications, alignment and division refuse overflow;
no float overflow is cast back. Division operands are constrained to signed-int
native-count width before rounding. A smaller arithmetic ceiling test is NOT
32bit runtime approval. Explicit baseline+max(phase)+caller reserve is compared
against128MiB ordinary fixtures (16MiB baseline,8MiB reserve). Those allowance
values are test budget inputs, not independently justified coefficients.

## One specific remaining evidence gap after safe independent work

A conservative, useful residual PHASE HEADROOM envelope over these live-block
terms is not justified: arena segment slack/fragmentation, PHP VM temporaries
and recoverable cleanup reserve. Controlled B=262144 only fixes segment rounding;
`zend_alloc.c:1934-1965`/5.6`:1938-1969` shows acquisition is tied to free-block
availability, not simply rounded total live bytes. Charging Q to every event
would make ordinary node-dense states unusable; multiplying live bytes by an
invented factor or adding one segment per phase is not defensible either.

Unknown SQL-driver and decimal-float domains are explicit exclusions above, not
missing default coefficients smuggled into the headroom envelope. Float
safe_emalloc scratch is precision+33 (`var.c:737-745`/5.6`:745-753`), but aggregate
libc dtoa Bigint/freelist workspace is not established by a bound on one block
(`zend_strtod.c:193-200,416-531,1383-1406`). No estimate/measurement here establishes
full operation headroom, cross-runtime calibration, no-OOM, or hard wall time.

## Executed test-first evidence / stop

F1 RED:9 tests/102 assertions,2 failures, including hash(empty) and preflight
progress past calibration. GREEN includes both malformed identities, ordinary
hash stability and refusal-before-SQL for preflight/switch/recovery. Candidate
RED: missing implementation. First GREEN attempt exposed one incorrect expected
JSON token count (actual12,not11); fixture corrected, not hidden.

Final execution receipts, exact source/package identities and the seven-part
handoff are outside FooProjects in `checkpoint-phase-candidate/`. Ordinary dense
option/table fixtures, duplicate/discarded payloads, depth/native-token/byte caps,
malformed inputs, packed floats/native-decimal refusal, precision/domain/driver
refusal, overflow and exact budget boundaries exercise the working calculator.
They are NOT actual public/DB admission tests. Frozen native-call spies separately
check this boundary. Modern full-suite results are recorded there; no fresh
literal/floor/32bit/DB/VM/final validator or production action was attempted.

STOP for receiving/independent review of the exact PARTIAL candidate and F1.
Do not accept Milestone A, complete root/release children, enable a public policy,
request another B export or invent a user capability blocker. Default owns the
next bounded decision: review these concrete terms/refusals and resolve the
specific headroom envelope before authorizing full admission integration.
