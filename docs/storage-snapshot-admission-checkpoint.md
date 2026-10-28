# Snapshot phase admission checkpoint (partial)

This bounded production slice passes the existing conversion coordinator's memory
policy and operation baseline into `LegacyTableSnapshot` through an optional,
fail-closed admission callback. It does not introduce policy numbers or approve
safe admission for the whole operation.

Rechecks cover encoded preflight before digest, retained prepared changes before
staging, bounded root/record fetching, driver rows before reconstruction, each
root while prior reconstructed outputs remain live, digest comparisons, and the
final retained-graph floor before publication. Encoded bytes are the existing
policy's input; a zero-byte floor still samples the baseline. The coordinator
keeps its existing recovery-facing error and authority semantics. Existing
snapshot callers without the callback retain their prior behavior.

The regressions execute real codec/snapshot/coordinator consumers against an
explicit query spy. They demonstrate refusal before SQL/staging/payload fetch,
rollback without publication, affirmative ordinary scalar and nested/binary
round trips, driver-backed high-water retention, and refusal between rebuilt
roots. They are NOT native SQL, physical rollback, full WordPress, HTTP,
complete-memory-envelope, or useful-capacity calibration evidence.

Test-first evidence: eight admission tests failed with 19 assertions against
the unchanged implementation, after separately preserved fixture corrections.
The implemented source passes 109 focused tests / 764 assertions and the full
739 tests / 16022 assertions on PHP 8.5.11. The full suite emits existing
ReflectionMethod::setAccessible deprecations. Modern PHP lint and the isolated
npm build pass. Exact source/package bytes and reflected declarations/includes
are checked by the invocation-owned frozen harness; canonical fallback is
rejected. The complete earlier failures, corrected rounds and final manifests
remain outside the distributed plugin in:

`/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/receiver-production-vertical-checkpoint/`

The existing source-bound caller trace has only its coordinator hash and four
shifted line pins refreshed; no new modeled path is claimed. Older calibration
profiles automatically cease to match changed implementation bytes. No new
profile or numerical policy approval is supplied.

The task remains PARTIAL. Next requires OPS receiving this checkpoint, fresh
independent review of the exact candidate, and owner-authorized native
source+matching-ZIP conversion and phase-authoritative recovery evidence on a
pinned disposable full WordPress/SQL fixture. Exact-final legacy runtime,
delayed-teardown/32-bit and complete material-live-cost proof remain open.
No fixture search/provisioning, publication, deployment, or child release is
part of this slice.
