# Offline scanner exceptional trace nominal-material envelope

This is a numerical prerequisite on SAME t_396d60fe, not product integration,
retained arena headroom, full trace capacity, a runtime-floor result or admission.
Only tests/support code uses it. Public admission/support/profile, ownership/v1,
no-native-estimation/refusal/reuse and all product bytes remain unchanged.

## Bindings and domain

Reuse StorageFixedHelperBounds' six-method descriptors and decoder SHA256
cf7ef2038a5395f70d64f9f2702c3735066a74048cb00dfddc0f97202b1777c3,
and StorageScannerTraceWitness' received separation dependency. Material source
manifest (original archive AND member hashes, extracted original members):
/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/scanner-trace-capacity-checkpoint/runtime-source-bindings.json
SHA256 2b304920b0de417aa34d8263629a24bec279052919b0fabb98da207686fa826a.
It binds 18 members per version, including zend_hash.{h,c}, zend_API.c,
zend_objects.{h,c}, zend_objects_API.{h,c}, zend.h, zend_gc.{h,c},
zend_exceptions.c, zend_builtin_functions.c, zend_variables.c,
zend_alloc.{h,c}, zend_vm_execute.h, zend_execute.h, and spl_exceptions.c.
Original archives remain untouched; PHP5.4.0 / PHP5.6.20 are source branches,
NOT executed runtimes. This model is modern test tooling only.

Conditional ABI: LP64, nondebug, alignment8, cookies/protection/end-magic off,
Zend MM enabled. Nominal N(request)=max(32,align8(request+16)), from
zend_alloc.c:529-539 (5.4) and corresponding TRUE_SIZE branch in 5.6. N is
an allocated family's minimum aligned block shape, NOT a retained/actual-size
upper bound: an unsplittable tail can enlarge a block, and arena/cache/rest
state is not included. All reported numerical upper envelopes are sums of
these *nominal material shapes*, not memory_get_usage(true) bounds.

Ordinary admitted structural context: raw<=60000, nodes<=8003, arrays<=16,
depth<=16. These are inherited context bounds, not newly installed limits.
Only decode -> scanner -> fail is covered, not encode/measure, native hydration,
parity serialization or recoverable cleanup. Nodes/siblings do not multiply a
simultaneous failure trace. The API can over-enumerate deeper paths up to64 for
separate rejection diagnostics; those results are not ordinary-domain evidence.

