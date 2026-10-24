# Legacy storage upgrade for large sites

**Status:** Single-site first-release candidate passed scoped implementation and independent QA; committed locally in `57103ca`. Not published or approved for release.

**Qualification:** 2026-10-23; current results, package identity and retained evidence are in [the final acceptance closure](dev-plan.md#final-acceptance-closure). Runtime validation is complete within the documented limits; historical PR inventory below is not the remaining backlog.

**Design date:** 2026-08-28

**Implementation reconciliation:** 2026-10-22, PR head `206655cbfd49541b0f957d32028d1e53c17f39a1`

**Scope:** Single-site table storage and essential lifecycle for the existing migration engine

**Scope approval:** Brad adopted the five simplification cuts on 2026-10-22, then authorized development using the plan and instructed “dont worry about budget - just go”. Local development/validation/commits are authorized; publishing is not.

**Execution tracker:** [Prioritised completion plan](dev-plan.md)

**Related future work:** [GitHub issue #14](https://github.com/fooplugins/foogallery-migrate/issues/14), which is deliberately not part of this design

## Decision

Upgrade storage before undertaking any new migration-engine work.

The existing option-backed behavior remains the default. Reuse the storage contracts, option/table implementations, and switching infrastructure already introduced by PR #15. Exercise a real single-site migration journey immediately in a disposable environment, then complete bounded access and essential cleanup before release. Multisite table support is deferred backlog, not an automatic next milestone or a first-release gate. Do not restart the extraction or introduce a replacement engine.

This project does **not** create or redesign a migration engine. It preserves the existing engine's sources, object model, identities, queue states, batching, AJAX flow, destination writes, content replacements, and retry behavior. Bounded persistence access may replace a whole-collection read with a page, count, aggregate, or single-record query so that table mode does not reconstruct every stored object. The existing engine continues to own every selection, transition, ordering, retry, title, and completion decision; storage only answers queries and applies engine-supplied patches.

Table mode is an administrator-selected storage choice. It is never enabled automatically.

### Storage settings and Troubleshooting (24 October 2026 amendment)

Use **FooGallery → Migrate → Settings → Migration Settings → Migration Storage**, at
`edit.php?post_type=foogallery&page=foogallery-migrate&tab=settings#migration-storage`.
The former `options-general.php?page=foogallery-migrate-storage` bookmark redirects here;
there is no separate WordPress Settings submenu. Recommendations link to this section.

Select **Database tables** and choose **Change storage** to perform the existing guarded
steps sequentially: read-only capacity preflight → provision/verify owned tables if
needed → start conversion → continue successful bounded copy/verification phases
until stable table authority. Keep the settings page open. Each request retains the
existing exact fields, purpose-specific nonce, permission checks and receiver safety
checks. Provisioning alone does not select tables, and no prerequisite CLI command
or separate provisioning button is required. CLI schema-only provisioning remains
available for operators; the conversion coordinator and migration engine are unchanged.

The normal section shows two labelled choices, a readable migration-data size
(or record count in table mode), and one Change storage button. Selecting a mode
alone performs no request; the button is disabled for the current mode. Routine
switching has no acknowledgement checkbox, backup acceptance or confirmation
dialog. A spinner reports progress and an inline success message survives reload.
Technical details and Reset & cleanup now live in the separate **Troubleshooting**
tab at `edit.php?post_type=foogallery&page=foogallery-migrate&tab=troubleshooting`.
Use the Troubleshooting navigation tab; Settings does not repeat a separate link
or render recovery/destructive forms inline.
The tab starts with "If you are having trouble with a migration, try the following:"
and three plain-language help bullets about interrupted migrations, memory errors
and trying table storage for larger migrations. **Reset & cleanup** follows,
always expanded, with this description:
"Galleries and media are kept. Migrating again after a reset or removal may create
duplicates." Next is **Technical details**, a labelled table with runtime
versions/memory and source-count estimates, followed by collapsed **Information for
support**. Its copyable summary contains only allow-listed metadata (no migration
contents, credentials or server paths). There is no routine healthy-status banner
or Open migration settings link. Raw debug-history output is not loaded by this tab.

Cleanup actions use labelled native settings rows; reset/removal/cancel each retain
one confirmation describing kept data and duplicate-migration implications.
Cancelling sends no request. Conditional warnings and **Continue storage change**
remain visible for unfinished cleanup, errors or interrupted switches. Underlying
permissions, purpose-specific nonce/intent fields, exact request
shapes and receiver checks are unchanged. The tab is rendered without constructing
the engine, including during unhealthy authority or incomplete cleanup; conditional
engine-derived Log/Debug navigation is not probed on this diagnostic route.

Failure details from a storage request can be inspected in Troubleshooting in the
same browser tab. These use site-scoped browser session storage, not a database
option, server log, telemetry upload or migration payload dump. A successful storage
operation clears the last browser-local error. All error text is escaped/text-only.

Normal successful continuation is not error retry or ambiguous-owner takeover.
An error, malformed reply, unexpected receipt, timeout or connection loss stops the
sequence. Reload to inspect durable status before explicitly continuing; a browser
session sends at most 256 continuation turns. If preparation succeeded but conversion
did not, options remain authoritative unless the recorded conversion phase says
otherwise; prepared tables may remain for an explicit retry or owned-data cleanup.
Switching back to options uses current table data and the same safety/size limits.
The Troubleshooting navigation tab remains available even when normal preferences
and the migration engine cannot safely load. It keeps recovery/cleanup controls
reachable in those states. Cleanup still preserves
source/destination galleries, attachments and content.

Redis is not a third storage mode or a dependency. A site may use Redis through WordPress's optional persistent object cache; the option or MySQL tables remain authoritative. Caching a large serialized option does not avoid its PHP decode-memory cost. Validation includes persistent caching so authority switches and cleanup cannot leave stale owned cache entries.

## Approved first-release boundary

On 2026-10-22 Brad approved all five cuts in the [October 20 PR simplification proposal](https://github.com/fooplugins/foogallery-migrate/pull/15#issuecomment-6287530658). That approval supersedes the broader original design and the S0–S9 plan committed in `cb8aa51` where they conflict with this boundary. The initial approval covered documentation; Brad's subsequent development request authorizes implementation within this boundary, **not public release**. Historical checkpoint “not waived” statements remain historical, not authority to undo these approved amendments.

1. **Release-owned capacity policy, not customer calibration.** Ship a small tested policy with conservative limits/headroom and clear refusal conditions. Detailed allocator, binary/host identity and dataset qualification belong in engineering diagnostics, not customer prerequisites. Retain useful PR foundations; simplify obsolete machinery with reviewed replacement coverage.
2. **Single-site table mode only.** Multisite continues on options, with table provisioning/conversion explicitly unavailable. Defer network registry/provisioning, shared-network lifecycle expansion, large-network uninstall preparation and the topology matrix. Existing shared table names/`blog_id` fields need not be rewritten merely to narrow the release.
3. **Assisted recovery for uncertain hard crashes.** Preserve resume after ordinary handled failures. If an old worker may still resume, block safely and require a documented procedure establishing quiescence before recovery. No automatic ambiguous-owner takeover, new fencing platform, or lock removal based only on age, PID absence or browser closure.
4. **Minimal maintenance interface.** Inspect → explicitly enable → copy and verify → switch; reverse from current table data only when safe. Keep a basic size recommendation, actionable status/errors, existing state clear, on-demand remove-all and uninstall. Defer elaborate advice tiers/re-notification and separate convenience controls. Cleanup must still remove all owned stale resources.
5. **Initial full decode and separate test domains.** Accept one full legacy-option decode per applicable request within measured limits; bound later copy/verification. Keep lossless codec behavior and refuse unsupported inputs without normalization. Test native WordPress text conversion separately from arbitrary-byte codec fidelity/refusal; do not demand a byte-preserving invalid-UTF-8 round trip through native text storage or build a universal streaming decoder.

Authorization, default option behavior, identities/edits, one authoritative backend, safe interruption, owned-data cleanup and realistic large-migration tests remain mandatory. Do not raise the PHP/WordPress minimums or change engine/source behavior to achieve a pass.

### Two delivery milestones

- **M1 — Testable integrated candidate:** In a disposable single-site environment, use production entry points to enable tables, run a representative large migration, edit table-backed state, and clean up/uninstall without touching output. Separately prove successful reversal from current edited data when it fits safely, and refusal without authority/data changes when oversized. Exercise the supported journeys on the runtime floor and a matching ZIP without dev dependencies. Begin trying them in R0; repair the first failing step in R1. M1 is controlled-test evidence, not permission for general use.
- **M2 — First-release readiness:** Finish all supported bounded paths, parity, fault/recovery/cleanup checks and basic recommendations; pass the maintained release suite and source/package validation. Deferred features do not block M2. Publishing still requires explicit approval.

[dev-plan.md](dev-plan.md) is the sole execution tracker, including test classification and the workflow rules for avoiding repeated blockers. Later sections retain detailed storage contracts; any network or advanced-advisor material explicitly marked deferred is reference only. New requirements outside this approved boundary require a new scope decision.

## PR #15 implementation status

This section is a source-review snapshot of [PR #15](https://github.com/fooplugins/foogallery-migrate/pull/15) at the revision above, not a claim that its tests or release acceptance pass. The remaining design sections define contracts within the approved release boundary (or labelled deferred reference); they are not an inventory of finished features. [dev-plan.md](dev-plan.md) is the single forward task/status tracker. Older checkpoint documents remain historical evidence, not competing plans.

### Already implemented — retain and complete

| Area | Code present at the reviewed revision | Remaining acceptance |
|---|---|---|
| Compatibility and option storage | `LegacyStateCodec`, `LegacyRecordCodec`, collection envelopes, `LegacyOptionBackend`, and five domain repositories | Re-establish option parity against the pre-PR engine, including child loading, typed keys, status transitions, retry, and resume. Extraction is not work to start again. |
| Table schema and access | Two shared legacy tables, typed-key/raw-key checks, generations, revisions, indexed projections, table reader/repository/backend and snapshots | Finish bounded production callers; schema/repository tests alone do not prove request memory is bounded. |
| Scalar state | `ScalarStateRepository` and table scalar mutation, including creation of missing state and deletion | Preserve unknown scalars and atomic coupling to collection metadata; earlier checkpoints describing scalar creation/deletion as absent are superseded. |
| Migrated history | Direct/batch/presence/source-identity access, revision-aware writes, cursor pages, summaries, retry/export/clear primitives | Wire all log, migration, content and tag consumers to supported table operations; whole-history methods remain option-only. |
| Content scans | Window/get-many access, metadata/checkpoints, batch append/patch and atomic reset primitives; partial caller integration | Audit the entire scan/list/count/selection/replacement path and eliminate residual whole-collection work. |
| Authority and maintenance | Control/authority records, maintenance gate, writer markers, pinned transactions, quiescence checks and authority-selected production backend | Verify actual request lifecycle and recovery. Do not weaken conservative ownership checks to make tests pass. |
| Provisioning and conversion | Provisioning, preflight, staged copying, verification, recovery and inactive-copy retirement services; admin and CLI entry points | Qualify supported public paths with real finite capacity evidence. Existing primitives are not a completed user journey. |
| Lifecycle | Activation/deactivation retain existing state without automatic conversion; an uninstall entry point exists | Uninstall still targets an older storage layout and is not sufficient for the current schema; see blockers below. |

Source entry points: [table repository](../includes/class-legacy-table-repository.php), [history repository](../includes/class-migrated-history-repository.php), [content repository](../includes/class-content-scan-repository.php), [schema](../includes/class-legacy-storage-schema.php), [authority](../includes/class-storage-authority.php), and [storage operations](../includes/class-storage-operations.php).

### Implementation priorities at the reviewed PR revision

This list records the original blockers at `206655c`, not the current remaining backlog. R0–R4 implementation and qualification have since passed within the approved scope; final implementation is committed locally in `57103ca`. Current evidence, limits and commit identities live in [the execution tracker](dev-plan.md), not this historical inventory. Deferred network/engine/advisor work is not an unfinished first-release requirement.

1. **Run the journey and classify the baseline (R0).** Prepare one owned disposable runner and attempt the public customer path immediately. Record its first failure and classify suite failures as product/safety/performance, environment, or superseded implementation diagnostics. Historical focused passes do not certify this head.
2. **Make one integrated candidate testable (R1 / M1).** The shipped [capacity policy](../includes/storage-capacity-policy.json) has empty classes and null digests, so public conversion refuses. Establish a small evidenced release policy without customer calibration, then fix the first failing migration/edit/reversal/cleanup step. [Uninstall](../uninstall.php) still requires excluded `vendor/autoload.php`; [MigratedStore](../includes/class-migrated-store.php) targets the older table layout. Complete essential single-site cleanup/package loading as part of the journey, not after UI polish. Use real SQL/runtime-floor/source+ZIP evidence, not an injected policy.
3. **Finish remaining supported bounded paths and safety (R2).** History `get_all()`, `log_page()` and `get_by_parent()` remain option-only; valid-URL table lookup is unsupported. Gallery/album reads and `replace_collection()` still reconstruct whole collections; the image-tag builder loads whole history. Complete page/query/affected-tree/queue access and remaining content consumers while preserving identities, ordering, revisions, atomic checkpoints and legacy behavior. Admission caps do not prove page-size scaling.
4. **Finish the minimal operator workflow (R3).** Add the basic size suggestion, explicit confirmation, status/errors and essential cleanup controls. Reuse existing admin/CLI services. Do not make advanced notices, separate reset/stale-copy buttons or a command matrix prerequisites.
5. **Accept the first release (R4 / M2).** Validate all supported flows, safety, memory scaling, maintained tests and the actual ZIP on applicable runtimes. Keep multisite options working and table operations rejected. No general table-mode rollout before M2.

Multisite table enablement, network lifecycle and advanced advisor/convenience features are **deferred**, not R5 or another required release task. The [ordered plan](dev-plan.md#ordered-work) supersedes the previous full-roadmap sequence.

### Evidence and scope qualifications

These bullets describe the reviewed PR and original checkpoints. Current candidate acceptance is recorded separately above; retain their original limitations rather than treating an old source review as proof of the final source.

- [The October 20 review checkpoint](pr15-review-checkpoint-2026-10-20.md) records a failing/timed-out full PHPUnit run and explicitly incomplete release acceptance. Its focused passes and package checks apply to that checkpoint, not automatically to this reviewed head.
- [The single-site release scope](storage-single-site-release-scope.md) records empty-policy admission and outstanding positive native 20K / actual 10K AIGPL journeys. Retain realistic positive workload coverage; classify historical exact-byte/host-qualification evidence under the approved native-text vs codec/refusal split. A fixture double or injected policy is not a production release pass.
- Table mode is wired into production backend selection; it is not merely an unused prototype. Nevertheless, supported public conversion and complete table-backed flows are not yet qualified.
- PR #15 contains structural serialization scanning and native decode/round-trip verification. This is additional storage-admission machinery, not proof of streaming option hydration. The initial design's exclusion of a new streaming PHP serialization decoder remains: measure and simplify existing overhead where necessary, rather than expanding parser architecture as a prerequisite.
- The capacity-policy JSON is storage admission configuration, **not** the migration-config JSON or v2 engine proposed in issue #14. That engine work remains excluded.
- Persistence-facing edits to the existing engine are necessary to call bounded repositories. They must not change execution decisions. Existing changes to compaction/child handling and retry paths require golden parity evidence; class names alone do not demonstrate unchanged behavior.

## Why the upgrade is needed — original baseline

The following describes the 2026-08-28/pre-PR option implementation, not the current PR's completed capabilities. The reference source is [the pre-PR develop revision](https://github.com/fooplugins/foogallery-migrate/tree/3bb151465818fbb164edb35866118794563ce12a). It stored almost all operational state in one non-autoloaded `foogallery-migrate-data` option, while user preferences lived in `foogallery-migrate-settings`.

The option is compacted before persistence, but its scaling characteristics remain problematic:

- Every operational read loaded the complete option and every mutation rewrote it.
- Gallery and album collections recursively embedded loaded child objects in the same payload.
- Discovery, queue selection, cancellation, and every migration turn operated on complete collections.
- Adding, checking, updating, or deleting one migrated-history record loaded and often rewrote the entire migrated map.
- Content scanning was time- and keyset-bounded, but accumulated findings and its checkpoint were rewritten together in the same option.
- The migration log loaded every migrated object, filtered in PHP, and only then sliced the requested page.
- There was no table schema lifecycle or uninstall cleanup.

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

R0 must reconcile this original inventory with all PR call sites, metadata and compatibility fixtures before further implementation. The resource manifest must include PR control/authority/schema/backup/writer resources and any older storage artifacts. Unknown scalar keys remain losslessly accessible through `ScalarStateRepository`.

## Outcomes

The completed storage upgrade must:

1. Keep current sites on option mode after upgrading, with no data conversion or table creation unless an authorized administrator explicitly provisions/selects table mode.
2. Preserve the current compact-v1 logical contract and runtime behavior, and never silently normalize a readable legacy non-compact payload.
3. Make table-backed requests scale primarily with the requested page or affected root tree rather than unrelated stored records.
4. Avoid rewriting unrelated root trees or state buckets during normal table-backed mutations.
5. Give a basic recommendation when observed option size crosses a documented, filterable threshold, without auto-switching.
6. Switch backends through a resumable, verified copy with exactly one authoritative backend.
7. Make it easy to inspect status, clear migration state, and remove all plugin-owned data including stale copies. Separate stale-copy/reset convenience controls are not required.
8. Remove all plugin-owned options, transients, locks, rows, and tables during single-site uninstall, and preserve existing multisite option-mode behavior. New network table/uninstall preparation support is deferred.
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

R0/R1 identify which historical non-compact shapes the first release can safely convert; unsupported shapes must refuse without data loss. “Parity” does not mean that a table import is allowed to normalize old objects into the narrower compact-v1 shape without an explicit, separately approved compatibility policy.

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
| Last successful gallery discovery counts | Small non-autoloaded `foogallery_migrate_discovery_summary` option | Same option |
| Advanced recommendation dismissal/observed metrics (deferred) | Optional dedicated non-autoloaded per-site option if later implemented | Same option |
| Installation-wide shared-table schema version/upgrade lock | Regular options in the main site's options table | Same resource |
| Sites using new storage (deferred multisite feature) | Future bounded/chunked network registry | Same resource plus table-state rows |
| Detected-plugin records | `foogallery-migrate-data` | Legacy state table |
| Small engine/adapter values | `foogallery-migrate-data` | Legacy state table |
| Gallery discovery/queue | `foogallery-migrate-data` | Flattened legacy records |
| Album discovery/queue | `foogallery-migrate-data` | Flattened legacy records |
| Migrated history/revision | `foogallery-migrate-data` | Flattened legacy records plus state head |
| Content findings/checkpoints | `foogallery-migrate-data` | Legacy records plus state head |
| Image-tag queue/checkpoint | `foogallery-migrate-data` | Legacy records plus state head |
| Short-lived per-user result transient | Existing transient | Existing transient |

The control option is deliberately outside both backends so backend selection never depends on first selecting a backend. It contains no migratable records. Every newly introduced option, option prefix, table, transient prefix, and registry chunk is part of the cleanup manifest; cleanup must be driven by that manifest rather than separate hand-maintained lists.

Retain existing PR resource names; R0 reconciles this original inventory with actual code and superseded artifacts. This list does not require creating deferred resources:

- Existing per-site options: `foogallery-migrate-data`, `foogallery-migrate-settings`.
- New per-site options/prefixes: `foogallery_migrate_storage_control`, `foogallery_migrate_storage_authority`, `foogallery_migrate_storage_maintenance`, `foogallery_migrate_storage_writer_`, `foogallery_migrate_storage_notice`, and `foogallery-migrate-data-storage-backup`.
- New installation-wide resources: `foogallery_migrate_storage_schema_version` and `foogallery_migrate_storage_schema_lock`, stored as regular options in the main site's options table so `option_name` uniqueness can arbitrate lock acquisition.
- Deferred per-network resource: a bounded/chunked `foogallery_migrate_storage_sites_` site registry; do not introduce it for the first release.
- Existing transient prefix: `foogallery_migrate_content_result_`, including timeout rows.
- New tables: `{base_prefix}foogallery_migrate_legacy_state` and `{base_prefix}foogallery_migrate_legacy_records`.

The notice resource is likewise not required for the basic advisor. Any owned artifact that actually exists must be covered by cleanup; do not create a deferred resource merely to satisfy this inventory. If implementation changes a name, this list, the lifecycle resource manifest, and cleanup tests must change together.

## Option backend

Option mode remains the initial and fallback-compatible mode.

- It uses the existing `foogallery-migrate-data` option.
- Writes continue using `update_option(..., false)` so the option is non-autoloaded.
- One logical multi-key update results in one option update.
- `update_option()` returning `false` is still treated as success when the requested value is already stored.
- It reads old un-compacted payloads without a write-time conversion.
- It does not create per-record or per-run options.
- It remains appropriate for small sites. PR #15 tests both option and table storage; R0/R4 must verify complete option semantics and lifecycle coverage rather than assuming extraction proves parity.

The original option-only extraction is already present in PR #15. Verify its logical/behavioral parity; do not restart an option-only release stage. The first release now includes explicitly selected single-site table mode once M2 passes.

## Table backend

### Physical scope

Retain the two PR plugin-owned tables based on `$wpdb->base_prefix`, including their `blog_id` scoping. They are provisioned/used only on single-site for this release:

- `{base_prefix}foogallery_migrate_legacy_state`
- `{base_prefix}foogallery_migrate_legacy_records`

The `legacy` qualifier is intentional. These tables persist the current engine's state; they must not be reused as a future engine ledger.

On a non-multisite installation, `$wpdb->base_prefix` and `$wpdb->prefix` are equivalent. The schema can support future shared-network use without a rename; that capability does not enable multisite table mode now.

Use the existing regular-option schema version and atomic upgrade lock on the single site. Ownership remains token-checked; uncertain owners require assisted recovery. Network-level provisioning authority, main-site coordination and cross-network upgrade behavior are part of the [deferred multisite design](#multisite), not additional first-release infrastructure.

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

During development, table-backend compatibility `getAll()` methods should be instrumented to fail tests when invoked from a registered production path. The R0/R2 inventory must cover object factories, retry/error repair, content scanning/status/rendering/replacement, image-tag queue building, the migration log, and debug diagnostics—not only the main migration loop.

The existing `page_size = 0` setting explicitly means “disable pagination” and remains valid. In table mode, it is one logical all-record result, but the repository and renderer must keyset-iterate fixed internal batches, batch related history lookups, and emit rows in exact legacy order without first building one complete PHP collection. Selection controls, counts, row keys, and visible output remain unchanged. The response body and rendering time are still O(N), and WordPress, PHP, web-server, proxy, or browser output buffering may retain that body in memory; table storage cannot guarantee bounded end-to-end memory for this deliberate no-pagination view. The Storage screen should explain that limitation and recommend a positive page size on large sites without changing the saved value automatically.

### Memory boundary and remaining limitations

With bounded access, table-mode state memory should grow with the requested page or affected root tree rather than unrelated stored records. An active root tree may itself contain a large portion of the site's stored state.

This project does not change source-adapter discovery contracts. An adapter that returns every source gallery at once can still have a high one-time discovery peak before storage receives the array. Similarly, current object contracts may require all children of the one active gallery or album to be loaded. Table mode therefore addresses stored-state hydration and rewrite pressure, but peak memory can still be determined by the largest single discovered result or active object tree.

Performance claims exclude initial/forced source refresh, adapter fallback scans that return complete arrays, WordPress-core entity preparation that still uses complete legacy results, the active gallery/album tree unless a specific full-read call site is replaced by one of the bounded storage paths above, and end-to-end response buffering for a deliberate `page_size = 0` view. Even in that view, table-repository hydration and history resolution must remain internally batched.

Those limitations must be visible in release notes and benchmarks. Fixing them would require source/engine contract changes and belongs to separate future work.

Image-tag fallback resolves at most 100 requested URLs against revision-pinned history pages. It retains only the current requested set and results, but an unresolved URL can require a full history scan for each fallback page. This is a bounded-memory path, not an indexed-URL or constant-time claim; source adapters may still return their full fallback array.

Table storage does not make every bulk action unlimited. The shared repository operation budget remains 10,000 admitted rows, 16 MiB of admitted bytes and 10 seconds by default, including applicable old/new affected trees. Whole-collection queue changes and the global gallery check may refuse when that operation cannot fit; the collection-wide root selector also retains its 10,000-root cap. Fixed-page reads and exact-gallery actions must not inherit that cap merely because unrelated roots exist. A successful 20,000-record conversion or fixed-page benchmark is not a promise that a 20,000-root bulk queue/check action fits one request.

## Storage recommendation policy

The first release includes a basic saved-size or source-count suggestion, not an advanced notification system.

- Observe serialized bytes using SQL `OCTET_LENGTH()` on the fixed option name without hydrating its value. Reuse cheap existing metrics where available; do not invoke adapters or add source discovery merely to advise.
- Use one byte threshold, default **1 MiB (1,048,576 bytes)**, through `foogallery_migrate_storage_advice_threshold_bytes`. Only a positive PHP integer is accepted; invalid types, zero, negatives and overflowing numeric values fall back to the default. The notice begins at the threshold. Explain the observed size, expected benefit and largest-tree/no-pagination limitations.
- Also recommend tables at **100 source gallery records or 1,000 source image entries**. These conservative heuristics are inclusive and independently filterable through `foogallery_migrate_storage_advice_threshold_galleries` and `foogallery_migrate_storage_advice_threshold_images`; each accepts only a positive PHP integer, otherwise its default is retained. They are not measured memory forecasts or conversion-admission limits.
- After successful gallery discovery or Refresh Galleries, save one small, non-autoloaded `foogallery_migrate_discovery_summary` option with the discovered gallery total, summed known image-entry total, image-count completeness, and refresh time. The count uses only objects already discovered and their `get_children_count()` values; it never loads children for advice. These are source-entry totals from the dated, most recently saved discovery snapshot, including already migrated entries, not unique media or remaining migration work. Saving this advisory option is best effort: if deletion and replacement both fail, an older dated snapshot can remain, so it is not authoritative current source state. Existing collections gain the summary on the next successful Refresh Galleries; an upgrade never forces discovery or loads persisted collections to backfill it.
- Count source records directly with scalar SQL aggregates, never by constructing the engine, invoking adapter detection/discovery, selecting source rows, decoding image lists or scanning post content. Published Envira, Modula and Robo galleries are counted; AIGPL requires its existing nonempty image metadata and uses `EXISTS` to avoid duplicate-meta overcounting. NextGEN counts galleries and non-excluded images joined to existing galleries. 10Web counts published galleries and their image rows. WPPA uses its active table constants (or current-site defaults), public albums and supported-looking photo statuses/extensions, without validating files. These are advisory source-record estimates, not the exact selected/unmigrated queue, attachment uniqueness or a guarantee that every row can migrate.
- Before a valid discovery summary exists, scalar SQL provides partial source-record estimates. Serialized image lists (including Envira, Modula, Robo and AIGPL) and unscanned WordPress core gallery occurrences remain **unknown**, not zero. Label positive SQL image counts as partial and show an incomplete/unknown hint when a gallery threshold triggers with no known images. Their gallery counts can still trigger advice. Do not hydrate those formats solely to obtain image counts. Source query failures are ignored for advice; successful known counts can still justify a recommendation. With all current providers present there are at most 14 source-count/existence queries and only scalar results retained; SQL may still scan source indexes/tables, so this is a PHP-memory bound, not a constant-time SQL promise.
- Recommend only on a supported, healthy, stable, single-site option backend, without pending cleanup. If the byte threshold already triggers, skip source counts. Otherwise prefer a valid last-discovery summary; use scalar SQL only while that summary is absent or invalid. Clear/redetection, progress reset, remove-all and standalone uninstall remove the summary. The Settings screen suppresses the duplicate global notice.
- Link to **Migrate → Settings → Migration Storage**, without a separate Migration Settings heading. The native label/control row shares the settings layout; technical details and cleanup live in **Troubleshooting**. Cleanup is expanded below the help bullets; Information for support is collapsed. Switching remains an explicit **Change storage** action, without routine confirmation dialogs or acknowledgement checkboxes. If switching is unsupported, explain the limit and safe next action rather than offering a broken enable button.
- Do not auto-create tables, select a backend, or treat a recommendation threshold as permission to bypass admission. Reverse conversion is governed by measured safety limits, not this advisory threshold.

The original 512 KiB/1,000-saved-record warning tier, 2,000-record and hydrated-memory tiers, dismissal growth tracking and repeated notifications remain deferred. The separate source-image count heuristic above is now included; it does not revive the multi-tier notification system.

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

The per-site maintenance gate is a separate exact option acquired atomically with `add_option()`, with a random ownership token and expiry recorded for diagnostics. Expiry does not authorize takeover; uncertain ownership requires assisted recovery. Each active storage writer uses a separate short-lived marker option. A writer checks the maintenance gate, acquires its marker, and checks the gate again; maintenance acquires the gate first and then waits for pre-existing writer markers to finish. This closes the check-then-start race without adding engine worker leases.

Writer acquisition is request-scoped and re-entrant so nested legacy calls share one marker rather than creating an unbounded marker chain. Only the owner token may renew or release a gate/marker; expiry is recovery evidence, not permission to delete either an expired or unexpired writer without proving it can no longer run.

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

Admit reversal only when the current table snapshot can safely fit the option representation under the measured release policy. Advisory notice thresholds are not safety limits. A CLI request may use explicitly configured additional memory, but neither filters nor CLI flags may bypass integrity, authority or headroom checks.

The retained pre-switch backup is stale and must never be used for reverse switching; reverse switching always exports the current authoritative table state.

### Failure and recovery rules

- Until the final control-option flip, the source backend remains authoritative.
- A failure records a bounded error and resume cursor without changing authority.
- After an ordinary handled failure with known released ownership, retrying resumes or discards the destination staging generation. Uncertain ownership follows the assisted procedure below.
- Source and destination state are never merged.
- Immediately before a flip, recheck the source raw digest/revision captured behind the maintenance gate. A mismatch aborts the switch.
- After a successful flip, a destination health failure blocks migration and offers an explicit recovery path; it never silently reads the stale source.
- Cleanup removes incomplete generations only after proving they are not authoritative; gate/writer removal also requires proof that the old worker cannot resume. Age alone is never sufficient.

**Assisted hard-crash recovery:** preserve state/markers; an authorized operator prevents old web/CLI workers from continuing or restarting, establishes quiescence, inspects the authority/control/sentinel and source/staging health, then explicitly resumes or discards verified work through the maintenance service. Record what was verified and the next safe action. If quiescence or authority cannot be established, remain blocked with actionable instructions. No automatic takeover or new worker-fencing platform is required. Test this procedure independently from ordinary handled-failure resume.

Operator checklist (not an automatic unlock command):

1. **Identify and freeze the exact site.** Record its canonical path, database, table prefix, plugin revision and current operation. Stop new requests at the serving layer and disable relevant cron, queue, CLI and supervisor restart entry points. A WordPress maintenance page alone does not drain existing workers. Keep the entry points frozen throughout intervention and verification.
2. **Drain every possible writer and output operation.** Inventory the relevant web/CLI workers, descendants and database sessions/transactions. Establish that each has finished or been terminated and cannot resume or restart; include attachment/file writes and other external output, not only SQL. An expired marker, missing PID, closed browser or absence of one database session is insufficient. If the environment cannot establish this, stop and retain the block.
3. **Preserve evidence and a recoverable backup.** Capture exact raw control, authority sentinel, maintenance/writer/cleanup marker rows, current option, retained backup and table heads/generations, together with protected output/media checks and the worker/session evidence. Back up the affected database and files under the freeze. Do not paste credentials into reports. Identify the recorded phase and authoritative backend without rewriting either.
4. **Verify health before intervention.** Check that the control/sentinel agree, the authoritative source and staged destination match their recorded identities, and no unexpected owner, cleanup operation or out-of-band change exists. A fresh public recovery attempt should remain blocked while the uncertain marker exists. Do not use cleanup/uninstall as a substitute for recovery or merge source and destination.
5. **Remove only a proven-dead owner's exact marker.** A site-qualified operator may compare-and-delete the captured marker row using its exact name and byte-identical raw value on a pinned connection. Require exactly one affected row, absent readback and invalidation of that option's caches. A mismatch, reconnect, unexpected row or uncertain drain aborts the procedure; never use a prefix-wide delete or unconditional `delete_option()` to force progress. This is an exceptional operator action under the verified freeze, not a public stale-owner release API. Do not delete control, authority, backup, staging or cleanup evidence.
6. **Resume through the existing public service in fresh requests.** For a healthy nonstable recorded switch, use an authorized administrator and the recorded target with `wp --path=EXACT_SITE --user=ADMIN foogallery-migrate storage recover --target=RECORDED_TARGET --confirm=recover-storage`. Inspect each result before another request. The service must follow the recorded phase; it may require several bounded requests. A stable phase needs health verification, not a forced recovery. Explicit discard is only available for the supported incomplete forward-copy phase and requires the normal confirmation; do not invent a reverse/discard shortcut.
7. **Verify and reopen.** Confirm stable authority, expected current data, absence of stale ownership and unchanged protected migration output/media. Retain the backup and intervention receipt. Re-enable entry points only after those checks pass. Any failed or ambiguous step leaves the site frozen/blocked with its evidence intact.

Keep backups and raw intervention evidence outside the web-accessible document root, or otherwise inaccessible, before reopening a real served site. The CLI-only disposable fixture may retain private evidence in its nonserved root; that is not a production placement recommendation.

The owned integration proof must cover a killed worker, fresh refusal, externally established quiescence, exact-marker intervention and successful public recovery separately. Passing handled-exception recovery does not prove this hard-crash procedure; a fixture with synthetic protected output is not an actual migration acceptance receipt.

### Existing oversized options

An option-to-table switch must deserialize the old WordPress option at least once before records can be separated. Measure raw bytes first and perform the import in a dedicated request with the WordPress admin memory limit raised. If the estimated decode peak does not fit safely, the web UI must refuse to start rather than risk a fatal error.

For a time-bounded web import, the maintenance gate keeps the source option immutable. Each resumed request may re-read that immutable snapshot, re-decode it, skip already verified destination records, and continue from the stored cursor. The cursor bounds database work and request time; it does not reduce the source option's decode-memory peak. Do not imply that a resumable web copy can deserialize once across separate PHP requests unless a separately designed internal staging resource actually owns the snapshot.

The current candidate's release-owned policy admits at most 3 MiB of raw option data, forecasts 16× raw bytes for decoding, reserves 128 MiB, and requires 32 MiB phase headroom. These are admission inputs, not a guaranteed memory requirement or universal performance promise; structural limits and current process memory also apply. A suggestion at 1 MiB does not override them. Low-memory or oversized requests must refuse without changing authority. If the existing option exceeds the supported shape/size, retain it and its backup; do not delete history or edit the policy merely to force a pass.

Use the existing storage-only WP-CLI commands for these cases: `status`, `preflight`, `provision`, `switch`, `recover`, `replace`, `discard`, `clear`, and `remove_all`. Verification is part of the recorded switch/recovery, not a separate new command. Requests can use explicitly configured additional CLI memory, within the same admission rules. Do not implement a custom streaming PHP-serialization parser in the initial release. Never discard legacy history automatically to make a switch fit.

## Storage and cleanup administration

Use the existing storage admin/CLI entry points for one minimal Storage & Cleanup workflow. Inspect → explicitly enable → copy/verify → switch, with safe current-data reversal, handled-failure resume or assisted recovery, state clear and remove-all. A new interface framework is not in scope.

It displays:

- Active backend and whether it is recommended for the observed size.
- Option bytes and the site table-record count, including retained copies. Additional payload/codec diagnostics are optional, not a release gate.
- Schema readiness and health.
- In-progress or failed switch status with resume/discard actions.
- Whether a previous table copy is retained after reversal; do not add a separate stale-copy inspector.
- A clear explanation that storage cleanup does not undo migrations.

Every mutating admin action requires `manage_options`, a dedicated nonce, exact target resolution, and scope-appropriate confirmation; CLI requires equivalent authorization and explicit confirmation through the shared service.

The matrix records resource semantics, not a requirement to ship a button for every row. First release requires the existing clear-state action and single-site remove-all/uninstall. Cleanup must remove stale resources; separate stale-copy and reset controls are deferred conveniences. Network/installation-wide multisite actions are deferred entirely.

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
- Normal activation does not create the legacy tables; first-release creation occurs only through explicit single-site provisioning/selection. Multisite table provisioning is unavailable.
- Deactivation retains all state and allows a later resume.
- Schema upgrades are versioned, idempotent, and run only when table storage exists or is being enabled.
- Schema version and schema-upgrade lock remain regular options on the single site; control records the last schema version it verified. Installation-wide coordination across networks is deferred.
- `dbDelta()` and DDL can auto-commit or partially apply. Upgrades must be additive, backward-compatible, stepwise, and health-checked after each step. Block table-mode requests during an incompatible partial upgrade and provide resumable repair; do not promise transactional DDL rollback.
- `uninstall.php` must check `WP_UNINSTALL_PLUGIN` and load a minimal uninstall-safe cleanup dependency directly. It must not bootstrap the normal admin engine.
- Uninstall removes internal storage only. It does not attempt rollback and does not delete galleries, albums, attachments, source data, or edited post content.
- Drop only exact fixed plugin-owned table names; never accept a table name from a request or wildcard.

Known transient families, including per-user content result transients, must be enumerated with a prepared, escaped fixed prefix and removed with their timeout rows. Use the Transients/Object Cache APIs for each resolved key where practical so a persistent object cache is invalidated; direct SQL cleanup alone is not sufficient. Cleanup code must also remove owned storage-control, table-authority-sentinel, schema, maintenance-gate, active-writer and backup resources. Include any owned notice/registry artifacts that actually exist; this is not an instruction to implement the deferred advisor or registry.

## Multisite

**Deferred design reference — not first-release implementation or acceptance.** In this release, existing multisite option behavior must remain compatible and table operations must refuse before mutation. Unexpected existing table authority blocks with preserved evidence, never stale-option fallback. Do not add network registry/provisioning, a topology matrix or large-network uninstall preparation now. Existing physical names/blog scoping may remain; no schema rewrite is required merely to defer support.

The following retained design applies only to a future separately scheduled multisite feature:

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

Execute [R0–R4 in the dev plan](dev-plan.md#ordered-work), not the superseded greenfield phases or S0–S9 roadmap.

| Order | Outcome | Required milestone |
|---|---|---|
| R0 | Owned test environment, runnable customer journey, first failure and classified baseline | Begin integration immediately |
| R1 | Small safe repairs to supported admission, actual migration/edit/reversal and essential cleanup | M1: testable source/ZIP candidate, including runtime floor |
| R2 | Remaining supported bounded consumers, parity and fault/recovery/cleanup coverage | Keep M1 green |
| R3 | Basic recommendation and minimal operator controls | No advanced advisor gate |
| R4 | Maintained suite, real-source/package/browser and scaling acceptance | M2: first-release readiness |

A positive journey is not a release by itself; remaining supported paths still need coverage. Conversely, do not wait for complete repositories, every historical diagnostic or UI polish before trying it. The dev plan defines standing authority after kickoff, limited repair loops, test classification and concrete escalation rules. No phase adds migration-config JSON or a new execution engine. Deferred multisite work is not an automatic next task.

## Test strategy

### Release requirements versus historical diagnostics

Classify tests under the [approved plan](dev-plan.md#test-classification-and-simplification-guardrails): product compatibility, safety, supported performance, superseded implementation diagnostics, or environment defects. Record rationale, replacement coverage, reviewer and commit before retiring/reclassifying a test. Do not rebuild abandoned machinery merely to satisfy its diagnostic suite; do not silently skip failures or weaken data-safety guarantees.

Native WordPress text conversion must succeed for supported customer-shaped inputs. Arbitrary-byte codec round trips remain lossless where supported, while unsupported native-text inputs must refuse without mutation. A native-text normalization mismatch is not permission to lose bytes, nor a requirement for a universal decoder. Historical host certificates and exact-binary allocator proofs are not customer prerequisites.

The following tests apply to first-release supported behavior unless explicitly labelled deferred. Start with the integrated journey, then expand coverage for M2.

### Existing baseline and gaps

The repository audit on 2026-08-28 recorded these historical baseline commands. These results do not describe PR #15 or validate its current head:

- `composer test` — passed on PHP 8.4.6 with 40 tests and 336 assertions.
- `php tests/content-scan-regression.php` — passed.
- `node tests/ajax-js-regression.js` — passed.
- `wp eval-file tests/integration/core-gallery-migration.php` — mutates a live WordPress database, so it was not run during this documentation-only work and must run only against an isolated disposable site.

- PHPUnit discovers `*Test.php`; the content-scan PHP regression and AJAX JavaScript regression are separate commands and must remain separate required checks unless the test runner is deliberately unified.
- The original PHPUnit bootstrap mocked only the Options API and its `update_option()` stub always returned success. PR #15 adds substantial fixtures and table doubles; those do not replace same-value `false`, non-autoload, object-cache invalidation, and raw option-byte checks in a real WordPress database.
- The AJAX JavaScript regression is focused on content scanning; it is not evidence for every gallery, album, log, or storage administration flow.
- The original live integration script protected only `foogallery-migrate-data`; current table/control/backup resources require explicit fixture ownership and isolated setup/teardown. Never run these tests against Brad's working site without a separately authorized disposable environment.
- PR #15 adds codec/repository, table, maintenance, conversion, lifecycle, compatibility and native integration coverage. Audit what each test actually exercises: lifecycle retention is not complete uninstall coverage, fault-free transport recovery is not process-death recovery, and fake-table benchmarks are not native database scaling evidence.
- R0 records/reproduces relevant failures and classifies historical diagnostics before attributing regressions. R4 must complete the maintained release suite and package validation with no unexplained failures/timeouts. Report excluded diagnostic groups and their reviewed rationale; focused passes are not a substitute.

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
- Writer/gate acquisition races, token ownership, re-entrant request handling and release on errors; expired/ambiguous ownership remains blocked until assisted quiescence is proven.
- Stable queued, started, paused, and incomplete persisted work is not rejected merely because of its status; switching follows the existing engine's opaque quiescence verdict after active writers drain.
- Compare-and-swap rejection when a source digest or state-head generation/revision changes.
- Missing/corrupt control data with a table-authority sentinel, retained table heads/staging, or backup blocks instead of reviving option mode; empty authoritative table state is covered by the sentinel.
- A valid table control with a missing or mismatched authority sentinel blocks until explicit repair.
- Downgrade warning and verified table-to-option preparation path.
- Reverse-switch headroom/representation admission independently of advisory notice thresholds.
- Oversized web-import refusal and CLI recovery.

### Cleanup, uninstall, and multisite

First-release gates:

- Each cleanup action removes exactly its documented scope.
- Interrupted cleanup reports exact residual resources, blocks migration, and completes idempotently when rerun.
- Cleanup removes active and stale copies where promised.
- Single-site remove-all/uninstall removes old and new owned options, transients, locks, backups, rows and exact tables, including any superseded artifacts actually present.
- The real production ZIP uninstalls without development dependencies and without booting the normal admin engine.
- Existing multisite option behavior remains compatible, table operations refuse before mutation, and unexpected table authority preserves evidence instead of falling back to stale options.
- Deactivation retains resumable state.
- Migrated galleries, albums, attachments, source data, and content remain untouched.

Deferred network tests belong to the future [multisite design](#multisite): cross-network schema coordination and cleanup isolation, site deletion/orphan audit, registry completeness, and supported/prepared large-network uninstall. They are not first-release required runs.

### Performance fixtures

Use representative native-text workloads for M1 first. For M2, measure supported flat/nested state and fixed-page/affected-tree scaling, including the native20K and actual10K AIGPL journeys. The larger/stress shapes below are useful boundary fixtures, not a promise that all fit every supported memory setting; unsupported conversion shapes must refuse safely rather than trigger an open-ended host-qualification project:

- 20,000 top-level records with no children.
- 2,000 galleries with 50 images each.
- One gallery with 10,000 images to expose the largest-tree boundary.
- One album with 5,000 gallery children.
- 50,000 migrated-history records.
- 50,000 content findings.
- No-pagination (`page_size = 0`) gallery, album, and content views to verify identical output/order and bounded repository hydration while separately measuring O(N) response size and buffering.

For table-backed paginated list, log, content-page, history lookup, and next-migration requests, increasing unrelated stored records by 10x should not increase peak PHP memory by more than 25%, excluding the requested page and affected root tree. For `page_size = 0`, the same 10x test applies to repository hydration measured before/downstream of response buffering, while total response bytes and time are expected to grow with N. A one-root update must not rewrite unrelated root trees or state buckets. Exact time budgets should be set from CI and supported-host benchmarks rather than hard-coded in this document.

## Acceptance criteria

The **first release** is ready at M2 when the following pass. Deferred network/advanced-advisor requirements are not part of this checklist.

- Upgrade/activation remain option-backed and storage-neutral without an explicit administrator choice; multisite options remain compatible and table operations refuse safely.
- Existing compact and pre-compaction state remains readable in option mode; unsupported conversion shapes refuse without silent normalization or data loss.
- Existing source adapters, identities, ordering, queue/status/retry semantics, output, content behavior and AJAX flow remain unchanged.
- A representative large real WordPress migration can enable tables, migrate, edit and clean up on the runtime floor and matching production ZIP without dev dependencies.
- A fitting current-data reversal preserves table edits (the final PHP5.4 ZIP proves1K); oversized reversal refuses without changing authority or data (the modern10K journey proves refusal, not successful reversal). These are separate size/runtime receipts, not a claim that the large10K journey positively reversed on the floor.
- Supported native-text conversion produces equivalent logical state/runtime objects; arbitrary-byte codec/refusal coverage is separate and does not weaken integrity.
- Table-mode list/log/content/history/queue/next-object operations do not rebuild unrelated state. Mutations affect only required records/trees; content and tag queue checkpoints remain atomic.
- Show-all uses internal batches with its documented O(N) output limitation; source-discovery and largest-active-tree limits are measured and disclosed.
- A small tested release policy admits supported inputs and safely refuses unsupported ones; no customer/host-specific certificate is needed.
- A basic filterable size suggestion reports its trigger and never automatically enables/provisions table mode.
- Switching is authorized, quiescent, resumable for ordinary handled failures, verified before authority changes and never falls back to stale state.
- Uncertain hard-crash ownership blocks until the documented assisted procedure establishes that old workers cannot resume; no age-based automatic takeover.
- Reversal preserves current table edits and runs only when the option representation fits safely.
- Deactivation retains state. Single-site on-demand remove-all and uninstall remove all owned old/new options, transients, locks, backups, rows and tables; partial cleanup is resumable and accurately reports residuals.
- Cleanup never deletes migrated/source galleries, albums, attachments or edited content, and never rolls back migrations.
- Retained product/safety/performance tests and applicable source/ZIP/runtime/browser checks pass; any superseded diagnostic tests have a recorded, independently reviewed disposition.
- No public release occurs without explicit approval.

M1 alone does not satisfy these criteria. Multisite table support and advanced advice/convenience operations require a future scope decision, not another mandatory first-release phase.

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
| Future multisite query leaks another site's state | Deferred: mandatory `blog_id`, composite indexes and cross-site contracts before enabling that future feature |
| Uninstall removes migrated output | Cleanup owns only fixed internal resources; output-removal code is absent |
| Future large-network table uninstall times out | Deferred network registry/preparation design; do not claim new large-network support in this release |
| Recommendation thresholds are inaccurate | Filters, visible evidence, benchmarks, and conservative opt-in behavior |
| Retained copies double storage temporarily | Display exact stale-copy size and provide explicit removal |
| Table schema is forced into future v2 work | Legacy-qualified names and a hard rule that future engine repositories are separate |
| Source discovery or one enormous object still exhausts memory | State the boundary; measure it; defer source/object contract changes |

## Implementation boundary for future reviews

A proposed change belongs in this project only if it changes where or how current state is encoded, queried, copied, validated, recommended, or removed while preserving current migration behavior.

Reject it from this project if it introduces a new source vocabulary, identity, execution state, worker, destination operation, journal, rollback rule, or migration result. Those concerns remain separate even when a future engine can reuse the storage lifecycle and cleanup conventions established here.
