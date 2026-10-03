# Milestone A: compact-record codec checkpoint

Task: t_d3ba5f56. Branch: feature/migrated-store-scalability.
Continues shared-storage primitives at 3f9e02249061e0dbb34fd19a0270e3c8f67531f0.
This is a bounded implementation checkpoint, NOT complete Milestone A, independent
acceptance, a selectable table backend, or publication authorization.

## Implemented

- Inert `LegacyRecordCodec::flatten_tree()` / `rebuild_tree()` for already-compact
  records. Nested array-valued `children` become parent-scoped rows. Ordered field
  keys restore the exact original record; absent/null/false/scalar/empty children,
  unknown array fields, arbitrary bytes, booleans, typed keys and negative zero
  remain distinguishable. Mutable fields are separated without changing values.
- Full logical-record hashes cover base, mutable values and descendants. Rebuild
  refuses checksum, typed-key/node identity, ancestry, missing/extra child,
  ordering, split-envelope and derived-projection corruption. Physical DB IDs,
  generation, blog ID and timestamps do not enter the logical digest.
- Derived exact source identity and legacy numeric image aliases stay separate;
  plugin bytes/case and exact source-ID spelling survive. Error presence retains
  the runtime's strict-false contract rather than using PHP `empty()`.
- `LegacyStateCodec::snapshot_digest()` streams ordered logical arrays/scalars into
  a domain-separated SHA-256 context without encoding a complete snapshot. It
  retains key/value types, raw bytes, order and existing migrated revision data.
  It rejects objects/resources rather than instantiating them or silently applying
  the existing lossy compact whitelist.
- Compatibility fixtures exercise the existing MigratorSettings compact-v1
  whitelist, lazy gallery children, adapter reattachment, missing-adapter fallback,
  nested album/image errors and all known option buckets plus unknown data.

No runtime caller adopts this codec. No provisioning hook, authority selection,
option rewrite, `unserialize()` or production SQL writer was added. Options and
conservative storage gates remain authoritative. Historical serialized runtime
objects are still refused by these primitives; a lossless allowlisted historical
representation remains a prerequisite to conversion, not an implemented claim.

## Safety bounds

An affected root defaults to 10,000 records, 16 MiB aggregate encoded columns,
and depth 64. Each row is also capped by the reader's 1 MiB encoded-column limit
and the scalar codec's byte/node/depth limits. Flatten preflights the entire tree
before logical tree hashing or row emission; rebuild preflights aggregate encoded
bytes before JSON decode. Invalid/coercible root keys fail before emission.
A staged consumer exception or explicit false return aborts; the future writer
must roll back any already-emitted rows and never expose a partial generation.

Snapshot digests deliberately have no whole-snapshot node/byte cap; the caller
must byte-preflight raw serialized options BEFORE decoding them. This is not a
bounded option decoder, streaming SQL snapshot verifier, heap-size guarantee or
never-OOM claim. Rebuild operates on one complete capped tree. Depth-dependent
subtree hashing still repeats descendant work; production batch budgeting and
large-job performance remain unproven.

## Executed verification

- Initial codec/digest tests failed before implementation; focused suites passed
  afterward. Strict-false error projection also had a separate RED/GREEN case.
- Full PHPUnit: 186 tests, 808 assertions, PASS on PHP 8.5.4. Existing PHP 8.5
  ReflectionMethod deprecation notices remain in MigrationFlowTest.
- Tree-budget-before-hash/decode, root-key symmetry, explicit consumer refusal,
  valid-checksum malformed envelopes, orphan roots and sibling gaps covered.
- Real WordPress 7.1.2-src / MariaDB 11.8.6 / PHP 8.5.4 integration passed both
  source and extracted build bootstrap/autoload. Nested physical rows round-trip
  with exact types/order/bytes, negative zero, exact versus numeric source identity,
  logical hash/projection corruption refusal and unchanged legacy revision.
- Existing schema/index/keyset/blog-ID isolation/metadata-only oversize tests also
  passed. Read-back confirmed fixture table removal and unchanged owned options.
  This is a single-site WP fixture with multiple blog-ID rows, not actual multisite
  network lifecycle verification.
- PHP syntax checks, git diff --check, standalone content-scan regression,
  AJAX/JavaScript regression, npm run build and ZIP integrity/presence checks pass.
- A short read-only internal reasoning check informed budget/key hardening. It
  does NOT replace the fresh native independent Milestone A acceptance review.
- PHP 5.4/minimum-runtime, browser/UI, full conversion, interruption/recovery,
  uninstall/network lifecycle and large-job resource limits were not exercised.

Replayable source harness: tests/integration/legacy-storage-seams.php (explicitly
disposable guard; refuses preexisting owned tables). Diagnostics and runner files:
/home/brad/.hermes/profiles/dev/diagnostics/t_d3ba5f56/.
Key evidence: record-codec-red.log, error-projection-red.log,
record-codec-hardened.log, record-final-unit.log, record-integration.json,
record-package-integration.json, record-build.log, record-runner.php,
record-package-runner.php, check-package.py.
The preserved superseded deactivation patch was not modified.

## Next authorized bounded continuation

Continue this same implementation card with lossless historical-object encoding
and atomic staged repositories/generation/checkpoint contracts. Then implement
raw-option byte preflight, authorized main-site provisioning/selection, writer
lease draining, verified conversion/authority flip and old-install recovery.
Do not wire the engine to the inert seams before those contracts are complete.

Keep t_f09f7555 validation, parent t_3a8f9a3c, Milestone B and publication gated.
Only complete the implementation card after its full scope is ready for the
pre-created validation child. No extra Brad product decision is needed for this
already-authorized continuation. No push, PR update, merge, deployment, release,
SVN, customer contact or production operation occurred.
