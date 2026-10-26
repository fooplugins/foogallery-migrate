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

## Subsequent source-query cleanup (review buckets 1 and 2)

The preceding cleanup was committed as `592c2fb`. Brad then authorized the
Envira/Modula placeholder fixes and the Robo template simplification, with
statement/output parity tests. Those follow-up changes are not included in that
checkpoint commit; no push or deployment is included.

Envira and Modula now prepare the post type and published status and use
`$wpdb->posts`; Robo puts its existing template directly inside `prepare()`.
No storage-wrapper changes or new scanner exceptions are included.

- The 27 SQL/output characterization cases passed before the edits (54
  assertions) and after them. With the added preparation contract they pass
  **81 assertions**, covering all three sources, three table prefixes, and
  empty/single/multiple returned rows. The emitted SQL is byte-identical.
- Native WordPress 7.1.3/MariaDB validation passes **66 assertions**, using real
  `wpdb::prepare()` and comparing the complete returned rows and their order
  against the preceding literal statements. Tests cover empty results, published
  galleries, excluded draft/private/trash/inherit rows, and retained Unicode,
  quotes and percent signs in row data. Only an owned disposable
  fixture was used; its records and Docker/site fixture were removed afterwards.
- Full suite: **1,718 tests / 312,063 assertions**, no errors/failures/skips,
  explicit 256 MiB, **3m26.557s**, reported peak **137.61 MiB**. The focused
  source-SQL/Robo suite passes **63 tests / 163 assertions**.
- All three changed production files pass PHP 8.4.6 and 5.4.45 syntax checks.
  Build, exact package/source parity, repeated byte-identical ZIPs and
  `git diff --check` pass. No translatable content changed; the POT timestamp
  was retained. Final ZIP SHA-256:
  `6a1c712ae8c4169a64bbbf5e243a2305e716c23ff6ec20b77edfb53fd3baecbc`.
- Fresh source Plugin Check: **86 errors / 325 warnings**, including **66 SQL
  error flags**. All three targeted SQL flags are gone; normalized comparison
  reports no new findings (three errors and two warnings removed). Other buckets
  remain for separate review. Agent CI was attempted again; no workflows exist.

Follow-up evidence: `/private/tmp/fgm-source-sql-20261026.x1lc5X` and
`/private/tmp/fgm-low-risk-20261026.YVQNZE/source-query-source-full.*`.

## Current storage-helper names

Brad subsequently approved renaming the actively used `LegacyTableReader` and
`LegacyTableSnapshot` to `TableReader` and `TableSnapshot`. Their files are now
`includes/class-table-reader.php` and `includes/class-table-snapshot.php`.
Updated production callers, test fixtures, direct includes, native-loader paths
and runtime fingerprint file lists. Corrected early-stage "inert/not integrated"
comments. No old-name aliases are retained for these unreleased internal classes.
Other storage class names are outside this targeted rename. Historical class
names and scan/package checkpoints above retain their original meaning.

This is a naming-only change: a token comparison of all **85** PHP files under
`includes` against the pre-rename working tree passes after normalizing just the
two class/file substitutions and ignoring comments/whitespace. SQL statements,
stored data formats, table/option names and diagnostic messages are unchanged.
Runtime implementation fingerprints naturally change with the renamed source;
all file bindings still resolve and no policy/identity check was bypassed.

- Full suite: **1,721 tests / 312,065 assertions**, no failures/errors/skips,
  explicit 256 MiB, **2m44.238s**, reported peak **137.61 MiB**. Naming regressions
  verify class/file mappings, absence of the former files/aliases and fingerprint
  bindings; existing snapshot and reader behavior tests were retained and renamed.
- All **87** packaged PHP files pass PHP 8.4.6 and 5.4.45 syntax checks. Packaged
  admin/non-admin native-loader probes pass on both versions without Composer.
  These are startup doubles, not a new native WordPress migration journey.
- The **148** cleanup fault checks, content scan, **64** rendered storage-workflow
  checks, build/package parity, repeated byte-identical ZIPs and diff checks pass.
  No translatable content changed; the POT timestamp was retained. Package SHA-256:
  `9977bc44e3f581c4f409309010fdb914991683dcedd29002308810b537ebcf41`.
- Source Plugin Check remains **86 errors / 325 warnings**, including **66 SQL
  error flags**. No additional suppression was added. Agent CI was attempted;
  no workflows exist, so no CI pipeline ran.

Evidence: `/private/tmp/fgm-table-names-20261026.Cns9i2` and
`/private/tmp/fgm-low-risk-20261026.YVQNZE/table-rename-source-full.*`.
Changes remain local and uncommitted, alongside the preceding query fixes.
No test-site deployment, push or public release was performed.

## WordPress 6.2 minimum

