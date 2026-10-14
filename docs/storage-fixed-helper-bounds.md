# Fixed-helper compiler/stack prerequisite (offline; review required)

This is a PARTIAL prerequisite to `StoragePhasePeakCandidate`, NOT its integration,
headroom acceptance, or task/Milestone A completion. Run
`php tests/tools/storage-fixed-helper-probe.php` for evaluated numbers. Production,
public estimator/policy, decoder, and accepted ordinary budgets are unchanged.
No legacy PHP compiler/runtime was run. Modern tests do not earn literal-floor credit.

## Binding and domain

`StorageFixedHelperBounds` binds the entire actual decoder file SHA-256
`cf7ef2038a5395f70d64f9f2702c3735066a74048cb00dfddc0f97202b1777c3`.
Only `decode`, `scan`, `unsigned`, `token`, `literal`, `fail` are lowered. Constructor,
encode/measure, arbitrary source, and arbitrary callbacks are NOT modeled. A changed
file/hash, unknown method/edge/version, or out-of-domain residual/depth is refused,
not tokenized or estimated by an unreviewed fallback. Both retained PHP 5.4.0 and
5.6.20 compiler/runtime bindings are returned by `bindings()`, verified against
retained original source archives by the checkpoint verifier. Source paths below
are `php-VERSION/Zend/…` within those archives, not installed host headers.

Keep the existing classic 64-bit, non-debug, 8-byte-aligned Zend allocator/runtime
profile and fixed helper-loaded/primitive-only/hook-disabled scope. Existing raw,
node and depth constraints, 32-frame caller/argument budgets and native/transport
reservations remain necessary; this work does not invent more permissive inputs.
The useful ordinary depth-16 domain is distinct from the decoder's depth-64 ceiling.
An attempted depth `d+1` can exist briefly before the guard fails.

## Conservative compiler ledger, not an opcode emulator

In both compilers `get_temporary_variable` increments `T`; `lookup_cv` adds a CV
only for a new name (5.4 compile.c:285-315; 5.6:293-323). Ordinary named locals and
parameters are CVs, not a temporary for each occurrence (5.6:661-714; 5.4 receive
at 1825-1864). `$this` fetches may first allocate a temporary that property/method
lowering reuses: do NOT count only the final optimized opcode (5.4:5247-5292;
5.6:5513-5558,2017-2068). We include `this` as a CV upper allowance too.

For this fixed syntax, each binary/unary/cast/comparison, local assignment,
increment, dimensional read, function result, and short-circuit begin allocates
at most one T. A `$this` method site gets TWO allowances (receiver/property fetch
and call result); assignment of its result gets another. A direct property fetch
gets one. Short-circuit end reuses its begin result, not another allocation
(5.4:4610 onward; 5.6:4871-4938). Switch gets FIVE allowances (one per case),
although its compiler control variable is shared (5.6:5042-5075). Silence has one
per branch, ternary one merge, and array initialization one; new/throw gets four
including class, object and constructor-call lowering. Jumps, returns, loop
bookkeeping and OP_DATA add no unlisted T. Include BOTH conditional branches, but
NEVER multiply these compiled sites by dynamic loop/node visits.

The executable `temporary_sites` ledger gives a ceiling for EVERY allocating
source line. Its small margins over the above primitive count are deliberate;
no per-method byte guess, arbitrary 68000-byte frame, or exact compiler output is
substituted. In particular scan:96 includes two short-circuit sites plus two
comparisons AND the two-slot fail site; scan:101/105/109/113/121 include receiver,
result AND assignment; scan:127/128 include depth addition. Decode:37 includes
both silenced native calls and their merge even on a legacy version taking only
one branch. A changed construct cannot inherit this proof just because it has
the same method name: the full helper hash must still match.

Evaluated common source ceilings:

| method | CV | T (sum of line ceilings) | outstanding calls | argument slots incl count | cache slots |
|---|---:|---:|---:|---:|---:|
| decode | 5 | 38 | 1 | 6 | 28 |
| scan | 11 | 106 | 1 | 6 | 58 |
| unsigned | 5 | 16 | 1 | 5 | 6 |
| token | 7 | 21 | 1 | 4 | 6 |
| literal | 4 | 8 | 2 | 4 | 8 |
| fail | 1 | 4 | 1 | 2 | 4 |

