# Bounded common history read checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `2763288a35aa9c94cf5c061d2ff2c24ed8477d43`.
This is an authorized bounded continuation, NOT completion of integration or Milestone A.

## Implemented and explicitly admitted

- `MigratedHistoryRepository` accepts an explicitly injected `LegacyTableBackend` for bounded reads. Its default and all option-backed operations still delegate to the sole option-history owner, `OptionMigratedStore`. Construction remains inert; no authority selection, provisioning or option fallback is added.
- Typed unique identifier presence/get-many/get, wrapped history item count, logical scalar revision, exact indexed source identity with a string plugin and nonempty scalar source ID, and keyset history/log pages hydrate through the existing settings codec. Numeric-looking string keys which PHP would coerce, duplicate identifiers and invalid/oversized requests explicitly refuse.
- Multi-key head/root metadata reads load no collection items. Known migrated compact item-root layout and a nonnegative integer logical revision are required; old header-root/raw history layouts are not silently reinterpreted or converted.
- A closed repository `history_read` operation shares one row/encoded-byte budget across both metadata checks and the selected bounded read. Heads/root metadata must be unchanged before returning. Oversized payloads, corrupt metadata, unavailable/wrong-blog heads and stale contexts fail closed.
- Logical pages translate physical CAS revision to the independent legacy history revision. Cursors bind the physical history head and scalar revision head; changes to either invalidate continuation. Filters and insertion order remain the physical indexed contract; exact full terminal pages return a null cursor.
- Logical exact-source lookup preserves the option policy of first positive target regardless of the separately stored migrated flag. Two fixed-flag ordered selectors use the existing exact-source index, avoiding duplicate-identity filesort; only the earliest candidate tree is hydrated. The existing physical migrated-flag policy is unchanged. Empty source IDs explicitly refuse because the unchanged projection conflates null and empty IDs; no scanning or schema change is used to pretend parity.
- Table writes/atomic companions, whole snapshots/offset exports, source URLs, null-plugin source matching and logical aggregate log summaries remain explicitly unadmitted. Public engine adoption is still absent. Physical APIs, wrapper metadata, schema/indexes, canonical digest domain and storage-neutral lifecycle are preserved.

## Verification and provenance

Detailed replayable commands, actual outputs, RED/GREEN, package evidence and seven-part handoff:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/history-read/`.

- New focused missing-method/parity RED, then GREEN. Preserved baseline production package bytes are checked against the baseline Git objects before real WordPress RED: explicit table logical-history construction refuses on the previous package. A second real RED demonstrates the null/empty source-ID collision refusal gap before its fix.
- Full PHPUnit: 284 tests / 1290 assertions, exit 0 with existing PHP 8.5 reflection deprecations excluded (`error_reporting=24575`). Content-scan and AJAX/JavaScript regressions pass.
- Real guarded WordPress 7.1.2 / MariaDB 13.0.2 / PHP 8.5.11 source history harness: 48 checks on single-site and 48 each on actual registered multisite blogs 1 and 2. Includes strict metadata/typed keys/null/false, option/table hydrated parity, independent revision, shared budgets, indexed source ordering and native EXPLAIN without filesort, stale physical/scalar cursors, dirty digest policy, no fallback/options mutation and read-back cleanup.
- Source wrapper/shared/option/physical/lifecycle regressions: single-site 29/27/39/73/79 checks; each multisite blog 29/27/48/76/148 checks. Each disposable runner verifies exact installation/database/socket and real blog admission before execution. Direct unadmitted history harness exits 255 as required.
- Serial build, archive CRC/safe extraction/all-source-byte parity, and extracted-package replays are recorded in the final machine evidence. Do not treat source checks alone as package acceptance. A bounded read-only reasoning review found the source-index ordering and null/empty projection issues; executed regressions and refusals address them. This is NOT independent native acceptance approval.

## OPEN / next action

Fresh same-card bounded continuation: failing contracts for logical history mutations/no-op revision/atomic companions and bounded summary/source-URL/null-plugin admission; then other common adapters and explicit engine adoption, without a parallel option owner or unbounded fallback. Keep `t_39cfe477` and all acceptance/publication/actual-merge children gated.

Minimum declared PHP/WordPress, MySQL server, true multi-network, browser/customer-memory/large-job scaling, secure selection/conversion/writer draining/recovery and independent exact-candidate approval remain OPEN. Encoded bounds are not heap/OOM guarantees. The original customer memory limit is unknown. No new product decision is needed for the already-authorized continuation.

No parent closure, push, PR update, merge, deploy, release/SVN, production data or customer contact is part of this checkpoint.
