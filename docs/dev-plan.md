# Legacy storage upgrade — completion plan

**Prepared:** 2026-10-22

**Status:** Documentation ready; implementation not started under this plan

**Branch:** `feature/migrated-store-scalability`

**Starting PR head:** `206655cbfd49541b0f957d32028d1e53c17f39a1`

**Pre-PR comparison base:** `3bb151465818fbb164edb35866118794563ce12a` (`develop`)

**PR:** [#15 — Use a bounded store for migrated object state](https://github.com/fooplugins/foogallery-migrate/pull/15)

**Requirements:** [Legacy storage upgrade](legacy-storage-upgrade.md)

## Scope and acceptance

Finish the storage-only upgrade already started in PR #15. Retain its codecs, option/table backends, repositories, schema, authority, maintenance and conversion infrastructure. Do not restart that architecture, add a v2 engine, introduce migration-config JSON, change source adapters, or change legacy migration semantics.

Persistence-facing caller changes in the existing engine are permitted only to replace unbounded storage access. Queue order, identities, selection, statuses, batching, child loading, destination writes, retries, content replacement and image-tag results must remain equivalent to the pre-PR implementation. Any change to those decisions is outside this plan and requires separate approval.

The user-facing outcome is reliable large-site storage, not merely more repository methods or admission refusals:

| Acceptance | Tasks |
|---|---|
| Options remain the default; upgrade/activation do not create tables or convert data; existing behavior and readable compact/non-compact state remain compatible | S0, S2–S6, S8 |
| Table-backed log, lookup, list, selection, migration-turn, content and tag requests use page/affected-tree memory, not total stored state | S2–S5, S8 |
| Administrators can clear state, reset storage and remove all internal data; uninstall works from the shipped ZIP; outputs/source data remain untouched | S1, S7–S9 |
| Supported single-site users can explicitly enable, recover and reverse table mode from current data with verified authority and bounded work | S6–S8 |
| Size-based, filterable recommendations are useful and never select table mode automatically | S7–S8 |
| Full source/package acceptance, supported runtime checks and representative native scaling evidence pass | S0, S6, S8 |
| Multisite table selection remains unavailable until network lifecycle, isolation and cleanup acceptance pass | S9 |

Single-site acceptance at S8 is an intermediate delivery milestone, not completion of the full multisite design. Storage cleanup never rolls back migrations or deletes FooGallery output, attachments, source-plugin data, or edited post content.

## Current implementation and evidence

See the [PR implementation inventory](legacy-storage-upgrade.md#pr-15-implementation-status) for what to reuse. Repository/schema existence is not end-to-end acceptance. In particular:

- Production backend selection exists; this is no longer just an injected table prototype.
- Table scalar creation/deletion and atomic content-reset primitives exist; do not repeat obsolete TODOs from early checkpoint documents.
- Whole-collection gallery/album reads, whole-history consumers and full image-tag construction remain.
- Current uninstall does not cover the new schema/resources and requires development-only Composer files.
- Public capacity classes are empty; injected policies do not demonstrate a usable public feature.
- Multisite table operations are intentionally rejected.

Evidence to retain, but not promote to a current release pass:

- [October 20 review checkpoint](pr15-review-checkpoint-2026-10-20.md): failing/timed-out full PHPUnit discovery, focused passes, package checks and outstanding acceptance.
- [Single-site scope / October 21 lifetime checkpoint](storage-single-site-release-scope.md): limited runtime-floor scope passes, focused tests, full-suite timeout, and unexecuted positive native20K/actual10K AIGPL journeys.
- [Native recovery checkpoint](storage-native-recovery-checkpoint.md): distinguish recoverable handled failures from ambiguous/dead-worker recovery; age alone never proves safety.

These reports are tied to their own source/package revisions. This planning commit has not rerun runtime tests or modified a WordPress database. S0 must establish a fresh baseline at the actual implementation starting commit.

## Ordered work

Execute in the order below. Each task needs its focused tests, independent review and an accepted local commit before dependent work begins. Do not turn public table selection on early. A task may be split into smaller commits without changing its exit criteria or creating another tracker.

All tasks are currently `pending`, with no developer/QA agent assigned, zero repairs, no new validation evidence and no implementation commit. The model column is proposed routing for a future authorized `budget-dev` run, not agents launched by this documentation task. Independent QA is GPT-6 Sol Medium for every task.

| ID | Priority / deliverable | Depends on | Likely owned areas | Proposed developer and reason | Status |
|---|---|---|---|---|---|
| S0 | P0: Reproduce baseline, map callers and lock compatibility fixtures | — | `tests/`, integration runners, this plan | Sol High — cross-cutting failure diagnosis and parity | pending |
| S1 | P0: Complete cleanup services and packaged uninstall | S0 | Lifecycle/bootstrap, resource manifest, cleanup services, lifecycle/package tests | Sol High — destructive scope, interrupted work and cache correctness | pending |
| S2 | P0: Finish history operations and production consumers | S1 | History/table repositories, engine, log view, lookup consumers | Sol High — cross-component identity/revision semantics | pending |
| S3 | P0: Finish bounded content scan/list/replacement access | S2 | Content repository/migrator/views, source-identity/count lookups | Sol High — atomic checkpoint and content-safety parity | pending |
| S4 | P0: Finish gallery/album paging and affected-tree mutations | S3 | Collection/table repositories, settings facade, migrator base/engine/views | Sol High — queue/selection/child semantics and memory | pending |
| S5 | P0: Finish incremental image-tag queue | S4 | Image-tag repository, engine tag paths, tag fixtures | Sol High — resumable queue construction and merge parity | pending |
| S6 | P0: Qualify public conversion, capacity and recovery | S5 | Capacity policy/admission, conversion/control/gate, native journeys | Sol High — real SQL, authority and finite memory qualification | pending |
| S7 | P1: Finish recommendation, status and cleanup controls | S6 | Storage admin/CLI/views/assets, advisor and UX tests | Luna High — established services and explicitly defined UI behavior; escalate cross-service defects | pending |
| S8 | P0 release gate: Accept the single-site source and ZIP | S7 | Test/integration/package evidence, user documentation, this plan | Sol High — independent end-to-end integration and performance qualification | pending |
| S9 | P2 separate milestone: Complete multisite lifecycle and enablement | S8 | Registry/schema authority, site hooks, network admin/CLI, multisite tests | Sol High — cross-network scope and large-network cleanup | pending |

P0 marks blockers to a safe, usable single-site release. S7 is still required before S8 acceptance. Multisite support must not delay identification/fixing of single-site defects, but its existing option-mode behavior must not regress during S0–S8.

### S0 — Establish the baseline and executable parity contract

1. Record exact HEAD, PHP/WP/database versions, memory limits, dependencies and test commands. Confirm the current checkout and retain any unrelated user changes.
2. Run the complete host suite and separate PHP/JS regressions. Capture exact failure names and distinguish deterministic failures, admission refusals and timeouts. Split discovery into reproducible groups if needed; a partial run is not a pass. Do not increase limits silently to hide the problem.
3. Trace every operational key and every production caller of the five repositories. Record reads/writes that hydrate complete state, not only obvious `get_all()` calls. Include logs, debug mode, AJAX list/selection/retry/cancel, source identity/URL lookup, content counts and image tags.
4. Establish golden fixtures against the pre-PR base for logical state and user-visible transitions/results: typed/missing/false/null keys, order, nested children, errors, history identities/revisions, retry/resume and source adapter behavior. Audit PR compaction/child-loading changes explicitly.
5. Extend the exact resource manifest with new and superseded options/tables, locks, writers, backups, transients and schema metadata. Identify disposable native fixtures and prove their setup/teardown ownership before running them.
6. Classify the first real public-journey blocker (currently empty policy) and map downstream outstanding workload checks. Keep native identity transport, exact-byte codec/refusal cases and customer-shaped positive journeys distinct.

**Exit:** Reproducible current failures and passing checks are recorded here; every hot path has an owner task; compatibility fixtures exist; no red test is silently waived. Baseline failures are either repaired within their owning storage task or explicitly block S8. Avoid an open-ended new test/telemetry framework.

### S1 — Cleanup before exposing table mode

Reuse the existing authority/gate and fixed schema naming. Implement one manifest-driven cleanup service shared by admin, CLI and uninstall; do not build separate destructive implementations.

- Implement the exact scopes in the [cleanup action matrix](legacy-storage-upgrade.md#storage-and-cleanup-administration): clear current state, retire inactive copies, reset to option defaults, and remove all internal data. Clear keeps preferences/mode/schema; reset keeps preferences but returns to empty options; single-site remove-all also removes preferences and drops both exact shared tables.
- Include old `foogallery_migrate_objects` artifacts where owned, both current legacy tables, data/settings, schema/control/sentinel/backup, active-writer/maintenance, transient/timeout and notice resources. Resolve explicit targets; never delete output/source data or use wildcard table drops.
- Make partial cleanup idempotent/resumable with exact residual reporting and a cleanup-in-progress block removed last. Do not erase uncertain live ownership to force progress. Clear active and stale state so an old copy cannot resurrect history.
- Replace the uninstall Composer dependency with a minimal packaged dependency path. Verify uninstall while inactive and without `vendor/`, without booting the migration/admin engine. Keep deactivation non-destructive and activation storage-neutral.
- Invalidate persistent caches, exercise denied SQL/DDL and partial schema states, and verify repeated cleanup. Keep site-level cleanup isolated on multisite; do not drop shared tables from a site action. The complete large-network lifecycle is S9.
- Wire the existing clear-history handler to the new backend-aware state-clear service. New screen controls follow in S7; test the service and existing handler now.

**Exit:** Real single-site options/table/stale/interrupted states clean correctly; package uninstall leaves zero owned remnants; output/source fixtures remain byte-for-byte/logically unchanged. No automatic provisioning or conversion is introduced.

### S2 — History and all lookup/log consumers

- Retain direct/batched lookup, source-identity projections, revision-coupled writes, summary and cursor APIs. Implement genuinely missing table operations required by callers, including valid source-URL and parent lookups, with the original identity/order contract.
- Replace table-path calls to option-only `get_all()`, `log_page()` and `get_by_parent()` with bounded operations. Wire log paging/filtering, factory reuse, history presence/status/retry and debug diagnostics; coordinate content/tag consumers with S3/S5.
- Avoid full-history exports for routine requests. Distinguish internal export for verified switching from a production query. Ensure atomic record+revision changes and no hidden full scan in helpers.

**Exit:** History repository, log and lookup paths owned by S2 are table-supported; cross-backend parity holds; enlarging unrelated history does not enlarge fixed-page request memory. Content/tag consumer integration completes in S3/S5. Missing/false/duplicate keys, URL identities, first-match ordering and retry behavior remain unchanged.

### S3 — Content state and user flows

- Reuse window/get-many and batch/checkpoint primitives, including the existing atomic reset. Remove residual full snapshots from discovery deduplication, reconciliation, list/count, exact selection, replacement and progress.
- Use bounded source-identity batches and preserve legacy matching/occurrence keys and filtered WordPress Core counts.
- Findings and checkpoint must commit together. Mid-batch failure or reset failure leaves the previous coherent state visible. Preserve stale-content checks, parsing and destination content behavior.
- Implement bounded internal retrieval for `page_size=0`; distinguish unavoidable full HTML output size from avoidable storage hydration/buffering.

**Exit:** Existing content PHP/JS and native content-flow checks pass in both backends, including resume, cancelled/failed turns, stale edits and exact selection. Increasing unrelated findings/history does not grow fixed-window working memory.

### S4 — Gallery/album collections and migration turns

- Replace active-table `get_migrator_setting()` collection hydration and old-state reconstruction in `replace_collection()` with purpose-specific bounded operations. Keep full-array option compatibility methods off table hot paths.
- Route list views, selection/cancel patches, first queued/started lookup, requested-gallery retry, global error audit, progress and one-parent migration turns through pages/aggregates/affected-tree access.
- Preserve all-or-nothing selection patches, order and status transitions. Update an affected root tree without reading/diffing every other root. Keep lazy child loading and existing images-per-turn behavior.
- Handle show-all via internal batches, without silently changing its UX. Publish the remaining largest-single-tree and output-rendering bounds; this storage upgrade cannot promise bounded source discovery for adapters that inherently materialize all source objects.

**Exit:** Golden queue/transition/output fixtures match the pre-PR engine; no table request reconstructs unrelated roots; atomic rollback and largest-gallery/album cases are tested. Fixed caps/refusals alone do not satisfy this exit.

### S5 — Incremental image-tag queue

- Replace `build_image_tag_sync_state()` full-history iteration and global URL/ID maps with bounded history pages and indexed lookups.
- Store high-cardinality queue entries as records, not one growing scalar/snapshot. Persist construction cursor, counters and queue writes atomically; page execution and completion updates.
- Preserve deduplication, tag merge order, retries, missing targets, completion and resume across requests. Do not introduce a background runner or new execution semantics.

**Exit:** Growing unrelated history/queue records does not inflate per-turn memory; interrupted construction/execution resumes without lost or duplicated work; output tags match option-mode fixtures.

### S6 — Make public switching usable within evidenced limits

- Reuse staged conversion, authority, verification, maintenance and current-data reversal. First repair the concrete failures reproduced by S0–S5; do not add another generalized calibration/parser subsystem.
- Measure native driver/preparation workspace and simultaneous representations at declared limits, including 128M/256M where applicable. Document full old-option decode, largest-state/tree and reverse-to-option size limits honestly.
- Derive a finite conservative supported policy from reproducible positive/negative evidence, headroom and implementation/evidence digests. Populate the empty policy only after that evidence exists. Runtime or policy mismatch must continue to refuse safely.
- Execute public admin/CLI preflight → enable → migrate/edit → reverse from current table data → cleanup. Include representative native20K and distinct actual10K AIGPL journeys plus applicable retained exact-byte workloads. Test-injected profiles, fake table benchmarks and a safe refusal are not successful positive journeys.
- Exercise interruption before/during/after the authority flip, failed verification, corrupt staging, denied privileges, source changes, ambiguous SQL outcomes and stale active-copy handling. Verify no stale fallback and exactly one authority.
- Preserve the conservative worker-ownership rule. Dead/aged owners require proven quiescence and an explicit assisted recovery path; never infer safety from age, PID absence or browser closure.

**Exit:** At least one documented supported public single-site class successfully completes the real positive journey; unsupported cases refuse before unsafe mutation; resumability/reversal/cleanup work with measurable bounds. The policy must not mask incomplete hot paths.

### S7 — Recommend table mode and finish operator controls

- Reuse StorageAdmin/StorageCli and expose status plus S1 cleanup actions, with capability+nonce checks, fixed target allowlists and scope-specific confirmation. Offer CLI parity for long-running maintenance, not a new migration engine.
- Measure serialized option bytes without loading the whole option where possible; report counts/size, current authority, schema health, pending maintenance, inactive copies and limits.
- Implement the [filterable provisional size thresholds](legacy-storage-upgrade.md#storage-recommendation-policy): early warning at 512 KiB/1,000 records; table recommendation at 1 MiB/2,000 records or estimated hydrated state reaching 20% of the effective PHP memory limit. Finalize thresholds from S6/S8 evidence; these are not conversion-admission guarantees.
- Persist/re-evaluate dismissal as specified. Never auto-select/provision table mode. If unsupported, explain why and the safe next step instead of offering a broken switch. Multisite remains option-only until S9.
- Clearly distinguish clear-state, reset, remove-all and inactive-copy retirement. Explain that cleanup does not undo migrated output. Surface recoverable failures and residual resources without leaking payloads or SQL.

**Exit:** Browser and CLI flows succeed for authorized users, refuse unauthorized/invalid requests, and present accurate readiness and cleanup scope. Small sites remain on options without unnecessary prompts.

### S8 — Single-site acceptance on immutable source and package

- Finish the entire test discovery with no unexplained failures/timeouts; rerun standalone regressions and affected native integrations. Record source commit and ZIP digest for all acceptance evidence.
- Test the actual production ZIP without dev dependencies: activate, options upgrade/resume, provision, switch, gallery/album/content/log/tag flows, recover, reverse, clear/reset/remove-all, deactivate/reactivate and uninstall. Verify no source/output deletion or stale-state resurrection.
- Exercise persistent cache and real InnoDB, minimum supported runtime pairs and current runtime. Preserve existing PHP 5.4 / runtime WP 5.0 declarations and the separate readme WP 6.0 declaration; changing floors is not an implicit fix. Limited scope smoke passes are not full compatibility acceptance.
- Benchmark 1K/5K/20K flat and nested state with a fixed page/tree, then largest single gallery/album. Record peak memory, elapsed time, queries/bytes and changed rows for normal requests and conversion separately. Show how each scales as unrelated records grow; publish limitations.
- Review admin behavior in a browser, not just receiver tests. Tune advisor thresholds/indexes/batches only from evidence, then rerun affected acceptance and regenerate digest-bound policy if needed.
- Update this plan and user-facing storage/recovery/cleanup guidance. Confirm the table-mode gate cannot bypass unqualified states.

**Exit:** Independent QA accepts the full single-site milestone at an identified source commit and matching ZIP. This is readiness evidence, not permission to push, merge or release. Full design completion still requires S9.

### S9 — Multisite as a separately gated milestone

- Retain table refusal until network-active prerequisites, strict blog scoping and the one installation-wide schema lock are proven.
- Add a bounded registry, site-deletion hooks including the WP 5.0 fallback, orphan audit, per-site/network/global cleanup scopes and storage-only CLI preparation/recovery.
- Replace unbounded site enumeration. Benchmark a supported synchronous uninstall bound; above it, complete batched zero-remnant preparation before file removal. Verify final removal of exact shared tables/schema resources without touching other networks during network-scoped actions.
- Test site-only vs network activation, network deactivation, missing/deleted sites, second-network isolation, interrupted purge, corrupt/missing registry, persistent caches, insufficient capabilities and uninstall after preparation.
- Repeat affected runtime/package and large-network acceptance; document limits and support procedures before enabling multisite table mode.

**Exit:** All multisite requirements in the storage design are accepted and committed, and no network/site action can remove another network's data. No silent downgrade to stale options when a table authority is unsupported or damaged.

## Validation commands and environment safety

At kickoff, verify installed dependencies and binaries before using these commands. Record exact commands, exit status and relevant failures in the task evidence; do not replace them with an unqualified “tests pass.”

```sh
git status --short --branch
git rev-parse HEAD
php -v
php -d memory_limit=256M vendor/bin/phpunit --colors=never --do-not-cache-result
php tests/content-scan-regression.php
node tests/ajax-js-regression.js
php tests/runtime-cleanup-regression.php
npm run build
python3 tests/production-package-regression.py dist/foogallery-migrate.v1.16.zip
git diff --check
```

Run focused test files for each task as well as complete discovery before acceptance. `runtime-cleanup-regression.php` and the store benchmark use doubles; they cannot certify native uninstall or table scaling. `npm run build` may update generated translation/package files: inspect and retain only authorized changes. Verify the actual versioned ZIP path if the version changes; do not bump versions solely to run tests.

Native fixture entry points include `tests/integration/storage-release-journey.php`, `storage-single-site-scope.php`, `storage-native-recovery.php`, and the existing source/content integration scripts. Read their guards and required phase/fixture parameters first. Build a deliberately owned disposable WordPress/database environment, back up relevant fixtures, and record setup/teardown boundaries. **Do not run a bare `wp eval-file` against Brad's working Local site.** Do not assume historical task-specific fixture paths or credentials are available, and never weaken safety guards to use an unrelated database.

Use production ZIP checks for package contents/source parity, then separately run package activation/uninstall and user flows inside the disposable site. PHP lint and fixture assertions do not replace those runtime checks. Read the applicable validation/release skills when executing that work; this plan does not authorize a release.

## Execution tracking and handoff

This file is the sole forward workflow tracker. Keep historical checkpoint documents unchanged unless correcting a factual error. Do not create per-worker plans or duplicate acceptance ledgers.

- **Next ready task:** S0, after development is explicitly started and its usage allowance is captured.
- **Current change:** Documentation reconciliation and this ordered plan only; no runtime/source/test changes.
- **Preparation validation:** Local Markdown file/heading links and code-fence balance checked across the three changed documents; `git diff --check` passed. No runtime test success is claimed for this documentation commit.
- **Shared-file conflicts:** S2–S5 touch the same engine/settings/repository contracts; execute serially. S1/S6/S7 share authority/admin lifecycle boundaries. No parallel work is scheduled; any future independent read-only QA/fixture work must have explicit ownership and immutable inputs.
- **Statuses:** `pending → developing → qa → validating → done`, with `blocked` / `budget-stopped` when appropriate. Accepted work must be committed before marking done.
- **Per-task updates:** Record developer model/reason and agent ID, separate QA agent ID (Sol Medium), repair count and reassignment history, commands/results, unresolved findings and implementation commit beneath the relevant task. Defaults for every row are currently unassigned / 0 repairs / not run / no commit.
- **Supervisor:** Current planning session; model/effort not independently verified. Verify/disclose at development kickoff; do not silently claim a model switch.
- **Active workers / scheduled wakeups:** None created for this plan. No automatic continuation is scheduled.
- **Commit policy:** Scoped local commits after validation. This request does not authorize pushing, merging or implementation. Explicit Brad confirmation is always required before making a WordPress.org tag live or changing its stable tag.

### Usage ledger for a future development run

No development usage allowance has been supplied for this plan and no budgeted run has started. On an explicit kickoff, apply the `budget-dev` workflow and capture its account-wide monitored baseline before implementation/delegation; do not infer permission from this preparation request.

| Item | Current value |
|---|---|
| Authorized allowance / extensions | Not supplied / none |
| Original baseline and window history | Not captured |
| Cumulative observed development usage | Not measured; no development run started |
| Advisory reserve / remaining allowance | Not set / unknown |
| Checkpoint | 2026-10-22: documentation-only reconciliation at PR head `206655cb` |

Retain this ledger on resume; do not reset allowance or repair counts. Keep raw account responses and account identifiers out of Git. A future budget authorization does not itself authorize a new engine, unapproved architecture assignment, push, merge or public release.
