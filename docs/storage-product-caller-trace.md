# Finite product-caller trace nominal material (offline prerequisite only)

## Scope and source identity

This extends the received scanner-local nominal-material helper with the finite
built-in StorageConversion/StorageOperations suffix. It is NOT an admission
estimate, retained arena bound, support change, calibration, production endpoint
execution, end-to-end conversion or authoritative-recovery acceptance. All
public implementation/policy/authority/recovery bytes are unchanged. The helper
and tests live under tests/ and are excluded from the plugin ZIP. This document
is packaged documentation, not runtime configuration.

Conditional inherited ABI: LP64, NTS, nondebug, alignment8, Zend-MM, no
cookies/protection/end magic. PHP5.6 zend_API.c:1150-1168 shares default-property
zvals in NTS but copies them in its ZTS branch:1159-1164. Neither this helper nor
the received local model establishes ZTS or 32-bit support. The supplied
source-file/callsite extent is conditional; 256 is NOT a shipped filename cap.
The direct probe illustrates 1024 and sweeps 255/256/1024/4096. It never installs
an input-refusal rule. The inherited raw60000/node8003/arrays16/depth16 context
is not a new product policy; depth64 remains a separate rejection diagnostic.

The helper's bindings() identifies all four exact product source files and the
received original PHP source-member manifest. Existing local trace/compiler/VM
bindings are retained, not reinvented. Evidence is outside FooProjects:
/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/product-caller-trace-checkpoint/
The exact source manifest there binds Git, canonical/frozen sources, TAR/ZIP,
original PHP5.4.0/PHP5.6.20 archives and their members, commands and ALL executed
namespace origins, including nested/isolated/probe processes. Source inspection
and modern arithmetic tests are not execution on either literal PHP floor.

## Actual finite caller paths and arguments

Start at StorageConversion::option_snapshot():276 -> decoder->decode($raw).
The FIVE callsites reaching that snapshot are :63, :107, :177, :184, :205.
The first two reach it directly from switch_storage/recover. The other three
are advance's copying/verified/flipped branches for a table-to-option operation.
Advance is called from switch_storage:87 or recover:110. switch_backend:35 and
replace_retained:40 call switch_storage with an explicit boolean. Authorized
dispatch:30-31 calls the selected method, optionally from handle:48. The helper
binds these source lines/expressions and tests Reflection formal arities AND
nonreference signatures. It does not pretend argument slots are locals.

There are EIGHTEEN finite descriptors: switch has four snapshot sites, recover
four, replacement one; each has dispatch-only and handle-plus-dispatch variants.
Replacement is authorized only toward table, so its advance branches use
TABLE snapshots, not option decoding. A failing reachability test originally
caught the six impossible replacement advance descriptors; they were removed.
Every finite descriptor starts with option_snapshot and ends at an explicit
unresolved external receiver boundary. Calls directly to the coordinator form
shorter subpaths; the captured finite suffix here conservatively includes the
built-in dispatch. Direct dispatch means concrete built-in receiver classes;
arbitrary extension subclasses/overrides, extra actual arguments, WordPress
hook/call-user-func/HTTP dispatch stacks and their graphs are outside this finite
profile, NOT silently zero-sized frames or a universal hook/OOM obligation.

Argument slots (actual calls supply the listed formal arities):

- option_snapshot(): 0.
- advance($r,$source): 2. The record is a by-value mutable local/COW candidate;
  the source is the two-field physical/value snapshot or, for flipped recovery,
  null. Granting a full source shell for that null case is conservative.
- switch_storage($request,$replacement): 2, including the explicit boolean.
- switch_backend/replace_retained/recover($request): 1 each.
- dispatch($operation,$request): 2, operation in switch/replace/recover.
- handle(): 0; external WordPress frames/extra actual args remain unbounded here.

The request's CLOSED outer shape is five keys, validated by authorize_request:
blog_id/scope/target/confirmation/nonce. Its string values, especially externally
supplied nonce, have no invented byte cap in this helper. A control record has
15 (v1) or 16 (v2) closed keys from StorageControl::decode():28-44 and coordinator
construction:77-82. Sixteen is a conservative outer-copy grant, not a v1 rewrite.
The snapshot shape physical/value is returned by BOTH option_snapshot:280 and
table_snapshot:336. No recursive graph is replaced by a two-scalar assumption.

In the advance suffix the source is the pre-existing table snapshot when
returning to option. Its value can retain many decoded strings/arrays and table
rows. The source-bound table limits (heads<=100, rows<=10000 and encoded bytes
<=16777216) and the existing codec/source safety/phase inventory still matter;
raw60000 for the CURRENT option scanner is NOT a new cap on that pre-existing
table graph. The exact new shallow-copy bytes below do not prove that graph's
retained capacity. Product receivers similarly retain calibration, connection,
codec/snapshot, controls/gate and external WordPress state through object refs.
Those graph frontiers remain explicit nulls; they must not be charged as tiny
scalars or casually counted again as recursively duplicated source payloads.

## Source-derived copy semantics and nominal material

PHP5.4 zend_builtin_functions.c:2006-2029 and PHP5.6:2027-2050 capture actual
arguments, separate nonobject shared nonreference slots and add refs to the
resulting zvals. zend.h:SEPARATE_ZVAL_TO_MAKE_IS_REF/SEPARATE_ZVAL mutates each
argument SLOT. Repeated visits to the same slot do not allocate again; distinct
mutable slots sharing an original request zval can each separate. Symbolic
identity records the sharing family; it is NOT a deduplication key or a claim
that mutated record COW versions remain identical. Existing is_ref or refcount1
slots do not separate. The offline upper grants refcount>1/nonref independently
to every distinct finite product slot. Bool/scalar copies have a GC zval grant;
only operation has a new bounded string backing (max7+NUL).

