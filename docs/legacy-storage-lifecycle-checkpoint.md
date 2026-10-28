# Retained lifecycle foundation checkpoint

This is a tests-only checkpoint for t_60d4f68e, based on ad495074724e7e0ca2cbe04c49c4f0a5eecd7a7e. No production behavior changed. The superseded deactivation export/drop/delete implementation was already removed; do not restore it. Activation/install/deactivation/automatic upgrade remain storage-neutral. Options remain authoritative; physical table repositories are explicitly injected only in disposable fixtures.

## Owned coverage

- `tests/LegacyStorageLifecycleTest.php`: complete option state, historical objects, typed/unknown fields, mappings/revision, started queue/cursor, failed control and sentinel state, backups and maintenance/owner markers survive repeated deactivate/reactivate. A database double throws on every method call, and a separate case removes the global database altogether. Virgin and corrupt option values are retained without repair. Mock scopes are not real multisite evidence.
- `tests/integration/legacy-storage-lifecycle.php`: guarded real WordPress callbacks registered by bootstrap, absent automatic admin upgrade hook, virgin zero-DDL/options, actual single-site and registered multisite blog switching. Lifecycle callbacks run under SQL/site-query/blog-switch rejection hooks. Explicit compact table fixtures preserve identical per-blog identifiers with different destination mappings, queue cursor, history revision and dirty digest heads. Old partial tables and an interrupted shared schema are retained without repair. Entire small fixture option/autoload/table row snapshots are compared byte-for-byte; these snapshots are test-only, not a runtime hydration API. Fixture cleanup has exact table/option read-back and original-context checks.

The harness refuses existing owned tables/options and requires explicit disposable admission. The external runners additionally verify exact MariaDB datadir/database name before admitting writes. No uninstall, provisioning admission, public backend selection or cleanup implementation is added here.

## Evidence and replay

Detailed logs, exact-target runners and `verify-checkpoint.py` / `checkpoint-evidence.json` are under `/home/brad/.hermes/profiles/dev/diagnostics/t_60d4f68e/`.

Run the existing PHPUnit suite and the lifecycle filter; syntax-check both PHP files. Replay real fixtures with:

    bash /home/brad/.hermes/profiles/dev/diagnostics/t_244a5783/wp-disposable.sh --path=/home/brad/.hermes/profiles/dev/cache/scratch/fg-pr15-lifecycle/wordpress --skip-plugins --skip-themes eval-file /home/brad/.hermes/profiles/dev/diagnostics/t_60d4f68e/source-runner.php
    bash /home/brad/.hermes/profiles/dev/diagnostics/t_244a5783/wp-disposable.sh --path=/home/brad/.hermes/profiles/dev/cache/scratch/t_244a5783-multisite/wordpress --skip-plugins --skip-themes eval-file /home/brad/.hermes/profiles/dev/diagnostics/t_60d4f68e/multisite-runner.php

Requires the previously owned disposable MariaDB process, verified running during this lane; never substitute another database. Do not stop this inherited database before the serial integration lane consumes it.

Passing baseline regression coverage, not RED/fix/GREEN: existing no-op bodies already satisfy these tests. Initial test setup errors (bootstrap function collision and missing explicit snapshot revision) were corrected in owned tests only. An initial helper execution permission failure was resolved by invoking its existing Bash interpreter. None is evidence of a production lifecycle defect.

## Integration handoff / limits

No shared production hunks are requested. Preserve the no-op methods and bootstrap-scoped hooks. Integration owns any additional common adapter/engine wiring, global stub support, build and ZIP verification. Confirm the table runtime remains inert and do not infer public admission from directly injected physical tests.

Callback retention is not an end-to-end engine resume or public authority-switch test. Real multisite evidence covers two blogs in one network, not multiple networks. Minimum PHP/WordPress compatibility, package parity, independent review and full Milestone A acceptance remain open. No push, merge, deployment, release or production write is authorized by this checkpoint.
