Original20K128M repository-sink flatten: integration incompatibility

Scoped result
  No production gate is changed. The prospective finite-host model cannot make
  this acceptance phase green while retaining the original aggregate policy.
  This is a blocker witness, not an integrated pending-aware implementation.

Exact conflict
  LegacyRecordCodec::flatten_tree finishes preflight and logical digest, then
  calls admit(preflight.encoded_bytes), before flatten_node or any consumer.
  The original fixture produces 20208 records and 15070354 encoded bytes.
  StorageScaleAdmission::admit_memory preserves amplification8/reserve33554432:
    15070354 * 8 + 33554432 = 154117264 > 134217728
  This fails even with ZERO sampled usage and ZERO pending. Real usage and
  pending deltas can only increase the requirement. Replacing that aggregate
  check with the smaller phase plan, lowering its input or amplification, using
  max(old, model) instead of both obligations, crediting retained source, or
  increasing the ceiling would not preserve the original admission policy.
  Reserving descriptor and after-descriptor deltas on the SAME domain reaches
  the unchanged refusal too. It does not fix the existing pending-blind legacy
  gates; the planned mode is deliberately NOT a pending-safety proposal.

Reproduction (run from the immutable source root)
  php -d opcache.enable_cli=0 -d memory_limit=128M \
    tests/tools/repository-flatten-incompatibility.php \
    "$PWD" "$PWD/tests/support/StorageA7Fixture.php" original
  Repeat with planned instead of original. Both commands intentionally exit1
  with bounded JSON evidence and zero emitted rows. Characterization PHPUnit
  tests PASS by asserting that refusal, NOT by redefining original acceptance.
  The original tests/fixture are unmodified. The probe takes a separate fixture
  path so the same immutable factory can exercise actual gulp package classes.

Applicability and next boundary
  Exact RepositoryFlattenModel native CLI host profile only, including binary
  and extension identity; no wp_json_encode, including no PHPUnit JSON shim.
  The absint/error/translation helpers are offline scaffolding, not WordPress
  or native unchanged-byte integration support. No SQL/DB/hooks/persistence,
  retained-output rows, literal floors, recovery or conversion is exercised.
  Pending-aware integration would still have to preserve EVERY relevant gate,
  not only the final aggregate check. No new model or integration is introduced.
  A distinct reviewer must validate this minimal policy/acceptance conflict;
  Default then needs an explicit policy/acceptance reconciliation decision
  before any further integration authoring. Nothing here authorizes a waiver.