Crucially, zend_variables.c's IS_ARRAY copy constructor allocates a new HT,
initializes it with zend_hash_num_elements(original), then zend_hash_copy with
zval_add_ref. The new vector is rounded from ELEMENT COUNT, not possibly large
historical original capacity. Buckets/keys are copied; nested values receive
addrefs, not recursive zval/string/array copies. Therefore:

- New array shell/backing = nominal(HT72) + vector(count) + sum of nominal
  Bucket72/key storage. Key duplication is conservatively allowed even when
  source keys are interned. The separate GC box grant is nominal(32).
- No separate per-child string copy or recursively doubled serialized payload
  belongs to this shallow-copy delta. That is NOT evidence the shared existing
  source graph has size zero or is released at catch.
- New trace frame shells/fields/function/class/file/type strings and argument
  containers use the same received source-layout rules, but concrete product
  class/method lengths and arities instead of arbitrary fixed byte allowances.
  Receivers are referenced, not recursively cloned.
- Additional finite trace entries have numeric buckets but do NOT each own a
  separate outer trace HashTable/vector. The local exception object/property
  grant is retained once, not per product frame.

Original zend_hash.c init/copy/index/update/resize, zend_hash.h structs,
zend_gc.h's zval_gc_info, zend_variables.c copy constructor and the received
exceptions/API/allocator members bind these formulas. The original member
manifest hash is checked against the direct probe; no installed modern PHP
headers substitute for retained original sources.

## One shared local-plus-product trace vector and lifetimes

For local frames L and product frames S, compute vector(L+S), never
vector(L)+vector(S). The local helper already charged its outer vector/shell.
At catch add vector(L+S)-vector(L). During construction also REPLACE the local
old-vector overlap with the joined growth sequence's old-vector overlap:

    catch = local.catch + suffix.material + joined.vector - local.vector
    construction = local.construction + suffix.material
                   + joined.vector - local.vector
                   + joined.old_overlap - local.old_overlap

The received hash resize doubles at9/17/33... and rehashes/frees the old vector.
For growth during capture, old+new overlap is temporary, not a second catch
vector. At21 local plus6 suffix frames the capacity remains32 and the catch
vector delta is ZERO; it is not an independently rounded suffix vector80.
At8 local plus1 suffix, catch delta is64 and construction also carries the
old-vector80 overlap. These equal/just-over cases are executable tests.

Catch retains the finite metadata, captured mutable slots and their shared
argument/object graph reachability. Construction can additionally overlap a
resize-old vector. VM/source caller argument lifetimes, exceptional unwind,
source arrays/strings/GC/object-store frontiers and delayed teardown are not
collapsed into a single shallow-copy total. No historical allocation census or
per-acquisition tail allowance is introduced.

## Test-first evidence and bounded results

The first frozen run had8 missing-helper errors (exit2) BEFORE helper/doc bytes.
A second red run had3 reachability failures (exit1), before the targeted path
prune. Logs and original frozen sources are retained, not overwritten by green.
First green: focused66tests/10438assertions; full676/12960; four direct probes
pass, PHP8.5.11/PHPUnit9.6.34. Modern tests bind callsite expressions/arities,
closed outer shapes, aliases vs distinct slots, reference/refcount1 behavior,
large nested source values without invented recursive-copy debt, historical
capacity vs element-count, every local case joined to every finite descriptor,
conditional filenames and persistent false/null proof flags. Final exact SHA
executions, lint/build/package/origin manifests are recorded beside those logs.

At illustrative file extent1024 and inherited raw60000/depth16, BOTH source
profiles give1548 conservative joined cases, max27frames, new-material nominal
catch upper1290680 and construction upper1290824. These are offline conditional
NEW material sums, NOT complete trace/arena/whole-operation bounds, nor observed
PHP5 allocations. The direct probe checks108 boundary controls, depth0/1/7/8/
12/16 plus separate64 rejection enumeration, joined8/9/16/17/32/33 boundaries,
and60001 raw lengths per profile. This does not claim every abstract local path
is an actual reachable production failure, or the final endpoints have run.

## Remaining gates / stop

existing_product_argument_graph_nominal_upper=null;
external_receiver_trace_nominal_upper=null; whole_operation_nominal_upper=null;
retained_arena_upper=null; object_store_resize_nominal_upper=null;
arena_retention_proven=false; shared_gc_frontier_proven=false;
complete_trace_capacity_proven=false; fits_justified_helper_budget=false;
task_scope_completed=false; children_released=false.

Retained split/tail/cache/rest/free/coalescing placement, shared object-store/GC
frontiers, PHP5.4 transfer EXTRA vs PHP5.6 reservation R/retained C, exact helper
VM/cache lowering, filename proof and whole-operation lifetimes remain separate
unresolved obligations. Source graph inventory is not a retained capacity proof.

Return exact seven-part PARTIAL to OPS and STOP for separately authorized fresh
native independent exact-candidate review before accepting any numerical term.
Final actual production endpoints/end-to-end conversion AND authoritative
recovery remain required AFTER proof/review/separate-policy gates. Exact-final
literal PHP5.4.0/WP5.0 AND PHP5.6.20/WP6.0 plus delayed teardown,32-bit/direct
review-publication, combined A+B/native approval, reviewed PR-update-only and
actual-merge gates remain. No new runtime provisioning/validator restart,
public-policy/guard/ownership/v1 change, push, release or production action.
