# Conversion ownership SQL checkpoint — Milestone A remains incomplete

Continues `storage-repeat-selection-checkpoint.md` on the same implementation card.
No backend, writer, task graph, takeover policy or default calibration is added.

## Integrated ownership dependency

Conversion used `ready()` before mutation but its options/control statements did
not themselves depend on that checked owner. A second process could replace the
maintenance marker or insert a writer after the checks and before the statement,
allowing a control/source mutation even though the eventual response refused.

The actual coordinator now passes its captured, canonical, current-blog maintenance
identity to every control/sentinel CAS and predicates backup/destination insertion
and original-source deletion too. Each such statement requires exact binary owner
bytes, unexpired SQL-time expiry, and absence of ANY scoped writer marker. Guarded
insertion uses `INSERT ... SELECT ... WHERE`; zero affected rows is a refusal.
Derived-table LIMITs prevent target-table merging; tests exercise MariaDB, not a
MySQL compatibility waiver. PHP validates closed canonical owner shape, site,
token and live expiry before SQL generation. Guard capture never renews an owner.
Existing source/destination/sentinel dependencies and exact SQL readback remain.

Lost ownership cannot authorize the raced option/control statement. A failed
operation preserves the recorded pending authority, original evidence and any
foreign owner/writer; no fallback, unowned release, TTL cleanup or silent success.
The separate-process fixture alone restores its own injected marker for teardown.

## Test-first and native evidence

`StorageControlTest` specifies exact owner/expiry/writer SQL dependencies and
no-SQL malformed, cross-blog, expired, unknown-field and invalid-token refusal.
The native conversion harness runs separate PHP processes on invocation-owned
sites to replace an owner or insert a malformed undrained writer between the final
check and SQL at initial copying, backup insertion, authority flip, original-option
deletion, stable publication, reverse-option insertion and sentinel removal.
Each case requires refusal, unchanged exact target rows and retained raced evidence.
After the fixture drains/removes only its own injected evidence, explicit recovery
must reach the recorded destination with complete logical parity. Denied initial
intent has no pending record and retries ordinary admission, not fabricated recovery.
Existing conversion/retirement/source-race and other-blog isolation checks remain.
The diagnostics handoff records executed receipts and exact source/ZIP identity;
only those receipts, not this test description, establish execution success.

## Deliberately unaccepted

This is a same-statement options/control dependency, NOT full drain/takeover proof.
It does not prove arbitrary concurrent schedules, same-request engine quiescence
across processes, table-staging ownership, DDL races, server/application clock
agreement, replay safety for remaining wpdb paths, uncertain commit/readback or
SIGKILL recovery. A predicate is not permission to clear dead/expired markers.
A safe future stale-owner policy must prove the old owner cannot continue or replay
SQL and prove every writer/output path drained before changing recorded authority.
Old v1 ownership evidence cannot establish that proof by expiry alone.

Keep `t_396d60fe` OPEN with `task_scope_completed=false`, and retain downstream
`t_7dc60149` and `t_8552160b` dependency holds. The existing remaining map still
includes independently approved worst-case runtime/SAPI calibration (or grounded
refusal), administrator selection/preflight UI, safe stale-owner/drain proof,
hard-death and uncertain ownership/readback/commit matrix, separate-process DDL
proof, delayed cleanup, safe floor decoding, literal PHP5.4.0/WP5.0 and
PHP5.6.20/WP6.0 receipts, and retained lifecycle/browser/large-state/exact-candidate
gates. No remote PR update, review approval, merge, deployment, release or SVN
operation is credited. Mixed-newline content migrator and class-init.php are untouched.
