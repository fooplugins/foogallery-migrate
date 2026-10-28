# Bounded logical empty-source-ID checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `3e3341570cba428a3e47e6ada61472d9c1c84da9`.
One bounded vertical contract, NOT integration or Milestone A acceptance.

## Contract

Explicitly injected table history source lookup now admits scalar IDs whose
string cast is empty (`''` or `false`), preserving the option predicate, binary
type/plugin identity, positive destination eligibility and first root order
across both independently stored migrated flags. Null/non-scalar requests
still return false without reads. URL and null-plugin lookup remain unadmitted.
Option behavior, schema v1 projections, physical source lookup policy and
engine/lifecycle selection are unchanged.

Schema v1 projects an explicit null ID and empty scalar IDs to the same exact
hash. Two fixed-flag identity-index selectors return at most 101 projection-only
candidates each. More than 100 combined eligible root candidates refuses before
canonical hydration, even if an early candidate would match. Admitted candidates
are ordered together and canonical trees are checked within the existing shared
row/encoded-byte/time budgets. Null IDs are skipped, scalar IDs are tested exactly;
missing IDs have no hash. Children are excluded as independent candidates. Both
history and logical revision metadata guard mixed reads. Verified dirty histories
remain readable; no whole-history rehash, option fallback, write or schema change.

The 100-candidate admission cap does not promise bounded SQL work: eligibility
and root filters can scan the scoped identity index. Indivisible trees can exceed
row/byte limits and refuse. Time checks do not interrupt an in-flight DB query;
encoded-byte bounds are not peak PHP heap/OOM guarantees. Canonical/projection
integrity still depends on verified writer/maintenance/admission paths.

## Verification and limits

Replayable RED/GREEN, guarded source and separately extracted-package evidence:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/empty-source-id/`.
Unit and actual disposable WordPress RED precede production edits. Real tests
exercise serialized option parity, null/false/empty IDs, missing IDs, nested
exclusion, case/spaces, zero destinations, empty histories, 1200 unrelated roots,
100/101 combined-flag admission, early-match overflow refusal, shared budgets,
projection-only selectors with actual EXPLAIN index/no-filesort evidence, exact
option/head preservation, two distinct pinned connections changing logical
revision and same-total identity, dirty reads and cleanup readback. Existing
read/summary/mutation/wrapper/shared/option/table/lifecycle regression harnesses
are replayed on single-site and registered multisite blogs 1 and 2, source/package.

Minimum supported PHP/WordPress, actual MySQL, true multi-network,
browser/customer-memory/OOM and 10x large-job acceptance remain unexecuted.
No independent review/approval, remote publication, merge, deploy or release.

## Open / next action

Resume this SAME card in a fresh bounded session: null-plugin/source-URL admission
or bounded retry/clear/export policy, RED before GREEN. Common adapter completion,
engine adoption, secure preflight/conversion/authority/recovery, nested flows and
cumulative integration acceptance remain OPEN. Preserve existing log-empty-status
and per-type-empty-type refusals. Keep `t_39cfe477` and downstream gates held.

Hotspot: `includes/class-legacy-table-repository.php`; serialize further edits and
preserve previous physical/wrapper/mutation/summary hunks.
