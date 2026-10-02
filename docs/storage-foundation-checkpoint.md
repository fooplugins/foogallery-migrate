# PR15 storage foundation: partial checkpoint

Task: t_3a8f9a3c. Branch: feature/migrated-store-scalability.
This is NOT Milestone A completion, independent acceptance, or a release.
No push, PR update, merge, deployment or production operation was performed.

## Implemented

- Superseded destructive deactivation delta preserved outside the repository.
- Retention commit: 8d3e12f99882f498c95e283efabe821704f612d0.
- Activation, network activation and the old admin upgrade hook create no tables.
- Default engine history uses OptionMigratedStore; the legacy table store remains
  available for compatibility tests, not automatically selected at runtime.
- Option history/revision changes use one option write, retain other buckets,
  preserve noncompact objects on reads and fail closed on malformed history.
- Corrupt root options cannot be replaced by an unrelated operational write.
- StorageAuthority blocks persisted control/sentinel/maintenance/old installation
  evidence and old per-site tables. Shared state/records probes use base_prefix
  and check current-blog rows, including orphan records. They create no resources.
- A 204-image option-mode regression finishes across fresh engines and verifies
  repeat execution does not create destinations again. This uses fake WP fixtures.

## Verified checkpoint

PHPUnit: 90 tests, 614 assertions passed on PHP 8.5.4. Existing PHP 8.5 reflection
method deprecation notices remain in MigrationFlowTest.
Changed/new PHP files passed php -l; git diff --check passed.
npm run build passed; the generated ZIP passed unzip -tq.
Real WordPress 7.1.2-src / MariaDB 11.8.6 / PHP 8.5.4 harness passed 47 checks,
including lifecycle retention, default option mapping, current-blog authority
isolation, and read-back verification of fixture cleanup. These are checkpoint
checks only: they do NOT prove conversion, complete multisite behavior, browser
flows, large-job performance or PHP 5.4 compatibility.

Replay tests/integration/option-storage-foundation.php only on an explicitly
disposable WP site, with plugins/themes skipped and FOOGM_STORAGE_TEST_DISPOSABLE
set to true. The header documents the WP-CLI command. It refuses existing owned
tables, restores option values and deletes only fixture-created tables/posts.

Diagnostics: /home/brad/.hermes/profiles/dev/diagnostics/t_3a8f9a3c/
Preserved delta: paused-deactivation-complete.patch
SHA256: 6de47194708179d97c7b28deaa98ef81ea146f198b15fce1aa02aff0a68fa540
Evidence: checkpoint-final-unit.log, checkpoint-final-build.log,
option-storage-integration.json, option-storage-integration.stderr,
option-204-regression.log and the RED/GREEN regression logs.

The disposable DB uses the preserved scratch mariadbd binary (the system binary
was confined from the scratch directory). Its datadir and socket are under
/home/brad/.hermes/profiles/dev/cache/scratch/fg-pr15-lifecycle/.
PHP 8.5 mysqlnd/mysqli extensions are under ~/.local/opt/php85-mysql/usr/lib/php/20250925/.
No credentials were printed or changed.

## Required continuation before review

1. Implement the approved shared legacy_state/legacy_records schema and bounded
   repository/codec seams from docs/legacy-storage-upgrade.md. Do not adopt a
   substitute history schema; preserve exact identities, ordering and unknown data.
2. Add capability/nonce/scope-protected preflight and explicit selection. Measure
   raw option bytes before decoding and refuse unsafe web conversion truthfully.
3. Implement writer draining, maintenance/checkpoints, verification, authority
   flip and safe recovery for options and existing PR15 table installations.
   The current conservative authority gate blocks these installations; it is NOT
   their completed conversion/recovery interface. Never delete markers to bypass it.
4. Complete real conversion/failure/interruption/multisite/uninstall and browser
   checks against the built package; separately test minimum runtime if available.
5. Freeze an exact full Milestone A candidate and request fresh independent native
   review. Do not release t_1eb4b573 or the publication lane on this checkpoint.

The option path intentionally retains whole-history cost. No large-job bounded
memory, exactly-once or never-OOM claim is made. A conversion lease must eventually
protect every read/write path; the current engine bootstrap gate alone does not
provide concurrent-writer draining.
