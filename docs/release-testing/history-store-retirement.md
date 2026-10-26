# Prototype history-store retirement

Date: 2026-10-26. Branch: `develop`. Local changes only; no commit, push,
deployment, merge, public release or Stable tag update is included.

## Implemented scope

- Removed the unused PR #15 `MigratedStore`, its prototype SQL/memory paths,
  no-op lifecycle wrappers/hooks, old-only tests and synthetic benchmark.
- Made `OptionMigratedStore` standalone. Its constructor now accepts settings
  and an optional option backend, not a database. The current
  `MigratedHistoryRepository` still delegates to it in option mode. Updated
  engine injection types and callers without changing migration decisions.
- Current single-site table names, based on `$wpdb->base_prefix`, are
  `foogallery_migrate_state` and `foogallery_migrate_records`. Updated schema,
  authority, cleanup and test fixtures consistently. Codec/reader class names
  and the current engine's logical storage format are not renamed or redesigned.
- Removed prototype table/option probes and cleanup. As explicitly agreed,
  there is no upgrade, rename, compatibility fallback or cleanup for unreleased
  prototype resources or the previous `*_legacy_*` names. Existing pre-release
  installations using those names are not supported upgrade fixtures. Do not
  deploy this over their active table-backed state expecting an automatic rename.
- Uninstall now uses `StorageCleanup` directly. Single-site retains the existing
  guarded cleanup, readback and refusal behavior for current resources. Multisite
  remains option-only: remove each site's data, preferences and discovery summary,
  restore the caller's blog context, and abort plugin deletion on incomplete or
  thrown deletion. It does not inspect, provision or drop network tables.
- Kept the previously requested, exact-rule false-positive comments. No new broad
  scanner suppression was added. Updated the changelog and translation template;
  version remains `1.16.1` and public Stable tag remains `1.16`.

No test-site operations were performed. No v2 engine work was started.

## Validation

- Full maintained PHPUnit suite: **1,691 tests / 311,971 assertions**, no failures,
  errors or skips; PHP 8.4.6, explicit 256 MiB, **2m47.248s**, reported peak
  **137.61 MiB**. Retired-only tests were removed, not skipped. Previously mocked
  SQL-counter assertions now exercise real option history/revision behavior;
  maintained table-scale and native history tests cover actual table behavior.
- New regressions verify the independent class, exact prefixed table names,
  engine injection, current-only cleanup, and multisite uninstall success,
  failed-delete and thrown-delete paths with context restoration. The isolated
  uninstall entrypoint tests also pass against the extracted production package
  on PHP 8.4.6 and PHP 5.4.45, without Composer or the plugin bootstrap. Those
  isolated tests use WordPress doubles, not a real network database.
- Native WordPress **7.1.3 / MariaDB 11.4**, in newly created manifest-bound
  disposable fixtures, separate from Local foo and the remote test site:
  - **39** option-foundation checks, including default persistence, retained
    lifecycle state, no implicit provisioning and fail-closed current authority.
  - **319** real history-mutation checks against the renamed tables, including
    option/table parity, atomic batches, revisions and SQL rollback faults.
  - Extracted production ZIP: **23** single-site checks through the actual
    `uninstall.php`, including real table history writes, removal of both current
    tables/options, and byte-exact preservation of gallery/attachment post rows,
    post metadata and an unrelated option.
  - Source: **34** checks on a real two-site network through `uninstall.php`,
    including per-site option removal, original-context restoration, no migration
    tables created, and retained gallery/attachment rows, metadata and unrelated
    options. Native SQL failure paths are not newly injected here; failure/context
    cases are covered by isolated regressions and existing cleanup fault tests.
- AJAX, shared progress-dialog, **64** rendered storage-workflow checks, content
  scan, and **148** cleanup fault-injection checks pass. The latter workflows use
  test doubles and are not new browser/native SQL acceptance claims.
- All **87** production PHP files pass PHP **8.4.6** and **5.4.45** syntax checks.
  Packaged startup probes pass for admin, non-admin, native class loading and
  unsupported WordPress. PHP 5.4 checks do not establish a full minimum-WordPress
  migration journey.
- `npm run build`, production package/source parity, safe paths/CRC/no symlinks,
  and byte-identical repeated ZIP builds pass: **93 production files**, no dev
  files. SHA-256:
  `bc44257340fc5887beba2280b8278c8e0bf01154792386ca5f5f08f758b0330d`.
- Translation format validation and `git diff --check` pass. `msgfmt` reports only
  the existing template-only missing revision-date/language header warnings.
- Agent CI was attempted: there is no `.github/workflows`, so no CI pipeline ran.

## Remaining Plugin Check findings

Plugin Check 2.0.0, WordPress 7.1.3, PHP 8.4.6; same static scan scope as the
preceding comment-only checkpoint. A zero command exit does not mean a clean scan.

| Scan | Errors | Warnings |
|---|---:|---:|
| Source after prior false-positive comments | 132 | 336 |
| Current source | 89 | 327 |
| Current production package | 86 | 326 |
| Package security category | 25 | 96 |

The **112 SQL error flags are now 69**: 59 `PreparedSQL.NotPrepared` and
10 `DirectDB.UnescapedDBParameter`. Removing the prototype retired 43 SQL
error flags; the remaining SQL paths still need their previously discussed
review/disposition. This cleanup is not a blanket security clearance or a clean
Plugin Check result. Source/package differences include excluded hidden dev files.

Private evidence: `/private/tmp/fgm-retire-history-20261026.QlYYyZ`.
Scanner output: `/private/tmp/fgm-low-risk-20261026.YVQNZE/retirement-*`.
Initial full-suite failures identified stale test SQL-name matchers and the
retired benchmark test; corrected before the passing full run. An initial loader
probe used an unsupported mode and ran no validation; all four supported modes
were subsequently rerun successfully. Both newly created disposable WordPress
fixtures and their owned Docker containers were removed after preserving logs.
