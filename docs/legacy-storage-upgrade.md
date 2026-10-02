# Legacy storage upgrade for large sites

**Status:** Proposed  
**Date:** 2026-08-28  
**Scope:** Storage and storage lifecycle for the existing migration engine  
**Related future work:** [GitHub issue #14](https://github.com/fooplugins/foogallery-migrate/issues/14), which is deliberately not part of this design

## Decision

Upgrade storage before undertaking any new migration-engine work.

The existing option-backed behavior remains the default. Storage is first extracted behind explicit contracts and proven with an option implementation. A table implementation is then introduced for high-cardinality legacy state, followed by safe switching, a size-based recommendation, cleanup, uninstall, multisite support, and legacy persisted-state hardening.

This project does **not** create or redesign a migration engine. It preserves the existing engine's sources, object model, identities, queue states, batching, AJAX flow, destination writes, content replacements, and retry behavior. Bounded persistence access may replace a whole-collection read with a page, count, aggregate, or single-record query so that table mode does not reconstruct every stored object. The existing engine continues to own every selection, transition, ordering, retry, title, and completion decision; storage only answers queries and applies engine-supplied patches.

Table mode is an administrator-confirmed storage choice. It is never enabled automatically.

## Why the upgrade is needed

FooGallery Migrate currently stores almost all operational state in one non-autoloaded `foogallery-migrate-data` option, while user preferences live in `foogallery-migrate-settings` ([constants](../includes/constants.php#L9-L14)).

The option is compacted before persistence, but its scaling characteristics remain problematic:

- Every operational read loads the complete option and every mutation rewrites it ([MigratorSettings](../includes/class-migrator-settings.php#L46-L86)).
- Gallery and album collections recursively embed loaded child objects in the same payload ([compaction](../includes/class-migrator-settings.php#L461-L560)).
- Discovery, queue selection, cancellation, and every migration turn operate on and persist complete collections ([MigratorBase](../includes/migrators/class-migrator-base.php#L74-L130), [migration turn](../includes/migrators/class-migrator-base.php#L218-L233)).
- Adding, checking, updating, or deleting one migrated-history record loads and often rewrites the entire migrated map ([MigratorEngine](../includes/class-migrator-engine.php#L776-L891)).
- Content scanning is time- and keyset-bounded, but all accumulated findings and its checkpoint are rewritten together in the same option ([ContentMigrator](../includes/migrators/class-content-migrator.php#L93-L184), [persistence](../includes/migrators/class-content-migrator.php#L544-L562)).
- The migration log loads every migrated object, filters in PHP, and only then slices the requested page ([log view](../includes/views/view-migrate-tab-log.php#L5-L90)).
- The plugin has no table schema lifecycle or uninstall cleanup ([bootstrap](../migrate.php#L30-L47)).

A development-site measurement on 2026-08-28 found a 402,231-byte operational option containing 507 top-level gallery records, one album embedding 500 galleries, 17 migrated-history records, and six content findings. This is not a release threshold, but it demonstrates how nested duplication and full rewrites appear before a site is exceptionally large.

## Current persisted-state inventory

The compatibility layer must recognize the concrete current keys, including legacy-readable keys, rather than treating the operational option as an undocumented blob.

| Resource | Current key/name | Classification |
|---|---|---|
| Detected plugins | `plugins` | Small operational state |
| Gallery records | `galleries` | High-cardinality collection |
| Gallery aggregate | `galleries-state` | Small operational state |
| Album records | `albums` | High-cardinality collection |
| Album aggregate | `albums-state` | Small operational state |
| Migrated history | `migrated` | High-cardinality associative collection |
| History revision | `migrated-revision` | Small counter coupled to migrated history |
| Image-tag job | `image-tag-sync` | Potentially high-cardinality queue plus counters |
| WordPress core mode | `wordpress-core-mode` | Preference-like scalar currently stored in operational state and cleared with it |
| Atomic content scan | `block-shortcode_scan_state` | Findings plus scan/status checkpoints |
| Legacy content findings | `block-shortcode` | Legacy-readable high-cardinality collection |
| Legacy content progress | `block-shortcode_scan_progress` | Legacy-readable checkpoint |

The separate `foogallery-migrate-settings` option currently contains six user preferences: `override_gallery_layout`, `override_gallery_settings`, `override_album_settings`, `page_size`, `images_per_turn`, and `debug_enabled`. These remain in that option in both modes.

The current transient family is `foogallery_migrate_content_result_{user_id}` with the corresponding timeout rows. The `_foogallery_migrate` post-meta name is declared but unused; this storage project does not begin writing it and cleanup must not delete matching post meta unless a future, separately reviewed feature owns such rows.

Phase 0 must repeat this inventory from all call sites and add a compatibility fixture for every key before extraction begins. Unknown scalar keys remain losslessly accessible through `ScalarStateRepository`.

## Outcomes

The completed storage upgrade must:

1. Keep current sites on option mode after upgrading, with no data conversion or table creation unless an authorized administrator explicitly provisions/selects table mode.
2. Preserve the current compact-v1 logical contract and runtime behavior, and never silently normalize a readable legacy non-compact payload.
3. Make table-backed requests scale primarily with the requested page or affected root tree rather than unrelated stored records.
4. Avoid rewriting unrelated root trees or state buckets during normal table-backed mutations.
5. Recommend table mode when observed state crosses documented, filterable thresholds.
6. Switch backends through a resumable, verified copy with exactly one authoritative backend.
7. Make it easy to inspect storage health, remove stale copies, clear migration state, and remove all plugin-owned data.
8. Remove all plugin-owned options, transients, locks, rows, and tables during uninstall on single sites and within the documented prepared/supported multisite scope; any multisite installation above the supported synchronous bound must first pass the full zero-remnant preparation described below.
9. Never delete or alter migrated FooGallery galleries, albums, attachments, source-plugin data, or changed post content during storage cleanup or uninstall.

## Compatibility invariants

The following are fixed for this project:

- `MigratorEngine` remains the active execution engine.
- Existing PHP source adapters and their detection/discovery methods remain unchanged.
- `Plugin`, `Gallery`, `Album`, `Image`, and `Migratable` contracts remain unchanged.
- Current `unique_identifier()` values and migration-history array keys remain unchanged, including display-name-based gallery/album keys and URL-based image keys.
- Current status values, queue selection semantics, object ordering, images-per-turn setting, and one-parent-per-AJAX-turn behavior remain unchanged.
- Existing lazy child loading remains unchanged.
- Destination creation, attachment reuse, gallery/album writes, content parsing/replacement, and stale-content protection remain unchanged.
- Existing non-compact state and compact payload version 1 remain readable.
- Option mode continues using `foogallery-migrate-data` with autoload disabled.
- User preferences continue using `foogallery-migrate-settings`.
- The existing declarations are not changed by this project: runtime `FOOGM_MIN_WP` remains 5.0, the WordPress.org `readme.txt` declaration remains 6.0, and the PHP floor remains 5.4 even though the two WordPress declarations are currently inconsistent.

Backend implementations may have different physical encodings, but decoding either backend must produce the same logical PHP state and the same runtime objects.

## Explicit non-goals

This work does not include:

- A v2 migration engine or parallel execution path.
- JSON migration configuration, schemas, validators, compilers, configured adapters, readers, or resolvers.
- New source adapters or changes to existing source queries.
- Stable source keys or any new identity model.
- Runs, tasks, dependency graphs, worker leases, source-to-target mappings, or a run ledger.
- New migration-execution concurrency, retry, crash-recovery, or idempotency semantics. Storage-maintenance locking and switch recovery are in scope.
- Destination writers, provenance metadata, created-versus-reused ownership, or media deduplication changes.
- Dry-run or preflight redesign.
- Content journals, content hashes, output rollback, or destination deletion.
- Changes to shortcode/block matching or replacement behavior.
- Background migration processing, cron, Action Scheduler, or a new migration-worker transport. Storage-maintenance WP-CLI commands are allowed for switching/cleanup recovery only.
- Reinterpreting old migration history into a future data model.

The legacy storage tables described here are not future run, task, mapping, journal, or rollback tables. Any future engine must receive separate repositories and purpose-built tables.

## Target architecture

```text
Existing legacy migration behavior
        |
        v
MigratorSettings / legacy storage facade
        |
        +-- LegacyStateCodec
        |
        +-- ScalarStateRepository
        +-- MigratableCollectionRepository
        +-- MigratedHistoryRepository
        +-- ContentScanRepository
        +-- ImageTagQueueRepository
        |
        +---------------------+
        |                     |
        v                     v
 LegacyOptionBackend   LegacyTableBackend

StorageManager
  +-- backend authority
  +-- estimates/recommendation
  +-- schema and health
  +-- switching
  +-- cleanup/uninstall
```

The storage facade retains compatibility methods such as `get_migrator_setting()` and `set_migrator_setting()` for scalar state and transitional tests. A permitted storage-facing refactor moves high-cardinality table-mode paths to bounded repository queries and patches. It does not create a run, task, worker, scheduler, or alternative execution contract. Merely putting the existing whole-array `get`/`set` API over a table would still hydrate and serialize every object and therefore would not meet the stored-state memory objective.

### LegacyStateCodec

The codec is extracted from the current compaction and hydration logic and sits above both backends.

Responsibilities:

- Continue reading existing un-compacted and compact-version-1 option data in option mode.
- Encode trusted runtime objects as scalar/array records only.
- Preserve list order, associative keys, integer-versus-string key type, `false` versus missing, nested children, settings, errors, status, and progress.
- Hydrate records using the same current adapter lookup behavior.
- Flatten and reconstruct nested records for the table backend without changing their logical representation.
- Produce per-record logical hashes and a snapshot-only logical digest for switching verification.
- Report encoded byte counts and record counts without hydrating records when the backend can provide them directly.

The codec version is independent of the plugin version and database schema version. A read must never rewrite or upgrade state implicitly.

Current compact-v1 writes intentionally retain only a whitelist of object properties; some older non-compact options can expose additional runtime properties that compact v1 would discard. Table conversion must therefore use these rules:

- A compact-v1 source must round-trip to the same compact-v1 logical value and runtime behavior.
- A non-compact source remains readable and untouched in option mode.
- A table switch must detect non-compact object values before staging. It may proceed only if a tested, allowlisted lossless legacy encoder can preserve every currently observable property needed by the runtime.
- The initial implementation must block conversion of an unsupported non-compact payload with an actionable explanation. It must not silently compact it, discard properties, or clear history.

Phase 0 decides which historical non-compact shapes require a lossless encoder. “Parity” does not mean that a table import is allowed to normalize old objects into the narrower compact-v1 shape without an explicit, separately approved compatibility policy.

### ScalarStateRepository

Stores small or unknown operational values while preserving the current key/value contract:

```text
get(key, default)
set(key, value)
setMany(changes)
delete(key)
has(key)
hasItems(key)
```

`setMany()` provides one atomic write for changes that involve scalar state only. Cross-repository invariants do not use it: migrated-history mutations and `migrated-revision`, for example, are owned and committed together by `MigratedHistoryRepository`. The API must distinguish a missing key from a stored `false` value.

### MigratableCollectionRepository

Owns the current `galleries` and `albums` collections:

```text
replaceCollection(bucket, objects)
count(bucket, filters)
page(bucket, offset, limit, filters)
findByLegacyIdentifier(bucket, identifier, includeChildren)
findManyByLegacyIdentifiers(bucket, identifiers, includeChildren)
saveRootTree(bucket, object)
applySelectionPatch(bucket, engineComputedPatch)
findFirstByStatuses(bucket, statuses, legacyOrder, includeChildren)
findFirstIdentifierByStatus(bucket, status, legacyOrder)
aggregate(bucket, engineDefinedFilters)
clear(bucket)
```

The option implementation may internally use the current full arrays. The table implementation must use indexed counts, pages, selection patches, and one-root reads. Repository filters and patches express decisions already made by the current migrator; the repository does not choose what should be selected, which transition is valid, or what should run next.

### MigratedHistoryRepository

Owns the current `migrated` map and its revision:

```text
addIfAbsent(legacyIdentifier, object)
has(legacyIdentifier)
get(legacyIdentifier)
getMany(legacyIdentifiers)
findFirstMigratedBySourceIdentity(objectType, pluginName, sourceId)
findManyMigratedBySourceIdentities(identities)
updateStatus(legacyIdentifier, status)
delete(legacyIdentifier)
page(type, status, offset, limit)
summary()
revision()
iteratePages(pageSize)
resolveImageDestinationsBySourceUrls(urls)
resolveImageDestinationsByPluginSourceIds(pairs)
clear()
```

History remains keyed by the exact current legacy identifier. No new mapping semantics are introduced.

Source-identity helpers are indexed access to values already present on current migrated objects; they do not introduce a new identity model. For gallery/album content matching they preserve the current exact comparison of object type, plugin `name()`, and `(string) ID`, require the current `migrated`/positive `migrated_id` conditions, and return the first match in legacy history order. Image-tag fallback lookup preserves its separate current plugin-name plus `absint( ID )` semantics. Duplicate matches therefore resolve exactly as the current ordered PHP loops do.

`addIfAbsent()`, `updateStatus()`, and `delete()` must update the history mutation and `migrated-revision` in one backend transaction: one `update_option()` in option mode and one database transaction in table mode. No caller should be able to observe a changed history row with the old revision or vice versa.

### ContentScanRepository

Owns content findings and scan/reconciliation checkpoints:

```text
replaceWithBatchAndCheckpoint(items, checkpoint)
appendBatchAndCheckpoint(items, checkpoint)
page(filters, offset, limit)
getManyByStoredKeys(keys)
count(filters)
applyStatusPatchAndCheckpoint(changes, checkpoint)
checkpoint()
clear()
```

`replaceWithBatchAndCheckpoint()` starts a reset by making the first batch, including an empty batch, and its checkpoint visible in one atomic replacement. It must stage and flip the replacement while the previous snapshot remains authoritative; it must never clear the old findings before the replacement commit succeeds. `appendBatchAndCheckpoint()` must likewise be atomic so a failed persistence operation cannot advance the cursor past uncommitted findings. These rules preserve the current safety guarantee.

Table mode must also enforce the existing `content_item_key()` result as a per-generation occurrence deduplication key. Retried scan batches query/upsert those keys instead of rebuilding an in-memory set from every previous finding. Repeated occurrences that currently receive different keys remain distinct. Submitted content selectors continue to address the same original typed array keys/absolute positions, and status reconciliation fetches only the current content page plus the corresponding migrated-history identifiers.

### ImageTagQueueRepository

Owns the potentially large `image-tag-sync` item list and its small counters/checkpoint:

```text
beginReplacement()
getManyBuildRowsByKeys(keys)
applyEngineComputedBuildPage(rows)
finalizeReplacement(summary)
nextBatch(limit)
applyBatchPatch(changes, summary)
summary()
clear()
```

The existing queue builder may consume migrated history through a bounded page iterator in table mode, resolve only the source URLs/source-ID pairs required by the current fallback page, persist each engine-computed page immediately, and discard page-local maps. The current attachment ID is the existing queue merge key and is enforced as a table deduplication key. Queue construction, tag merging, tag assignment behavior, and batch size remain owned by the existing service and remain unchanged. If an adapter's existing `find_image_tag_sync_items()` returns an unbounded fallback array, that adapter-owned peak remains a documented limitation.

## Data placement

| State | Option mode | Table mode |
|---|---|---|
| User preferences | `foogallery-migrate-settings` | Same option |
| Backend authority/switch status | Small dedicated non-autoloaded control option | Same option |
| Table-authority safety sentinel | Dedicated non-autoloaded per-site option when a table flip is pending or table mode is authoritative | Same option |
| Storage-maintenance lock | Dedicated non-autoloaded per-site lock option | Same option |
| Recommendation dismissal/observed metrics | Dedicated non-autoloaded per-site option | Same option |
| Installation-wide shared-table schema version/upgrade lock | Regular options in the main site's options table | Same resource |
| Sites using new storage | Bounded/chunked network registry where required | Same resource plus table-state rows |
| Detected-plugin records | `foogallery-migrate-data` | Legacy state table |
| Small engine/adapter values | `foogallery-migrate-data` | Legacy state table |
| Gallery discovery/queue | `foogallery-migrate-data` | Flattened legacy records |
| Album discovery/queue | `foogallery-migrate-data` | Flattened legacy records |
| Migrated history/revision | `foogallery-migrate-data` | Flattened legacy records plus state head |
| Content findings/checkpoints | `foogallery-migrate-data` | Legacy records plus state head |
| Image-tag queue/checkpoint | `foogallery-migrate-data` | Legacy records plus state head |
| Short-lived per-user result transient | Existing transient | Existing transient |

The control option is deliberately outside both backends so backend selection never depends on first selecting a backend. It contains no migratable records. Every newly introduced option, option prefix, table, transient prefix, and registry chunk is part of the cleanup manifest; cleanup must be driven by that manifest rather than separate hand-maintained lists.

Proposed owned names are fixed before implementation:

- Existing per-site options: `foogallery-migrate-data`, `foogallery-migrate-settings`.
- New per-site options/prefixes: `foogallery_migrate_storage_control`, `foogallery_migrate_storage_authority`, `foogallery_migrate_storage_maintenance`, `foogallery_migrate_storage_writer_`, `foogallery_migrate_storage_notice`, and `foogallery-migrate-data-storage-backup`.
- New installation-wide resources: `foogallery_migrate_storage_schema_version` and `foogallery_migrate_storage_schema_lock`, stored as regular options in the main site's options table so `option_name` uniqueness can arbitrate lock acquisition.
- New per-network resource: a bounded/chunked `foogallery_migrate_storage_sites_` site registry.
- Existing transient prefix: `foogallery_migrate_content_result_`, including timeout rows.
- New tables: `{base_prefix}foogallery_migrate_legacy_state` and `{base_prefix}foogallery_migrate_legacy_records`.

If implementation changes a name, this list, the lifecycle resource manifest, and cleanup tests must change together.

## Option backend

Option mode remains the initial and fallback-compatible mode.

- It uses the existing `foogallery-migrate-data` option.
- Writes continue using `update_option(..., false)` so the option is non-autoloaded.
- One logical multi-key update results in one option update.
- `update_option()` returning `false` is still treated as success when the requested value is already stored.
- It reads old un-compacted payloads without a write-time conversion.
- It does not create per-record or per-run options.
- It remains appropriate for small sites. All current tests execute through option storage, although they do not yet cover every option semantic or lifecycle behavior.

The first implementation release must route existing behavior through this backend only. Its pass condition is logical snapshot parity and the complete existing regression suite, not a performance improvement.

## Table backend

### Physical scope

Use two network-shared plugin-owned tables based on `$wpdb->base_prefix`, with mandatory `blog_id` isolation:

- `{base_prefix}foogallery_migrate_legacy_state`
- `{base_prefix}foogallery_migrate_legacy_records`

The `legacy` qualifier is intentional. These tables persist the current engine's state; they must not be reused as a future engine ledger.

On a non-multisite installation, `$wpdb->base_prefix` and `$wpdb->prefix` are equivalent. On multisite, one schema can be upgraded or dropped without creating a table pair for every site.

Because that schema is shared, provisioning and schema upgrades require network-level authority on multisite. The schema version and atomic upgrade lock have one installation-wide authority: both are regular options in the main site's options table, even on a multi-network installation, so two networks cannot run independent upgrades against the same base-prefix tables. Acquire the lock by switching to the resolved main site and calling `add_option()` with autoload disabled; the unique `option_name` index is the arbitration primitive. Token-checked renewal/release and stale takeover use compare-and-swap semantics on that exact row, and every path restores the prior blog context. A site administrator may select table mode only after an authorized network administrator has provisioned and verified the shared schema; site-level enablement then creates rows only for that blog. Multi-network provisioning/repair requires super-administrator authority.

### State-head table

One row describes the authoritative value or collection head for a site and state key.

| Column | Purpose |
|---|---|
| `id` | Unsigned auto-increment primary-key row ID |
| `blog_id` | Mandatory site boundary |
| `state_key_hash` | Binary SHA-256 of the exact tagged operational-key bytes |
| `state_key_value` | `LONGTEXT` lossless tagged operational key used to verify hash lookups |
| `state_order` | Exact top-level operational-key order in the logical PHP state |
| `state_kind` | Scalar, list, map, content collection, or object collection |
| `active_generation` | Generation visible to readers |
| `revision` | Incremented logical state revision |
| `codec_version` | Codec required to decode records |
| `record_count` | Count without hydration |
| `payload_bytes` | Encoded bytes without hydration |
| `snapshot_digest` | Binary SHA-256 digest computed for an explicit snapshot/verification |
| `digest_revision` / `digest_status` | Revision covered by the digest and whether it is current or dirty |
| `root_payload` | `LONGTEXT` scalar/root payload or collection metadata |
| `created_gmt` / `updated_gmt` | UTC lifecycle timestamps |

Required uniqueness and indexes:

- Primary key `(id)`.
- Unique `(blog_id, state_key_hash)`.
- Unique `(blog_id, state_order)`.
- Index `(blog_id, updated_gmt)` for health and cleanup reporting.

### Record table

Each collection item or nested child is one row.

| Column | Purpose |
|---|---|
| `id` | Unsigned auto-increment primary-key row ID and deterministic tie-breaker |
| `blog_id` | Mandatory site boundary |
| `state_id` | Owning state-head row ID, application-enforced with the same `blog_id` |
| `generation` | Owning staged/active generation |
| `row_kind` | Root item, child item, content item, or queue item |
| `node_hash` | Binary SHA-256 internal row identity |
| `legacy_identifier_hash` | Optional binary SHA-256 of the exact current object identifier |
| `legacy_identifier_value` | Lossless encoded current identifier used to verify hash lookups |
| `dedupe_hash` | Optional binary SHA-256 for state-specific existing deduplication keys, such as content occurrences or attachment queue keys |
| `parent_node_hash` | Parent row for nested children; `NULL` for roots |
| `key_type` / `key_value` | Lossless tagged/base64 representation of the original PHP array key |
| `item_order` | Exact sibling ordering |
| `depth` | Nested depth for validation and cleanup |
| `object_type` | Denormalized gallery/album/image/content type for filtering |
| `source_plugin_hash` / `source_plugin_value` | Binary SHA-256 plus lossless raw value for the current plugin name or content `plugin_name` |
| `source_id_value` / `source_id_numeric` | Lossless exact `(string) ID` plus nullable current `absint( ID )` projection where applicable |
| `source_identity_hash` | Binary SHA-256 of object type, exact plugin-name bytes, and exact string source-ID bytes |
| `source_numeric_identity_hash` | Optional binary SHA-256 of image type, exact plugin-name bytes, and current positive numeric source ID |
| `migration_status` | Denormalized current status for queue/log filtering |
| `is_selected` | Denormalized `part_of_migration` flag |
| `is_migrated` | Denormalized migrated flag |
| `migrated_id` | Denormalized destination ID for common reads |
| `has_error` | Denormalized error flag for summaries |
| `payload` | Tagged compact base record excluding children and table-canonical mutable fields |
| `mutable_payload` | Tagged values for all mutable compact fields, including status, selection, migrated title/count/progress, destination ID, and error |
| `payload_hash` / `logical_hash` / `payload_bytes` | Base-payload integrity, complete logical-record integrity, and statistics |
| `created_gmt` / `updated_gmt` | UTC lifecycle timestamps |

Required uniqueness and indexes:

- Primary key `(id)`.
- Unique `(blog_id, state_id, generation, node_hash)`.
- Page/child index `(blog_id, state_id, generation, parent_node_hash, item_order, id)`.
- Legacy lookup index `(blog_id, state_id, generation, legacy_identifier_hash)`.
- Source-plugin count index `(blog_id, state_id, generation, source_plugin_hash, item_order, id)`.
- Exact source-identity index `(blog_id, state_id, generation, source_identity_hash, is_migrated, item_order, id)`.
- Numeric image-source index `(blog_id, state_id, generation, source_numeric_identity_hash, item_order, id)`.
- Unique deduplication index `(blog_id, state_id, generation, dedupe_hash)`; nullable outside state types that already define deduplication semantics.
- Work index `(blog_id, state_id, generation, migration_status, is_selected, item_order, id)`.
- Log page-order index `(blog_id, state_id, generation, object_type, item_order, id)`.
- Separate aggregate index `(blog_id, state_id, generation, object_type, migration_status)`.

Raw state keys, record identifiers, plugin names, and source IDs are not indexed. Existing values can be arbitrarily long strings, case-sensitive URLs, or differently typed PHP keys. Hash the exact tagged bytes, retain a lossless encoded value, and compare it after lookup to protect against a theoretical hash collision; a hash match with different raw bytes is a closed-failure storage-health error. Source hashes use explicit version tags and the exact legacy comparison normalization described above. A record's `state_id` must resolve to the same site's state head before use. `item_order` is the authoritative collection/history order, including first-match source-identity behavior; generated row IDs are only final integrity tie-breakers and must never redefine legacy order.

`node_hash` is derived from a version tag, the state-key hash, parent node hash, key type, and length-prefixed key bytes. It does not include the generation, so the same logical row can be compared across generations. PHP arrays cannot contain duplicate typed keys under one parent; detecting such a duplicate during reconstruction is corruption.

Use `BINARY(32)` for SHA-256 values; bounded `VARBINARY` columns for state kind, row kind, object type, and status; `TINYINT UNSIGNED` for flags; and `BIGINT UNSIGNED` for IDs, revisions, generations, counts, byte totals, depth, and ordering. Pass hashes as prepared hexadecimal strings and convert with a fixed `UNHEX()` expression instead of sending raw binary through text/JSON code paths. After `dbDelta()`, inspect the real table definition and required indexes before marking the schema healthy.

### Encoding and database compatibility

- Store compact scalar records as `LONGTEXT` containing a versioned, tagged, lossless envelope encoded with `wp_json_encode()`. The envelope must explicitly preserve scalar types, array-key types and order, `false`, `null`, missing values, and arbitrary string bytes; strings that are not safely representable as UTF-8 use a tagged base64 form. Plain unqualified JSON encoding is not sufficient for parity.
- Do not store PHP objects in the database.
- Do not use MySQL `JSON`, enums, foreign keys, or user-defined table names.
- Create and upgrade with `dbDelta()` and a separately stored schema version.
- Use WordPress charset/collation helpers.
- Require InnoDB for table mode because content findings and checkpoints need transactional commits. If InnoDB cannot be created or verified, leave option mode authoritative and explain the failure.
- Prepare every value. Table names are fixed plugin constants and never derived from a request.
- Do not cache complete reconstructed collections in table mode. Cache only bounded rows/pages or aggregates keyed by state revision, and invalidate them after the committing transaction or generation-head flip.

Snapshot verification uses one codec-defined canonical logical digest, not a hash of either backend's physical bytes. Its input is the ordered sequence of top-level state keys using `state_order`, with every key's exact tagged type/bytes and complete decoded logical value, including nested key types/order and the logical `migrated-revision` value. It excludes table row/state IDs, generations, repository cache revisions, timestamps, encoded byte counts, indexes/projections, and backend-specific envelopes. Option import captures current PHP top-level key order; updating a key retains its position, adding a key appends it in the same order as the equivalent PHP array operation, and deleting a key removes only that position. This definition makes the option and table digest comparable without treating physical metadata as engine state.

### Flattening and reconstruction

The codec first produces the same compact logical records as option mode. The table backend then stores the parent record without its `children` array and writes each child as an ordered row linked by `parent_node_hash`. The process is recursive, so album galleries and gallery images can be represented without duplicating their entire tree in a single row.

Reconstruction follows parent links and item order, restores typed keys, combines the base and mutable envelopes, reinserts `children`, verifies logical hashes, and then asks the codec to hydrate runtime objects. The base and mutable envelopes together are authoritative. Indexed status/type/selection/source-identity columns are derived projections and must be updated atomically with their canonical payload fields and `logical_hash`; any mismatch is storage corruption, not a second source of behavioral truth. Bulk selection/status changes may issue bounded SQL/application batches to control memory, but every batch stays inside one logical commit: use one database transaction for the current synchronous operation, with the state-head revision committed last. No intermediate batch is visible, and a failure rolls the complete patch back. If a supported database cannot provide that transaction contract, block table mode rather than weaken current option-write visibility. Unknown or corrupt records fail closed with a storage-health error; the backend must not silently fall back to a stale option copy.

### Atomicity and generations

Synchronous whole-collection replacement, option-to-table import, and repair use staged generations:

1. Keep the current head authoritative.
2. Write a new generation in bounded batches.
3. Verify row counts, typed keys, ordering, payload hashes, and the logical snapshot digest.
4. Update the state head to the new generation in one transaction.
5. Mark the previous generation stale for later cleanup.

An interrupted or invalid staged generation is never visible to normal readers. A normal current-engine collection save must finish and flip atomically in its calling request or report failure; it does not become an asynchronous operation. Only an explicitly coordinated backend switch, repair, or maintenance import may persist a cursor and resume a staged generation in a later request.

Normal single-record changes update only the affected row/tree and increment the state-head revision in the same transaction. Counts and byte totals are maintained by transaction deltas. A whole ordered snapshot digest is computed only for switching, explicit verification, or repair; a normal mutation marks it dirty instead of performing an O(N) rehash. Bulk selection/status patches, content findings and their checkpoint, image-tag batch results and their checkpoint, and migrated-history mutations plus `migrated-revision` each commit as one logical transaction. A content reset or queue rebuild stages a replacement and flips its head only after the initial/complete replacement state is valid, so failure cannot expose a cleared or partial collection. Every state-head flip uses compare-and-swap against the captured active generation and revision. Table mode does not add engine-level worker leases or change current concurrent-request semantics.

## Required bounded access in table mode

The table backend only solves total-state memory pressure if callers use its bounded repository methods. The completed storage project therefore includes the following storage-facing substitutions while preserving the same visible behavior:

| Current operation | Required table-mode access |
|---|---|
| Gallery/album list | SQL count plus requested root page; load children only for roots on that page when the current view requires them |
| Gallery/album/content list with `page_size = 0` | Preserve the current no-pagination output and order, but keyset-iterate and render fixed internal batches instead of materializing the complete collection |
| Queue selection/cancel | Apply the engine-computed selection/status patch with bounded statements inside one transaction/revision, updating canonical mutable envelopes and projections together with no partially visible patch |
| Continue migration | Fetch the first queued/started root and its active child tree; save only that root/tree |
| Overall progress | Indexed aggregate counts rather than hydrating every object |
| Current object | Indexed first `started` record |
| Adapter object factories | Direct history `get()`/`getMany()` instead of an existence check followed by a complete history read |
| Content source-identity resolution | Indexed exact object-type/plugin-name/string-ID lookup in legacy history order, with batched variants for one findings page |
| History lookup/update/delete | One legacy-identifier lookup and one row mutation |
| Migration log | SQL count/summary and requested page |
| Retry/error-check flows | Fetch/patch requested gallery roots directly; the current global “Check For Migration Errors” audit iterates gallery roots, child errors, and related history in bounded pages |
| Content scan | Repository-enforced existing occurrence deduplication; append one findings batch and checkpoint |
| Content occurrence construction | Resolve the occurrence page's current identifiers through direct/batched history lookups; never hydrate all migrated objects while building scan findings |
| Content status refresh | Requested content page plus batched `getMany()` history resolution, not an all-history lookup |
| Content rendering/selection/replacement | Preserve exact stored selectors while retrieving only the requested/selected records |
| WordPress Core detection summary | Use the indexed source-plugin projection plus raw-value verification to count `plugin_name = WordPress Core`, rather than loading every content finding into PHP |
| Image-tag queue construction | Page history, resolve page-local source keys, persist one engine-computed build page, and discard page maps |
| Image-tag execution | Fetch and patch only the current bounded queue batch |
| Debug diagnostics | Bounded pages/aggregates and an explicit bounded export path; never an unconditional all-history dump |

Compatibility full-collection reads may remain for option mode and tests, but table-mode production paths must not call them. A table backend that merely reconstructs every array on every request is not considered complete and must not be presented as a stored-state scaling solution.

During development, table-backend compatibility `getAll()` methods should be instrumented to fail tests when invoked from a registered production path. The Phase 2 inventory must cover object factories, retry/error repair, content scanning/status/rendering/replacement, image-tag queue building, the migration log, and debug diagnostics—not only the main migration loop.

The existing `page_size = 0` setting explicitly means “disable pagination” and remains valid. In table mode, it is one logical all-record result, but the repository and renderer must keyset-iterate fixed internal batches, batch related history lookups, and emit rows in exact legacy order without first building one complete PHP collection. Selection controls, counts, row keys, and visible output remain unchanged. The response body and rendering time are still O(N), and WordPress, PHP, web-server, proxy, or browser output buffering may retain that body in memory; table storage cannot guarantee bounded end-to-end memory for this deliberate no-pagination view. The Storage screen should explain that limitation and recommend a positive page size on large sites without changing the saved value automatically.

### Memory boundary and remaining limitations

With bounded access, table-mode state memory should grow with the requested page or affected root tree rather than unrelated stored records. An active root tree may itself contain a large portion of the site's stored state.

This project does not change source-adapter discovery contracts. An adapter that returns every source gallery at once can still have a high one-time discovery peak before storage receives the array. Similarly, current object contracts may require all children of the one active gallery or album to be loaded. Table mode therefore addresses stored-state hydration and rewrite pressure, but peak memory can still be determined by the largest single discovered result or active object tree.

Performance claims exclude initial/forced source refresh, adapter fallback scans that return complete arrays, WordPress-core entity preparation that still uses complete legacy results, the active gallery/album tree unless a specific full-read call site is replaced by one of the bounded storage paths above, and end-to-end response buffering for a deliberate `page_size = 0` view. Even in that view, table-repository hydration and history resolution must remain internally batched.

Those limitations must be visible in release notes and benchmarks. Fixing them would require source/engine contract changes and belongs to separate future work.

## Storage recommendation policy

### Inputs

The advisor uses storage observations only:

- Serialized bytes of the current legacy option, queried with SQL `LENGTH()` using the fixed option name so the option need not be hydrated.
- Encoded bytes reported by the codec during a write or import.
- Root and nested record count.
- Largest collection and largest single record/tree.
- Effective PHP memory limit and a benchmarked hydration amplification factor.

It does not invoke source adapters, add source-count queries, or perform a new preflight. Before relevant state has been discovered, a storage-only advisor may have insufficient evidence to recommend a mode.

### Initial thresholds

Use provisional, filterable defaults and confirm them with Phase 0/Phase 6 benchmarks:

- Show an early warning at 512 KiB encoded state or 1,000 compact records.
- Recommend table mode at 1 MiB encoded state or 2,000 compact records.
- Also recommend when estimated hydrated state would consume at least 20% of the effective PHP memory limit.
- Count galleries, albums, nested gallery/image children, migrated-history records, content findings, and image-tag queue items—not only top-level galleries.

The recommendation shows the exact trigger, current metrics, expected benefit, and known largest-tree limitation. When `page_size` is zero, it also explains the no-pagination response-memory limitation and suggests a positive value. It links to the Storage & Cleanup screen with “Use table storage” as the recommended action, but requires an administrator confirmation; it does not change either setting automatically.

Thresholds must be filterable. Dismissing the notice records the observed size, not a permanent opt-out; show it again when encoded bytes or record count grows by at least 25% or the hard recommendation tier changes.

There is no silent switch. Even far above the threshold, the plugin shows a stronger warning rather than creating tables or changing authority without approval.

## Backend authority and switching

### Control option

Use a small, non-autoloaded `foogallery_migrate_storage_control` option outside both backends. It records:

- Active backend: `option` or `table`.
- Last verified shared-schema version and active codec version; the authoritative shared-schema version remains installation-wide in the main site's regular options table on multisite.
- Switch ID, source, destination, phase, and cursor.
- Authority epoch.
- Source snapshot marker: raw serialized-option digest for option mode, or the ordered state-head generation/revision manifest plus verification digest for table mode.
- Destination staged generation.
- Start/update timestamps and last verified health result.
- Stale retained backend copies available for removal.

The per-site maintenance gate is a separate exact option acquired atomically with `add_option()`, with a random ownership token, expiry, and token-aware stale takeover. Each active storage writer uses a separate short-lived marker option. A writer checks the maintenance gate, acquires its marker, and checks the gate again; maintenance acquires the gate first and then waits for pre-existing writer markers to finish. This closes the check-then-start race without adding engine worker leases.

Writer acquisition is request-scoped and re-entrant so nested legacy calls share one marker rather than creating an unbounded marker chain. Only the owner token may renew or release a gate/marker; expiry is recovery evidence, not permission to delete an unexpired writer blindly.

`foogallery_migrate_storage_authority` is a second, non-autoloaded safety sentinel, not a second authority source. Before a table-authority flip, write its site ID, switch ID, and next authority epoch; require it to match the valid control option for every table-mode read. It remains while table mode is authoritative and is removed only after a verified reverse flip makes option mode authoritative. This gives empty table-mode state durable evidence even when no state-head row exists.

Missing control data means option mode only when there are no table state heads, table-authority sentinel, staged switch records, or distinct backup option for that site. If any such resource exists without valid control data, block migration and require explicit storage recovery; never infer option authority from a stale value. A valid table control with a missing/mismatched sentinel also blocks and requires repair.

### Preconditions

Switching requires:

- `manage_options` and a purpose-specific nonce.
- Explicit confirmation.
- A healthy source backend.
- An acquired storage-maintenance gate and no remaining active writer marker.
- A repeated post-gate affirmative verdict from the existing engine's opaque quiescence integration.
- A destination health check, required table privileges, and sufficient disk space where it can be estimated.

The quiescence check is an integration supplied by the existing engine; the storage layer consumes only its yes/no verdict and does not define or reinterpret engine states. Persisted queued, started, paused, or incomplete work can be a stable between-request snapshot and is not by itself a storage-switch veto. Every mutating administrator/AJAX endpoint must acquire a storage-writer marker before any destination or state mutation and release it in all supported control paths. Storage mutations also verify the maintenance gate defensively.

### Option to table

1. Set maintenance state while keeping option mode authoritative.
2. Create or upgrade the fixed tables lazily.
3. Read a codec-level snapshot of the option backend.
4. Import each state key into inactive table generations in bounded batches.
5. Reconstruct and verify every key, key type, order, count, payload hash, revision, and logical snapshot digest.
6. Re-read the authoritative raw option value and compare its captured digest immediately before the flip.
7. Create a distinct non-autoloaded backup option from the verified source snapshot; do not use the live `foogallery-migrate-data` name for a stale copy.
8. Write the table-authority sentinel with the switch ID and next authority epoch.
9. Flip `active_backend` in the external control option only after verification succeeds, then remove the live legacy option name.
10. Release maintenance state.

Retaining a distinct backup avoids accidental reads by table-mode code. It does not make table mode compatible with installing an older plugin version that does not understand the authority option. Administrators must switch back to option mode before downgrading; the Storage screen and release documentation must warn about this explicitly.

### Table to option

1. Apply the same permission, quiescence, locking, and health checks.
2. Export the current table snapshot into a fresh complete `foogallery-migrate-data` value.
3. Verify the option's logical snapshot against the table source.
4. Flip authority only after verification.
5. Remove the table-authority sentinel only after the option-authority flip is durably verified.
6. Retain table generations as stale until explicitly removed.

Warn strongly and normally refuse a reverse switch above the recommendation thresholds unless an explicit developer filter or WP-CLI override is used.

The retained pre-switch backup is stale and must never be used for reverse switching; reverse switching always exports the current authoritative table state.

### Failure and recovery rules

- Until the final control-option flip, the source backend remains authoritative.
- A failure records a bounded error and resume cursor without changing authority.
- Retrying resumes or discards the destination staging generation.
- Source and destination state are never merged.
- Immediately before a flip, recheck the source raw digest/revision captured behind the maintenance gate. A mismatch aborts the switch.
- After a successful flip, a destination health failure blocks migration and offers an explicit recovery path; it never silently reads the stale source.
- Cleanup removes incomplete generations and expired maintenance gates/writer markers only after proving they are not authoritative.

### Existing oversized options

An option-to-table switch must deserialize the old WordPress option at least once before records can be separated. Measure raw bytes first and perform the import in a dedicated request with the WordPress admin memory limit raised. If the estimated decode peak does not fit safely, the web UI must refuse to start rather than risk a fatal error.

For a time-bounded web import, the maintenance gate keeps the source option immutable. Each resumed request may re-read that immutable snapshot, re-decode it, skip already verified destination records, and continue from the stored cursor. The cursor bounds database work and request time; it does not reduce the source option's decode-memory peak. Do not imply that a resumable web copy can deserialize once across separate PHP requests unless a separately designed internal staging resource actually owns the snapshot.

Provide storage-only WP-CLI commands for those recovery cases, for example status, switch, verify, and purge commands that can run with an explicitly increased CLI memory limit. Do not implement a custom streaming PHP-serialization parser in the initial release. Never discard legacy history automatically to make a switch fit.

## Storage and cleanup administration

Add a Storage & Cleanup section to the existing admin area. This is the only new workflow in scope.

It displays:

- Active backend and whether it is recommended for the observed size.
- Encoded bytes, root/nested record counts, and largest collection/tree.
- Option bytes and table row/payload bytes.
- Schema and codec versions and health.
- In-progress or failed switch status with resume/discard actions.
- Stale option/table copies and their size.
- A clear explanation that storage cleanup does not undo migrations.

Every mutating action requires `manage_options`, a dedicated nonce, exact target resolution, and a confirmation appropriate to its scope.

| Action | Removes | Keeps |
|---|---|---|
| Clear current migration state | Current detection, discovery, queues, history, content scan, image-tag state, and stale copies so old history cannot reappear | User preferences, active storage mode/schema, migrated output |
| Remove stale option copy | Distinct inactive storage-backup option | Active table state, settings, output |
| Remove stale table copy | Inactive generations/rows | Active backend, settings, output |
| Reset storage to defaults on this site | Both site state backends and site control/lock/writer data; deletes this site's shared-table rows and returns to empty option mode | User preferences, shared multisite schema, and output |
| Remove all internal data on this site | Per-site options, transients, locks/writers, notice data, sentinel, backup state, registry membership, and this site's rows; drops tables only on non-multisite | All source and migrated content/media; other multisite sites; installation-wide schema options on multisite |
| Remove all internal data in this network | Owned per-site resources for that network's sites, that network's registry, and their rows | Other networks; installation-wide schema options/shared tables while any network can still use them; all source and migrated content/media |
| Remove all internal data installation-wide | All registered/prepared site and network resources, installation-wide schema options, all rows, and the two exact shared tables | All source and migrated content/media |

The existing “Clear Migration History” control currently clears the complete operational option despite its label. During this project it should be routed to the backend-aware “Clear current migration state” behavior; changing its product wording can be decided separately. It must clear both authoritative state and stale state copies to prevent accidental reappearance after a later switch.

Cleanup spans resources that cannot share one transaction, so it is explicitly idempotent and resumable rather than falsely atomic. A destructive action acquires the maintenance gate, records cleanup-in-progress, resolves only the fixed ownership manifest, and removes authoritative/stale state before deleting the cleanup-in-progress marker last. An action whose scope removes storage mode also deletes the authority control and table sentinel only after their owned state is gone; “Clear current migration state” keeps both because table mode may remain selected with an empty state. Each response and CLI run reports the exact resources removed, already absent, failed, and still present. If any step fails, migration remains blocked by the cleanup marker; rerunning the same action safely continues from observed resource state. Cleanup does not roll back resources already removed.

### Lifecycle behavior

- Register lifecycle hooks at plugin bootstrap scope.
- Normal activation does not create the legacy tables; table creation is lazy after explicit single-site selection or multisite network provisioning.
- Deactivation retains all state and allows a later resume.
- Schema upgrades are versioned, idempotent, and run only when table storage exists or is being enabled.
- Shared-table schema version and schema-upgrade lock are regular options stored once in the main site's options table; on single-site, the current site is the main site. All networks resolve the same installation-wide lock before DDL. Per-site control records only the last schema version it verified.
- `dbDelta()` and DDL can auto-commit or partially apply. Upgrades must be additive, backward-compatible, stepwise, and health-checked after each step. Block table-mode requests during an incompatible partial upgrade and provide resumable repair; do not promise transactional DDL rollback.
- `uninstall.php` must check `WP_UNINSTALL_PLUGIN` and load a minimal uninstall-safe cleanup dependency directly. It must not bootstrap the normal admin engine.
- Uninstall removes internal storage only. It does not attempt rollback and does not delete galleries, albums, attachments, source data, or edited post content.
- Drop only exact fixed plugin-owned table names; never accept a table name from a request or wildcard.

Known transient families, including per-user content result transients, must be enumerated with a prepared, escaped fixed prefix and removed with their timeout rows. Use the Transients/Object Cache APIs for each resolved key where practical so a persistent object cache is invalidated; direct SQL cleanup alone is not sufficient. Cleanup code must also remove storage-control, table-authority-sentinel, schema, maintenance-gate, active-writer, recommendation-dismissal, backup, and site-registry resources introduced by this project.

## Multisite

The selected design uses network-shared tables and per-site rows:

- Every read, write, update, delete, aggregate, and integrity query requires the current `blog_id`.
- Storage mode and switch state remain per-site.
- Site-level cleanup deletes only that site's rows and cannot drop shared tables used by other sites.
- Cleanup for one selected network deletes only that network's blog rows. Global uninstall/cleanup may drop both exact tables only after proving no network still owns rows.
- On non-multisite, “remove all” may drop both tables immediately.
- Maintain a bounded, chunked or table-backed network registry of sites that have plugin storage so cleanup and diagnostics do not normally scan every site; do not assume one ever-growing network option remains small.
- Multisite table mode requires the plugin to be network-active for the site's network before provisioning or selection; site-only activation remains option-mode only. When loaded for site deletion, remove that site's rows and registry entry. On WordPress 5.1+ use the appropriate modern site-uninitialization hook; retain the older `delete_blog` fallback for the runtime's declared WordPress 5.0 compatibility.
- Network deactivation or an unusual deletion path can prevent the hook from running. Registry health checks, network cleanup, and WP-CLI therefore audit registered `blog_id` values in bounded pages, identify sites that no longer exist, and remove only those orphan rows/entries after confirmation or during authorized uninstall cleanup.
- Network activation does not iterate sites or create tables.
- Within the benchmarked synchronous bound, uninstall removes all registered/current per-site resources and performs a bounded final enumeration for pre-upgrade sites that never entered the registry. Above that bound, every per-site resource—not only pre-registry options—must be removed by the preparatory batched admin/WP-CLI workflow before uninstall. Final uninstall still drops the two exact shared tables and removes the installation-wide schema resources.

For installations above that supported bound, the Storage & Cleanup screen must provide a preparatory batched cleanup and WP-CLI path, report any sites that still contain any owned option, transient, lock, backup, notice, control, sentinel, or registry resource, and require that audit to reach zero before uninstall. WordPress uninstall cannot schedule resumable work after plugin files are removed, so per-site cleanup is best effort on an arbitrarily large, unprepared network. The two shared tables and the main-site schema resources are still removed exactly.

On a multi-network installation, a network cleanup may delete only rows and per-network registry entries for blogs belonging to that network. Schema provisioning and upgrades always use the one main-site authority/lock. Dropping base-prefix shared tables or deleting installation-wide schema resources requires global uninstall/super-administrator authority and proof that no rows remain for any network; a single-network action cannot drop tables still used elsewhere.

## Security and failure handling

- Pair every nonce with `manage_options`; network-wide actions require the corresponding network capability.
- Read only explicit request fields, call `wp_unslash()`, and validate each value against a fixed allowlist.
- Prepare all SQL values and use only fixed plugin-owned identifiers.
- Always include `blog_id` in multisite queries and verify it in table-backend contract tests.
- Bound page size, import batch size, payload size, nesting depth, error-message length, and switch execution time.
- Reject corrupt JSON payloads, codec mismatches, invalid hashes, impossible parent links, duplicate typed keys, or ordering gaps.
- Redact payload content and SQL from administrator-visible errors and logs.
- Invalidate relevant WordPress object-cache entries after direct database writes.
- Do not use stale data as automatic recovery.
- Do not accept snapshot import files from administrators; internal snapshot transfer is not a user-upload feature.
- A failed schema creation, upgrade, copy, or verification before an authority flip leaves the current authoritative backend unchanged and reports the next safe action.
- A failed destructive cleanup may already have removed owned resources. It must leave/restore the cleanup-in-progress block, report exact residual resources, and support idempotent continuation; it must never claim that authority or data remained unchanged.

## Delivery plan

Each phase is independently testable. Public table mode is not enabled until all table-backed hot paths and cleanup behavior are complete.

### Phase 0 — Characterize current storage

- Capture representative fixtures for old un-compacted data and current compact payloads.
- Inventory every operational state key, including adapter-specific scalar values, content scan state, and image-tag sync state.
- Add golden logical-snapshot tests covering missing versus `false`, typed keys, order, errors, and nested children.
- Benchmark option mode with 1,000, 5,000, and 20,000 root/nested records.
- Record peak memory, bytes read/written, request duration, and rows/option bytes changed.
- Finalize thresholds from evidence before table mode is public.

**Exit:** Existing state behavior and baseline costs are reproducible.

### Phase 1 — Extract codec and option backend

- Move compaction/hydration into `LegacyStateCodec` without changing its logical format.
- Add the storage facade, repository contracts, and `LegacyOptionBackend`.
- Route current state operations through the option backend only.
- Preserve exact option names, autoload behavior, and legacy read behavior.
- Run the complete existing PHPUnit, content-scan, AJAX JavaScript, and integration suites.

**Exit:** Option mode is a behavior-compatible refactor and no table exists.

### Phase 2 — Introduce bounded repository call paths

Refactor only persistence-facing access in this order:

1. Migrated-history direct/batched lookups, log paging, adapter factory reuse checks, scan-time occurrence resolution, and bounded debug diagnostics.
2. Content occurrence deduplication, indexed legacy source-identity resolution, findings/checkpoints, status reconciliation, exact selector retrieval, replacement access, content-list paging, and the filtered WordPress Core finding count.
3. Gallery/album paging (including internally batched no-pagination rendering), atomic selection patches, first-by-current-status lookup, requested-gallery retry access, bounded global gallery error-audit iteration, progress aggregates, and one-tree saves.
4. Incremental image-tag queue construction, page-local source resolution, and queue execution paging.

Run these paths first against the option backend to prove semantic compatibility. Full-array compatibility methods remain available for small option mode, but no production table path may depend on them.

**Exit:** Every high-cardinality operation has a bounded repository method and unchanged visible behavior. Golden transition/result fixtures remain exactly equivalent for queue order, selection effects, status transitions, revision increments, retry outcomes, content occurrence keys, and image-tag merging. Any difference is rejected as engine work.

### Phase 3 — Add table lifecycle and backend

- Add the fixed legacy tables, schema versioning, health checks, and table-backend contract suite.
- Implement typed-key hashing, flattening/reconstruction, indexed projections, staged generations, row-level mutations, and transactional batch/checkpoint writes.
- Add internal feature gating so table mode is available to automated tests and controlled development sites only.
- Implement cleanup services and uninstall handling before any public table-mode switch is offered.

**Exit:** Both backends pass the same logical and legacy-flow contracts; all owned table data can be removed safely.

### Phase 4 — Add switching, recommendation, and administration

- Add the external authority/control option, atomic maintenance gate, request-scoped writer markers, and repeated quiescence checks.
- Implement resumable option-to-table and table-to-option copies with full verification.
- Add metrics and filterable recommendation thresholds.
- Add Storage & Cleanup status and actions.
- Keep table mode opt-in and require confirmation.
- Exercise failures before, during, and immediately after authority flips.
- Enable the public switch on single-site only; keep multisite table selection gated until Phase 5 is complete.

**Exit:** Single-site administrators can safely enable, inspect, recover, reverse, and clean table storage.

### Phase 5 — Multisite and operational recovery

- Add strict blog isolation, the network-activation prerequisite, site-deletion cleanup, orphan-row audit, network registry, network purge, and network uninstall coverage.
- Add storage-only WP-CLI status, switch, verify, cleanup, and purge commands for oversized legacy options and large networks.
- Document operational recovery for interrupted switches, corrupt staged generations, and insufficient table privileges.

**Exit:** Multisite lifecycle behavior is complete and recoverable, the supported synchronous network-cleanup bound is benchmarked/documented, and multisite table selection may be enabled.

### Phase 6 — Persisted-state performance hardening and conservative rollout

- Benchmark nested and flat 1K/5K/20K fixtures plus larger stress fixtures where practical.
- Verify table list/migrate/log/content requests do not scale memory with unrelated stored records outside the requested page or affected root tree.
- Tune batch sizes and indexes from query plans.
- Test a largest-single-gallery and largest-single-album fixture and publish the remaining limitation.
- Roll out option mode unchanged and table mode as an explicit recommendation for large persisted state.

**Exit:** Recommendation thresholds and the persisted-state scaling claim are supported by repeatable measurements.

No phase in this plan introduces JSON configuration or a new execution engine.

## Test strategy

### Existing baseline and gaps

The repository audit on 2026-08-28 recorded these exact baseline commands:

- `composer test` — passed on PHP 8.4.6 with 40 tests and 336 assertions.
- `php tests/content-scan-regression.php` — passed.
- `node tests/ajax-js-regression.js` — passed.
- `wp eval-file tests/integration/core-gallery-migration.php` — mutates a live WordPress database, so it was not run during this documentation-only work and must run only against an isolated disposable site.

- PHPUnit currently discovers only `*Test.php`; the content-scan PHP regression and AJAX JavaScript regression are separate commands and must remain separate required checks unless the test runner is deliberately unified.
- The PHPUnit bootstrap mocks only the Options API. Its `update_option()` stub always returns success, so new tests must cover same-value `false`, the non-autoload requirement, object-cache invalidation, and raw option bytes in a real WordPress database.
- The AJAX JavaScript regression is focused on content scanning; it is not evidence for every gallery, album, log, or storage administration flow.
- The current live integration script protects only `foogallery-migrate-data`; table/control/backup resources need their own isolated setup and teardown.
- There is no existing coverage for Clear Migration History resource scope, activation/deactivation, schema upgrades, table permissions, switching/gates, cleanup, uninstall, multisite, or persistent object caches. These are new required suites.

PHPUnit 9.6 cannot execute on PHP 5.4. In addition to modern PHPUnit/WordPress integration tests, the built production package needs a PHP 5.4 syntax/runtime smoke job with the declared WordPress 5.0 runtime floor. New production code cannot use generators, `finally`, `Throwable`, scalar/return type declarations, null coalescing, or unpolyfilled modern randomness. Repository methods named `iteratePages()` describe repeated bounded page calls/callbacks, not a PHP generator.

### Codec and compatibility

- Compact/hydrate round trips for plugins, galleries, albums, images, errors, settings, and nested children.
- Old non-compact objects remain readable without implicit writes.
- List and map ordering, integer keys, numeric-string values, URLs, arbitrarily long unknown state keys and identifiers, `false`, `null`, and missing values.
- Missing source adapter behavior remains exactly as today.
- Stable logical digest across option and table representations.

### Shared repository contracts

Run identical behavioral tests against option and table implementations:

- Counts, filters, ordering, and page boundaries.
- Selection and cancel behavior, including rollback of the complete multi-row patch after an injected mid-batch failure.
- First queued/started lookup.
- One-object/tree save.
- History add-if-absent, lookup, status update, delete, revision, page, and summary.
- Exact and numeric source-identity history lookups, first-match ordering, batched resolution, hash/raw collision verification, and filtered content plugin counts.
- Atomic content findings plus checkpoint, including a reset failure that leaves the complete previous findings/checkpoint snapshot visible.
- Image-tag batch plus checkpoint.
- Clear and snapshot behavior.
- Missing/default/false semantics.
- Injected failure in a non-destructive repository mutation leaves the previous logical state authoritative.

### Existing behavior regressions

- Detection and adapter hydration.
- Gallery queue, cancel, resume, retry, completion, and the gallery-only global missing-file error audit.
- Album queue, cancel, resume, and completion; no album retry/error-audit behavior is invented.
- Configured images-per-turn behavior.
- Nested gallery flow inside albums.
- Content scan reset, pause, retry, atomic cursor, status reconciliation, and replacement.
- Image-tag sync.
- Migration log actions.
- Current identities and migrated-history compatibility.
- Existing AJAX JavaScript behavior.
- `page_size = 0` preserves current all-record output/order while table repository calls remain fixed-batch/keyset-bounded.

### Table integration

- `dbDelta()` install and idempotent schema upgrades.
- Partial/autocommitted DDL detection, table-mode blocking, and resumable additive repair.
- Required indexes and InnoDB verification.
- SQL pagination and aggregate queries.
- Query plans use the source-plugin and exact/numeric source-identity indexes; every hash lookup verifies the raw projected values.
- One-record mutation does not rewrite unrelated records.
- Corrupt payload, hash mismatch, missing parent, duplicate key, and codec/schema mismatch.
- Object-cache invalidation after direct writes.
- No complete-collection cache in table mode; page/aggregate cache keys include the state revision and invalidate after commit/head flip.

### Switching and recovery

- Option to table to option logical round trip.
- Interrupt before staging, mid-batch, during verification, immediately before flip, and immediately after flip.
- Source remains authoritative before the flip.
- Stale source is never read after the flip.
- Resume and discard staging.
- Quiescence rejection during active writes.
- Writer/gate acquisition races, token ownership, expiry takeover, re-entrant request handling, and release on errors.
- Stable queued, started, paused, and incomplete persisted work is not rejected merely because of its status; switching follows the existing engine's opaque quiescence verdict after active writers drain.
- Compare-and-swap rejection when a source digest or state-head generation/revision changes.
- Missing/corrupt control data with a table-authority sentinel, retained table heads/staging, or backup blocks instead of reviving option mode; empty authoritative table state is covered by the sentinel.
- A valid table control with a missing or mismatched authority sentinel blocks until explicit repair.
- Downgrade warning and verified table-to-option preparation path.
- Reverse-switch threshold warning.
- Oversized web-import refusal and CLI recovery.

### Cleanup, uninstall, and multisite

- Each cleanup action removes exactly its documented scope.
- Interrupted cleanup reports exact residual resources, blocks migration, and completes idempotently when rerun.
- Cleanup removes active and stale copies where promised.
- Site cleanup leaves other blog IDs untouched.
- Global table cleanup drops only the two exact plugin tables; one-network cleanup cannot drop installation-shared tables still in use.
- Concurrent schema provisioning from different networks serializes through the one main-site regular-option schema lock, including token-checked stale takeover.
- Multisite table provisioning is blocked unless the plugin is network-active for that network; orphan-row audit covers a deletion that occurred while hooks were unavailable.
- Site deletion removes its rows.
- Uninstall removes old and new options, transients, locks, registry, rows, and tables on single sites and within the documented supported/prepared multisite scope; above the synchronous bound, tests cover the full owned-resource zero-remnant audit, including both registered and pre-registry sites.
- Deactivation retains resumable state.
- Migrated galleries, albums, attachments, source data, and content remain untouched.

### Performance fixtures

At minimum, benchmark:

- 20,000 top-level records with no children.
- 2,000 galleries with 50 images each.
- One gallery with 10,000 images to expose the largest-tree boundary.
- One album with 5,000 gallery children.
- 50,000 migrated-history records.
- 50,000 content findings.
- No-pagination (`page_size = 0`) gallery, album, and content views to verify identical output/order and bounded repository hydration while separately measuring O(N) response size and buffering.

For table-backed paginated list, log, content-page, history lookup, and next-migration requests, increasing unrelated stored records by 10x should not increase peak PHP memory by more than 25%, excluding the requested page and affected root tree. For `page_size = 0`, the same 10x test applies to repository hydration measured before/downstream of response buffering, while total response bytes and time are expected to grow with N. A one-root update must not rewrite unrelated root trees or state buckets. Exact time budgets should be set from CI and supported-host benchmarks rather than hard-coded in this document.

## Acceptance criteria

The storage upgrade is complete only when all of the following are true:

- An upgrade with no administrator action continues in option mode without creating a table or changing state.
- Existing state, including pre-compaction data, remains readable in option mode; unsupported non-compact data blocks table conversion rather than being normalized silently.
- Current adapters, identities, queue/status semantics, output, content behavior, and AJAX flow are unchanged.
- For payloads admitted by the conversion preflight, option and table backends produce equivalent logical snapshots and runtime objects.
- Table-mode list, log, content, history, queue, and first-by-status operations are bounded and do not reconstruct unrelated stored state outside the requested page or affected root tree; no-pagination views internally iterate bounded batches while retaining their documented O(N) response/buffering limitation.
- Content findings/checkpoints and image-tag item/checkpoint writes remain atomic.
- Table mode updates only affected records during normal operation.
- The plugin recommends but never automatically enables table mode.
- The recommendation identifies the threshold that was crossed.
- Switching is permission-protected, quiescent, resumable, fully verified, and leaves exactly one authoritative backend.
- A stale backend is never used automatically.
- No tables are created until table mode is explicitly selected or network-provisioned.
- Deactivation retains state.
- On-demand cleanup can remove all plugin-owned state and, at the correct site/network scope, all plugin-owned tables.
- Uninstall removes both legacy and new internal storage completely on single sites and on prepared/supported multisite networks, and always drops the exact shared tables; every multisite installation above the documented synchronous bound must pass the preparatory audit showing zero owned per-site resources.
- Cleanup and uninstall never undo migrations or delete source/destination content or media.
- Multisite queries are blog-isolated and covered by tests.
- The existing suite passes unchanged in option mode; shared behavioral contracts pass against both backends; table-specific physical-storage tests pass separately.
- The known source-discovery and largest-active-tree memory limitations are documented and measured.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| A generic table wrapper still rebuilds whole arrays | Public table mode is gated on bounded repository call paths and memory tests |
| Flattening changes order, keys, or nested data | Typed keys, ordered rows, codec parity fixtures, and logical digests |
| Legacy non-compact values contain properties compact v1 drops | Detect and block unsupported conversions; never normalize silently |
| A switch is interrupted | Staged generations, resumable cursor, source authority until verified flip |
| Stale state is used after switching | External authority option and a hard no-fallback rule |
| An older plugin version reads state after table mode | Distinct backup name, downgrade warning, and required verified switch back to option mode |
| Existing option is too large for a web import | Raw-size check, safe refusal, dedicated request, and storage-only CLI recovery |
| Table creation/transactions are unavailable | Health check; remain in option mode with an actionable error |
| DDL partially applies | Additive stepwise upgrades, post-step health checks, request blocking, and resumable repair |
| Shared multisite query leaks another site's state | Mandatory `blog_id`, composite indexes, and cross-site contract tests |
| Uninstall removes migrated output | Cleanup owns only fixed internal resources; output-removal code is absent |
| Large-network uninstall times out | Registry, preparatory batched cleanup, bounded final enumeration, and CLI tooling |
| Recommendation thresholds are inaccurate | Filters, visible evidence, benchmarks, and conservative opt-in behavior |
| Retained copies double storage temporarily | Display exact stale-copy size and provide explicit removal |
| Table schema is forced into future v2 work | Legacy-qualified names and a hard rule that future engine repositories are separate |
| Source discovery or one enormous object still exhausts memory | State the boundary; measure it; defer source/object contract changes |

## Implementation boundary for future reviews

A proposed change belongs in this project only if it changes where or how current state is encoded, queried, copied, validated, recommended, or removed while preserving current migration behavior.

Reject it from this project if it introduces a new source vocabulary, identity, execution state, worker, destination operation, journal, rollback rule, or migration result. Those concerns remain separate even when a future engine can reuse the storage lifecycle and cleanup conventions established here.
