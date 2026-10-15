# Production snapshot callback/maintenance remediation checkpoint

Implementation-only continuation of `t_396d60fe` from
`46a859e0e577388c1a2fd2534e5274740e5b67ea`, under
`ops-run565-continuation-authority.md`. This addresses the independent R1/R2
findings; it is not independent acceptance, Milestone A completion, admission
calibration approval, native persistence/recovery proof, or publication authority.

## Production changes

- `LegacyTableSnapshot::replace_many()` rolls back and clears busy state for
  modern `Error`/`TypeError` from the post-staging admission callback, just as
  for `Exception`. Separate Exception/Throwable catches retain PHP 5 syntax;
  modern testing does not establish literal legacy runtime compatibility.
- `StorageConversion::table_snapshot()` checks the existing operation-owned
  baseline/policy before dirty-head digest maintenance, not only afterwards.
- `verify_digest()` and `maintenance_head()` reuse that same optional callback
  before/after root payload hydration, after physical statistics, around material
  export-page/reconstruction work, and before digest finalization/publication.
  Early physical row/payload bounds use the existing repository budgets. The
  callback introduced into publication is covered by both Exception/Throwable
  rollback cleanup. Uncertain commit outcomes retain the existing semantics.
- No public guard, profile, numerical policy, coefficient, authorization/nonce,
  site/blog, authority/CAS, option default, or schema activation path changes.
- Only the coordinator hash in the existing finite option/decode trace binding
  changes; its modeled callsites precede the edit and do not move. This binding
  refresh adds no modeled path or complete memory-bound credit.

## Test-first evidence and limits

Diagnostics live outside FooProjects at
`/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/receiver-production-remediation-checkpoint/`.
`red.stdout.txt` preserves an initial spy-correction failure.
`red-corrected-spy.stdout.txt` then reproduces six intended failures in 16 tests:
Error/TypeError missing rollback, coordinator dirty-head payload access before
refusal, and absent direct maintenance/page/digest admission. The ordinary
nonempty binary collection already passes on the starting production behavior.
`green.stdout.txt` records the first 16-test/107-assertion pass after production
changes. `working-full.stdout.txt` preserves three correctly invalidated exact
coordinator bindings. After rebinding and extra publication cleanup controls,
`working-full-rebound.stdout.txt` passes 750 tests/16101 assertions (three existing
PHP 8.5 ReflectionMethod deprecation notices).

The maintenance tests execute the real coordinator-created snapshot, private
coordinator table snapshot, repository export, reconstruction, digest and
publication paths. Gate/connection behavior is explicitly a query spy; successful
COMMIT/ROLLBACK commands here do not establish persisted SQL state or physical
transaction cleanup. Ordinary useful admissions and blog-scoped queries are
covered alongside refusal. Exact committed source plus matching ZIP test/build,
origin and hash receipts are recorded separately in `checkpoint.json`.

Complete concurrent-live/NEW/VM/reference/observer/arena/failure/cleanup and
rejected-graph/useful-fit bounds remain open. No invocation-owned disposable
fullWP/SQL fixture identity, usable pinned non-replaying connection and explicit
ownership/cleanup permission was supplied; the existing single request remains,
without a fixture hunt, shared config/credential access, provisioning, or an
inference that SQL is unavailable. Persisted source+ZIP conversion and interrupted
copying/verified/flipped authoritative recovery remain required. Exact-final
PHP5.4.0/WP5.0 and PHP5.6.20/WP6.0, delayed teardown/32-bit and the retained
independent review/publication/actual-merge gates are not waived.

Stop as SAME-card SCHEDULED PARTIAL_CHECKPOINT, task_scope_completed=false,
children_released=false. OPS receives this exact candidate before a separate
fresh independent exact-candidate review. No push, merge, deployment, release,
SVN, production/customer write, child release or self-approval.
