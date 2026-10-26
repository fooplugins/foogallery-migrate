# Bounded common history mutation checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `e175235be71f975e8bcbe79e7e29df2ef26fcf25`.
This continues the explicitly injected read checkpoint; it is NOT completion of this integration task or Milestone A.

## Implemented subset

- Shared `MigratedHistoryRepository` dispatches bounded table upsert/upsert-many/upsert-batch, status updates and single-key deletion. Option ownership, PHP map-key semantics for object identifiers, compact object serialization, hydration and parent-retention precedence remain with existing conventions. Table callers must explicitly inject `LegacyTableBackend`; there is no selection/authority flip or option fallback.
- Closed `history_mutate` admits only an indexed compact migrated map plus a valid existing logical revision scalar. Batches contain at most 100 object records and 98 existing scalar companion heads. Missing/raw-layout/collection companions, reserved keys, unsupported values and over-budget operations fail closed without head creation. Arbitrary status strings retain option behavior; explicitly injected table status writes admit at most 32 encoded bytes for exact projections.
- Duplicate upserts retain sequential parent semantics and requested count, but coalesce affected roots. Changed history advances logical revision once, in the same transaction as affected-root changes and changed scalar companions. Exact serialized no-ops leave both history heads untouched. Companion-only updates do not increment history revision. Stored null/false deletion is distinct from missing deletion; raw array status updates preserve uninterpreted fields without hydration/recompaction.
- One operation budget spans metadata before/after, affected old roots, new payloads, preflight and publication. Runtime mutations never hydrate, rehash or rewrite the full history. Physical history digests remain dirty until explicit existing paged maintenance; schema, canonical digest domain, physical projections and wrapper field order are unchanged.
- Transaction guards include ALL read-dependent heads, including unchanged companions/history. All locks are acquired in established hash order before the first consistent head read, avoiding stale REPEATABLE READ snapshots. Complete head identity/generation/revision is compared under those locks. Publication never reconnects/replays.

## Executed verification

Detailed RED/GREEN, source/package replay runners, logs and machine evidence:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/history-mutation/`.

- Focused unit RED: table mutations previously refused; four translation/admission/option tests GREEN. Full suite recorded in final evidence.
- Actual guarded WordPress baseline-source RED: explicitly injected table status mutation was unadmitted. Initial parity fixture used invalid UTF-8 that WordPress could not persist in its real options table; replaced with admitted Unicode/NUL data and an explicit seed-success check. That harness admission failure is not reported as a production defect.
- Internal read-only reasoning review identified a stale unchanged-companion guard. A real second mysqli connection reproduced the bug under REPEATABLE READ with disposable MariaDB's snapshot-conflict extension disabled to exercise MySQL-style semantics. Initial strict MariaDB isolation instead refused the stale read; neither behavior is a MySQL-server acceptance claim. Acquiring all dependency locks before a consistent snapshot makes the same regression GREEN.
- Mutation harness: 67 checks on single-site and 67 each on actual registered multisite blogs 1 and 2. Includes raw wrapper/item/order parity, null/false deletion, duplicate/parent upserts, no-op and companion-only revisions, shared budget refusal, injected scalar CAS rollback, stale affected-root/pretransaction revision and concurrent unchanged-companion refusal, wrong-blog/missing-head refusal and cleanup read-back.
- Existing history read, wrapper, shared, option, physical and lifecycle harnesses pass on those same source targets. Final serial build, ZIP CRC/safe extraction/all-file source parity and extracted-package replay are recorded separately in final evidence, not inferred from source results.

## OPEN / next action

Continue this SAME integration card in fresh bounded runs: logical aggregate summary/source-URL admission and appropriate bounded retry/clear/export policy, then nested galleries/albums, content findings and image-tag queue adapters and explicit engine adoption. Unadmitted methods still refuse; no whole-history scan is substituted for a bounded contract. A subsequent manager must explicitly stage/verify/admit the indexed wrapper layout and provision any absent companion heads before runtime mutation.

Other common adapters, public engine integration, conversion/authority/retention safety, minimum PHP/WordPress, a real MySQL server, true multi-network, browser/customer-memory/large-job and native independent approval remain OPEN. Budgets are encoded row/byte/time limits, not heap/OOM guarantees. Keep downstream `t_39cfe477`, review/publication and actual-merge gates held.

Hotspot: `includes/class-legacy-table-repository.php` — keep changes serialized and preserve physical/wrapper/no-op lifecycle hunks.
No push, PR update, merge, deployment, release/SVN, production writes or parent closure occurred.
