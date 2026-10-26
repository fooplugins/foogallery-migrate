# Stale-owner admission requirements — conservative v1 boundary

Partial SAME-card `t_396d60fe` continuation from received `262bd307`. This is a
proof-requirements/design checkpoint, NOT authorization to clear a marker, an
accepted replacement for the committed proposal, or complete Milestone A.

## Contract reconciliation

`legacy-storage-upgrade.md` lines 529–531 and 586 require token-aware stale
maintenance takeover and cleanup after proving nonauthority. The received v1
implementation intentionally refuses every retained owner. A random token and
an expired deadline establish identity/age, not that an execution has drained.
That difference is a remaining proposal acceptance gap, not a passing recovery
feature. Do not quietly describe permanent refusal as implemented stale takeover.

V1 records carry only version, blog ID, token and expiry. They bind no process,
transport, output worker or globally enforceable fencing generation. Two
histories can leave the same durable evidence: a killed request, and a suspended
request about to resume. A fresh receiver cannot distinguish these histories by
reading the marker, control phase or destination. Local fixture PID/session
observations are parent-owned evidence unavailable to that receiver. They must
not become client-supplied authorization or a production liveness heuristic.

## Selected safe behavior for this slice

Retain the existing conservative refusal for ALL v1 dead/aged/uncertain owners,
including stable destination authority. Admit ordinary public phase recovery
only after the original owner has released its exact marker on its intact
transport. No product bytes, marker format, engine semantics or proposal bytes
are changed here. No expiry/PID/table/nonce/quiescence-based adoption is added.

This deliberately chooses the already-authorized fallback. It does NOT settle
whether OPS will accept a conservative restriction of the proposal or commission
a stronger admission protocol. That is an explicit receiving decision; neither
this document nor the new refusal tests self-approve it.

## Minimum proof obligations before any future admission

1. Bind ownership to a server-verified, non-reusable execution identity and exact
   database/blog/transport context, not merely a local PID/SQL thread number or
   an administrator assertion. Define the behavior across hosts and restarts.
2. Prove cessation of ALL possible side effects, not just DB connection loss.
   A native post/meta callback or delayed media/file operation must either have
   drained or be unable to publish output after takeover. SQL-session absence
   alone is insufficient. Do not silently change migration exactly-once/output
   rollback semantics, which the proposal excludes.
3. Fence the old execution before any successor mutation. Define an atomic
   owner-token/authority-epoch check and the crash/lost-ACK behavior for every
   intervening state. A CAS that removes an old marker does not stop its owner.
4. Serialize competing authorized recoverers and preserve the existing
   maintenance-first/writer-double-check admission order. Never erase an active,
   foreign, malformed, legacy, uncertain or lost-ACK marker; define bounded
   handling of each version and preserve evidence on unsuccessful admission.
5. Bind recovery to the committed authoritative control/sentinel and verify the
   destination at the right phase. Incomplete/inactive copies and retained option
   backup never override authority. Scope is the exact current blog, including
   shared tables and network-level schema constraints.
6. Specify product-visible operations, audit evidence and fail-closed errors with
   a bounded test matrix: paused owner resumes after takeover attempt; competing
   recoverers; callback/output tails; pre/post-flip and stable ownership; physical
   death; lost ACK; session/server reuse; other-blog markers; v1 retention; token
   CAS failure. Run applicable literal runtime floors on the final candidate.

If these cannot be proved within the approved storage-only architecture, stop
rather than bolt on a new migration-worker lease/output ledger. OPS must choose
an explicitly documented scope restriction or a separately designed protocol.
No new automatic-recovery API or client-controlled drain proof is designed here.

## Focused evidence path

`storage-native-death.php` reuses the actual WordPressCore/base discovery and
completed gallery output from `storage-output-drain.php`, but selects ONLY hard
public-switch death cases. An independent native request calls the real
administrator receiver with a switch-purpose nonce. Test-only pinned interception
pauses before the next phase CAS (copying/verified/flipped) or before releasing
maintenance after stable commits. The parent checks its exact live child PID and
SQL session, SIGKILLs that child, proves death and SQL-session disappearance, and
compares committed storage/output rows before and after. This is not an exception
or an intentional DB disconnect substituted for process death.

Two new purpose-authorized public recovery processes per death case then see the
retained and fixture-aged marker respectively. They inherit no gate, engine,
object cache or DB handle; they must refuse, block public runtime/option fallback,
retain native output, and leave all captured storage/output bytes unchanged.
Both directions and four committed phases run in source/package single-site and
both multisite blogs. Outer whole-database equality and exact root/database
teardown remain mandatory. Fixture-only expiry injection and final cleanup are
never reported as product recovery. Per-case receipt hashes/PIDs/signals/errors
live outside FooProjects; no raw SQL data or identifiers are copied into it.

No product change is made in this slice, so no product RED/green claim is made.
New integration coverage is exercised against received implementation. Two
initial native fixture runs failed with complete DB equality and owned teardown:
the fixture expected the lower gate's busy code instead of the coordinator's
public recovery-required code; then its stable interception matched a guarded
source-option DELETE before stable, not the exact maintenance release. Those
fixture mistakes were corrected without product changes. A clean corrected
matrix passed 6 source/package topology cases, 2,426 checks, 48 physical receiver
deaths and 96 fresh public refusal probes. Subsequent external receipt verification
caught the fixture hashing failed JSON encoding of binary SQL columns; raw strict
row comparisons had passed, but those receipt hashes were invalid. Receipt hashing
now serializes exact captured bytes and the final matrix is rerun. Failed runs and
the superseded invalid hashes are retained, not final verified receipts or product
RED. Build translation generated only a timestamp and line-reference
change in the POT; its diff was retained and those agent-generated bytes restored
to received HEAD before exact packaging. No unrelated bytes were included.
Exact final execution and seven-part handoff: Dev diagnostics
`t_396d60fe/checkpoint-stale-owner-proof/`. Native positive phase recovery remains
in the received checkpoint; this physical-death evidence proves refusal only.

No network-lost ACK, installed FooGallery/browser/remote media, physical output
rollback/exactly-once, literal runtime floor, production sizing, push/publication,
merge/deploy/release/SVN or independent acceptance is claimed. Task and children
remain open. Next receiving decision: resolve the proposal's stale-takeover gap
explicitly before more implementation or any Milestone A acceptance.
