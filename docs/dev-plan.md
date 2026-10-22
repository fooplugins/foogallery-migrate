# Legacy storage upgrade — first-release plan

**Updated:** 2026-10-22

**Status:** R0, R1a, floor environment and actual10K source preparation committed; R1b bounded forward copy/verification accepted in this checkpoint. Actual migration, reversal and lifecycle remain pending.

**Branch:** `feature/migrated-store-scalability`

**Reviewed PR source:** `206655cbfd49541b0f957d32028d1e53c17f39a1`

**Pre-PR behavior comparison:** `3bb151465818fbb164edb35866118794563ce12a` (`develop`)

**Previous plan commit:** `cb8aa51`; its S0–S9 sequence is superseded by R0–R4 below.

**Requirements:** [Storage design and approved release boundary](legacy-storage-upgrade.md#approved-first-release-boundary)

## Approved scope and authority

On 2026-10-22 Brad explicitly approved all five cuts in the [PR simplification proposal](https://github.com/fooplugins/foogallery-migrate/pull/15#issuecomment-6287530658). After committing the documentation, Brad requested development using this plan and then instructed: “dont worry about budget - just go”. This authorizes implementation, delegated development/independent QA, owned disposable testing and scoped local commits. The explicit budget override removes the workflow's usage-budget approval/stop requirement for this run. Pushing, merging and public release remain separately controlled.

Deliver a testable **single-site** storage upgrade using the existing migration engine and useful PR #15 foundations. Preserve codecs, repositories, authority and bounded table access where they serve the release. Existing complexity is not automatically a requirement to preserve: simplify or remove obsolete product calibration/proof paths with reviewed replacement coverage, without rewriting the engine.

| Approved cut | First release | Explicitly deferred/excluded |
|---|---|---|
| Capacity policy | Small release-owned policy, measured conservative limits/headroom, clear unsupported-input refusals | Per-machine/customer certificates and bespoke dataset qualification; allocation diagnostics as customer prerequisites |
| Single-site only | Table mode on single-site; unchanged multisite option behavior and explicit refusal of table operations | Network table enablement/provisioning, registry/topology expansion, large-network uninstall preparation |
| Assisted hard-crash recovery | Resume ordinary handled failures; uncertain ownership blocks until documented operator procedure proves old workers cannot resume | Automatic ambiguous-owner takeover, new worker-fencing platform, age/PID/browser-based lock removal |
| Minimal maintenance UI | Inspect → explicitly enable → copy/verify → switch; current-data reversal when safe, basic size recommendation, essential cleanup/uninstall | Multi-tier advice, growth-triggered repeat notices, separate convenience controls and diagnostic polish |
| Bounded conversion and appropriate proof | Initial full legacy-option decode within measured limits, then bounded copy/verification; native WordPress text journey plus separate arbitrary-byte codec/refusal tests | Universal streaming decoder; requiring invalid UTF-8 to survive a native text round trip unchanged |

No v2 engine, migration-config JSON, source adapter redesign, new identities, queue semantics, destination behavior, background runner or runtime-floor increase. Persistence-facing caller edits must preserve existing selections, statuses, ordering, lazy child loading, batching, retry/resume, content replacement and tag results.

## Two milestones, not one expanding finish line

### M1 — Testable integrated candidate (R1)

On a deliberately owned disposable single-site installation, using production entry points and real WordPress/SQL:

1. Inspect existing large option-backed state and explicitly enable table mode.
2. Copy and verify before flipping the single authority.
3. Run an actual representative large migration through the existing engine.
4. Edit table-backed state and demonstrate the edits are retained.
5. Reverse from **current** table data when the option representation fits safely.
6. Clear/remove internal state and exercise packaged uninstall without deleting migrated/source content.

Exercise this path from R0, fixing its first concrete failing step in small R1 slices. Demonstrate it on the supported runtime floor as well as the development runtime before expanding the matrix. A matching ZIP must work without development dependencies. A fake backend, injected test policy, safe refusal, or collection of isolated passes does not satisfy M1.

M1 is for controlled testing, not release. Record known incomplete paths and prevent unsupported use; do not broadly expose table mode just because the selected journey works.

### M2 — First-release readiness (R4)

M1 stays green, all supported storage-backed flows are compatible and bounded, essential cleanup/recovery is verified, the basic advisor works, and the maintained release suite plus source/ZIP acceptance pass. Deferred features are **not** M2 requirements. Readiness is not authorization to publish.

## Existing work and evidence

The [source inventory](legacy-storage-upgrade.md#pr-15-implementation-status) is pinned to the reviewed PR head, not a current test pass. Reuse the option/table backends, schema, scalar mutation, history/content primitives, authority, provisioning and conversion services. Do not redo extraction.

Known starting barriers:

- Empty shipped capacity classes prevent production conversion; test-injected policies do not solve that.
- Uninstall requires excluded `vendor/autoload.php` and targets the older table layout rather than all current resources.
- Whole-collection gallery/album paths, option-only history consumers, unsupported URL lookup and full-history tag construction remain.
- Full PHPUnit discovery previously failed/timed out; focused passes are not complete acceptance.
- Multisite table refusal is intentional and remains a release requirement, not a bug to remove.

Historical receipts: [October 20 review](pr15-review-checkpoint-2026-10-20.md), [single-site/lifetime checkpoint](storage-single-site-release-scope.md), and [native recovery checkpoint](storage-native-recovery-checkpoint.md). Keep them unchanged as historical records. Their old “not waived” language does not reinstate requirements explicitly amended by Brad, and their successes do not certify a newer source tree.

## Ordered work

R0–R4 replace the previous S0–S9 sequence. R0 is done in `c7eda75`; R1a is done in `fb65305`; R1b is accepted in this checkpoint and R2–R4 remain pending. R1 as a whole is incomplete. Independent QA is GPT-6 Sol Medium, separate from the developer.

| ID | Deliverable / exit | Depends on | Owned areas | Proposed developer and reason |
|---|---|---|---|---|
| R0 | Runnable disposable journey, first reproducible failure, classified baseline | — | Existing integration runners/fixtures, relevant tests, this tracker | Sol High — environment, failure attribution and compatibility |
| R1 | M1: smallest safe integrated large-site journey passes on source/ZIP and runtime floor | R0 | Capacity/conversion, affected repository/caller paths, essential cleanup/uninstall; one failing-step slice at a time | Sol High — cross-component authority, memory and lifecycle |
| R2 | Remaining supported hot paths, parity and failure safety complete | R1 | History/log, content, gallery/album, image-tag, cleanup/recovery tests and affected source | Sol High — bounded access and legacy behavior |
| R3 | Basic recommendation and usable minimal operator workflow | R2 | Storage admin/CLI/views, advisor, user guidance | Luna High — established services and bounded presentation work; cross-service defects go to Sol |
| R4 | M2: independent first-release source/package acceptance | R3 | Maintained suites, native/browser/package checks, performance evidence, this tracker | Sol High — integrated qualification, with separate Sol Medium QA |

Dependencies govern acceptance, not when tests may be tried. Run the journey from R0 onward; do not wait for complete domain repositories, an exhaustive matrix or UI polish. Commit accepted slices serially; keep integration runnable between them.

### R0 — Prepare once and expose the first real failure

- Verify Git state, actual starting SHA, PHP/WP/database versions, dependencies and access to an explicitly owned disposable site. Prepare one reproducible runner and teardown procedure using existing guards; never run destructive fixtures on Brad's working Local site.
- Choose a representative actual gallery migration fixture, including the known AIGPL shape, and record its size, expected outputs and protected content. Prefer existing native journey scripts. Run the public path immediately and capture its first failure (the empty policy is the known starting barrier).
- Attempt baseline discovery once with an explicit runtime budget and capture exact failures/timeouts. Split it for attribution where necessary; do not repeatedly rerun the same uninformative timeout. Apply the test classification below.
- Inventory operational resources and high-cardinality production callers; map gaps to R1 or R2. Add/reuse golden pre-PR fixtures for the next affected behavior, not an exhaustive new framework before beginning repairs.
- Record ready-to-run commands, fixture ownership and the next smallest repair. Lack of a passing whole suite is not a reason to postpone all integration work; unexplained relevant failures still block M2.

**Exit:** Environment and runner work, first failure is reproducible, relevant baseline failures are classified, and the next slice is concrete. R0 is not an open-ended architecture audit or permission to weaken test guards.

### R1 — Repair toward one integrated candidate

Work from the first failing journey step, not from a list of frameworks to finish. Each slice records reproduction → smallest in-scope change → focused safety/parity checks → rerun journey → independent review → scoped commit.

- Replace customer/host-specific qualification requirements with a small tested release-owned policy. Measure required headroom for the supported path and reject unsupported inputs; do not populate arbitrary coefficients, bypass production admission or require a new certificate from each customer. Audit existing calibration/allocator machinery for diagnostic-only retention or removal with replacement tests.
- Keep initial full option decode and largest-tree limits explicit. Bound subsequent copy, verification and normal table work. Reuse the lossless codec and protect unsupported text/objects with safe refusal.
- Preserve authorization, source verification, one authority, no stale fallback and reversal from current table data. Keep ordinary handled-failure resume. Define an assisted hard-crash runbook that prevents old requests/workers from resuming before inspecting authority and resuming/discarding verified work; never infer quiescence solely from expired locks.
- Fix only repository/caller gaps actually encountered by this path initially, preserving legacy behavior. Record remaining supported-path work for R2 rather than declaring it complete.
- Complete essential single-site cleanup used by the journey: backend-aware existing state clear, remove-all and packaged uninstall. Cover old and new owned resources, stale copies, cache invalidation and interrupted cleanup. Remove the uninstall dependency on development files. Keep deactivation non-destructive.
- Use existing admin/CLI entry points; no new UI framework. Re-run enable → actual migration → edit → current-data reverse → cleanup on real SQL and the runtime floor, then verify the matching production ZIP.

**Exit:** M1 has reproducible positive evidence, basic refusal/interruption checks, protected output checks and an explicit known-gaps list. A supported-path refusal is a defect to fix, not evidence that the feature works. No claim of general release readiness.

### R2 — Complete supported flows without expanding scope

Retain the M1 journey as the integration check after every affected change. Address these bounded slices serially because they share engine/settings/repository code:

- **History/log:** Direct/batch/source-ID and URL/parent lookup, log paging/filtering, factory reuse, revisions, retry and debug paths. Replace table calls to option-only whole-history APIs; preserve exact identities and first-match order.
- **Content:** Reuse windows/get-many and batch/checkpoint primitives. Finish deduplication, exact selection, count/list/reconciliation and replacement access; findings/checkpoints remain atomic and stale-content protections unchanged.
- **Galleries/albums:** Replace whole-collection hydration and old-state diff reconstruction with page/aggregate/selection patch/next-object/affected-tree operations. Preserve queue order, lazy children, all-or-nothing patches, retries and images-per-turn.
- **Image tags:** Incremental history-driven queue construction, indexed page-local resolution and queue/checkpoint writes; preserve tag merging, deduplication, retries and resume.
- **Cleanup/recovery:** Verify all essential single-site clear/remove-all/uninstall scopes in option, table, stale-copy, partial-schema and interrupted states. Cleanup is idempotent, reports residuals and leaves a blocking marker until complete. Assisted ambiguous-owner recovery is tested as an operator procedure, not automatic fencing. Ordinary handled failures remain resumable.
- **Compatibility/scaling:** Unknown scalars, typed/missing/false/null keys, ordering, errors, compact/non-compact reads and pre-PR results remain equivalent. Show-all keeps its UX with internally batched storage reads; disclose O(N) output buffering and largest-tree/source-discovery limits.
- **Multisite regression only:** Existing options still work; provisioning/switching table mode refuses safely. Unexpected pre-existing table authority preserves evidence and blocks, never falls back to stale options. No network feature expansion.

**Exit:** Every supported production storage path is bounded and compatible; required safety/cleanup tests pass. Growing unrelated state does not inflate fixed-page/affected-tree working memory. Fixed caps alone are not proof of bounded access.

### R3 — Basic recommendation and minimal administration

- Provide one understandable storage workflow using existing services: status, explicit enable/copy/verify/switch, current-data reversal if safe, handled-failure resume or assisted-recovery instructions, state clear and remove-all.
- Preserve the user's requested size suggestion: a basic filterable serialized-byte threshold, provisionally 1 MiB pending measurements, with observed size, benefit, limits and a link to the storage screen. Query bytes without hydrating the option. Unsupported runtimes get an explanation, not a broken switch action.
- Do not add early-warning tiers, hydration-estimate tiers, growth-triggered repeat notices, extensive dismissal state or separate stale-copy/reset convenience screens. Essential cleanup must still remove stale resources even without dedicated buttons.
- Keep capability/nonce checks, confirmations, fixed target allowlists, redacted errors and the explicit warning that storage cleanup does not undo migrations. CLI maintenance uses the same services; do not build a broader command matrix.
- Document supported limits, downgrade preparation and assisted recovery. Never automatically create/select tables. Do not let recommendation thresholds override conversion/reversal safety limits.

**Exit:** Authorized administrators can understand and complete the minimal browser/CLI workflow; invalid/unauthorized actions refuse safely. Basic recommendation remains useful without advanced notification machinery.

### R4 — Accept the first release, not the entire future roadmap

- Complete all retained release suites without unexplained failures/timeouts. Record dispositions for removed/superseded diagnostic tests; no hidden skips or unsupported blanket green claim.
- Run M1 plus remaining supported gallery/album/content/log/tag and cleanup/recovery flows against immutable source and matching production ZIP without dev dependencies, using real InnoDB, persistent cache and browser interactions.
- Keep current declared floors: PHP 5.4 / runtime WP 5.0 and the separate readme WP 6.0 declaration. Verify applicable floor pairs and a current supported runtime; do not raise floors or build a universal host certification matrix to escape concrete defects.
- Measure realistic large native-text workloads (including the existing native20K and actual10K AIGPL cases), fixed-page scaling with unrelated records, affected-tree writes and largest-tree boundaries. Preserve separate arbitrary-byte codec/refusal coverage. Unsupported shapes must refuse safely; supported cases must actually succeed.
- Record peak memory, duration, bytes/queries/changed rows and source/ZIP hashes. Recheck policy limits after affected changes. Retain historical failed evidence without turning obsolete host-specific proofs into release gates.
- Independent QA reviews the exact final source/package and user workflow. Confirm essential uninstall leaves zero owned remnants on single-site without touching output/source content. Confirm multisite options/refusal behavior has not regressed.

**Exit:** M2 accepted and committed with documented limits and no remaining first-release blockers. No multisite table implementation or advanced advisor is needed for this exit. Public release still requires explicit approval.

## Test classification and simplification guardrails

R0 records each failing test/group as one of:

| Class | Treatment |
|---|---|
| Product behavior / compatibility | Fix; must pass for supported flows |
| Safety / integrity / authorization / cleanup | Keep equivalent or stronger coverage; cannot be waived for convenience |
| Supported performance / positive customer journey | Measure and fix; an admission refusal is not a positive pass |
| Superseded implementation diagnostic | Preserve as optional engineering evidence, or remove/replace after documented rationale and independent review |
| Environment / fixture defect | Repair the owned runner or report missing access; do not alter product policy merely to accommodate the harness |

For each reclassification record the original test/failure, requirement, replacement coverage or reason it is diagnostic, reviewer and commit. The approved test-domain amendment separates native-text WordPress conversion from arbitrary-byte codec/refusal behavior; it does not authorize data loss or normalization. Any further reduction of product guarantees requires Brad's decision. No test is retired merely because it is red.

Do not require every historical allocator/host-identity experiment to pass after its architecture is superseded. Conversely, do not silently exclude failing tests from full discovery. Maintain a clear release suite and separately labelled optional diagnostics, and report exactly what ran.

## Workflow to avoid repeated blockers

Development is now authorized. Apply these rules with Brad's explicit no-budget-stop instruction; do not reopen the settled scope or request a usage allowance again.

1. **One supervisor, one tracker, one settled release contract.** This file is the forward tracker; no per-worker plans or parallel acceptance ledgers. Old checkpoints are historical evidence. Record new decisions here without reopening the five approved cuts.
2. **Prepare the environment once.** Record the disposable site's ownership, runner, dependencies, runtime-floor access and teardown. Reuse it; never assume old private fixture paths exist. Missing access is a real blocker, not a reason to invent another test platform.
3. **Standing execution authority within scope and budget.** Routine investigation, implementation, tests, repairs, independent QA and scoped local commits continue without repeated permission requests. A failed test, a worker handoff or the end of a subtask is not itself a reason to ask Brad to continue.
4. **Small work packages with observable outcomes.** Every dispatch names the failing user-path step, allowed files, non-goals, test and exit condition. Start with one developer; serialize shared engine/storage edits and commits. Use the budget-dev developer/QA routing when invoked; do not spawn nested oversight teams.
5. **Bound diagnostics by a hypothesis.** State the suspected cause, one discriminating experiment and what the result changes. If a probe adds no decision-relevant evidence, change the approach. Do not repeatedly raise memory, re-run identical timeouts or create helper frameworks just to produce another receipt.
6. **Repair loops trigger reassessment, not permission churn.** After one unsuccessful Luna repair, Sol reassesses/reassigns per the workflow. After two unsuccessful repair cycles, classify implementation vs environment vs superseded assumption, choose a different bounded approach, and preserve repair counts. Continue in-scope work when possible; do not automatically stop or silently weaken requirements.
7. **Escalate only a concrete unresolved decision.** Ask Brad for missing access/authority, a genuinely new product/scope decision, additional budget when the authorized allowance is exceeded, or separately controlled actions such as an Astra assignment or release. Include reproduction, attempts, options/tradeoffs and a recommendation. Routine in-scope choices stay with the supervisor.
8. **Keep useful progress possible.** If one dependency is externally blocked, continue independent approved work with clear ownership. Do not claim dependent acceptance or leave uncontrolled test workers running. Record/stop owned processes at handoff.
9. **Report customer progress.** Updates identify the last passing journey step, current failure, next repair and milestone status. Focused test counts and diagnostics support that story; they are not the outcome.
10. **Commit verified slices, preserve incomplete work honestly.** Separate developer completion from independent QA and supervisor acceptance. Update status/evidence/repair history in this plan and commit only validated scoped work. No push, merge or deployment is implied.

## Validation and environment safety

At development kickoff verify binaries/dependencies. Baseline and release commands include:

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

256M is an explicit diagnostic invocation, not a new minimum or universal customer limit. Record effective memory and runtime. If R0 classifies/splits the suite, record exact maintained release and diagnostic commands here; never silently substitute a narrower suite.

Read guards and phase parameters before using `tests/integration/storage-release-journey.php`, `storage-single-site-scope.php`, `storage-native-recovery.php` and existing source/content runners. Do not run bare `wp eval-file` on Brad's working Local site. Test doubles do not certify native SQL, uninstall or performance. Builds may change generated files; inspect and keep only authorized changes. Verify the actual ZIP version/path without bumping versions just to test.

## Tracking, deferred work and handoff

- **Next ready work:** R1c actual10K production migration, table edit and current-data reversal; independent floor forward conversion can run on its own owned site without production edits. Floor environment ready in `7cac1e4`, actual10K source preparation ready in `b5176db`.
- **Current authorization:** Development, delegated QA, disposable test setup and scoped local commits. No push/merge/release requested.
- **Documentation validation:** The pre-implementation docs check covered links, fences, task IDs and whitespace without claiming runtime results. Current development evidence is recorded below.
- **Statuses:** `pending → developing → qa → validating → done`; use `blocked` / `budget-stopped` with a precise reason.
- **Per-task ledger:** Kickoff `54f7ae2`. R0: developer `/root/r0_journey` (Sol High), QA `/root/r0_qa` (Sol Medium), repairs 0, accepted `c7eda75`. R1a: same developer/independent QA, repair 1 (explicit test memory limit), accepted `fb65305`. Floor support: developer `/root/r1_floor` (Sol High), QA `/root/r0_qa` (Sol Medium), repair 1 (seed guards), accepted `7cac1e4`. R1c source preparation: same floor developer/QA, accepted `b5176db`. R1b: `/root/r0_journey` (Sol High, cross-component copy/verification safety), independently accepted by `/root/r0_qa` (Sol Medium) in this checkpoint; two harness QA repairs, plus the documented performance reassessments before QA. No nested delegation.
- **Shared files:** R1/R2 share engine/settings/repository/lifecycle code. R3 consumes their services. Production ownership and integration are serial; independent fixture-only work may run in parallel as recorded below.
- **Supervisor:** Current session's exact model/effort cannot be independently verified. Developer routing remains explicit Sol High for R0 and Sol Medium for independent QA.
- **Active workers / wakeups:** R1b developer and independent QA have completed the accepted slice. Next dispatches: Sol High for actual migration/reversal and encountered persistence defects (shared production files, one owner); Sol High for independent floor execution/runner-only repairs (separate fixture, no production edits). No scheduled wakeups.
- **Deferred backlog, not follow-on authorized tasks:** Multisite table support/network lifecycle; advanced recommendation tiers/re-notification; convenience reset/stale-copy UI; broader diagnostic/host qualification polish. A future scope decision is required to schedule these. Do not label the first release incomplete merely because this backlog remains.
- **Never implied:** New engine/config work, pushing, merging, deployment. Brad must explicitly confirm any WordPress.org live tag or stable-tag change.

### R0 evidence — 2026-10-22

Independent QA `/root/r0_qa` passed the runner's isolation, manifest/container binding, syntax, native seed and nonmutating refusal checks. Supervisor reran the same refusal. Setup interrupted before writing its manifest can leave an owned orphan requiring explicitly targeted manual cleanup; this is a known nonblocking fixture limitation. No positive conversion/migration acceptance is inferred.

- Disposable source fixture: `/tmp/foogallery-migrate-r0.S1gKqR`; labelled Docker container `fgm-r0-S1gKqR`; database `fgm_r0_s1gkqr` at `127.0.0.1:32768`. WordPress 7.1.3, PHP 8.4.6, MariaDB 11.4.13, FooGallery 3.3.18. Brad's Local database is not used. Manifest and seed-journal checks bind the runner to this fixture.
- Reproduction: `php tests/integration/storage-release-r0.php /tmp/foogallery-migrate-r0.S1gKqR preflight`. Native fixture `a7-options-utf8mb4-20k-v1` persists exactly 2,998,293 serialized bytes. Public CLI preflight returns `foogallery_migrate_storage_calibration_required`; seed, markers and schema remain unchanged. **Product failure, not M1 acceptance.**
- Setup: `bash tests/integration/setup-storage-release-r0.sh`; use its printed path for `seed`, then `preflight`. Teardown: `bash tests/integration/teardown-storage-release-r0.sh <printed-site-path>`. Developer exercised a second complete setup/seed/refusal/teardown cycle successfully; preserve the first fixture for R1. No cached/local PHP 5.4/5.6 runtime was found; obtaining a disposable floor runtime remains R1 work, not a floor waiver.
- Full PHPUnit baseline was attempted with a 150-second process-group deadline and 256M PHP limit. Its output capture failed on invalid UTF-8 in diagnostic output, so no final totals or pass are claimed. A bounded `--stop-on-failure --stop-on-error` attribution run reported 25 tests, 139 assertions, one failure and six skips. The first failure is `ContentIndependentRecordRegressionTest::test_content_descriptor_matches_all_emitted_columns_and_qualified_path` at the exact-host `GenericRetainingModel::supports` assertion; six `CertifiedPreorderRebuildTest` cases skip for the original host. These are **environment-bound implementation diagnostics**; descriptor equality, byte-cap refusal and ordering remain product requirements. No tests have been removed or silently excluded; later suite classification must separate these checks and retain equivalent product coverage, with independent review.
- Focused existing safety baseline: `php -d memory_limit=256M vendor/bin/phpunit --colors=never --do-not-cache-result --filter 'Storage(Preflight|Operations|Authority|SingleSitePolicy|Conversion)Test'`: **41 tests, 436 assertions pass**. Existing tests asserting absent customer calibration are expected to require explicit replacement when R1 changes that architecture; authorization and nonmutation assertions stay.
- `node tests/ajax-js-regression.js` and `php tests/content-scan-regression.php` pass. `php tests/runtime-cleanup-regression.php`: 148 checks pass, explicitly fault-injection connection coverage, **not native cleanup acceptance**. No GitHub Actions workflow exists in this checkout; these direct repository checks are the applicable baseline.
- Next smallest repair: replace the empty host-qualified production policy with measured, conservative release-owned admission, including portable memory checks (the old budget observer requires Linux `/proc`). Rerun the native preflight immediately; conversion, actual migration, reversal and cleanup remain unproven.

Binary-safe follow-up discovery completed (exit 2): **1,621 tests, 309,954 assertions, 158 errors, 87 failures, 20 skips**. This run overlapped R1a edits, so it is a diagnostic inventory, not an immutable before/after receipt. Many errors are source-hash/callsite-bound allocator experiments (`StorageCurrent*` and predecessor trace/composition families); failures also include exact-host allocations and the now-changing absent-calibration assertions. These require explicit diagnostic disposition/replacement coverage in R4, not a blanket waiver. The content-reset and original20K snapshot-stat errors are being reproduced separately to distinguish product failures from host-only assumptions. No whole-suite pass is claimed.

Targeted attribution (`--filter 'test_real_selected_reset_publishes_empty_map_before_replacement|test_callback_free_original20k_stats_do_not_admit_retained_output'`) confirms two errors: the content sentinel fake SQL connection does not implement the current keyset-window query (**fixture defect**, repair while preserving reset-before-side-effect assertions); the snapshot-stat test forces a 128M, original arbitrary-byte fixture through a host-dependent optimized path, falling into generic admission refusal (**performance/diagnostic boundary**, separate native-text positive coverage from lossless byte-codec tests before any retirement). Neither result proves a product data-integrity defect or permits deleting its underlying guarantee.

### R1a evidence — capacity policy (accepted checkpoint)

- Developer `/root/r0_journey`, Sol High; independent QA `/root/r0_qa`, Sol Medium. Scope: small shipped release policy and portable admission, no engine changes. Original host-class APIs remain diagnostics; production no longer selects exact host/certificate classes. Candidate limits: 3 MiB raw, 16x whole-option decode forecast, 128 MiB reserve and 32 MiB per active phase. These are not yet whole-journey/floor qualification.
- Native WordPress hydration of the 2,998,293-byte fixture measured about 21.6 MiB additional allocated memory (~7.2x). Public preflight succeeds at 256M with a 44.5 MiB sampled baseline and ~48 MB decode forecast; `conversion_ready` remains false. The same public path capped at 128M refuses without modifying seed, markers or schema.
- Explicit provision/switch and 11 bounded recover turns copied **11,264 of 20,208 rows**, peak allocated about 66.5 MiB (earlier process RSS samples ~97–110 MiB). Options remain authoritative. The next recover repeatedly hits the existing 10-second deadline after `source-decoded`; the last successful request took 9.875 seconds. Failed requests leave seed and cursor unchanged. Reproduce with `php tests/integration/storage-release-r0.php /tmp/foogallery-migrate-r0.S1gKqR recover`. **R1b must repair growing-prefix copy/verification work; do not simply inflate the deadline.**
- Supervisor PHP lint and whitespace checks pass. Focused validation at the plan's 256M invocation found a new test assuming 128M without setting it (`StorageCoordinatorScaleTest`); test-fixture repair 1 now scopes/restores its limit explicitly. Developer and independent QA then ran `php -d memory_limit=256M vendor/bin/phpunit --colors=never --do-not-cache-result --filter 'Storage(CapacityPolicy|Operations|CoordinatorScale|Preflight|SingleSitePolicy)Test'`: **40 tests, 676 assertions pass**. QA reviewed the exact production diff and found no blocker; R1a is accepted in this checkpoint commit, not M1.
- Directly amended absent-calibration assertions are replaced with release-policy parsing/bounds and low-memory-before-SQL checks; request authorization remains covered. QA also identified two old `StorageBaselineCompositionTest` allocation-release assumptions tied to the removed calibration filter. Reviewer `/root/r0_qa` confirmed their preceding entry-baseline-retention assertions and the direct high-water test pass; unchanged `sample()` retains max(real-size memory). The obsolete allocation-release assertions are classified for explicit release-path composition replacement at R4. The broader maintained suite remains red, not waived or green.

### R1 floor environment — accepted checkpoint

- Developer `/root/r1_floor`, Sol High; independent QA `/root/r0_qa`, Sol Medium. Retained owned fixture `/tmp/foogallery-migrate-floor.WDmNT3`; database `fgm_floor_wdmnt3`, container `fgm-floor-WDmNT3-db`, isolated Docker network. No Local-site data or sibling plugin checkout changes.
- PHP **5.4.45**, WordPress **5.0**, MariaDB **11.4.13 InnoDB**, FooGallery **1.8.8**, migration plugin **1.16** activate successfully. Normal plugin loading also passed with Composer `vendor` masked. Source-runtime evidence, not yet ZIP/uninstall/M1 acceptance.
- Reproducible runtime build: checksum-pinned upstream PHP source in an official PHP 5.6 toolchain, refreshed `config.guess`/`config.sub` for ARM64 and an explicit refusal for obsolete SSLv3 methods missing from the archived OpenSSL headers; TLS retained. This is the documented compatibility build, not an unmodified historical binary or allocator certificate. Image ID `sha256:fe464d50acc3d1f8891902b3ed212ebb0dd2677ca7b81d0b7a4e74795d98d9d0`.
- Floor native20K seed: **2,998,086 bytes**, SHA-256 `b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677`. PHP 5.4 serializes 207 negative-zero values as `d:0;` versus the modern fixture's `d:-0;`; the floor fixture pins its own native persisted bytes, without production normalization or an arbitrary-byte codec waiver.
- Independent QA reproduced `bash tests/integration/run-storage-release-floor.sh /tmp/foogallery-migrate-floor.WDmNT3 eval-file /repo/tests/integration/storage-release-floor-preflight.php --user=1`: preflight passes at **256M**, `conversion_ready=false`, seed/markers/schema unchanged. WP-CLI 2.1 changes its initial memory limit to unlimited, so the script explicitly sets 256M before invoking the public service; the unlimited CLI invocation is not the bounded receipt.
- Setup/run/teardown and seed/preflight scripts are under `tests/integration`. Developer exercised a second clean setup/activation/teardown cycle. The wrapper checks manifest/labels/container IDs/plugin path and reads `DB_NAME`/`DB_HOST` with networking disabled before executing requested WP commands. QA repair 1 now rejects existing storage markers/tables before seed construction/write; a separate disposable site proved both refusals leave the option absent and was removed. `/root/r0_qa` independently accepted the repaired guards, public floor preflight, syntax and setup instructions. Supervisor reviewed the exact files and whitespace check. Preserved fixture remains option-backed and unprovisioned. Accepted environment support belongs to this checkpoint commit; full floor conversion/migration remains pending.

### R1b evidence — bounded copy and verification accepted checkpoint

- Developer `/root/r0_journey`, Sol High. Existing v4 cursor resumed without reseeding. Diagnosis: each turn re-encoded and individually SQL-compared every committed row. Bounded tail readback removed SQL growth, then a fresh-site run exposed remaining prefix-encoding CPU. Reassessment after those two iterations led to topology-only skipping, retaining source hash/cursor checks, aggregate counts, bounded tail proof and exact new-row readback.
- Measured 1,024-row turns still lacked deadline margin; release-only yield reduced to **512 rows**, retaining the same byte/total/source caps and 10-second deadline. Completed late turns use **1,151 session questions** independent of prefix size and mostly take 2–5 seconds. Full option decoding and source hashing remain explicit whole-input costs, not constant-work claims.
- The original fixture reached all 20,208 rows and completed an independent verified→flipped request in 7.292 seconds, about 73.7 MB allocated peak. A fresh second fixture copied all rows but its final whole-destination verification repeatedly hit the 10-second deadline. These failed requests preserved option authority and verified control. That is **not accepted R1b/M1 evidence**; a single successful host timing does not close the repeated failure.
- Increasing final-reader page sizes and reducing SQL queries did not solve the whole-destination decode deadline (a subsequent cleanup attempt still took 13.34 seconds). The one-query reader experiment was reverted; the bounded 256-row metadata window remains conversion-capacity-only under the existing 1 MiB payload cap. The accepted repair uses a persisted, bounded verification cursor, exact source-row/SQL comparison and retained proof under unchanged maintenance ownership before authority/cleanup transitions. Proof binds exact source/head/generation metadata and all destination columns before selection. Supported writes remain blocked between pages; arbitrary out-of-band SQL corruption after a row was proved is not a new guarantee.
- Separate R1c source-fixture preparation completed at `/tmp/foogallery-migrate-r0.T4hhza` (evidence below). No actual10K seeding ran during the isolated failing verification measurements. No table transition or migration runs on that fixture until R1b acceptance. Production files remain exclusively owned by the R1b developer.
- Refocusing after context compaction found no configured OpenRig topology/workspace trace roots. This repository plan, Git state and live worker handoffs remain the direct task evidence; no missing rig configuration is a product blocker.
- Frozen candidate now yields at **256 rows** after 512-row timings approached 10 seconds; caps/deadline are unchanged. The 10-core host briefly reported load averages above 80, with mostly idle owned databases; lower-load validation continues, not a performance waiver. `option_snapshot()` now returns its already-validated logical digest to avoid computing it twice. Exact raw hashes, structural decode and one full logical validation remain; whole-option work remains O(N).
- The second native fixture completed all 20,208 exact proof rows and cleanup with the earlier 512-row candidate (late proof pages 575 questions/2.1–5.0 seconds, cleanup 93 questions/3.044 seconds). Fixture `/tmp/foogallery-migrate-r0.WBuxBn`, database `fgm_r0_wbuxbn` at port 32772, then finished final frozen verification and post-delete recovery: exact table readback before and after stable matched **2,998,293 bytes**, SHA-256 `60392c9d095f334fed1475ccd5cd1e3db5ed1142922627980f5d0bff64072297`. Last full 256-row page: **321 questions / 1.402 seconds**; final 240-row page: **305 / 1.375 seconds**; flip: **109 / 1.037 seconds**. Guarded deletion of the owned source simulated interruption before stable CAS; public recovery reached stable in **0.056 seconds / 63 questions**, allocated peak **53,608,448 bytes**, with all 20,208 rows and exact backup preserved. Supervisor independently confirmed stable/table/20,208 status. C copied under earlier 512-row iterations; this proves resumed final verification/recovery, not an immutable final-candidate whole20K run or M1.
- Public absent-option and present-serialized-empty-option journeys passed on separately owned `T6LRto` and `7Kgc14` fixtures, reaching stable empty table authority and exact `a:0:{}` backup. Supervisor caught the absent-source backup identity edge; regression coverage distinguishes it from a present empty option. QA harness repairs 1–2 added maintenance/schema/table-remnant guards and exact live container ID/label plus journal binding. Fresh `kMZZT8` and `SnX3KD` journeys passed all public phases with the new journal; old journals are status-only and were not rewritten. On fresh owned `DFKxkG`, stale schema version and lock markers each caused init refusal before writes; after removing those test markers, init passed. Independent QA accepted the final repaired runner.
- Independent QA reproduced **107 tests / 25,192 assertions** with filter `Storage(RepresentationLifetime|SnapshotStreamingBoundary|SnapshotReadAdmission|SnapshotMaintenanceAdmission|CapacityCopyTransaction|CapacityConversion|CapacityPolicy|Operations|CoordinatorScale|Preflight|SingleSitePolicy)Test`. Supervisor separately passed **146 tests / 376 assertions** with filter `(LegacyRecordCodec|LegacyStateCodec|StorageConversion|StorageControl|StorageAuthority|LegacyTableSnapshot|LegacyRecordStructuralIndex)Test`, plus content-scan, AJAX/JS and 148 runtime-cleanup fault-injection checks. Both PHPUnit invocations explicitly use `-d memory_limit=256M --colors=never --do-not-cache-result`. These are focused checks, not whole-suite or M1 acceptance.

### R1c source preparation — accepted checkpoint, not migration acceptance

- Developer `/root/r1_floor` (Sol High), independent QA `/root/r0_qa` (Sol Medium). Two new runners only: `tests/integration/storage-release-real-journey.php` and `storage-release-real-seed.sh`. Supervisor requested initial/resumed seed guards for migration data and schema remnants before QA; no QA repair cycle needed. Production changes remain separately uncommitted R1b work.
- Owned fixture `/tmp/foogallery-migrate-r0.T4hhza`, Docker container `fgm-r0-T4hhza`, database `fgm_r0_t4hhza` at `127.0.0.1:32771`. PHP 8.4.6 / WordPress 7.1.3. Source-only seeding used fresh processes of at most 256 attachments, approximately 5m20s plus the initial 9.4s batch. No conversion or migration ran.
- Exactly **10,000** owned attachment posts/files, AIGPL source `10004`, protected content page `10005`. Ordered attachment IDs SHA-256 `48c943771951356744164a9f018a3f41a35c71c3b3c575d21769f2e44fdf224a`; content SHA-256 `40e45aa5a30231bf1cd01a9ef4955bd766aa877bd2af2d951888c19d5e73633b`; PNG SHA-256 `a38a4ff7320a3d8764ac959b264f15e335360d7c1e23a0627dee7f366c95c58f`.
- QA independently passed `php tests/integration/storage-release-real-journey.php /tmp/foogallery-migrate-r0.T4hhza verify-source`, including all IDs/order, metadata and 10,000 file hashes; `status` confirms `ready`, zero turns/completed children, stable option authority and no schema. PHP/shell lint and whitespace checks pass. Completed-fixture reseed and invalid-path attempts refuse before writes.
- Both initial and resumed seeding check raw migration data/control/authority/maintenance/backup/schema markers and schema-table absence. Journal identity binds site/database/container ID. A hard crash between file/post creation and journal persistence safely refuses on collision; this test fixture does not promise automatic orphan reconciliation. Future `turn`/`edit` phases are prepared but unaccepted until exercised after R1b. No M1 claim.

### Usage policy for this development run

Brad explicitly overrode usage-budget gating with “dont worry about budget - just go” after the kickoff request. Continue the scoped work without percentage-budget stops or allowance questions. Retain the workflow's task routing, independent QA, evidence and scoped commits; provider availability and separately controlled actions still apply.

| Item | Current value |
|---|---|
| Authorized allowance / extensions | No percentage cap requested; explicit user override |
| Original baseline and window history | Not captured |
| Cumulative observed development usage | Not tracked as a task stop condition |
| Advisory reserve / remaining allowance | Not set / unknown |
| Checkpoint | 2026-10-22: kickoff authorized; R0 starting at `bbc9448` |

Retain task and repair history on resume. Do not infer scope expansion or controlled release authorization from the budget override.
