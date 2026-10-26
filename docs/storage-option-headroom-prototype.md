# Offline option-helper headroom prototype — PARTIAL, NOT A SAFE ENVELOPE

## 1. Bottom line

`StoragePhasePeakCandidate::option_headroom()` implements a restricted executable
capacity-family prototype on top of the delivered live-capacity model. It scans
without native hydration/serialization and keeps displaced duplicate nodes. The
ordinary, dense-short and nested fixtures produce arithmetic fits for both source
version descriptors at the requested 128MiB/16MiB and 256MiB/64MiB limit/baseline
pairs. **These are NOT yet justified safe helper fits.**

The authorized slice's useful, source-discharged safe-headroom acceptance is NOT
met. `headroom_proof_complete=false`, `fits_justified_helper_budget=false`,
`real_usage_peak_bytes=null`, `admitted=false` and explicit missing source terms
are mandatory even when `fits_modeled_budget=true`. No production class, public
policy, profile/support guard, schema, authority, conversion or recovery changes.
No Milestone A acceptance or child release.

## 2. Exact domain and executable families

The calculator requires both retained source versions' LP64/NTS/nondebug/Zend-MM
model, 262144-byte segments, no driver, the retained precision descriptors, raw
<=60000 bytes, <=8003 nodes INCLUDING keys/displaced duplicate values, <=16 arrays,
depth<=16, value strings<=128 and string keys<=64. It checks real integer
arithmetic, aggregate overflow, exact budget boundary and reserve exhaustion.
Floats/objects/references, malformed/trailing data and uncovered domains refuse.
The existing table-origin and broader live-only candidate remain unchanged.

The bounded caller descriptor supplies <=32 frames, <=8 primitive arguments per
frame, <=1024-byte metadata/argument strings, no hooks, loaded helpers, prepared
PCRE patterns and a spare object-store slot. These are hypotheses, not discovered
runtime state or public permissions. No claim that ordinary WordPress callers
satisfy them. Only frozen decode/parity helper execution is exercised separately.

Families: three material graphs (includes geometric hash-vector growth and COW
coverage), raw buffers, unserializer tracking, serializer-reference vectors,
all possible smart_str capacity generations, final parity result, scanner
proposals, PCRE match scratch, proposed runtime caches, trace/argument separation
and built-in exception cleanup. Coexistence is summed rather than subtracted;
no baseline free-segment reuse credit is taken. Original live-capacity accounting
and the source-informed 32-byte segment+guard and allocator block lowering are
reused, not replaced by a Q-per-tiny-node rule.

## 3. Source facts versus proposals

