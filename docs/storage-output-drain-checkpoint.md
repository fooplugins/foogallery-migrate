# Output callback drain checkpoint — Milestone A remains incomplete

Continues `storage-callback-drain-checkpoint.md` on SAME `t_396d60fe`.
This bounded checkpoint does not accept the card, release its children or
implement dead-owner recovery.

## Integrated production paths

The existing private engine operation boundary now covers setting writes,
settings save, migrated-object updates, selection, retry and error-check paths.
Gallery/album migration, creation and child-processing calls use a private
protected object boundary. Its request-global nested depth is not serialized and
also refuses engine drain verdicts. No public callback/fault-injection API,
activation table creation, storage fallback or stale-owner takeover is added.

Gallery and album creation revalidate authority after lookup and on both sides
of `wp_insert_post()`. Each subsequent metadata output revalidates before/after
its native hook. Child output is revalidated before recording child completion.
Legacy option backend writes recheck their existing admission callback after
`update_option()`. This is NOT a pinned option-API transaction or exactly-once
output: already performed WP output/option writes can survive a refusal.
Supported Exception/Error exits unwind local depth; a failed storage pin remains
failed. Writer lifetime and owner-checked shutdown semantics are unchanged.

## Test-first evidence and native scope

The original corrected output suite produced seven failures on the received
baseline before production edits (regression commit `ab3a42d`). The subsequent
full suite passes 475 tests/2,136 assertions on PHP 8.5, retaining existing
reflection deprecation diagnostics. Actual final candidate, package SHA and
receipt totals live in the diagnostic seven-part handoff and executed verifier,
not an inferred acceptance claim.

`tests/integration/storage-output-drain.php` executes production gallery/album
objects and ordinary setting mutation with native WP post/meta/option callbacks,
option authority and actual conversion-coordinator-selected table authority.
It exercises nested flush, post-hook and first-meta-hook handle replacement,
callback Error unwinding and multisite blog changes. A separate DB observer
checks persisted history/completion; cached objects alone do not prove progress.
The source adapter, FooGallery constants/settings and calibration are SYNTHETIC.
No media download, real source-plugin fixture, dropped ACK or PHP SIGKILL is
credited. Table tests seed established compact history/revision: absence of
those heads remains a refusal, not an option fallback. Normal post reuse is
avoided using distinct fixture source identities.

Native fixture-development failures are retained separately from final PASS
receipts: repeated source identity/reuse, omitted WP schema helper, missing
initialized history and invalid child identity. They did not authorize a
production fallback or schema rewrite. Fixture cleanup may remove only exact
fixture-owned marker bytes; this is not a product recovery protocol. The native
runner independently checks all data-row equality and removes owned sites/DBs.

## Still unaccepted / next bounded SAME-card slice

Complete production drain coverage is not established for all content, media,
batch/direct-helper paths. No cross-request complete-drain/death proof, safe
expired-owner takeover, independent uncertain transaction/readback schedule,
DDL/process identity/ID-reuse/clock agreement, supported-floor decoder or literal
legacy/delayed-teardown acceptance is credited. Public authorized preflight/UI,
production calibration and independent exact-candidate final validation remain
outstanding. Retain v1 markers unless owner/death/drain proof is established.

Preserve `task_scope_completed=false`, Milestone A unaccepted, and dependency
holds on `t_7dc60149` and `t_8552160b`. Next fresh bounded SAME-card slice:
remaining actual output/batch integration plus separate-process hard-death and
uncertain-readback evidence before any stale-owner admission; safe supported-
floor decoding remains an authorized alternative. Preserve class-init.php,
mixed-newline content migrator and paused unrelated work. No push, PR mutation,
merge, deploy, release, SVN, runtime provision, compatibility waiver or customer
contact. A local specialist checkpoint is not independent acceptance.
