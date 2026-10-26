# Bounded logical log summary checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `d44f5b08592f796d8a8740621586b050a24f2850`.
This is one completed vertical contract, NOT complete integration or Milestone A acceptance.

## Implemented subset

- Explicitly injected `MigratedHistoryRepository::log_summary()` now uses a closed table read operation, not physical root counts or hydration defaults. The option implementation is unchanged. No authority flip, provision, write, payload scan, full-history reconstruction or fallback occurs.
- Only non-empty string object types are loggable, matching the option contract; opaque/empty-type roots and all nested children are excluded. Totals, aliases, completed/error status counts and first-occurrence group ordering match options, including unknown types/statuses, case, significant spaces and unhydrated records. Error status is not the physical error flag.
- Schema v1's binary projections remain unchanged. Two SQL aggregate queries return at most 101 groups each; more than 100 groups in either dimension refuse. Both queries and metadata before/after share existing row/encoded-byte/time budgets. Projection counts/order are checked and totals must agree and fit logical root count. Metadata checks bind the complete result to both history and logical revision heads, including same-total concurrent changes.
- Missing/null/explicitly empty statuses on non-empty typed roots project identically. This domain is deliberately refused, NOT assigned invented counts or resolved with a whole-history payload scan. Option mode retains its exact distinctions. Projection consistency still depends on verified writes/maintenance/admission; no new corruption-proof canonical scan is claimed. Verified dirty-digest projections can be summarized without whole-history rehash.

## Executed verification

Detailed RED/GREEN, runners, actual outputs and machine evidence:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/history-summary/`.

- Focused unit RED: previously unadmitted table summary/metadata dispatch. Real disposable WordPress baseline-source RED: same unadmitted-operation refusal. Tests preceded implementation.
- Full PHPUnit: 292 tests / 1324 assertions. Four new translation/metadata/no-hydration/unchanged-option tests; previous unimplemented-method tests now assert per-type summary refusal.
- New guarded WordPress harness: 47 checks on single-site and 47 each on actual multisite blogs 1 and 2. Exact serialized option parity, empty/opaque histories, case/spaces, nested exclusion, status-vs-error semantics, 1200-root fixed aggregate queries, 101-group refusal, shared row/byte budget refusal, wrong-blog/request/layout refusal, two-connection history/revision changes and cleanup read-back.
- Source history-mutation/read/wrapper/shared/option/table/lifecycle harnesses replay on those targets. Serial build, ZIP CRC/safe extraction/all-file parity and separate extracted-package replay are recorded in final evidence; they are not inferred from source results.
- Harness corrections: SQL observer initially mistook the parent-node predicate for payload retrieval; an added-root concurrency fixture correctly triggered early inconsistent-total refusal rather than the expected late head guard; digest status required a scoped SQL probe because existing public metadata intentionally omits it. Corrected tests do not represent production defects. No behavior fix or schema change arose from these harness corrections.

## OPEN / next action

Continue this SAME integration task in a fresh bounded session. Next: per-type logical summary and source URL/null-plugin/empty-ID admission or explicitly bounded retry/clear/export policy, choosing one coherent vertical contract. Do not relax the empty-status refusal without an exact, bounded, migration-compatible contract. Other common adapters, nested gallery/album/content/tag flows, explicit public engine adoption, conversion/authority/recovery and cumulative integration acceptance remain OPEN.

Minimum PHP/WordPress, a real MySQL server, true multi-network, browser, customer-specific memory/OOM and 10x large-job acceptance were not executed. SQL aggregates can scan scoped indexes; result allocation/encoded budgets are not server-work, query-interruption, heap/OOM or unlimited-cardinality guarantees. Native independent review is still required; preserve downstream `t_39cfe477`, publication and actual-merge gates.

Hotspot: `includes/class-legacy-table-repository.php` — serialize subsequent edits; preserve previous physical/wrapper/mutation hunks and no-op lifecycle/options/schema ownership.
No push, PR update, merge, deployment, release/SVN, production write or parent/full integration closure.
