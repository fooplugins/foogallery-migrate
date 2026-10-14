# Writer barrier prerequisite checkpoint (2026-10-14)

Task: `t_396d60fe`, Milestone A conversion/recovery. This is PARTIAL, not conversion delivery or Milestone A acceptance. Public table selection is still disabled. No production mutation endpoint or backend is wired to this primitive yet.

## Delivered contract

`StorageMaintenanceGate::current()` provides a re-entrant request owner bound to the current database, blog ID and options-table prefix. A new writer checks the maintenance row, inserts a non-autoloaded marker, and checks the row again before admission. A failed post-insertion verification cannot turn into a nested successful admission. Existing admitted writers can finish behind a newly acquired gate, which must wait for them.

All ownership probes are fresh bounded SQL reads, not cache-authoritative option reads. Empty/oversized markers remain evidence: a nonempty SQL envelope avoids WordPress `get_var()` collapsing an empty string into absence. Claims use insert-only `INSERT IGNORE`, not `add_option()` (whose duplicate-key update is unsuitable for locking). Exact binary raw-value CAS owns renewal/release. Successful renewals retain the updated raw owner even when verification subsequently fails. Direct SQL invalidates the exact option, negative-option cache and alloptions cache.

The maintenance owner must see no writer marker and consume a trusted integration callback's strict affirmative verdict twice. It rechecks ownership, context and writer drain; it does not inspect stored paused/queued state or implement an engine lease. Expired/foreign markers are not automatically deleted or adopted. Recovery/takeover remains a separate unimplemented contract. Readiness must be repeated immediately before every future authority flip.

Normal cross-blog use of cached handles refuses. Shutdown may temporarily restore the captured blog solely to release its exact owned writer marker, then restore the exit context. It never releases maintenance automatically: interrupted conversion must retain its gate. Process death, inaccessible databases, ambiguous writes and altered marker ownership can still leave recovery evidence.

## Test-first evidence and verification

- Initial RED: 14 missing-class errors before implementation.
- Bounded review found real WordPress API mismatches and ownership/context failure paths. New regressions demonstrated five failures; a separately exercised delayed-renewal regression demonstrated the successful-write/failed-read ownership failure.
- GREEN: 20 writer-barrier tests within the full PHPUnit run: 342 tests / 1,695 assertions, PHP 8.5.11. Existing ReflectionMethod PHP 8.5 deprecations remain unrelated.
- Content-scan and AJAX/JavaScript regressions pass. Production/test PHP lint and shell syntax checks pass.
- `tests/integration/storage-writer-gate.php` is a disposable real-WordPress harness for cache bypass, invalid gates, claim collision, writer/gate race, token CAS, source-byte retention and multisite context/shutdown. The existing exact-head Cove runner accepts optional fifth argument `storage-writer-gate`; its default read-only preflight mode is unchanged. The outside-repository handoff records actual candidate/build/native execution, checksums and cleanup evidence; no unexecuted test is claimed here.

## Finite remaining Milestone A contracts

1. Adopt this request marker before every authorized migration/output mutation, with defensive storage/engine assertions and real endpoint race/no-change tests. Supply the actual opaque engine quiescence integration; a test callback is not that integration.
2. Implement versioned authoritative-control validation and exact-token/CAS phase transitions. Preserve options-default/no-table virgin behavior and fail-closed foreign/partial storage evidence.
3. Calibrate current-host source admission, decode under measured memory/time limits, validate raw/object/typed-state parity and produce verified staged generations.
4. Implement resumable bidirectional storage-only conversion, immutable-source/digest verification and authority flips only behind renewed gate/drain/quiescence checks. Denied/refused/failed attempts must not alter logical authority.
5. Implement authoritative interrupted-state recovery and explicit safe stale-owner takeover. Never treat an expired row or table existence as authority; never restore stale backups over current state.
6. Exercise the full independent exact-source/package acceptance matrix, all required lifecycle/site/network isolation cases and actual literal PHP/WordPress floors through the existing validation/review/publication graph.

Next action: continue this SAME card in a fresh bounded implementation slice beginning with failing authoritative-control and actual writer/engine endpoint adoption tests. Do not release validation children or mark Milestone A accepted merely because the primitive passes. No new backend/graph, push, merge, deployment, production, SVN or publication authorization is implied.

Reusable learning candidate: verify WordPress's real option API SQL and `wpdb` null/empty semantics before relying on lightweight mocks for ownership boundaries. This checkpoint exposed both through bounded review and added native regression fixtures; no skill or automation was changed.
