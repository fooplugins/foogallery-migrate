# Internal snapshot stats preflight checkpoint

This slice replaces only LegacyTableSnapshot::preflight's no-op flatten callback
with LegacyRecordCodec::preflight_stats. It is not snapshot stage/readback memory
admission, native persistence acceptance or PR15 completion.

Ownership and parity

The operation has no consumer argument and exports only record_count,
payload_bytes and encoded_bytes. It reuses the unchanged private preflight row
validation, then makes and discards the same rows and complete-record logical
digests as full flatten emission. Projection, typed key/order, row/tree/count,
codec encoding/byte/node/depth, shared logical token/depth and deadline checks
remain. The old arbitrary callback and RepositoryFlattenModel paths are intact.
Unsupported domains still refuse; the supported codec's original binary strings
are not normalized. This is not evidence of native database byte persistence.

Prospective workspace

SnapshotStatsModel independently requires the exact existing finite native
GenericRetainingModel runtime/allocator/encoder and exact producer/codec shape.
It does not relax that model's sampler guard or approve another runtime. It
reuses only GenericRetainingModel::flatten_plan's private component, unchanged:
descriptor arena, field-order/values/mutable row copies, tagged arrays/strings,
JSON workspace, projections, hash contexts, all encoded ancestor rows and
maximum scalar digest overlap. No retained-row list or arbitrary callback exists
in this producer, so future-output and consumer-COW/external forecasts do not
apply. The row is destroyed before child recursion; the larger original
ancestor-row bound is deliberately retained. The original descriptor admission
runs before descriptor allocation; the private plan is admitted before any row
encoding/digest traversal. No observed peak is used as authority or credit.

An owner-bound existing pending ledger reserves this workspace with zero future
row events. Other owners and legacy pending liabilities stay charged. Release
occurs only after all producer frames unwind successfully. Failure leaves the
reservation pending, just as the existing finite refusal path does. The existing
finite phase count/serial bounds are unchanged. The new operation additionally
checks native allocated usage, effective PHP limit and finite ceiling alongside
the observation sampler, using the unchanged reserve, before admitting scratch.
An observer may tighten/refuse, never enlarge the native budget. Unsupported
runtime/codec/owner/reserve uses callback-free validation/discard with the
existing conservative row and aggregate admission instead; it is not optimized.

Verification boundary

SnapshotInternalStatsTest covers parity against full flatten, original20K exact
statistics, binary/large/deep/domain/projection/cap/key/order cases, deadline,
owner/pending isolation, actual live ballast and unsupported opcache runtime.
Native child probes measure the private scratch bound without qualifying hosts.
They expose every loaded product path/hash for independent source/package audit.
The production caller is only snapshot preflight; tests/tools call it directly.

The unchanged original20K snapshot target remains RED at snapshot318 ->
StorageScaleAdmission::admit_snapshot115. Its 15070958-byte aggregate still
requires 15070958*8+33554432 = 154122096 bytes before any live usage, more than
128M (134217728). This is relocation from codec299, not a passing snapshot.
Snapshot staging/readback representation/models, global amplification/caps,
reserve, fixtures and original tests are unchanged. ReviewerBoundaryV2's
encoded-cap ERROR is separate and not fixed or waived here. Native byte-domain
persistence is BLOCKED_NOT_WAIVED; original A1-A8 and all final gates remain OPEN.
