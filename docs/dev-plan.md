# Legacy storage upgrade — first-release plan

**Updated:** 2026-10-22

**Status:** Development authorized on 2026-10-22; R0 starting under the approved five cuts.

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

R0–R4 replace the previous S0–S9 sequence. R0 is starting; R1–R4 remain pending. Independent QA is GPT-6 Sol Medium, separate from the developer. There are no accepted implementation commits yet.

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

- **Active task:** R0 — prepare an owned disposable runner and reproduce/classify the first customer-path failure.
- **Current authorization:** Development, delegated QA, disposable test setup and scoped local commits. No push/merge/release requested.
- **Documentation validation:** File/heading links, code-fence balance, active task IDs and whitespace checked across the three changed documents. No runtime tests were run or runtime pass claimed for this update.
- **Statuses:** `pending → developing → qa → validating → done`; use `blocked` / `budget-stopped` with a precise reason.
- **Per-task ledger:** Record developer model/reason and agent ID, separate Sol Medium QA ID, repair/reassignment history, commands/results, milestone gaps and accepted commit. Currently all unassigned, repairs 0, runtime checks not run, no implementation commits.
- **Shared files:** R1/R2 share engine/settings/repository/lifecycle code. R3 consumes their services. Default is serial ownership and integration; no parallel work scheduled.
- **Supervisor:** Current session's exact model/effort cannot be independently verified. Developer routing remains explicit Sol High for R0 and Sol Medium for independent QA.
- **Active workers / wakeups:** None created for this plan.
- **Deferred backlog, not follow-on authorized tasks:** Multisite table support/network lifecycle; advanced recommendation tiers/re-notification; convenience reset/stale-copy UI; broader diagnostic/host qualification polish. A future scope decision is required to schedule these. Do not label the first release incomplete merely because this backlog remains.
- **Never implied:** New engine/config work, pushing, merging, deployment. Brad must explicitly confirm any WordPress.org live tag or stable-tag change.

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
