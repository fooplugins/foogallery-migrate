# Conditional source-safety phase ledger — review checkpoint, NOT admission

## Authority and exact deployment limit

This continues `216ddc788d698498af75015ffc626a03039f9f0f` on
`feature/migrated-store-scalability`, SAME task `t_396d60fe`, under the received
14 October `ops-allocator-continuation.md`. It reconciles the prior reverse
serialization preflight and expanded identity with the independent REQUEST
CHANGES. No public profile, `StorageCalibration::policy()`/PHP<7 guard, declared
support, authority/recovery/v1 handling, provisioning or timing assertion changes.

The newly received startup evidence closes B/mode ONLY for the two explicitly
controlled PHP5.4.0/5.6.20 LP64, nondebug, NTS CLI launches, `USE_ZEND_ALLOC=1`,
`ZEND_MM_SEG_SIZE=262144`, no loaded/scanned INI. Source-plus-launch evidence is
indirect; it is not heap-field inspection, original-launch reconstruction,
reproducible-build attestation, production SAPI approval or native timing.
Retained `allocator-startup-evidence/evidence.json` SHA256:
`699759ef80d07a433a0b688f7bc66b7015576f9670c09fc63debed155ec6043a`.
Original archive hashes remain those in `storage-source-safety-checkpoint.md`.

## Implemented pre-native table JSON safety prerequisite

`LegacyStateCodec::decode()` now runs `inspect_json()` before native parsing.
The pass retains only offset/counters, one-character views and at-most-five-byte
scalar fragments; it does not construct a token list, graph, recursive stack or
copy string contents. It is lexical resource inspection, NOT syntax validation
or memory admission. Native syntax, envelope/tag/key and logical budgets remain.

For logical-node cap N (keys INCLUDED) and logical depth D:

* Each tag contributes at most three JSON tokens (tag container, type string,
  scalar or items container). Each logical entry contributes one pair container.
  The entry count is `(N-1)/2` for an encoded array tree. Four outer envelope
  tokens plus `4N` safely dominate the total. Count duplicate keys and discarded
  values too: measuring only the final overwritten graph would be unsound.
* A logical array edge adds three JSON containers. The envelope and root tag
  contribute two, and a final empty array's items container contributes one.
  Native nesting is at most `3D+3`, including the empty-array boundary at D=64.
* Strings skip escaped characters without copying or interpreting their contents.
  Native JSON is still responsible for grammar, UTF-8 and escape validity.
* The only unquoted scalars in a valid v1 envelope are integer `1` (version),
  `true` and `false`. Logical integers/floats/null/binary strings are tagged.
  A malformed long decimal is refused after inspecting at most six scalar bytes,
  before native `zend_strtod`/numeric fallback. Small scalar fragments are copied
  only after checking that bound. Invalid short grammar can still reach native
  rejection, but only inside the counted byte/token/depth envelope.
* `4N+4` saturates at PHP_INT_MAX before multiplication; D is already capped at64.
  Input length also fits the original parser's signed-int `str_len+1` domain
  (at most2147483646). This is a C-width refusal guard, NOT a claim that such an
  input can be admitted under an actual PHP memory limit.

Default N10000/D64 therefore bounds lexical native tokens at40004 and nesting
at195. These are structural domain derivations, not cost coefficients. Valid
encoder outputs, exact typed keys, binary strings/negative zero, escaped JSON and
whitespace remain covered. Flood/duplicate-key prefixes can now fail earlier;
rejected noncanonical input is not promised a particular error or native call.
The calibration fingerprint already hashes this codec, so old fingerprints do
not silently approve new bytes. The JSON graph/raw-row allocation that occurs
BEFORE this pass still needs the phase budget below.

## Conditional allocator/container building blocks

References below are to the verified original release archive members, exported
read-only into this checkpoint's diagnostics. Both versions have the cited
layout definitions; references default to5.4.0, with5.6 differences noted.
No scalar below is a universal deployment cost or an approved profile.

* `Zend/zend_alloc.c:336-404,528-539`: nondebug/nonprotected block header16,
  minimum small free header32, alignment8. With the received LP64 configuration,
  native requested bytes s have `t(s)=max(32,align8(s+16))`. Segment plus guard
  overhead is32. `Q(s)=max(B,round_up_B(t(s)+32))` dominates a NEW segment acquired
  for that request (`:1953-1965`;5.6 `:1957-1969`). No credit is taken for fitting
  existing free blocks, reuse, cache hits or fragmentation being absent.
