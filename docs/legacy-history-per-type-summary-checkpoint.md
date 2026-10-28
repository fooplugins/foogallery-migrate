# Bounded logical per-type summary checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `2a3d34a50c27f4a0e5833f1ebe9512939ef6eefc`.
One completed vertical contract, NOT complete integration or Milestone A acceptance.

## Implemented subset

- Explicitly injected `MigratedHistoryRepository::summary()` dispatches a closed table read. Option mode remains unchanged; no public selection, provisioning, authority flip, writes, hydration, payload scans or fallback.
- Non-empty binary types group in first root-occurrence order with exact option `count`/`errors` shape, including unknown types, case, significant spaces and PHP numeric-string keys. Only exact logical status `error` increments errors, independent of physical error flags; nested children do not count.
- One SQL projection aggregate returns at most 101 groups and refuses more than 100. Existing row/encoded-byte/time budgets cover aggregates and before/after metadata. Counts/order/totals must be valid and agree with logical root count. Both history and logical revision heads guard concurrent changes, including same-total updates.
- Schema v1 collapses missing/null/opaque/explicit-empty types. Per-type option semantics skip missing/null/opaque but include an explicitly empty type, unlike log summary. Histories with any empty type projection therefore refuse wholly, without an invented answer, schema change, whole-history scan or option fallback. Empty histories are admitted. Missing/null/empty statuses are safe for per-type errors (all mean zero); log summary's empty-status refusal remains unchanged.
- Projection integrity still depends on verified writer/maintenance/admission paths. This adds no canonical corruption scan. Verified dirty projections can be read without rehashing the entire history.

## Executed verification

Detailed runners, raw RED/GREEN outputs and structured evidence:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/per-type-summary/`.

- Focused unit RED: table summary was unadmitted and metadata refusal messages were absent (exit 2). Real disposable WordPress source RED: same previously unadmitted operation (exit 1). Both preceded production edits.
- Full PHPUnit: 294 tests / 1332 assertions. Translation tests exercise exact option shape/order/error semantics without hydration, wrapper/revision refusal and unchanged option empty-type semantics. The previous generic unimplemented-operation contract now tests parent lookup rather than the newly admitted summary.
- Expanded guarded summary harness: 85 checks per single-site and actual registered multisite-blog target (blogs 1 and 2). Includes existing log summary regressions, exact per-type serialized option parity, case/spaces/numeric keys, nested exclusion, status versus error flags, missing/null/empty status admission, five ambiguous-type refusals, empty history, 1200 roots, 100/101-type boundary, row/byte budgets, wrong blog, malformed request, old layout, concurrent revision and same-total status changes, dirty projections and cleanup read-back.
- Source and separate extracted-package history-summary/mutation/read/wrapper/shared/option/table/lifecycle harnesses, serial build, ZIP CRC/safe extraction/all-file source parity, content scan and AJAX regressions are recorded in final evidence. Source checks alone are not package acceptance.

## OPEN / next action

Resume this SAME integration task in a fresh bounded session. Next coherent contract: source URL/null-plugin/empty-ID admission or explicitly bounded retry/clear/export policy, RED before GREEN. Preserve schema/options/no-op lifecycle and exact typed identities. Do not relax empty-type or log-summary empty-status refusal without an exact bounded migration-compatible contract.

Other common adapters, public engine adoption, nested gallery/album/content/tag flows, secure preflight/conversion/authority/recovery and cumulative integration acceptance remain OPEN. Minimum PHP/WordPress, real MySQL, true multi-network, browser/customer-memory/OOM and 10x large-job acceptance were not executed. SQL aggregates may scan scoped indexes; bounded results do not imply bounded database work, interruption or heap guarantees.

Hotspot: `includes/class-legacy-table-repository.php` — serialize further edits and preserve prior physical/wrapper/mutation hunks. Keep `t_39cfe477`, independent review, publication and actual-merge gates held. No integration/parent closure, push, PR update, merge, deployment, release/SVN or production write.