source_file_bytes is an EXPLICIT domain parameter, in bytes excluding NUL, for
EVERY local frame's recorded file/callsite and exception file. It must bound the
actual compiled filenames (including decode's product caller callsite). The
probe's 256 is an illustrative conditional input, not an accepted installation
path ceiling and not a raw-byte substitute. No path-length cap is shipped.

## Failure path derivation, slots and visitation

Decode fails directly at :30,33,38. Scan (:93-134) first checks depth/nodes/offset
then calls itself only for one child at a time. There are <=d+1 active scan
invocations at depths0..d. Each can fail directly, via token, unsigned,
unsigned->token, or literal. There is no recursive unsigned/token/literal call.
paths(d) enumerates fail+decode plus each of those five tails for each scan
count1..d+1. At d16 that is86 paths, not one observed fixture extrapolated.
The longest over-enumerated path has21 local frames,20 raw argument slots.
A depth-rejecting child occurs at d+1: include fail + d+2 scans + decode (d+4
frames). It cannot also call token/unsigned after rejecting depth. d16 rejection
has20 frames; d64 diagnostic has68. Ordinary d64 token paths can be larger,
and are separately over-enumerated, never credited to the ordinary domain.

Exact formal arities: decode1, scan5, unsigned3, token4, literal3, fail0.
A reflection regression binds every modeled path's argument total to those
unchanged method definitions; compiler argument reservation ceilings are NOT
substitutes for trace argc. Every method except fail receives raw at slot0.
SEND_VAR/RECV source machinery transports a zval pointer in distinct argument
stack slots. builtin_functions.c get_args (5.4:2006ff,5.6:2027ff) visits each
slot, SEPARATE_ZVAL_TO_MAKE_IS_REF then ADDREF/insertion; zend.h separation
and zend_variables.c string copy constructor duplicate shared, nonreference,
noninterned strings. A copied slot becomes a reference; revisiting that slot
cannot recopy it. Other slots may still point at the old shared value and each
can separate once. Thus <=20 new raw backings, each request<=60001, nominal
shape<=60024. The product's offset/nodes are already references and need no
separation; counting every argument as a fresh GC box conservatively grants
those anyway. Sole-owner/existing-reference controls add no new box/backing;
interned shared strings can need a box but no backing. visit() distinguishes
slot identity from shared zval identity and suppresses repeat visits, not
historical allocation counts. This is a bound, not a claim all20 copies occur.

## Local construction and catch/unwind families

The model charges these simultaneous nominal shapes on every enumerated path:

* zval_gc_info32 (zend_gc.h overrides ALLOC_ZVAL at 5.4:202-208),
  HashTable72, Bucket72 (zend_hash.h:54-82 and corresponding 5.6 declarations).
* Trace: one zval/HT, numeric frame buckets, power-of-two pointer vector >=8.
* Every frame: one zval/HT and six fields file,line,function,class,type,args,
  including all keys+NUL in noninterned buckets even when a field is omitted.
  options=0 supplies no object field (builtin_functions.c 5.6:2260-2345 and
  corresponding 5.4 branch). Five field zvals; args gets its own array shell.
  File, method, qualified class and type strings include NUL and N overhead.
* Args: array shell, numeric buckets and one possible separated GC box per
  argument, raw backing once per raw slot. Other args are integers/bools or
  decoder literals/terminators <=2 bytes; grant each a 3-byte backing too.
  They cannot be arrays/objects in this fixed callgraph. Unknown external
  argument graphs belong to the suffix, not this scanner-local assertion.
* Exception: zend_object32, seven-pointer property vector56, all seven default
  properties granted a box, object box and catch box, exception file and exact
  fail-message string. zend_exceptions.c 5.4:147-167,194-218,684-690;
  5.6:155-175,202ff,804-810. RuntimeException is a property-free subclass
  (ext/spl/spl_exceptions.c:63). object_properties_init copies pointers/addrefs
  to defaults, not a new property HashTable (zend_API.c5.4:1084-1098).
  zend_objects.c:141-152 allocates the object and registers it in a shared
  store. Granting a box to every property overcounts shared defaults/trace;
  it is deliberate, not an implicit missing-properties assumption.
* Construction growth: zend_hash.c5.4:94-97,148-175,440-461 and5.6:100ff,
  444-460 grow only AFTER elements>capacity. Vector8 at8; new16 plus old8
  at9; new32 plus old16 at17. Old/new erealloc overlap is granted even for
  in-place growth. Local frame/args tables have <=6 entries and no resize;
  only the outer trace vector grows. A largest old-vector allowance alongside
  final material safely overcounts earlier resize phases. Buckets do not all
  relocate with the pointer vector. INIT_DATA stores zval* inside Bucket's
  pDataPtr (5.4 hash.c:125-137), so no separate payload per pointer bucket.

Catch nominal excludes the extra resize overlap; construction nominal includes
it. Raw argument copies can survive scanner unwind in the exception trace even
when original call frames return. A retained exception survives decoder reuse;
new independent exceptions/caller-retained exception histories are not claimed
to have zero cost. Tests keep one caught exception through reuse and test all
ordinary/dense/nested/sibling/malformed/depth paths on modern PHP. This is a
control-flow/retention-lifetime check, not PHP5 allocation measurement.

## Caller suffix and shared-frontier parameters

zend_fetch_debug_backtrace(trace,0,0) runs until ptr is null, NOT until decode
returns. A local trace envelope therefore cannot certify the full trace.
The only product decode callsite is StorageConversion::option_snapshot():276.
Its existing callers are switch_storage():63, recover():107, and
advance():177,184,205. switch_storage is reached from switch_backend or
replace_retained; advance is reached from switch_storage/recover.
Production entry is StorageOperations->conversion
methods behind the existing authorized receiver. These finite product methods
are source-inventoried, not arbitrary hooks. Their request/record/source array
arguments may contain nontrivial graphs: source-bound their separation before
using a numerical suffix term. An external receiver/WordPress stack beyond
these methods is a distinct boundary, not a reason to refuse ordinary input.

caller_suffix_nominal is null by default. If supplied, its independent proof
MUST include caller frames/argument graphs, function/class/file strings and
THE SHARED OUTER TRACE VECTOR CAPACITY/RESIZE DELTA at the local/suffix join.
Separately rounded vector sizes are not simply additive. Null is not zero,
and providing a number does not set complete_trace_capacity_proven=true.

Object-store resize is also shared, not one fixed per-exception bucket debt:
zend_objects_API.c5.4:107-121 /5.6:107ff checks free_list_head, then top==size,
doubles the vector and ereallocs. LP64 zend_object_store_bucket64 follows from
zend_objects_API.h:31-48. Optional old capacity K grants N(64K)+N(128K), including
old+new overlap and deliberately charging the pre-existing half too. A null K
is unresolved; it is not silently omitted in the parameterized total. Existing
slot reuse needs no new vector, but this run does not infer the global frontier.

GC is another shared frontier, NOT another nominal box per argument.
zend_gc.c5.4:121-127 allocates a shared10000-entry buffer with malloc, not
per-trace emalloc. possible_root:130-190 takes a free buffer slot or may collect
cycles when full; collect_cycles:803ff can call existing garbage destructors.
This source inventory does not prove the frontier at the trace/catch boundary
or charge external collection work. shared_gc_frontier_proven=false explicitly
prevents interpreting even the suffix/store-parameterized nominal sum as a full
construction/cleanup bound. No new global hook restriction is installed.

## Large backing and page-hole boundary: retained charge still OPEN

The useful material prerequisite is <=20 outstanding new raw backings, not a
per-node/per-acquisition retained page multiplier. A 60001 request has nominal
60024. Conditional isolated former VM block130984 leaves nominal split tail
70960, smaller than130984, while backing is live (received witness unchanged).
zend_alloc.c5.4:2022-2040 absorbs short tails or inserts a split tail; freeing
uses neighbor/cache/rest/coalescing rules. One allocation can come from a
coalesced region, existing segment slack, or a new segment. Backing placement,
free adjacency, rest-frontier selection and later VM-page reuse are not proved
by a nominal subtraction. No retained byte coefficient is installed here.

PHP5.4 split argument transfer can acquire then free an EXTRA page while
moving an argument pack (reviewed StorageFixedHelperBounds). PHP5.6 separately
checks reservation R, then advances by retained C. Material counts do not
replace either distinction with LIVE page count. The same reviewed descriptor
sources are reused rather than implementing another VM simulator.

arena_retention_proven=false, retained_arena_upper=null,
fits_justified_helper_budget=false, complete_trace_capacity_proven=false.
The next gate is independent exact-candidate review of this local nominal
material prerequisite, then source-derived cache/rest/resize/suffix/retained
charging and separately authorized policy integration. Native decode/parity,
recoverable cleanup, SQL driver/transport/full-operation/end-to-end conversion
and authoritative recovery remain unaccepted final gates. Literal exact-final
PHP5.4.0/WP5.0 AND PHP5.6.20/WP6.0 delayed teardown, 32-bit/direct-review and
publication edges remain required. No final task/Milestone A or floor approval.

## Test-first execution evidence

Run467 first extracted parent3f3301f with only the new test file overlaid:
15 tests errored for absent StorageScannerTraceCapacity before implementing it.
The original Python3 extraction attempt used an unsupported tarfile filter
keyword; it failed before tests, then safe member validation replaced it.
The initial green attempt exposed an incorrect new-test/model message premise
(actual fail text above) and overly intrusive auto-prepend loader instrumentation
in the native-loader regression. Both were corrected; loader-only processes
now get a passive reflection observer, not a test autoloader. A subsequent
exact-arity regression failed (unsigned was over-granted four args, actually
three) before correcting that descriptor. No product behavior was changed.
Receipts/logs, process origins and final exact candidate live alongside the
source manifest outside FooProjects in scanner-trace-capacity-checkpoint.