Retained sources:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_396d60fe/checkpoint-conditional-safety/runtime-source/php-{5.4.0,5.6.20}/`;
review archive-derived VM/compiler/backtrace/object excerpts:
`/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/phase-candidate-review/runtime-excerpts/`.
Additional six files per version were extracted ONLY from the already retained
original PHP archives into this checkpoint's diagnostics `runtime-source/`;
no fetch/build/guest/native legacy runtime or provisioning was performed.

* Both `ext/standard/php_smart_str.h:37-68` use initial78, spare128 and request
  capacity+1. For output<=L, each request<=L+129. Successive growth capacities
  differ by at least128. `smart_family()` sums a backwards arithmetic progression
  from max(79,L+129), plus initial79, using allocator occupancy for EVERY generation.
  This intentionally charges realloc history, not two final buffers. Source graph
  input excludes native decimal serialization. Canonical/parity success is still
  unproved by this lexical census; duplicate/coercing cases reach native refusal.
* Both allocators' `zend_alloc.c` rest/cache/search/split/realloc paths are retained.
  Rest count16 does not include small rest entries. The ordinary proposed bound is
  `B * ceil((V+18B)/(B-K-32))` for B262144, K73728. The implemented ordinary cutoff
  checks raw/smart/reference/hash largest request and doubles geometric vector
  families. This needs a complete proof that V includes EVERY material allocation
  family, not merely a successful test or a list of source files.
* `zend_execute.h` default page size is a POINTER count: 16368 pointers plus the
  three-pointer page header =130968 requested bytes, allocator occupancy131008.
  `zend_vm_stack_free[_int]` calls efree on page pop. The prototype reports proposed
  23 helper activations, 68000 bytes/frame and28 live pages. The bounds on compiler
  T/CV/nested_calls/used_stack/runtime-cache lowering have NOT been discharged.
* The source's page-pop behavior bounds live VM capacity, NOT historical occupancy
  in retained Zend-MM arenas. A freed page region can be carved by ordinary
  allocations before a same-size VM request, leaving an interior hole too small
  for that VM request. Substituting live VM high-water volume into the ordinary
  packing lemma is therefore UNJUSTIFIED. The explicit exceptional correction
  implemented here is a numerical proposal only. It is deliberately not credited
  as a safe envelope. No claim of a source-reachable >128MiB counterexample is
  made; that would require the exact compiler lowering and allocation ordering.
* PHP5.6 backtrace `zend_builtin_functions.c:2027-2050` separates nonobject args to
  references and addrefs them; `zend_variables.c:109-143` duplicates strings and
  shallowly copies arrays. Exception creation at `zend_exceptions.c:155-175`
  builds properties, trace and file/line while original material remains live.
  New built-in exception object-store insertion can double the store; the domain
  explicitly refuses a missing spare slot instead of making up a resize cost.
  Full trace vector growth and scanner box/cache proposals are NOT proved.
* Prepared capture-one PCRE paths in both extracted `ext/pcre/php_pcre.c` request
  two-pointer subpattern-name tables and six-int offsets. Native PCRE/C-stack/RSS
  costs and cold compile costs are excluded, not silently claimed bounded.

A read-only reasoning subtask audited the ordinary packing paths; another audited
VM/exception/compiler sources. Their handoffs are reasoning aids, NOT independent
candidate review, not run evidence and not accepted coefficients. The parent found
and retained the live-versus-historical VM distinction rather than certifying it.

## 4. Test-first and verification

New `StorageOptionHeadroomTest` ran BEFORE implementation: exit2,24 cases, undefined
`option_headroom()` errors/failures retained in diagnostics `red.txt`. Subsequent
runs exercise arithmetic fits WITHOUT crediting justified fits; duplicates/coercing
keys count and fail actual helper parity; success, malformed refusal, cleanup and
reuse execute the actual frozen helper. Node/depth/array/string/key/raw boundaries,
growth steps, hooks/trace/store/domain refusal, overflow, reserve and exact limit
are exercised. The isolated probe spies on native decode/serialize in BOTH namespaces
and separately constructs a genuine canonical60000-byte fixture.

Authoritative final execution totals, hashes, source origins and package verification
are in the checkpoint's `verification.json` and seven-part handoff. PHP8.5 execution
is calculator/helper regression evidence only, NOT executed PHP5 support/calibration,
legacy-final validation, numerical certification or real-memory measurement.

## 5. Remaining work and receiving gate

The authorized safe-headroom target remains incomplete. Required next evidence is
exact-source compiler frame/cache/box lowering, a valid exceptional VM page-retention
argument (not merely larger K with live pages), complete trace allocation families,
and fresh independent exact-candidate review. Do not integrate this prototype into
production conversion/recovery. SQL/driver/transport/table/native-decimal/full-
operation costs remain incomplete. Retain SAME literal validator/final delayed
teardown,32bit,AIGPL combined A+B,independent review/publication/actual-merge gates.
No new cards or external infrastructure/capability question is requested here.

## 6. Bounded retention checkpoint: a precise fixed-helper lowering gap

Run461 follows OPS `ops-option-headroom-remediation.md`, not a new general
allocator prototype or public admission. It delivers executable source-path
witnesses and identifies one precise indispensable input to a useful retention
argument: version-specific VM reservation R and retained top C for the fixed
`LegacyOptionDecoder::{decode,scan,unsigned,token,literal,fail}` methods, including
their nested internal-call argument tail. Those descriptors have NOT been derived.
The result now exposes `vm_retention_obligation`; all four original missing terms,
arithmetic, budget examples and unconditional non-admission remain unchanged.

### Why the missing descriptor matters on the actual bounded path

`class-legacy-option-decoder.php:93-160` repeatedly calls scan for keys/values at
127-128, unsigned -> token at136-139, and literal at154-156. Calls return before
the next sibling; token/literal allocate substring and concatenation temporaries,
and malformed input can construct a RuntimeException while ancestor frames and
material state are live. Native graph/parity allocation at37-38 is a distinct
phase: do not invent a simultaneous graph inside the lexical scan. Loaded helpers
and prepared PCRE are already required; unknown caller history is NOT the question.

PHP5.4.0 `Zend/zend_vm_execute.h:358-365` requests the aligned execute-data header
+ CV slots (last_var, doubled without a symbol table) + T temp_variables, retaining
that allocation for the frame. PHP5.6.20 `Zend/zend_execute.c:1625-1630,1673-1698`
requests header + T + CV + nested_calls*call_slot + used_stack*zval-pointer tail,
then sets top to `zend_vm_stack_frame_base` at1686. `zend_execute.h:264-267` defines
that base AFTER call slots, BEFORE the argument tail. Thus 5.6 R includes a tail
not present in C. Even a bound on C alone does not bound whether an R request
crosses the parent's remaining space. T/last_var/nested_calls/used_stack and the
associated box/runtime-cache paths are compiler outputs for these fixed methods,
not numeric constants inferable from array depth or the proposed page count.

Both versions' `zend_execute.h` implement default count16368, page request
aligned24 + count*8, extension on insufficient residual space, and efree when
returning to the page's first frame:5.4 lines164-190,208-213,243-269,272-290;
5.6 lines167-193,211-216,235-261,270-288. Parent top is restored on child return.
Consequently a sibling loop can acquire/free a page repeatedly with one live
child page throughout. The witness uses hypothetical R/C values solely to test
this transition, NOT to certify actual helper compiler coefficients. In 5.4 it
requires C=R; in 5.6 it also tests C<R. A future derivation must discharge the
actual descriptor and relevant ordinary allocation interleavings, not multiply
every input node by a whole segment or demand an exact census of all prior callers.

### Executed allocator witnesses and their limits

`StorageVmRetentionWitness` is a small restricted transition witness, not a
legacy runtime, general allocator emulator or memory estimator. Explicit domain:
LP64,8-byte alignment,NTS,nondebug,default no cookies/heap protection,256KiB
segments; large requests1024..130968 only, <=64 allocations; no realloc/rest/cache
branch. Ambiguous searches refuse unless there is one exact-size match. BOTH
retained allocators have the same source rules used by these specific traces:
block/minimum/segment lowering at `zend_alloc.c:336-404,528-543`; exact-size large
search at5.4:1803-1873 /5.6:1807-1877; normal segment acquisition and split at
5.4:1953-2040 /5.6:1957-2044; large free/coalescing/whole-segment release at
5.4:2056-2115 /5.6:2060-2119; segment real-size subtraction at5.4:915-925 /
5.6:919-929. Requests exceed the small-cache threshold (64*8+32=544), and are
below the oversized/rest threshold. Source excerpts are byte-compared against
BOTH original retained archives in this checkpoint's source manifest.

Two default page requests each130968 lower to130984 and fit in262112 usable bytes,
leaving144. Return the child, coalesce with the tail, allocate ordinary8192
(true8208) from that free region, then reacquire a default page: the remaining
122920 hole is too small and a second262144 segment is acquired. Peak live VM
pages is still2, but acquisitions are3 and arena footprint524288. Freeing the
parent restores an exact-size hole; a subsequent page reuses it. Releasing all
VM pages leaves the material-pinned segment; releasing the pin frees it. The
uncarved control reacquires eight times without growing beyond one segment.
This simultaneously covers material state, reuse and final coalescing rather
than assuming every page acquisition permanently consumes a fresh segment.

These are executable counterexamples to equating page-allocation history with
live-page high-water, and to treating live block bytes as real segment usage.
They are NOT a counterexample to the complete proposed arena_family inequality,
not claimed reachable by this helper, and not evidence of a >128MiB helper peak.
The ordinary8192 pin isolates the allocator branch; helper scanner small-cache
temporaries need their own source lowering. No observation certifies coefficients.
The original headroom formula remains proposed/unproved, not newly falsified or
approved by these examples. Trace-family/compiler/cache terms remain unresolved.

### Test-first receiving gate

New9-case suite ran before witness/metadata implementation: exit2,7 missing-class
errors and2 missing-obligation failures (2 assertions). Initial GREEN:9tests /
50assertions exit0 on PHP8.5.11. Final full/frozen/build/probe/hash/origin receipts
are in `checkpoint-vm-retention/verification.json`; source/package identifiers
and exact totals in its seven-part handoff. PHP5 source tracing is not PHP5
runtime execution. No public source changed. Stop PARTIAL with the precise
R/C lowering blocker for ONE fresh native independent exact-candidate review
through OPS; useful justified helper fits, actual endpoints/end-to-end recovery,
literal final/delayed teardown/32bit and all children remain held.