* A conservative segment-accounting ledger can charge Q to EVERY allocation and
  realloc event, never subtract freed charges, and add the initial real-usage
  baseline. This deliberately retains old/new buffers and discarded duplicate
  values. It is an event-sum construction, not `live_payload*factor`. All events
  (including runtime/driver events) must actually be enumerated before it becomes
  a bound. Realloc paths (`:2119ff`;5.6 `:2123ff`) do not permit counting only the
  returned final buffer. Q is not a bound for malloc-backed extensions or RSS.
* `Zend/zend.h:307-324`, `zend_gc.h:81-97`, `zend_hash.h:54-82` give conditional
  LP64 zval24, allocated zval_gc_info32, Bucket72, HashTable72 and root-buffer32.
  Charge the allocated GC wrapper, not only sizeof(zval). Key allocation uses
  sizeof(Bucket)+nKeyLength in `zend_hash.c:247-253`, numeric buckets72, and pointer
  data are inline in the bucket (`:108-130`). No guessed per-node multiplier.
* Hash pointer vectors start at8 slots, power-of-two capacity, and double
  (`zend_hash.c:139-162,449-455`). Charge each growth event, including old/new
  overlap. Per-container cumulative requested slot counts are less than twice
  final capacity; a useful loose bound is `8+4E` for E insertions. Convert that
  request bound through Q for EACH growth event, not one aggregate Q.
* `ext/standard/php_smart_str.h:37-66` in BOTH versions: start capacity78,
  growth slack128, allocation capacity+1. A final-length-L buffer requests at
  most `max(79,L+129)` per growth. Number of appends/growths and realloc overlap
  still matter; native serialize/JSON do not allocate only their final length.
* JSON native input conversion requests `2L+3` before parsing
  (`ext/json/json.c:605-631`;5.6 `:683-709`). Parser initialization
  (`JSON_parser.c:242-254`) separately allocates its struct, depth*4 integer stack
  and, when above static capacity, depth*sizeof(zval) storage (NOT pointer-size
  storage despite the declared pointer type). The call still requests depth512;
  the lexical195 nesting ceiling does not reduce that native stack allocation.
* The lexical event ceiling bounds native JSON containers/scalars/duplicate-key
  insertions on rejected prefixes as well as accepted input. Each insertion's
  key/string bytes are bounded by input L. Parser smart strings and discarded
  values must be charged separately from the resulting native tagged graph.
  Untag adds key probes, `array_keys`/`range` validation arrays, base64 decode and
  base64 parity strings, packed-float buffers and a distinct logical graph.
* Native option unserialization still requires reference/destructor tracking
  blocks and graph allocation (`ext/standard/var_unserializer.re:27ff`), even
  though the structural scan rejects object/reference/custom tokens first.
  Canonical parity serialization retains graph+raw+serializer workspace.
* Finite-float measurement is NOT workspace-free: `ext/standard/var.c:737-745`
  explicitly allocates from effective serialize_precision before appending.
  `Zend/zend_strtod.c:200,416,476-510` uses malloc-backed Bigint blocks/freelists;
  `rv_alloc` also uses them. These are outside Zend-MM real-usage accounting.
  A131-byte scalar-output check does not bound its preceding workspace. No new
  precision/configuration approval or coefficient is introduced here.

Checked sums/products/alignments/rounding must refuse before overflow at BOTH
PHP integer and native C size/count widths. `StoragePreflight` already refuses
overflow-to-float in raw-byte amplification; it does NOT evaluate this ledger.
The existing offline worksheet is still arithmetic-only, not evidence approval.

## Full allocating-phase/overlap ledger

Here G means complete logical graph including keys/containers; J means native
JSON tag graph plus parser/base64/parity; S means serializer buffers/tracking;
W means raw driver wire/result/metadata AND PHP row containers; P means prepared,
escaped/placeholder-normalized SQL AND driver command copy; C means runtime call
frames/temporaries/COW clones; R means independently justified response/cleanup
reserve. These remain named obligations, NOT supplied numeric constants.