CV names are raw/offset/nodes/value/this; scan additionally depth/key/type/bytes/
count/i; unsigned raw/offset/terminator/value/this; token additionally max_bytes/
start; literal raw/offset/literal/this; fail's only allowance is this. No variable
variables, captures, symbol table creation or user call inside arguments occurs.
Literal's `substr(raw,offset,strlen(literal))` is the sole syntactic two-call
maximum: two outer arguments, one inner argument and its count require four
slots, NOT the sum of all call arities in the function. `nested_calls`/`used_stack`
are compile-context maxima (5.6:2008-2010,2599-2620,2734-2735).

Cache ceiling is two per syntactic call/property/constant, covering polymorphic
sites even where one suffices. Counts: decode 12 calls + one property + one
constant; scan 26 calls + three properties; unsigned/token three calls each;
literal four; fail class/constructor two. GET/FREE_POLYMORPHIC_CACHE_SLOT reuses
the just-allocated receiver cache during method rewrite (5.6:68-95,2017-2045);
there is no second permanent method cache allocation. A runtime cache belongs
to the ONE fixed op_array, not each recursion frame or sibling invocation.

## Evaluated R versus C and argument transitions

64-bit field-layout sums from the retained compile/execute structures are:
execute_data 144 (5.4), 136 (5.6); temp_variable 32; call_slot 32 (5.6); pointer 8.
CV storage is two pointer arrays, hence 16 per CV. Thus

    5.4 C = R = 144 + 16*CV + 32*T
    5.6 C = 136 + 16*CV + 32*T + 32*nested_calls
    5.6 R = C + 8*used_stack

Use 5.4 vm_execute.h:358-365 and 5.6 execute.c:1625-1630,1673-1686 plus
execute.h:264-267. All results are already 8-aligned. The default page payload
is 130944 bytes. Frame growth uses `R > residual`, so EQUALITY FITS. In 5.6 the
top after entering is base+C, NOT base+R. Subsequent SENDs/count advance it again.

| method | 5.4 C=R | 5.6 C | 5.6 R |
|---|---:|---:|---:|
| decode | 1440 | 1464 | 1512 |
| scan | 3712 | 3736 | 3784 |
| unsigned | 736 | 760 | 800 |
| token | 928 | 952 | 984 |
| literal | 464 | 520 | 552 |
| fail | 288 | 312 | 328 |

5.4 execute.h:220-224,302-341 is separate debt: every argument push checks a
single pointer; `push_args` may extend when arguments cross a page or leave the
page exactly full; transfer copies the pack and frees an emptied intermediate
page. The checked execute-data reservation happens AFTER this. The executable
witness with only 8 parent bytes free and five arguments acquires TWO pages,
peaks at two, frees one DURING transfer, then frees the other after unwind.
Calling all of that `R=C` or assuming just one complete-call acquisition is false.

The transition model follows actual fixed-method edges and arities, frame entry,
argument cleanup, failure chains and repeated siblings. Its chosen UPPER sizes
are a witness, NOT an exact compiled trace: increasing a frame can change page
boundaries and does not monotonically increase acquisitions. The following live
bound instead uses the maximum sizes, without relying on that monotonicity.

Let B = sum(C_upper + 48) for decode, d+2 scans, unsigned/token/fail. This knowingly
overcounts an extra rejected scan plus a token chain that cannot coexist with
that rejection, all incoming packs, and terminal unused argument capacity.
Every noncurrent helper page left by checked growth has residual < Rmax; in 5.4
at most five transferred pointers can then leave that page. Therefore its fill
is at least F = P-Rmax (5.6) or P-Rmax-40 (5.4). Only the current page may be
underfilled; 5.4 additionally permits one transient split-pack transfer page
before the old page is freed. Caller-owned initial page is baseline, not counted
again. Conditional on the source ceilings, stable additional pages <=
`1+floor(B/F)`; transfer peak adds one for 5.4 only.

| domain | 5.4 B / F | stable / transfer peak | 5.6 B / F | stable / peak |
|---|---|---|---|---|
| depth 16 | 71264 / 127192 | 1 / 2 | 71792 / 127160 | 1 / 1 |
| depth 64 ceiling | 251744 / 127192 | 2 / 3 | 253424 / 127160 | 2 / 2 |

