# Source-derived safety prerequisite: partial, not calibration approval

This checkpoint implements two local prerequisites behind the unchanged public
admission boundary. It does not supply a numeric memory policy, enable PHP<7,
approve a runtime, or establish Milestone A acceptance. The adopted proposal and
OPS `ops-envelope-review-receiving.md` remain authoritative. This document records
the specific unfinished proof rather than strengthening the support contract to
universal never-OOM, arbitrary-host RSS, or hard ten-second completion guarantees.

## Implemented prerequisites

* `LegacyOptionDecoder::encode` traverses table-origin logical state before
  whole-graph native serialization. It uses the existing option decoder's byte,
  depth, node and top-level limits. Strings are counted without copying their
  contents. Additions check the remaining byte budget before adding, preventing
  native integer overflow. Finite float formatting uses native scalar
  serialization; its precision-dependent workspace is NOT a closed memory proof.
* The reverse copying path invokes the existing memory-budget check with that
  counted size before serializing the complete graph or inserting the option.
  Native output must match the count and pass the existing object/reference-free
  structural scan. No second native hydration is performed by this encoder.
  Aliases/references, cycles and nonfinite values retain their existing refusal
  domain; failures happen before the target option insert. These changes do not
  prove the preceding table hydration safe.
* Calibration identity now includes the canonical decoder, collection envelope,
  settings and checkpoint/queue repository bytes, plus the relevant current
  allocator environment. `identity()` exposes those same fingerprint inputs for
  evidence inspection; it is not an approval endpoint. Public profile shape,
  trusted-only policy source, authorization ordering and PHP<7 refusal are
  unchanged. Old fingerprints are invalidated, never silently migrated.
* Current `getenv` values do not attest process-start allocator state. Trusted
  bootstrap code can mutate the environment after Zend-MM initializes. Further
  WordPress/driver/build provenance remains necessary for a real policy.

## Retained source provenance and conditional facts

Source archives were inspected locally, without starting either retained VM or
provisioning a runtime:

* PHP5.4.0 SHA256
  `63fbc4d0f0e6fb36895d48f028a823c5981df8025ef444ac4ebe52586a91f38b`.
* PHP5.6.20 SHA256
  `9a7ec6e1080ee93dcbe7df3e49ea1c3c3da5fc2258aff763f39ab3786baf8d56`.

Both hashes match retained x86-system input/provenance receipts. Targeted extracted
members and their archive bytes are checked by the checkpoint verifier in Dev
diagnostics outside FooProjects. Existing `x86-system/build-php.sh` describes the
non-debug, non-ZTS CLI/mysqlnd build; this is a build recipe, not process-start
allocator attestation or native performance evidence.

The following facts are conditional source derivations, not measurements or
approved coefficients. References are relative to each original PHP source tree:

| Allocation | PHP5.4.0 | PHP5.6.20 | Obligation |
| --- | --- | --- | --- |
| Zend segment default and fatal reserve | `Zend/zend_alloc.c:485-488` | same | Defaults 256 KiB and 8 KiB are conditional, not fixed deployment costs. |
| Runtime segment selection | `Zend/zend_alloc.c:1195-1217` | `1199-1221` | Startup environment can override segment size B. |
| New-segment rounding | `Zend/zend_alloc.c:1953-1965` | `1961-1969` | Charge segment rounding/fragmentation, not only requested payload bytes. |
| Native serialization | `ext/standard/var.c:897-916` | `912-936` | Graph remains live while a separate smart string grows. |
| Float formatting workspace | `ext/standard/var.c:737-745` | corresponding double branch | Account for effective serialize_precision before using scalar measurement. |
| Smart string growth | `ext/standard/php_smart_str.h:37-66` | same | Capacity slack and realloc old/new overlap are separate charges. |
| Native unserializer reference lists | `ext/standard/var_unserializer.c:27-33` | `27-34` | 1024-entry tracking blocks, plus temporary/destructor entries. |
| JSON input conversion | `ext/json/json.c:605-631` | `683-709` | UTF-16 workspace `(str_len+1)*sizeof(unsigned short)+1` exists before rejection. |
| JSON parser stacks | `ext/json/JSON_parser.c:242-254` | same | Parser, integer stack and zval stack allocated before envelope validation. |
| Buffered driver rows | `ext/mysqlnd/mysqlnd_result.c:1123-1199` | `1383-1435` | Retain wire chunks/pointer arrays alongside PHP rows. |
| Driver command copy | `ext/mysqlnd/mysqlnd_wireprotocol.c:935-951` | `1001-1017` | SQL command buffer is additional to prepared SQL. |