| Phase / source boundary | Simultaneous obligations (no COW/reuse credit) | Existing/new refusal boundary |
| --- | --- | --- |
| Authorization/preflight | Initial usage, fixed control/nonce/error response C+R | Existing auth/profile refusal before storage SQL remains unchanged. |
| Option size/fetch | W for size probe, CONCAT result X+2, raw substring X, row containers, P | `StorageConversion::option_raw` size budget before payload SQL; row/CONCAT/substr allocation still precedes structural scan. |
| Option scan/decode/parity | Raw X, bounded scan frames/tokens, G(source), native tracking, S(parity), scalar-float workspace, C | `LegacyOptionDecoder` scan before native; byte/node/depth/root caps and exact canonical parity. |
| Table raw read/native decode | Size/count metadata, W(raw rows), row/visited/parent maps, input JSON, UTF16/parser/J, G(partial logical) and C | SQL encoded-length/count caps before fetch; NEW lexical event/depth/scalar caps before native JSON. No native-memory-admission claim. |
| Table-origin source assembly | Accumulated G(source), previous heads/row maps, current W/J/base64/parity, descendant reconstruction and validation C | Existing bounded readers/snapshot controls; aggregate heads<=100, rows<=10000, encoded bytes<=16777216. These do not bound every transitive native allocation by themselves. |
| Forward/replacement staging | Retained G(source), record/tag/change/tree lists, staged rows/JSON, P/W, physical/logical hashes, optional retirement manifest | Existing per-record and staging caps. Include both staged/tagged and flattened forms, not just final JSON bytes. |
| Reverse measurement/serialization/insert | Retained table G(source), scalar-float measurement workspace, full S(target option), P(insert) and driver command, C | Received encode() structural/exact-byte counting and budget callback before whole-graph serialize/insert; callback is not a scalar-workspace proof. |
| Verification/recovery readback | G(source) can remain live, G(target), current W/J or target option raw+tracking+S(parity), maps/digest C | Existing advance()/target_snapshot recovery phases choose recorded authority; table existence never proves verified or authoritative state. |
| Backup/publication/control | Source option/backup SQL P, both retained evidence copies, hashes/control JSON, old/new control records, C+R | Existing fenced transaction/ack handling unchanged; uncertain outcome retains evidence, not arbitrary fallback. |
| Retirement capture/replace | BOTH table manifests, row arrays, fixed-column hashes, manifest JSON, backup identity, source graph already in caller, P/W/C+R | Capture count/budget before fixed-hash rows, all generations/orphans included; no payload hydration. Replace retains one pinned transaction. |
| Failure/rollback/response | Every object still owned by unwinding frames, errors, rollback SQL/driver buffers, cleanup/control response C+R | Separate reserve REQUIRED; Zend fatal-reporting reserve8192 is not recoverable application headroom. No never-OOM/hard-wall-time guarantee. |

`LegacyTransactionConnection::get_results():80-89` uses buffered mysqli results,
fetch_assoc PHP row copies and then frees the result. `get_var():93-97` also makes
array_values. Releasing the result does not make its wire buffers disappear from
peak overlap. The exact mysqlnd source paths are retained, notably5.4 result
`:1123-1199`/wireprotocol`:935-951`,5.6 result`:1383-1435`/wireprotocol`:1001-1017`.
Field metadata, pointer arrays, row wire chunks, null bitmaps and command buffers
must be included; an extension VERSION or presence of mysqli does not alone
establish driver/configuration or preparation-hook identity. Arbitrary libmysql,
SAPI, active WordPress hooks, allocator modes and builds stay UNAPPROVED.

## Verification and review boundary

Test-first RED:12 tests,4 undefined-method errors/8 exception-type failures.
After implementation, correction of one malformed test fixture, and explicit
independent depth coverage: focused31 tests/92 assertions; complete modern
PHP8.5.11 CLI558 tests/2359 assertions. The isolated native-boundary probe uses
namespaced spies ONLY in its separate process. Six large/refused cases invoke
native JSON zero times; depth flood stops after9 peak updates, long malformed
million-byte decimal copies zero scalar bytes, token flood copies28 scalar bytes.
A valid envelope invokes native JSON exactly once. These are executed local
work assertions, not literal-floor/SAPI/performance or storage-DB acceptance.

The actual product prerequisite is now coherent across option structural scan,
reverse pre-serialization checking, and table JSON pre-native structural work.
The complete numeric phase-event census, VM/COW/driver/float workspace and reserve
proof is STILL OPEN. Symbolic terms above are NOT numeric proof; received B does
not close them. No indispensable new external value is invented to replace that
remaining analysis. No more B export, guest boots, provisioning, native target,
final validator restart or public-policy integration is requested by this slice.

STOP for fresh independent EXACT-checkpoint source-safety review and SEPARATE OPS
policy-integration authorization. OPS owns receiving/lifecycle. SAME-card and
all children remain held; partial review must not be mistaken for approval to
complete Milestone A or to enable public admission. Literal-floor, installed
WordPress/DB/output, stale-fencing/lost-ACK/death, delayed teardown/native timing,
combined A+B and publication gates remain separate and incomplete.
