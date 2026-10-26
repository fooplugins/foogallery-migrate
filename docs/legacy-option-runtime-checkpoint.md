# Normal-option runtime checkpoint

Task: `t_244a5783`. This is the option implementation phase, not final foundation/Milestone A acceptance. Baseline: `21ec555f7a41cb3c9a22146388c6d93d1be020c6`, branch `feature/migrated-store-scalability`. Authority, schema, lifecycle, engine, facade and codecs are unchanged. No public table selection, provisioning or deployment was added.

## Delivered

- Five common adapters with explicit `__construct($backend, $settings)`: ScalarStateRepository, MigratableCollectionRepository, MigratedHistoryRepository, ContentScanRepository and ImageTagQueueRepository. Only LegacyOptionBackend is admitted here; other backends explicitly refuse, never fall back.
- Scalar ordered presence tuples distinguish stored false/null from absence. Exact keyed APIs reject coercible/duplicate keys; multi-key writes and no-ops retain unrelated state.
- Collection snapshots reuse current facade codecs. `update_record($bucket, ['root' => <exact PHP key>, 'path' => [<exact child keys>]], $object)` changes only the containing root, preserving unrelated raw data. Historical nested keys/unknown properties remain unchanged on reads and partial updates. `update_with_state` commits collection and its `-state` checkpoint together.
- Content findings use the existing `block-shortcode_scan_state` items/progress shape; legacy keys are read without implicit import. Post replacement/removal uses strict typed post IDs and atomically updates progress.
- Image-tag queue operations retain items/errors/processed/total and unknown metadata; item changes and companion sync state commit together. Patch arguments are existing queue item/state values, not a generalized patch language.
- OptionMigratedStore remains the sole history owner. Added ordered get_many, exact source identity/URL lookup, status mutation, bulk/companion updates, revision, scalar log summaries, offset compatibility log_page and rows/cursor/revision page envelopes. Source matches retain case-sensitive bytes, stringified source IDs and first positive target in legacy order. Legacy identifier and upsert return shapes are retained.
- History mutations preserve unknown wrapper metadata/unrelated records and advance revision once for a changed logical history. Failed writes preserve both state and revision. No-op status updates do not write or advance revision.
- Option pagination bounds hydration, not storage I/O: each option read still loads the entire option; cursor binding also hashes the option history. This is not indexed SQL, CAS or a writer lease. Table hot paths must not reuse these option-only strategies.

## Shared fixture contract

`tests/fixtures/legacy-runtime-contract-fixtures.php` is a pure namespaced factory with no WP globals/stubs. `legacy_runtime_contract_fixture($binary = true)` returns `image`, `historical` and `logical`. The logical map covers plugins, galleries/albums and checkpoints, history/revision, image-tag sync, wordpress-core-mode, current/legacy content findings and progress, unknown, false and null values. Historical children/settings contain distinct `'09'` and `9` keys. Binary=true uses invalid UTF-8/NUL bytes for pure option/codec contracts; false uses valid UTF-8 for actual WP utf8mb4 persistence.

This shape is now read-only to subsequent workers; additions go through serial integration. Real WP rejects invalid UTF-8 option bytes in this environment; the guarded replay checks explicit refusal and unchanged prior state rather than inventing binary DB persistence.

## Verification

Detailed commands/logs/runners: `/home/brad/.hermes/profiles/dev/diagnostics/t_244a5783/`.

- Runtime RED: exit 2, missing adapter classes. History RED: exit 2, missing new history APIs. Preservation RED: exit 2, unsupported collection version accepted, wrapper metadata lost and serialized-object no-op wrote. Typed-delete RED: exit 1, coercible delete accepted.
- Final focused suite: 34 tests / 190 assertions, exit 0.
- Final full PHPUnit: 257 tests / 1104 assertions, exit 0. Existing PHP 8.5 ReflectionMethod deprecations remain in MigrationFlowTest.
- Content scan and AJAX/JS regression scripts: exit 0.
- Exact changed PHP paths: `php -l`, exit 0. `git diff --check`: exit 0.
- Guarded real WordPress single-site replay: 39 checks, exit 0; WordPress 7.1.2-src, PHP 8.5.4, MariaDB 11.8.6.
- Guarded real WordPress multisite replay: 48 checks, exit 0; WordPress 7.1.2, two actual blogs. Same repository follows switch_to_blog; cursor refuses another blog even with identical history/revision; both original options and original blog context are restored and read back.
- Node: v22.22.3. Build/package verification is reserved for serial integration and was not run in this phase.
- Preserved historical deactivation patches still hash to `6de47194708179d97c7b28deaa98ef81ea146f198b15fce1aa02aff0a68fa540`; proposal blob unchanged: `6475b60dc8aaa9aa420c146666c373f6b33ed46e`.

## Required integration work / OPEN acceptance

`t_eec7d527` owns these shared edits; they are NOT implemented or marked passed here:

1. `MigratorSettings::compact_migrator_setting` / `hydrate_migrator_setting` (around 331/356): install public `compact_state_value($key,$value)` and `hydrate_state_value($key,$value)` bridges, retaining protected codec policy. Current replay uses isolated test subclasses; production adapters cannot be wired to the plain facade yet.
2. Existing `compact_migratable_collection` (479), `hydrate_migratable_collection` (505) and child compaction (581): current legacy galleries/albums and nested compaction normalize map keys to list positions. This phase intentionally tests current facade parity and preserves historical raw keys, but cannot claim lossless typed-key snapshot conversion. A read-only failing integration probe records expected `['09',9]` versus actual `[0,1]`, plus missing public bridges. Resolve via agreed lossless codec/admission or explicit refusal before writes, without silently changing legacy caller shapes. Unknown historical properties also need lossless table representation or explicit unsupported refusal.
3. Wire table dispatch/hydration into the common adapters using the table worker's exact capabilities, never option callback/read_all fallback. Adopt bounded history methods in engine constructor/store factory and existing source/log/status consumers. Existing collection callers are MigratorBase::get_objects_to_migrate/queue_objects_for_migration (74/100); content persistence is ContentMigrator::persist_content_items/persist_scan_state (338/552); status caller is MigratorEngine::update_migrated_object_status (977). Retain caller list/queue values and compatibility shapes.
4. Run full common-backend/engine adoption regressions, build and extracted-package replay. Real multi-network isolation and minimum supported PHP/WP are still OPEN, not established by this two-blog test. Authorized conversion/recovery remains a later lane.

`integration-gap-probe.php` and `integration-gap-red.json/.stderr` are external read-only RED evidence (WP-CLI exit 255 from intentional RuntimeException), not failing tests hidden from the unit suite. This checkpoint releases the serial table lane only; it does not release final acceptance or public conversion.