Zval/bucket/hash-table layouts depend on the exact ABI/configuration
(`Zend/zend.h`, `Zend/zend_hash.h`). Power-of-two hash capacity, minimum slots,
inline string keys, container headers and allocator metadata cannot be replaced
by an invented universal per-node constant. Native count parsing and JSON
`str_len+1` require checked bounds before arithmetic at the literal C/PHP widths.

## Full phase ledger obligations still open

These are real allocating paths already in production, not hypothetical tests:

1. Option fetch/decode: receiver/result buffers, CONCAT prefix, substring copy,
   raw option, scan frames/tokens, native graph/reference lists, parity string
   and serializer capacity/reallocation; count source-retained overlap.
2. Table fetch/decode: bounded encoded SQL columns and row count, driver buffers,
   raw PHP row containers, malformed JSON UTF-16/parser/container allocations,
   tags/base64/parity, logical graphs and row/parent/visited maps. Logical node
   checks occur after native JSON parsing and do not bound its rejection peak.
3. Copy/verify/recovery: retained source and target graphs, change lists, staged
   rows/tags/JSON, prepared/escaped SQL and driver command/readback buffers. Later
   active snapshots may coexist with both source and target. Phase-specific
   authority remains unchanged; table existence never proves conversion success.
4. Reverse conversion: table source already hydrated, scalar float workspace,
   complete native serialized string, option insert SQL/driver copy and target
   option decode/parity. The new preflight fixes only serialize-before-budget.
5. Backup/retirement/error: retained source/backup strings, guarded delete SQL,
   retirement manifests/JSON, control/response work and separately justified
   emergency/cleanup reserve. Zend's fatal-reporting reserve is not guaranteed
   recoverable headroom. No emergency reserve coefficient is approved here.

A numeric phase plan must cover successful and malformed/rejected inputs, worst
live overlap including old/new realloc buffers, allocator segment rounding, and
checked integer arithmetic. Copy-on-write cannot be assumed to eliminate copies,
particularly with legacy foreach/call-frame/container behavior. Expensive-operation
budgets and checkpoints remain cooperative; no timing assertion was changed.

## Exact missing dimension and one capability request

The effective Zend-MM startup segment size B for the retained exact-runtime
launch is not established by the supplied build recipe or JSON receipts. Both
original allocator implementations select it from `ZEND_MM_SEG_SIZE` at startup
or use the compiled default. With `USE_ZEND_ALLOC=0`, the allocator/accounting
model instead changes altogether. Current environment/fingerprint values are
insufficient to prove the initialized allocator state.

ONE minimal request to OPS: permit read-only inspection/export of retained
exact-runtime process-launch/environment evidence establishing effective B for
Zend-MM-enabled PHP5.4.0 and PHP5.6.20. Reuse existing artifacts; no provisioning,
new runtime/VM start, production access or final-validator restart is requested.
If such evidence was not retained, OPS must designate the narrow evidence-export
route; do not invent B or turn this into arbitrary-host timing calibration.

Closing B alone will not close the ABI/driver/phase/reserve proof, approve any
profile, or release exact-candidate literal and independent-review gates. This
checkpoint stops as partial at that evidence boundary. Modern unit execution
exercises local prerequisites only; literal semantics and full DB conversion
acceptance are not claimed. Fresh independent review and separate policy
integration authorization remain required.
