# Explicit repeat-selection checkpoint — Milestone A remains incomplete

This continuation integrates current-site administrator replacement of inactive
storage into the existing receiver/coordinator. It is not a purge UI, production
calibration, stale-owner takeover, literal-floor acceptance or release approval.
Exact candidate/ZIP identity and executed receipts belong in the diagnostic handoff.

## Explicit admission and prior-state classification

Ordinary `switch` still refuses retained table rows or a backup. A separate POST
operation `replace` requires exactly the same five site-scoped semantic fields,
`target=table`, `confirmation=replace-retained-with-table`, `manage_options`, and
a nonce for `foogallery_migrate_storage_option_reimport_<current-blog-id>`.
Authorization precedes trusted calibration and any storage SQL. No approved
calibration is shipped, so the default receiver refuses before mutation.

New first imports use closed canonical control version 2. V1 remains readable for
existing runtime/recovery contracts but is never implicitly upgraded to authorize
retirement. V2 adds only `retained_digest`: null on forward records, a 64-hex
physical manifest digest on reverse records. It binds every known column in all
current-blog heads and records (including inactive generations and orphan rows),
plus the exact backup row identity/bytes digest/autoload. An extended table shape
with unknown columns refuses rather than omitting that evidence.

Replacement requires a stable v2 reverse, absent sentinel, unchanged retained
manifest and fresh admitted current-option source. Pending/corrupt/missing/v1
control, proposed PR tables/markers, unknown table shapes, changed/missing backups,
changed generations/metadata and unexpected orphan rows are retained and refused.
The first-import empty check covers both heads and records. No fallback, merging,
automatic classification, cleanup on activation or schema creation is introduced.

## Atomic retirement and recovery

The already-owned maintenance identity is captured without renewing it inside
the retirement transaction. The pinned non-replaying mysqli connection:

1. Refuses ambient transactions with unqualified `SET TRANSACTION` and requires
   InnoDB options as well as the verified existing shared tables.
2. Locks the exact live owner, checks writer absence, locks exact old control,
   absent sentinel, current source and backup plus scoped head/record ranges.
3. Rechecks full retained physical evidence, source identity, actual table engines,
   site/connection/deadline context and the engine's trusted quiescence verdict.
4. Deletes only the verified inactive current-blog rows and backup, then publishes
   the new `copying` control in the SAME transaction; verifies SQL readback,
   emptiness, owner and quiescence again before commit.
5. Invalidates affected option caches and requires exact committed control readback.
   A failed/uncertain operation is a refusal, never a replay or synthetic success.

Before commit, rollback restores both old stable control and all inactive copies.
After commit, the independent current option remains authoritative behind the new
copying record. Existing conversion/recovery verifies that immutable source,
creates a fresh exact backup, imports with full logical parity and flips authority.
A failed subsequent import does not revive the retired backup or old table state.
Repeat reverse exports the new authoritative table, never a previous backup.

Capture uses SQL fixed-size per-column hashes rather than payload hydration,
count-admitted bounded result sets, a 10,000-row-per-table and 16-MiB aggregate
refusal bound, the injected memory policy and existing coordinator deadline.
These are finite implementation refusal limits, NOT proven production worst-case
memory/time/calibration ceilings or a large-state acceptance receipt.

## Executable evidence and remaining map

The focused unit tests specify v2 canonical evidence and distinct replacement
confirmation/nonce/no-SQL calibration denial. The native administrator receiver
matrix includes empty and compact typed shapes with two forward/reverse cycles.
The native conversion matrix includes old-control and changed-evidence refusal,
retirement DELETE/control-update/readback trigger faults with full raw rollback
parity, ambient-transaction refusal, committed retirement followed by failed import
and exact-source recovery, fresh backup parity and other-blog isolation.
Native same-request triggers are not separate-process races or hard-death proof.
The executed results and fixture teardown verification are in the adjacent handoff.

Keep `t_396d60fe` OPEN with `task_scope_completed=false`; retain the existing
compatibility `t_7dc60149` and independent review `t_8552160b` dependency holds.
Remaining same-card work includes independently approved runtime/SAPI worst-case
calibration (or evidence-backed refusal), approved selection/preflight UI, safe
stale-owner/drain proof, hard-process-death and uncertain ownership/readback/commit
matrix, separate-process writer/DDL races, delayed cleanup, safe literal-floor
legacy decoding and PHP5.4.0/WP5.0 plus PHP5.6.20/WP6.0 receipts. Existing downstream
lifecycle/browser/204-image/large-AIGPL and independent exact-candidate gates remain
unaccepted. Milestone B, new task graph/writer, remote PR, merge, production,
deployment/release/SVN and compatibility waivers are not authorized by this slice.

The mixed-newline content migrator and retained unrelated work remain untouched.
