Repository-sink flatten phase admission (experimental native profile only)

Scope and ownership
  LegacyRecordCodec recognizes ONLY the final RepositoryFlattenModel object as
  a non-retaining digest sink. No callback, interface, ownership string or user
  flag confers that capability. Its actual __invoke hashes each scalar column
  incrementally and retains only a hash context, count, one fixed plan and four
  phase samples. It does not write SQL, persist a migration, or retain rows.
  A row-limit argument may only reduce its finite24576 capacity. Refusal leaves
  partial staged digest/count evidence; callers must not interpret that as a
  committed conversion. A fresh sink cannot release the shared domain's pending.

  Arbitrary callbacks, including callbacks claiming not to retain, use the old
  aggregate estimate. Original retaining roundtrip remains RED/OPEN at128M.
  No WP/SQL caller is activated. Native JSON cannot satisfy the wp_json_encode
  product gate. This is one original-scope phase experiment, not final approval.

Qualification
  Only PHP CLI8.5.11 Darwin arm64,64-bit NTS/non-debug, Zend allocator, CLI
  opcache off, exact binary SHA256 and extension/version fingerprint in the
  class qualify. Runtime checks repeat at entry, including rejection of a WP
  encoder installed after construction. Unknown profile/custom model codec,
  subclassed or altered LegacyStateCodec, memory/clock hooks, widened finite
  ceiling, reserve below32MiB, or a different domain on the same sink refuse.
  The exact built-in LegacyStateCodec with constructor-default configuration
  sharing the same domain is accepted (including an equivalent explicit
  built-in instance); arbitrary custom/subclass code never runs under the model.
  No coefficient overrides, runtime-approval flag, clone or constructor reset.

  Actual runtime origin, binary/source/package/fixture hashes and measurement
  receipts are frozen by the delivery evidence. This is NOT a production
  calibration approval, arbitrary-hook contract, or universal never-OOM proof.
  Literal PHP floors and WordPress/native persistence remain separate OPEN gates.

Shared pending and operation ordering
  1. Code, source and sink are live BEFORE the measured baseline. Source graph,
     raw strings and retained caller values are already in usage(true). Never
     subtract/credit them or charge a second input amplification to the model.
  2. Qualify the internal sink and same default codec/domain. BEFORE descriptor
     traversal, reserve descriptor_bytes(64) via domain.admit_additional.
  3. describe_tree observes the actual compact-v1 representation without row
     encoding. project(descriptor,state_key) gives a conservative additional
     phase delta. Reserve after_descriptor_bytes on the SAME domain BEFORE
     preflight/encoding/digest/emission. Descriptor reservation remains pending.
  4. Actual preflight bounds row count, row/tree encoded bytes and row depth;
     actual streaming snapshot_digest checks logical depth/tokens. Both run
     before emission. Their deadline/work checks are unchanged. The native
     encoder, base/mutable encodes, typed-key/node hashes, projections and every
     per-record logical digest all run in the integrated measured path.
  5. ONLY the qualified flatten aggregate total-output*8 estimate is replaced
     by that pre-reserved phase delta. The final admit_memory(0) still checks
     current sampled usage + ALL pending + reserve against BOTH effective PHP
     and finite<=128MiB ceilings. Per-row MAX_RECORD_BYTES*8, raw/decoder,
     rebuild/snapshot estimates and every other legacy check remain in force.
     All admit_memory checks now add pending, including nested later checks.
  6. Pending monotonically increases for the operation. No credits, releases,
     phase resets, sample-based deductions or replacement domain. Failed
     reservation leaves the previous pending total intact. Repeated successful
     flatten calls consume further deltas and eventually refuse conservatively.

Structural coefficients (unchanged native representation model)
  A(n)=768+160*n includes old+new array capacity/bucket/hash/rounding overlap.
  Installed headers and tests/tools/repository-flatten-sizes.c observe zval16,
  Bucket32, zend_array56, string header24, execute_data80, page4096/chunk2097152.
  Mixed slots include TWO4-byte hash positions; old+new capacities<3n use<120n
  bytes. For>=205 entries, two page tails<=8192 fit40n; smaller transitions fit
  the768 intercept. Source strings/zvals are shared, not recursively copied.

  Descriptor reserves4MiB heap/run/VM slack plus4096 per frame for two recursive
  depth spans+eight callees. Four MiB covers observed bin-run partial pages,
  <2MiB chunk tail and fixed metadata/VM rounding in this finite native profile.
  This is structural finite-host accounting, not a fitted peak ratio or RSS
  theorem. Held-out integrated true/live peaks must fit the prospective bound.

  Row phases reserve field_order/values/mutable shells; tagged containers;
 64-byte generated-string/node overhead and2*max_row_bytes base64/tag bodies;
 8*max_row_bytes+four pages for native JSON old/new buffers, payload/mutation,
  projections and typed-key/hash concatenation;2*max_ancestor_row_bytes plus
  three20-entry row shells per active frame. Maxima may be from different rows;
  summing over-reserves. Digest reserves16*max(largest logical string/key,
  state-key length,32)+four pages for six overlapping scalar encoding/domain
  buffers, each<=8/3 input bytes. It never builds a whole-tree encoded digest.
  Fixed sink hash/scalar/observation scratch fits descriptor slack; it does not
  concatenate a second whole row. Source mutation, allocating hooks, SQL/driver
  lifetimes, external native allocation and retaining callbacks are not modeled.

Reproduction and evidence
  php -d opcache.enable_cli=0 -d memory_limit=128M \
    tests/tools/repository-flatten-phase-probe.php "$PWD" \
    "$PWD/tests/support/StorageA7Fixture.php"
  The unchanged original20K factory is exercised through ACTUAL public flatten,
  including descriptor/preflight/digest/emission. Phase/current/incremental/live
  and true peaks, exact loaded origins and sink checksum are reported. Binary,
  wide, deep, encoded-ancestor overlap, repeated calls, real traversal expiry,
  low finite/effective headroom, custom codecs/encoders, and staged sink refusal
  are exercised. PHPUnit additionally tests exact/+1 ceilings, pending last
  headroom, nested legacy checks and integer overflow with arithmetic-only seams.
  The old model tools remain historical experiments, not admission authority.

Open gates
  A1-A8/UI/CLI/large-state/AIGPL/literal floors/recovery/cleanup/calibration/native/
  final gates OPEN. Native unchanged-byte BLOCKED_NOT_WAIVED; positive persistence
  corpus PENDING;15October MISSED. Distinct exact-candidate review required.
