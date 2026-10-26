# Shared logical integration checkpoint (partial)

Task `t_eec7d527`, branch `feature/migrated-store-scalability`, baseline `7aff37ca88050b6461530b03d8d59748f79f308b`.
This is a bounded shared-file checkpoint, NOT full integration/Milestone A acceptance. Options remain authoritative. No public table selection, conversion, provisioning or lifecycle change is included.

## Implemented

- `MigratorSettings::compact_state_value` / `hydrate_state_value` provide the previously missing production codec bridge. Ordinary option adapter tests and real WordPress now use `MigratorSettings`, not a test-only subclass.
- Galleries/albums and nested migratable children retain exact existing PHP keys (`'09'` versus integer `9`) through compact wrappers and hydration. Dense lists remain lists. Already-compact supported wrappers pass through unchanged, including unknown headers. Known buckets refuse invalid wrapper version/type/items instead of silently accepting or dropping records.
- Ordinary `MigratorEngine` constructs `MigratedHistoryRepository`, which continues to delegate to `OptionMigratedStore` as the sole physical option-history owner. The repository exposes the engine compatibility methods and can be explicitly injected alongside existing legacy test stores. The conservative authority gate is unchanged.
- Non-batched engine status changes patch the exact stored identifier via `set_status`, preserving unknown raw record fields and wrapper metadata and coupling history/revision in one write. Repeated status is a no-op; failed persistence leaves history and revision unchanged. Queued object status remains part of the existing batch until flush.
- Historical object options remain readable without implicit rewrite. The existing typed `LegacyStateCodec` explicitly refuses raw PHP objects before table encoding; this is refusal evidence, NOT a newly implemented conversion/admission protocol.

## Executed evidence

Evidence and exact guarded runners: `/home/brad/.hermes/profiles/dev/diagnostics/t_eec7d527/`.

- Test-first corrected RED: three missing-production-bridge errors and one engine-owner assertion failure. The initial RED also contained a missing fake authority DB setup, subsequently corrected before production edits; that setup failure is not claimed as a production defect.
- Full PHPUnit: 273 tests / 1225 assertions, exit 0. Content-scan and AJAX JS regressions pass. Existing PHP 8.5 reflection deprecations remain.
- New guarded real WordPress harness: 27 checks on single-site and 27 each on actual registered multisite blogs 1 and 2, including exact fixture option/autoload restoration and unchanged authority markers.
- `npm run build` passes. Generated POT changes are only owned engine/settings reference-line shifts after restoring timestamp noise. ZIP regeneration and byte-parity/extracted-package replay results are recorded separately in machine evidence; do not infer them from the build result alone.
- Proposal remains blob `6475b60dc8aaa9aa420c146666c373f6b33ed46e`; both historical preserved patches retain SHA-256 `6de47194708179d97c7b28deaa98ef81ea146f198b15fce1aa02aff0a68fa540`.

## Explicit OPEN / next slice

Continue on this same integration task from its local checkpoint; do not complete it or release parent/backend/conversion safety lanes from this partial result.

- Common adapter dispatch to bounded/indexed `LegacyTableBackend` methods, approved lossless compact-wrapper/table-root translation, history logical revision/atomic companion policy, source/log APIs and export/digest logical parity remain OPEN.
- Collection/content/queue table logical shapes and corresponding engine runtime adoption are not implemented. Physical table runtime tests are not proof of this logical wiring. No table read-all/callback fallback has been added.
- The public runtime compact bridge retains existing selected-field compaction behavior (including intentional gallery child omission); it is NOT a lossless historical object-to-array converter. Conversion must inspect raw state and preserve or explicitly refuse unsupported objects before compaction. Hydration of compact records still exposes the existing known runtime fields; raw unknown fields are retained during partial status/nested updates, not promised through arbitrary replacement/queued recompaction.
- Option mode retains whole-option/history memory and existing WordPress concurrency semantics. This checkpoint does not establish table boundedness, queue scalability, a memory guarantee or a customer large-job pass.
- Multiple-network topology, minimum PHP/WordPress, browser/customer-memory/large-AIGPL gates and native independent approval remain OPEN. Actual multisite evidence does not imply multiple-network evidence.

No push, PR update, merge, deployment, release/SVN, production data or parent/Milestone closure is authorized or performed.
