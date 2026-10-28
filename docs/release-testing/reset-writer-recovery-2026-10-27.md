# Reset recovery of expired writer markers

This local change adds expired-writer recovery to the existing **Reset migration
progress** action (including its Clear Migration History route). There is no new
button and no automatic recovery during page loads, storage switching or uninstall.
Stop running migrations before resetting; writer admissions use a five-minute renewable expiry.

## Behavior

- Existing administrator capability, nonce, site/confirmation and single-site
  checks remain mandatory. Reset acquires its maintenance gate first, checks the
  schema guard, classifies a bounded marker set, then checks quiescence twice.
- Valid expired markers belonging to this site are removed using exact name/value
  comparisons and option-cache invalidation. Live, malformed, changed, foreign or
  oversized markers, uncertain SQL, and more than 100 markers refuse recovery.
- New writer requests hold a per-marker advisory lock on their pinned database
  connection. Even an expired writer blocks Reset while that lock is held.
  Normal completion releases it; the database releases it on disconnect.
  Writer assertions refuse a lost connection/lock instead of admitting a new owner.
- Old unreleased lockless markers can be recovered by explicit Reset once expired.
  Their old records cannot identify a surviving PHP worker; old migration requests
  must be stopped before updating/resetting. This intentionally replaces the
  previous operator-only recovery policy for well-formed expired writer markers.
- Recovery then uses the existing reset path: history/state are cleared, while
  preferences, selected backend, migrated output and media are kept. A changed
  marker aborts state cleanup and retains the changed owner. Receipts include
  confirmed removals even if a later marker cannot be removed.
- Maintenance, schema, conversion and cleanup ownership are not force-deleted.
  Remove all/uninstall still require clean writer drain; Reset provides the
  explicit recovery step first.

## Validation

- Added regression coverage for three old-format markers, invalid/live/foreign
  records, SQL and liveness failure, authorization, schema guards, bounded reads,
  exact-value races, partial recovery receipts, lost writer ownership and an
  expired-but-connected writer. Existing gate/authority SQL doubles now model
  connection locks; public request guards have not been bypassed.
- Focused reset/gate/cleanup/authority/receiver tests: **82 tests / 573 assertions**
  at **256 MiB**. The native test also runs at 256 MiB.
- Native `tests/integration/storage-reset-writers.php`: **14 checks** on MySQL
  8.0.35 using two independent real connections and an owned pair of fixture
  tables. A live expired writer blocks reset; disconnect permits reset; history
  is cleared, preferences remain, the old writer cannot resume, and retry works.
  Fixture tables are removed; no real migration data or output is reset.
- Shared-dialog, AJAX and 66 rendered storage-admin workflow checks pass.
- Production PHP syntax passes on PHP 5.4.45 and PHP 8.4.6. The package has exactly
  93 production files (87 PHP files), without tests/development files.
- Agent CI has no GitHub Actions workflow to run in this repository.

The full-suite runner uses 512 MiB. An earlier 256 MiB monolithic run hit the
release receiver's conservative headroom checks as test objects accumulated;
those receiver tests pass independently at 256 MiB. Production memory budgets,
conversion limits and admission policy are unchanged. The final full-suite run passed **1,847 tests / 313,849 assertions**, PHP 8.4.6,
with peak memory 139.61 MiB.

## Plugin Check

Plugin Check **2.0.0**, with the same early runtime wrapper/check set as the
previous validation, reports **2 errors / 255 warnings**. Finding identities
(file, severity, code and message) match the previous build; only source line
positions move. Errors remain the deliberate pinned `mysqli_query` transport
and **1.16 / 1.16.4** unpublished Stable tag mismatch. This is not a clean Plugin
Check result or public-release approval.

The skill's first direct invocation did not initialize Plugin Check 2.0's required
constants. The established wrapper was then used for the completed check; the
failed invocation is not counted as validation.

The remote test site remains with FooGallery Migrate uninstalled after the
previous authorized uninstall test. This work does not deploy, push, merge or
change the WordPress.org Stable tag.

Private evidence: `/private/tmp/fgm-reset-writers-20261027`.


## Local artifact

The working-tree candidate remains **1.16.4**, following base commit `dbe7033`;
no version bump or deployment was requested for this follow-up.
`dist/foogallery-migrate.v1.16.4.zip` contains the current source and regenerated
translation catalogue. SHA-256:
`20a93b50208c340ec8504d820f3407dac126fd992dc680705721958f3c3e5be9`.
The repeated ZIP-stage build is byte-identical. This supersedes the local ZIP
payload from the earlier main-site test; it is not a claim that the new reset
recovery was deployed in that earlier test.
