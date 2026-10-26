# Legacy foundation integration checkpoint

This is local resumable progress on PR NUMBER 15, not Milestone A acceptance,
independent review, public opt-in/conversion, publication or actual-merge approval.
Default retains integration card t_04219c53. Baseline:
8343cadb1c14fdf2359f4b96dfecc529997d066c, feature/migrated-store-scalability.

## Supported integration envelope

- Explicit MigratedHistoryRepository injection only. Options remain default;
  no storage-authority/bootstrap/schema/lifecycle/public-selection edits.
- Common retry, history-only clear and raw paginated export; raw compact values,
  typed order, wrapper headers and opaque fields are preserved, never hydrated
  for export. Filters are not admitted on raw export.
- Retry visits the exact parent plus indexed ERROR candidates, preserving
  completed/opaque unrelated roots. Excess error candidates refuse under one
  cumulative budget rather than publish a partial retry.
- Clear atomically publishes an empty retained generation and increments logical
  revision once. Empty clear and missing retry remain no-ops. No active-history
  scan, old-generation deletion or stale-option fallback.
- Engine restoration uses only already-loaded, deduplicated child identifiers,
  exact parent matching and one consistent backend read; 100-key SQL batches,
  at most 10,000 loaded identities, aggregate repository row/byte/time admission.
- Injected engine presence trusts empty table history. Scalar/checkpoint settings
  use the same explicitly injected table backend; up to 100 existing scalar heads
  mutate atomically with dependency/CAS guards. Missing heads, collection/history
  replacement through the scalar facade and whole-state engine reset refuse
  before side effects. Ordinary option-engine behavior remains unchanged.
- Successful source-URL refusal and existing indexed source identity contracts
  are preserved. No new adapter or alternate engine.

## Executed RED/GREEN and authoritative evidence

Focused unit/real SQL behavioral RED/GREEN covers maintenance, loaded-child
restoration, empty injected history, bounded maintenance with ten unrelated
completed records and an eight-row budget, engine state-backend binding and
scalar-array presence. Full PHPUnit at this checkpoint: 302 tests / 1385
assertions before the final option-injection regression. Final full PHPUnit:
303 tests / 1389 assertions. Final exact-candidate source/package and preservation results are
recorded externally, not inferred from historical evidence.

Seven-part handoff and exact command/exit/RED/GREEN/package/checkpoint evidence:
/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/ops-integration-20261007/implementation-handoff.md
/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/ops-integration-20261007/verification-evidence.json

## Acceptance deliberately OPEN

Minimum PHP/WordPress and actual multi-network acceptance remain OPEN, not
substituted with current-runtime or registered-multisite coverage. Table common
collection/content/image-tag dispatch, scalar creation/deletion semantics and
end-to-end approved opt-in/AIGPL table-engine workflow need continuation. Public
conversion/authority selection remains a separately owned phase. Whole-state
reset requires the approved maintenance owner. Empty-generation clear reuses
existing snapshot row/byte admission; snapshot publication does not yet share
its caller's wall-clock deadline, so that bound must not be marked accepted.
Conversion UI, large AIGPL, native independent exact-candidate review,
publication and actual merge are separate gates. No push/PR update/merge/deploy,
production writes, runtime/profile/guard changes or parent closure authorized.
