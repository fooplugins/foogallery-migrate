# Internal scalar receiver / endpoint checkpoint

This is a bounded implementation prerequisite, not a completed admission model,
Milestone A acceptance, or conversion/recovery acceptance. Original global
`wp_unslash` remains unchanged, in its original authorization/error-ordering
position. There is no new guard, cap, policy coefficient, or calibrated profile.

## Real production seam

`StorageOperations::handle()` records seven root-slot observations immediately
before its original `wp_unslash($_POST)`. `StorageMemoryBaseline` retains only
scalar lengths and key byte requests, never payloads or references. Scalar here
includes null, boolean, integer and floating-point values; it is not semantic
request validation. Arrays, objects and resources produce unresolved evidence
without recursive observation. Original WordPress normalization still traverses
whatever it previously traversed; the observer does not refuse malformed input.

The same existing per-operation baseline context reaches dispatch, preflight,
conversion begin, advance and budget. Direct PHP dispatch does not normalize or
invent receiver events. This slice does not modify conversion/recovery state
transitions. Its real begin witness intentionally fails the pinned connection;
advance/budget witnesses use reflection and an explicit database read fixture.
Those witnesses are NOT successful conversion, SQL, HTTP or recovery.

Aliased scalar strings are observed once per root visit using their INITIAL
length. Later original WordPress visits may shorten the same referenced string.
These are upper lengths, not exact allocation sizes or unique-string counts.
Historical root capacity is neither reconstructed nor inferred from seven live
slots; the sampled baseline continues charging originals without free credit.
The observer's own allocations, argument/foreach copies and teardown are also
unclosed; sampling its retained projection does not certify their transient peak.

## Offline source-bound projection

`tests/support/StorageReceiverNewComposition.php::receiver_events()` consumes
these actual observations. It reuses the accepted nominal packing, hash-vector,
and caller/argument helpers, with checked arithmetic and their existing
conditional PHP 5 LP64/NTS/nondebug domains. The selected string duplicates
charge INPUT length plus terminator, not normalized output length. One selected
seven-slot shallow root-copy family is reported separately; children are not
recursively charged. Summing selected families conservatively does not enumerate
ALL possible engine copies, nor establish their simultaneous peak or arena cost.

The semantic site counts follow original WordPress `formatting.php`:

- WP 5.0 `map_deep`:4329-4343; WP 6.0:5002-5016: one root call,
  seven leaf calls, seven callbacks/results, seven root assignments, one root
  foreach reset and eight fetch attempts including loop termination.
- `wp_unslash` and `stripslashes_deep`: one wrapper call/return apiece.
  `stripslashes_from_strings_only`: seven calls/returns, but `stripslashes`
  executes only for string visits. These are source-derived semantic counts,
  not measured PHP opcode/allocation counters or exact VM bytes.
- PHP 5.4.0 `Zend/zend_vm_def.h`: DO_FCALL:2789, RETURN:2812,
  FE_RESET:4045, FE_FETCH:4188. PHP 5.6.20 corresponding sites:
  2825, 2853, 4292, 4454. Return/reference and foreach paths can separate,
  addref or copy depending on VM state. Counts do not select those paths or
  certify their multiplicities. No exact reference-box/COW/stack census claimed.

Original source files, source hashes, frozen candidate/manifests, negative origin
control, command logs, original-WP semantic matrix and seven-part handoff live in:

`/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/receiver-scalar-endpoint-integration-checkpoint/`

Failures and earlier snapshots are preserved under their original labels. Only
explicitly successful exact-final receipts receive verification credit. Modern
PHP execution of original WordPress functions is not literal PHP 5 execution.
Capability, nonce, calibration, status and database fixtures remain identified.

## Unclosed gates

`whole_operation_new_upper`, invalid/rejected graph bounds, VM allocation bytes,
observer allocation bytes, failure/cleanup overlap and retained-arena bytes stay
unresolved. No selected-copy sum is consumed as runtime admission. Useful whole
operation fits, full trace/reference/arena accounting, record/source/staging/
verification retention, SQL/driver/transport/decode/output/parity/cleanup,
positive source-and-matching-ZIP conversion AND phase-authoritative recovery,
exact-final literal PHP 5.4.0/WP 5.0 and PHP 5.6.20/WP 6.0, delayed teardown,
32-bit evidence and independent exact-candidate review remain required.

The precise disposable-fixture prerequisite is an already-authorized,
invocation-owned full WordPress/SQL fixture with an ownership/cleanup receipt
and a pinned usable connection, allowing source and matching ZIP runs without
reading shared credentials/configuration or provisioning/starting services.
Registry absence is not proof that SQL is unavailable. This slice neither
provisions such a fixture nor treats synthetic connection refusal as acceptance.
