# Explicit administrator receiver — bounded checkpoint, Milestone A incomplete

This continuation connects the existing conversion coordinator to an authenticated
current-site administrator operation and makes runtime calibration identity
explicit. It deliberately ships NO approved production calibration. The default
receiver refuses before storage SQL, source hydration, ownership acquisition or
schema preparation. Measurement receipts are not permission to convert.

## Receiver contract

`admin_post_foogallery_migrate_storage` accepts POST only. There is no unauthenticated
hook, network operation, GET mutation, UI, automatic activation conversion or DDL.
The transport contains exactly `action=foogallery_migrate_storage`, `operation`,
and the coordinator's five semantic fields: `blog_id`, `scope`, `target`,
`confirmation`, `nonce`. Extra fields (including request policy/profile), coerced
values and foreign sites refuse. Operations are exactly:

- `preflight`: table target only, `switch-to-table`, option-import nonce.
- `switch`: table/option target, matching confirmation and import/export nonce.
- `recover`: explicit `recover-storage` and dedicated recovery nonce.

Nonce actions remain `foogallery_migrate_storage_<purpose>_<current-blog-id>`, with
purposes `option_import`, `table_export`, `recover`. `manage_options`, exact site
scope and nonce validation precede evaluation of trusted calibration configuration.
The shared coordinator checks context again; authorization is not cached permission.

JSON errors contain a stable code and translated guidance. Request errors use 400,
capability/nonce errors 403, and unavailable calibration or conversion/recovery
refusals 409. Successful switches expose only blog ID, backend, epoch and phase,
not raw payloads, source hashes or internal exceptions. A successful read-only
preflight still says `conversion_ready=false`; conversion rechecks budget/gate.
Preflight cannot provision schema, certify reverse readiness or retire evidence.

## Trusted calibration and fail-closed admission

The server-only `foogallery_migrate_storage_calibration` filter defaults to null.
It receives no POST data. An explicit trusted internal `StorageOperations`
constructor injection is also available for tests/administrator tooling. Neither
is populated by plugin defaults or by this checkpoint. Trusted PHP configuration
is already privileged code; profile shape checking is NOT a signature or a proof
of empirical coverage.

The closed five-field profile consists of integer `version=1`, `status=approved`,
64-lowercase-hex `fingerprint`, 64-lowercase-hex `evidence_sha256`, and the existing
three-positive-integer policy (`max_raw_bytes`, `amplification`, `reserve_bytes`).
`approved` is a trusted assertion that the referenced operating-envelope evidence
has been independently accepted; the plugin cannot infer approval from samples.
No actual approved profile is produced or installed here. `measurement_only`,
missing/unknown fields, wrong runtime, invalid policy and PHP below 7 all refuse.
Literal floor-safe legacy decoding remains an OPEN requirement, not waived.

The fingerprint includes exact PHP version/SAPI/word size/endianness/build flags,
OS architecture/kernel, allocator switch, loaded extension versions, WordPress
version, memory/execution/precision/GC/opcache/JIT settings, and the relevant
coordinator/gate/schema/codec/reader/transaction/engine/receiver implementation
bytes. CLI measurements cannot be replayed as web-SAPI admission. This identity
is intentionally restrictive; changes require fresh calibration. Live memory
headroom is still checked immediately before decoding and on coordinator entry.
Direct uncalibrated coordinator use now returns `decode_policy_required` BEFORE
claiming a maintenance marker, instead of turning missing policy into apparent
interrupted recovery. No valid interrupted phase is automatically cleared.

## Native measurement protocol and limits

`tests/integration/storage-operations.php` uses invocation-owned Cove fixtures.
It executes the actual registered administrator receiver with native WordPress
nonces, JSON generation and termination interception. Query instrumentation
proves uncalibrated/GET/nonce refusals perform no storage SQL. A clearly SYNTHETIC,
fixture-only approved-shape profile exercises routing, not calibration approval.

Six compact shapes (empty, packed-null arrays of 256 and 1024 members, 256 keyed
maps with unknown typed values, eight large Unicode strings, depth-32 nesting)
exercise forward/reverse receiver calls. Each operation records raw source bytes,
real/allocated baseline and reset peak memory, elapsed time and success, and checks
stable authority, bounded response and complete reverse logical parity. Fixtures
prepare schema EXPLICITLY outside the production receiver. Receipts contain
`calibration_status=measurement_only`. Original source-option bytes are not printed.

This is real measurement of this finite corpus on current CLI runtime, NOT a
worst-case bound for arbitrary scalar/array/reference graphs, a production safe
ceiling, an HTTP/browser process proof, web-SAPI evidence, a scale benchmark,
second-network/cache coverage, hard-death/race/ownership proof or floor acceptance.
No measured maximum is silently multiplied into a production policy. Missing
worst-case/reserve/deadline/SAPI evidence means evidence-backed refusal remains the
safe operating envelope. A future approved calibration must resolve those bounds
before server configuration is enabled.

Replay with clean source and exact package identity:

    bash tests/integration/run-storage-preflight-cove.sh /absolute/clean/repo /absolute/candidate.zip /absolute/new/evidence/directory <40-character-candidate-sha> storage-operations

The allowlisted runner compares source/ZIP runtime bytes, executes both on
single-site and two multisite blog scopes, requires native completion/JSON and
identical database DATA dumps, and verifies removal of owned roots/databases.
The existing `storage-conversion` matrix must also pass on the same candidate.

## Remaining same-card work

`t_396d60fe` remains OPEN with `task_scope_completed=false`; the compatibility and
independent review children remain held. No new task graph/writer, remote PR,
merge, production operation, deployment/release/SVN or compatibility waiver.

1. Independently justify and approve a worst-case runtime/SAPI-specific operating
   envelope (or retain refusal), and later deliver the approved UI/preflight UX.
2. Define/prove inactive generation/backup replacement for repeat selection and
   classify previously proposed table state without erasing recovery evidence.
3. Safe stale-owner/drain proof, hard process death, uncertain readback/ownership,
   separate-process writer/DDL races and delayed cleanup remain unaccepted.
4. Safe supported-runtime decoding and exact literal PHP5.4.0/WP5.0 and
   PHP5.6.20/WP6.0 receipts remain required.
5. Exact-candidate independent review and existing downstream lifecycle/browser/
   204-image/large-AIGPL gates remain unaccepted. Milestone B is out of scope.

The mixed-newline content migrator and retained unrelated work remain untouched.
