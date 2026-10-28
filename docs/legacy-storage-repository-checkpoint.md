# Bounded affected-root backend checkpoint (partial Milestone A)

This is a test-backed partial implementation for t_2ac0ed80, based on
4cd3c4f1782d3da255881d864c28ce2764bed063 on
`feature/migrated-store-scalability`. It does NOT complete that card, admit the
runtime table backend, or release conversion/recovery, QA or Milestone B.
Options remain the public default and authoritative backend.

## Owned implementation

- `MigratorSettings::set_migrator_settings()` performs one ordered, non-autoloaded
  option update for a multi-key logical operation. The existing single-setting
  setter delegates to it. Unknown keys and PHP key order remain intact; history
  revision advances once with changed history, never through a second write.
  Explicit history + derived-revision collision and malformed persisted roots
  refuse. Same-value persistence succeeds by exact read-back. Whole-option
  memory usage and existing WordPress option concurrency semantics remain.
- Inert `LegacyTableRepository` uses the exact shared schema, pinned non-replaying
  transaction connection and existing compact codecs. It reads one compact root
  tree, keyset root pages, small scalars and SQL-only root counts. Missing/false/null
  differ; binary values, typed keys, nested children and unknown fields survive.
- `patch_many()` accepts existing map heads with explicit root set/delete patches
  and scalar companions under expected-revision CAS. It locks heads in stable
  hash order, stages only affected trees in the active generation and verifies
  logical/projection parity before one commit. Unrelated records keep physical
  IDs and bytes. Append/delete preserve surviving map order, including gaps.
  Map complete digests become dirty without full-history rehash; small scalar
  digests stay current at their new revision in the same transaction.
- Row/encoded-byte limits apply to the entire operation, including old and new
  affected trees and companions. Scoped metadata discovery and SQL length predicates
  precede LONGTEXT fetch/decode. A cooperative elapsed-time limit is checked
  between queries; it does not cancel a hung DB query or replace manager leases.
  Ambient transactions/disabled autocommit and reentrant calls refuse. An uncertain
  COMMIT/ROLLBACK taints this repository; reconcile on a fresh connection, no replay.

No runtime table selection, provisioning hook, schema change, full-history adapter,
implicit conversion, authority flip, option fallback or destination write was added.
Historical runtime objects still deliberately refuse in these compact seams. Lists
are readable, but list mutation and compact collection-wrapper interpretation are
not admitted. `LegacyTableSnapshot::read_bounded()` correctly refuses map digests
marked dirty; it is not the runtime page API or completed reverse-conversion path.

## RED/GREEN evidence

New unit tests were executed before implementations existed:
- `tests/LegacyTableRepositoryTest.php`: RED 14 missing-class errors; GREEN 14 tests,
  15 assertions. Constructor is inert and malformed/historical/over-budget writes
  refuse before SQL.
- `tests/SettingsBatchTest.php`: RED 3 missing-method errors; GREEN 3 tests,
  15 assertions. One update, non-autoload, same-value, no partial failed batch,
  malformed root and revision collision contracts.
- A later real DB test first failed specifically on missing scalar-digest corruption
  refusal. Scalar digest publication/read verification was then implemented and
  the disposable integration harness passed.

Diagnostics: `/home/brad/.hermes/profiles/dev/diagnostics/t_2ac0ed80/`.
Full exact commands, source/package results and counts are recorded in the external
seven-part implementation handoff. Source PHP unit run: 228 tests / 894 assertions,
plus content scan and AJAX regressions passed. Existing PHP 8.5 ReflectionMethod
setAccessible deprecations are unchanged. No minimum-PHP compatibility or true
multisite acceptance is claimed; multiple blog IDs in one shared-table fixture are
scope/isolation evidence, not a network install test.

## Precise continuation still required on this card

1. Lossless historical runtime-object representation and common logical/runtime
   fixtures for all proposal inventory keys, unknown keys and compact wrappers.
2. Indexed history identifier has/get/getMany/source lookup in exact legacy order;
   bounded log summaries/pages and actual engine/settings backend adapters. Do not
   route large get/set through snapshot replacement or hydrate complete history.
3. Complete bounded list/queue/root mutation contracts and explicit dirty-digest
   verification/export/recovery suitable for the conversion lane.
4. Extend real schema/projection/ownership/collision/budget fault coverage, actual
   multisite/network scope tests and option/table lifecycle parity. Normal activation,
   network activation and admin upgrade must remain zero-DDL; deactivation retains
   every mapping, queue/checkpoint and state head even on DB failure.
5. Only after this backend card's full named acceptance passes may completion
   release t_a2140dc0. That child owns authorized preflight/selection, writer drain,
   conversion, authority backup/sentinel/recovery and cleanup/uninstall selection
   safety. Native independent approval is still a later gate.

No obsolete destructive deactivation hunks were removed: they were already superseded
at the verified baseline. The historical six-file patch remains preserved outside
this repo; no reverse replay, unrelated worktree edit, reset/clean, push, merge,
release, deployment, production DB write, SVN or customer action occurred.
