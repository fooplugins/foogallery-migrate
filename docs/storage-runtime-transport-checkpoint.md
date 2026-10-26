# Runtime writer transport checkpoint — Milestone A remains incomplete

Continues `storage-pinned-transport-checkpoint.md` on SAME `t_396d60fe`.
No stale-owner admission, backend, task graph or production calibration is added.

## Integrated runtime boundary

The production MigratorEngine table repository now uses the ownership gate's
exact LegacyTransactionConnection instance, not another adapter around the same
mysqli handle. Repository uncertainty therefore invalidates marker ownership
and runtime authority together. Runtime control, sentinel, virgin-option/table
probes and option-mode refusal use the supplied writer's pin rather than healthy
global wpdb. Quiescence cannot affirm a complete drain through an unusable pin.
No read-only/injected backend is credited as the production writer integration.

Three unit regressions expose healthy-wpdb authority fallback and false quiescence
before the fix. A disposable native regression additionally requires identical
repository/gate adapters. Production has no new fault-injection or query-hook API.

## Receipt-loss fixture, not a hard-death or network-fault proof

`storage-runtime-transport.php` uses the actual conversion coordinator and engine
writer with a test-only LegacyTransactionConnection subclass installed at the
existing gate boundary. One scenario performs a real native scalar update and
COMMIT, then explicitly discards its acknowledgement and latches the pin unusable.
Another acknowledges that same real COMMIT, then discards the next real runtime
authority read. The latter mutation succeeded; the subsequent runtime read is
refused. Neither is PHP SIGKILL or an actual dropped server/network response.

An independent native observer checks committed scalar presence/value, retained
writer identity and exact marker bytes at loss, unchanged control/sentinel/backup,
and SQL isolation of other blogs. Healthy global wpdb cannot rescue authority;
cached and fresh engines refuse; shutdown retains the uncertain marker. An
independent request can claim/release its own maintenance marker but cannot
assert readiness through the retained writer. No rollback, release, reacquisition
or SQL dispatch is permitted on the unusable pin. Explicit owned fixture cleanup
is not a product recovery or takeover policy. Source/ZIP receipts, exact identity
and executed counts belong in the diagnostic handoff, not inferred from this map.

## Still unaccepted

Ordinary option writes and every output/batch path have NOT all been converted to
this runtime pin. No complete writer/output drain, hard-PHP-death recovery, full
uncertain transaction/readback matrix, arbitrary schedules, connection-ID reuse,
clock agreement or separate-process DDL proof is credited. Old v1 markers remain
blocking without dead-owner/drain proof. A shared failure latch is necessary, not
sufficient, for safe recovery and never permits stale-owner admission by itself.

No live production calibration, selection/preflight UI, safe supported-floor
decoding, literal PHP5.4.0/WP5.0 or PHP5.6.20/WP6.0 receipts, delayed teardown,
lifecycle/browser/204-image/large-AIGPL or independent exact-candidate acceptance
is credited. Preserve `task_scope_completed=false`, keep SAME card OPEN and retain
`t_7dc60149`/`t_8552160b` dependency holds. No PR update, push, merge, deployment,
release, SVN, customer contact or compatibility waiver. class-init.php and the
mixed-newline content migrator remain untouched.

Next bounded integration: complete actual writer/output drain and independent
hard-PHP-death/uncertain-readback evidence before any stale-owner admission.
