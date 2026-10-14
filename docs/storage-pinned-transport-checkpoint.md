# Pinned coordinator/gate transport checkpoint — Milestone A remains incomplete

Continues `storage-ownership-cas-checkpoint.md` on SAME `t_396d60fe`.
No takeover policy, backend, writer, task graph or production calibration is added.

## Integrated transport

The actual conversion coordinator captures the ownership gate's existing mysqli
transport before its first marker statement. Marker probes/claim/renewal/release,
conversion virgin-authority checks, option/source/destination reads and writes,
control/sentinel CAS/readback, schema verification, table snapshots and retirement
use that captured transport. Production no longer runs those statements through
wpdb's reconnect/replay/query-filter path. The ordinary engine/backend/output
paths outside this coordinator/gate boundary have NOT all been converted.

The adapter captures mysqli object AND server thread identity, checks before
preparation/execution, and latches replacement/closed handles, preparation failure,
client transport errors and failed COMMIT unusable. It does not reconnect, ping,
open a connection, switch authority or clear ownership. Known server SQL rejection
still permits same-handle rollback; a lost session cannot authorize rollback or
release on its replacement. Scalar reads preserve empty/zero versus absence.

Existing native race fixtures intercept a test-only adapter subclass at the SQL
boundary; production has no query hook/injection API. The native session-loss
fixture uses an independent observer and a separate PHP worker that verifies the
exact owner, invocation-owned database and target session before KILL CONNECTION.
Its scenarios cover marker readback, initial copying insertion, control readback,
source deletion and stable publication. After loss it requires refusal, exact
persisted evidence retention, no wpdb coordinator/gate query, an unusable pin and
no release/reacquisition after the fixture explicitly reconnects WordPress.
Executed receipts and exact candidate/ZIP identity belong in the diagnostic
handoff; this protocol description alone establishes no execution result.

## Still unaccepted

Database-session KILL is NOT SIGKILL of the PHP owner, full hard-process-death
recovery, arbitrary schedules, full drain, uncertain COMMIT/readback matrix,
connection-ID reuse proof, server/application clock agreement or separate-process
DDL proof. The fixture's explicit teardown is not a product stale-owner policy.
Old v1 markers must remain blocking when dead-owner/drain proof is unavailable.
No live production calibration, selection/preflight UI, delayed cleanup,
supported-floor decoding, literal PHP5.4.0/WP5.0 or PHP5.6.20/WP6.0 receipts,
lifecycle/browser/204-image/large-AIGPL or independent exact-candidate acceptance
is credited. Preserve `task_scope_completed=false`, keep SAME card OPEN and retain
`t_7dc60149`/`t_8552160b` dependency holds. No remote PR update, merge, deployment,
release, SVN, customer contact or compatibility waiver is included.

Next bounded integration: hard-PHP-death and uncertain transaction/readback proof
with complete writer/output drain before considering any stale-owner admission.
The mixed-newline content migrator and class-init.php remain untouched.