These are LIVE VM-page bounds, NOT historical segment acquisition bounds. Frame
return and argument cleanup reset/free the source-owned pages. Siblings may
repeat the same growth sites and arbitrarily many acquisitions within the node
budget; the analytical bound does not count a new segment per visit. Tests sweep
all 16369 aligned parent residuals for each depth/version, and all residuals for
argc 0..5, plus equality/just-over, failure, and repeated siblings. Descriptor
witnesses must unwind to zero source-owned pages and stay within the live bound.

## Phase ownership, material/cache reuse and the precise remaining inequality

Lexical scan: raw is immutable pre-existing input, with ancestor frames, CV/ref
boxes, bounded token/cast/concatenation temporaries, and lazily initialized fixed
method caches. It does NOT yet have the native decoded graph. All substring
creation here is bounded: token <=128 bytes, unsigned <=20, int <=21,
literal <=2; string contents are SKIPPED without substring materialization.
The executable payload-only cap grants every CV/T/argument a 32-byte box and
every T an aligned 129-byte string (136), even though most cannot coexist.
It is 344952 bytes at depth16 and 1225848 at depth64. This is a conservative
inventory candidate, NOT its ealloc/PCRE/allocator/cache lowering proof.

Fixed runtime cache blocks have at most 1120 bytes total under the existing
16-byte block header / 24-byte unsplit-tail allowance: sum(8*slots+40) across
SIX op_arrays. They are persistent pins potentially allocated between VM page
acquisitions, but are initialized once and shared by siblings. Neither count
gets multiplied by nodes. Temporary scanner allocations disappear or enter
bounded allocator caches; treating them all as permanently pinned is equally
incorrect. Need a source-backed rest/cache/resize ledger, not an allocation log.

Native decode/parity: scanner ancestors are gone; decode frame, native decoded
graph and parity serializer overlap with raw/caches. Its graph/serializer terms
stay in the existing phase candidate; do NOT multiply the graph by scanner depth.
Malformed lexical failure has scanner state + exception/trace/cleanup, NOT the
decoded graph. Parity refusal has decode/native graph/serializer cleanup and a
DIFFERENT trace overlap. Trace arguments may retain/copy the graph depending on
runtime reference handling: full trace HashTable/bucket/resize/cache families
remain unresolved by these frame numbers.

The exact-hole/coalescing controls in `StorageVmRetentionWitness` are reused,
not rewritten. Their arbitrary 8192-byte carve is NOT established as a lexical
helper allocation. Reacquisition is not synonymous with fresh persistent segment
retention; returned holes can be reused or carved. To replace the retained VM
reserve in the existing formula, the still-missing source premise is:

    retained arena charge caused by repeating helper VM sites
      <= independently justified ordinary allocator charge for simultaneous
         scanner boxes/strings + six shared caches + applicable trace inventory
         + their bounded free-cache/rest/resize capacity
         + exceptional live-page charge

under the already accepted 128MiB/16MiB and 256MiB/64MiB budgets. The ordinary
allocator cutoff is not applicable to a VM page itself. Bounded material payload
and at most 1/2/3 live pages alone DO NOT prove this inequality: they omit allocation
headers/size classes/cache occupancy and fragmentation/pinning across returned
large holes. No source lemma currently supplies the missing exceptional-page
retention charge against that simultaneous inventory. This is the precise
unmet premise, not evidence of a source-counterexample or a falsified useful fit.
The prototype's existing `fixed_helper_vm_frames`, scanner and trace obligations
are NOT silently cleared by this new prerequisite. Probe explicitly returns
`arena_retention_proven=false`, `fits_justified_helper_budget=false` and all three
missing families. This does not change public admission into refusal-only code;
there is no public integration at all.

## Verification and next gate

Initial eight missing-model errors establish RED; focused/new and existing full
suite are rerun on the frozen exact checkpoint with complete per-process product/
test origins, including isolated children. Modern actual decoder success,
malformed/depth refusal and reuse supplement the source transitions; they do not
execute legacy source. Namespace/source receipts, retained archive hashes,
build and selective commit identity live in the external checkpoint directory.
Fresh independent exact-candidate review must audit these source ceilings and
fill lemma before using them for any next proof step. Scanner/cache and trace
lowering plus the retention inequality still need a separately authorized slice;
actual conversion/recovery and every final runtime gate remain required later.