Brad then requested a WordPress 6.2 minimum and a proposal for SQL review bucket
3. The main plugin header, readme requirement and `FOOGM_MIN_WP` now all declare
**6.2**. PHP metadata/syntax requirements remain unchanged. Current requirements,
the distinction from WordPress's own PHP minimum, historical runtime fixtures and
the [proposed three-batch identifier-placeholder rollout](../legacy-storage-upgrade.md#identifier-query-follow-up-sql-review-bucket-3)
are documented. No bucket-3 SQL statement was changed in this task.

- Full maintained suite: **1,722 tests / 312,087 assertions**, no failures/errors/
  skips, explicit 256 MiB, **2m42.018s**, reported peak **137.61 MiB**.
- Startup regressions accept 6.2 and 7.1.3 in admin/non-admin contexts, reject
  6.1.9, 6.1, 6.0 and 5.0 before initialization, and check both metadata headers.
  Packaged 6.2 acceptance/6.1.9 refusal probes pass on PHP 8.4.6; standalone
  syntax/startup probes also pass on PHP 5.4.45. These use startup doubles, not
  actual WordPress installs. A native WordPress 6.2 migration journey was not run
  here and remains part of the proposed identifier-query follow-up validation.
- Build, package/source parity, repeated identical ZIPs, translation format and
  diff checks pass. No translatable content changed. Agent CI was attempted but
  no workflows exist. Final ZIP SHA-256:
  `17f970658a14c9e1e15758eec9de82881cc9640be271aa08c564cc577a33cf86`.

Evidence: `/private/tmp/fgm-min-wp62-20261026.YaUcYA`. All follow-up work remains
local and uncommitted; no push, deployment or Stable tag change was performed.

## Implemented bucket-3 identifier placeholders

Brad authorized the proposed three batches. All **16 statement sites** now bind
their table argument using native `%i`: two storage-admin statements, one
authority statement, two WPPA source reads and eleven snapshot statement sites.
Existing identifier validation, site/revision predicates, limits, ordering,
transaction boundaries, ownership checks and pinned execution remain unchanged.
No compatibility fallback, production SQL abstraction or suppression was added.

Coverage and results:

- Added **50 tests / 107 assertions**: all 16 production SQL templates must
  match their pre-change characterizations except identifier binding, SQL shape
  and value-argument order remain identical across three prefixes, and WPPA's
  identifier rejection remains intact. The shared identifier binder is only for
  existing test doubles; it is not production SQL preparation or native evidence.
- Full maintained suite: **1,772 tests / 312,194 assertions**, no failures/errors/
  skips, explicit 256 MiB, **3m01.945s**, reported peak **137.61 MiB**.
- Native **WordPress 6.2 / PHP 5.6.40** and **WordPress 7.1.3 / PHP 8.4.6**, both
  using real MariaDB 11.4 in new manifest-bound disposable fixtures:
  - **78 checks per runtime**: real `wpdb::prepare()` byte parity for every
    template across three prefixes, actual admin/authority/snapshot queries,
    and WPPA raw-result/order parity before its existing photo eligibility filter.
    WPPA's formerly unquoted table names gain backticks; SQL text is otherwise
    unchanged. The previously quoted statements remain byte-identical.
  - **59 snapshot checks per runtime**: real transactional writes, visibility,
    scoped state, exact values and injected rollback/connection failure paths.
  - **319 history checks per runtime**: real repository mutations and rollback.
  - **26 checks / 14 public receiver dispatches per runtime**: standard release
    policy, provisioning, staged copying, discard of a real prefix, verified
    forward conversion, reversal and exact source restoration, then cleanup.
    This is a native SQL protocol check in one CLI worker per journey, not new
    browser, fresh-worker memory or customer-scale qualification.
  These are **482 checks per runtime**. The WP 6.2 fixture does not activate the
  current FooGallery build, which requires newer WordPress; SQL/storage checks
  need only Migrate and synthetic source tables. They are not a new end-to-end
  gallery migration qualification on the minimum WordPress release.
- AJAX/dialog checks, **64** rendered storage-workflow checks, content scan and
  **148** cleanup fault checks pass. All **87** packaged PHP files pass 8.4.6 and
  5.4.45 syntax checks. Packaged native-loader, build, exact package/source parity,
  repeated identical ZIPs, translation format and diff checks pass. POT content
  is unchanged. Final ZIP SHA-256:
  `2bc99a3063fcca5e2f2d2e82749d50b7c0f10f663e10d576bbc90f0bab9615fc`.
- Plugin Check source: **62 errors / 321 warnings**; production package:
  **59 errors / 320 warnings**. SQL error flags fall from **66 to 42**:
  **37 NotPrepared / 5 DirectDB**. Of the original 36 bucket-3 reports, **24**
  disappear. The remaining **12** are duplicate token reports on the two
  snapshot hash queries using the custom preparation wrapper; all identifiers
  there are now bound and native conversion checks pass. The other **30** SQL
  flags belong to the previously reviewed builder/forwarder buckets. These
  residual findings are not suppressed and this is not a clean Plugin Check
  or blanket security-clearance claim. Agent CI was attempted; no workflows exist.

Native harness setup now accepts an explicit WordPress version and an option to
skip the unrelated FooGallery activation for SQL-only minimum-version checks.
Initial harness problems (overlong fixture extension, comparing filtered photos
to raw rows, wrong provisioning scope) were corrected before the passing runs;
no production guard was weakened to obtain acceptance. The initial 6.2 setup
activated Migrate but returned a FooGallery minimum-version warning; the owned
fixture identity was retained and SQL-only tests proceeded with FooGallery inactive.
Both new owned sites/containers were removed after retaining logs.

Evidence: `/private/tmp/fgm-identifiers-20261026.Un7CYR`; scanner output:
`/private/tmp/fgm-low-risk-20261026.YVQNZE/identifier-*`.
All changes remain local/uncommitted. No test-site operations, push or public release.

## HTML output and exception-path review

Reviewed the **four buffered HTML output findings first**, followed by the
**eleven exception findings**. All fifteen are clear false positives in the
current implementation; no executable production change was needed. Each now
has a line-specific PHPCS comment explaining its producer or display boundary.
There is no file-wide or category-wide suppression.

| Finding | Reviewed locations | Result |
| --- | --- | --- |
| HTML output (4) | `GalleryMigrator::render_gallery_form`, `AlbumMigrator::render_album_form`, `ContentMigrator::render_content_form`, `ContentMigrator::render_content_form_body` table rows | Producers escape by context; preserve form markup and final revision checks. |
| Exception helpers (5) | `SnapshotReadProfile`, `TableSnapshot`, `LegacyStorageSchema`, `LegacyTableRepository`, `LegacyRecordCodec` private `fail` methods | Fixed diagnostics and reviewed definition labels; keep exception data plain. |
| Engine exceptions (3) | `MigratorEngine::__construct`, `assert_storage_writer`, `clear_migrator_setting` | `WP_Error` diagnostics; consumers escape/render as text. |
| Cleanup exceptions (3) | `StorageCleanup::required`, `delete_option`, `drop_table` | Fixed operations or owned resource names; no resource values enter receipts. |

- The gallery, album and content forms escape dynamic text, attributes and URLs
  before buffering. The content table-row renderer does the same. The eventual
  echoes publish this already-escaped form markup after checking its revision.
  Escaping the entire buffer as text would break the controls; removing the
  buffer would expose incomplete forms when the source changes during rendering.
- The five private diagnostic helpers use literal messages and reviewed fixed
  schema, projection or loop labels. The three engine exceptions carry diagnostic
  `WP_Error` messages. The three cleanup exceptions carry fixed operation labels
  or validated, plugin-owned resource names. They do not interpolate stored
  resource values. Exception creation is not an HTML output operation.
- Troubleshooting HTML and cleanup receipts escape diagnostics at display time.
  The storage request UI and its restored session messages use `textContent`.
  JSON and CLI consumers retain plain diagnostic data. Pre-escaping exception
  messages would double-encode HTML displays and alter non-HTML data.

Added regression coverage for all three forms in option/table mode, hostile
source values, preserved controls/nonces, stale-buffer refusal, raw exception
payloads escaped exactly once, all three engine exception branches, status-error
rendering, and successful cleanup SQL contradicted by option/table readback.
The private-helper call-site contracts reject new payload expressions in diagnostic
messages. Browser-script doubles also cover hostile HTTP diagnostics and restored
session messages. The new PHP tests run in isolated processes so renderer globals
and tokenizer/DOM allocator pages do not interfere with the suite's memory witnesses.

Native output checks use a fresh manifest-bound disposable WordPress **7.1.3 /
PHP 8.4.6** fixture: **51 checks** pass using real WordPress escaping and nonce
functions plus DOM inspection. This includes rejection of `javascript:` URLs,
intact form controls and single-escaped support diagnostics. Source records and
repository reads are doubles; this is not browser execution, native table-storage
or migration qualification. The owned fixture and container were removed after
preserving evidence. The existing test site was not changed.

The production executable-token comparison against the prior identifier-query
package passes for all **87 packaged PHP files** (comments and whitespace
excluded). POT message content is unchanged; generated source references reflect
the added comments/formatting.

Final validation:

- Full maintained suite: **1,798 tests / 313,670 assertions**, no failures,
  errors or skips; explicit **256 MiB**, **2m40.200s**, reported peak **137.61 MiB**.
  The new targeted PHP coverage is **26 tests / 1,476 assertions**.
- AJAX/dialog regressions, **66** rendered storage-workflow checks, bounded
  content scanning and **148** repository cleanup fault checks pass.
- All **87** packaged PHP files pass PHP **8.4.6** and **5.4.45** syntax checks.
  Build, exact source/package parity, repeated byte-identical ZIPs, translation
  format and diff checks pass. Existing POT template header warnings remain.
  ZIP SHA-256: `a523ae32f259969e5a574e281e648ff59b0e08d34fa27206aa88f4bc3309ad59`.
- Agent CI was attempted; the repository has no `.github/workflows` directory,
  so it could not run a workflow. The checks above were run directly.

Plugin Check source now reports **47 errors / 321 warnings**; the production
package reports **44 errors / 320 warnings**, down from **59 packaged errors**.
All four `OutputNotEscaped` and eleven `ExceptionNotEscaped` findings are gone.
The remaining packaged errors are **37 NotPrepared**, **5 DirectDB**, the existing
pinned `mysqli_query` call and the deliberately held Stable tag mismatch. This is
not a clean Plugin Check or blanket security-clearance claim.

Evidence: `/private/tmp/fgm-output-review-20261026.hir5pb`; scanner output:
`/private/tmp/fgm-low-risk-20261026.YVQNZE/output-boundary-*`.
Changes remain local/uncommitted. No push, deployment or public release.

## SQL preparation and escaping annotations

Reviewed the remaining **42 preparation/escaping error reports** and added
**24 line-specific PHPCS comments across 23 statement/forwarding sites**. The
extra comment covers the second line of one multi-line statement. Only the
specific `WordPress.DB.PreparedSQL.NotPrepared` and
`PluginCheck.Security.DirectDB.UnescapedDBParameter` reports are suppressed;
other query/cache warnings remain visible. This pass covers the previously
reviewed error-report buckets, not the remaining source-adapter interpolation
and placeholder warnings.

- `TableSnapshot` and `TableReader` builders use fixed SQL expressions and table
  names from `LegacyStorageSchema::definitions()`, which validates the installation
  prefix and supplies the two owned suffixes. Values are passed through native
  WordPress placeholder preparation, via the pinned adapter where required.
  The generated insert uses only definition-owned columns/types and placeholders
  for every non-NULL value. The conversion head limit is a validated integer
  policy value. Neither arbitrary table names nor request SQL reach these builders.
- The two raw-option hash reads already use `%i` and `%s`. The scanner does not
  recognize preparation through the custom connection; each comment identifies
  its native `wpdb::prepare` delegation.
- The private read/write wrappers receive prepared statements or fixed session,
  transaction and schema-inspection commands. Source-count queries use fixed
  aggregate templates, validated identifiers and prepared values. Comments identify
  these callers rather than suggesting that a forwarding wrapper escapes SQL.
- `LegacyTransactionConnection::prepare` forwards the template and arguments to
  native WordPress preparation after checking the pinned connection. Its separate
  direct-`mysqli_query` restriction remains unsuppressed: that intentional,
  non-replaying transport decision is outside this preparation/escaping pass.

Executable-token comparison against the preceding output-review package passes
for all **87 packaged PHP files**. No SQL text, argument order, result processing,
identifier validation, transaction behavior or memory policy was changed.
The existing SQL characterization, identifier, invalid-prefix, cursor, snapshot,
cleanup and pinned-connection regression coverage remains applicable.

Validation:

- Full maintained suite: **1,798 tests / 313,669 assertions**, no failures,
  errors or skips; explicit **256 MiB**, **2m40.754s**, reported peak **137.61 MiB**.
- All **87** packaged PHP files pass PHP **8.4.6** and **5.4.45** syntax checks.
  Build, exact package/source parity, repeated byte-identical ZIPs, translation
  format and diff checks pass. POT content/references are unchanged; timestamp-only
  regeneration was discarded. ZIP SHA-256:
  `d5bc868b86b056bd4c7e05127dd1bbb10e176db108a04794a77a0476665b752c`.
- Plugin Check source: **5 errors / 321 warnings**; package:
  **2 errors / 320 warnings**. A normalized comparison confirms exactly **37
  NotPrepared and 5 DirectDB errors removed**, no new findings and unchanged
  warnings. The remaining package errors are the intentional pinned `mysqli_query`
  restriction and the held Stable tag mismatch. The source-only three hidden-file
  errors are not shipped. This is not a clean Plugin Check claim.
- Agent CI was attempted; no `.github/workflows` directory exists, so the
  maintained tests above were run directly. No native database journey was
  repeated for these comment-only edits; executable code is unchanged.

Evidence: `/private/tmp/fgm-sql-comments-20261026.5rdc05zn`; scanner output:
`/private/tmp/fgm-low-risk-20261026.YVQNZE/sql-comments-*`.
Changes remain local/uncommitted. No push, deployment or public release.
