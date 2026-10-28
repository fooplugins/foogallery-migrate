# Native hard-process-death checkpoint — partial, not recovery admission

Continues `storage-media-batch-checkpoint.md` on SAME `t_396d60fe`.
No product code, schema, public selection policy or v1 marker semantics change.
Milestone A remains incomplete and independently unaccepted.

`tests/integration/storage-process-death.php` starts an invocation-owned PHP
process, verifies its exact `proc_open` PID and live database thread independently,
and sends SIGKILL only to that owned child. The OS must report signal9; its
registered shutdown receipt must be absent and the database session must vanish.
The observer compares fresh SQL snapshots before/after death. A second fresh
PHP request performs actual authorized conversion/recovery and gate checks; the
entire persisted snapshot must remain identical after the refused request.

Eight deterministic schedules run: committed option writer output; table writer
before COMMIT and after actual COMMIT before the adapter returns; conversion
copying control, snapshot COMMIT, flipped control, source deletion and stable
control before owner release. All output/control bytes originate in the actual
coordinator/public writer, not fabricated control records or synthetic API output.
Table existence is never credited as authority. Before-COMMIT death leaves the
old committed value; after-COMMIT death independently exposes the new value.

Each schedule is repeated with this exact killed owner's v1 marker aged by a
fixture-only binary compare-and-swap. Authorized conversion/recovery stays blocked
both before and after aging. A fresh request can own absent maintenance behind a
dead writer, but cannot affirm drain or delete that writer; an existing dead
maintenance owner cannot be adopted, released or replaced. This is conservative
blocking, NOT a stale-owner recovery implementation. Ordinary concurrent writer
admission is not claimed to be globally blocked by another writer's presence.
Fixture teardown uses exact observed owned rows; it is not product recovery.

Test-first `e12036b` fails on the intentionally absent process worker; this is a
missing-evidence-harness RED, NOT a reproduced product defect. `591791d` introduces
the worker. That native attempt exposed stale observer option cache after a child
removed source data; `b02c463` invalidates all known fixture option cache keys,
including now-absent rows. All six source/package single/main/subsite schedules
then pass, with independently restored full database data and removed fixtures.

Limits: test-only adapter boundary hooks and synthetic small-state calibration;
PHP7.4+ CLI process harness, NOT literal supported product floor proof. SIGKILL
is real, but this does NOT simulate a network-lost COMMIT acknowledgement or
prove arbitrary schedules, physical WordPress output pinning/rollback/exactly-once,
complete output audit, direct native base-migrator turns, remote media import,
public selection/browser UX or safe postdeath takeover. Existing receipt-loss
fixture still explicitly labels its injected response failures as SIMULATED.

Remaining: complete actual output/callback/recovery integration and justified
stale-owner policy, approved production calibration/public selection flows,
literal supported floors/delayed teardown in the existing approved legacy lane,
and independent exact-candidate acceptance. No child/validator/review/publication
release, push, PR mutation, deployment, SVN or new runtime/VM is authorized.
`task_scope_completed=false`. Final exact SHA/package/receipts and seven-part
handoff live under Dev diagnostics `t_396d60fe/checkpoint-process-death/`.
