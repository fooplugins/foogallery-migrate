# Bounded logical null-plugin source checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `b981c37ed1da4eec621d4c3ea703ca7057c8dda0`.
One bounded source-ID contract, NOT integration or Milestone A acceptance.
This supersedes the null-plugin refusal in the previous empty-source-ID note;
its empty-ID contract and other operation-specific refusals remain unchanged.

## Contract

Explicitly injected table history source-ID lookup now admits a null plugin,
including records with an absent plugin field as the option backend does. Null
is not an empty string, named plugin or wildcard. Canonical type comparison is
case-sensitive and scalar source IDs compare by exact PHP string cast, retaining
leading zeros. Null/non-scalar source requests still return false without reads.
Only positive destination root records qualify; children are not candidates.

Schema v1 intentionally omits null-plugin exact-source hashes. Two fixed-flag
`source_identity` index selectors use the existing NULL-hash bucket and a NULL
plugin-hash predicate. Each selector returns at most 101 projection-only rows.
At most 100 eligible null-plugin roots COMBINED across both flags and ALL types
and source IDs are admitted. This includes missing/null IDs and other types;
canonical matching happens only AFTER the combined cap. More than 100 refuses
before any canonical tree hydration, even if the first row matches. Physical
source lookup policy, schema, projections, option behavior and authority/engine
selection are untouched. No persisted hash upgrade is required.

Within that envelope, candidates are merged in original root order, and bounded
canonical trees skip absent/null IDs and non-matching types/IDs. Exact null plugin,
positive destination, row identity/order and canonical/projection integrity are
verified. Shared row/encoded-byte/time budgets and both history/logical-revision
metadata guards apply. Verified dirty history reads need no whole-history rehash.
No option fallback, write, schema creation or lifecycle behavior change.

The admission cap bounds returned candidates, NOT SQL scan work. Residual root,
plugin and destination filters may scan a scoped NULL-hash index bucket. Time
checks do not interrupt an in-flight SQL query. Indivisible trees can refuse;
encoded-byte budgets do not prove peak PHP heap/OOM or large-job scalability.
Integrity still depends on the verified writer/maintenance/admission paths.

## Verification and limitations

Replayable RED/GREEN, commands, machine evidence, source/extracted-package logs:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/null-plugin/`.
Focused PHPUnit and actual guarded WordPress RED preceded production edits.
The expanded read harness exercises missing/null vs empty plugins, scalar IDs,
case/leading zeros, first-positive ordering across flags, missing/null IDs,
children/zero destinations, empty history, 100/101 combined candidates including
other types, early-match overflow before payload retrieval, 1200 unrelated roots,
shared budgets, actual EXPLAIN index/no-filesort, exact option/head preservation,
two distinct pinned connections changing logical revision or same-total plugin,
dirty canonical reads and exact cleanup readback. Prior read/summary/mutation/
wrapper/shared/option/table/lifecycle harnesses are replayed on single-site and
registered multisite blogs 1 and 2, for source and separately extracted package.

Minimum supported PHP/WordPress, actual MySQL, true multi-network,
browser/customer-memory/OOM and 10x large-job acceptance remain unexecuted.
No independent review/approval, remote publication, merge, deploy or release.

## Open / next action

Fresh bounded continuation on SAME card: source-URL admission or explicit bounded
refusal contract, RED before GREEN. Retry/clear/export, other common adapters,
engine adoption, secure preflight/conversion/authority/recovery, nested flows and
cumulative integration remain OPEN. Preserve log-empty-status/per-type-empty-type
refusals, schema/options/no-op lifecycle and all previous owned hunks. Keep
`t_39cfe477` and downstream acceptance/review/publication/actual-merge gates held.

Hotspot: `includes/class-legacy-table-repository.php`; serialize further edits.
