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
