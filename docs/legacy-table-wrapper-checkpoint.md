# Lossless compact table-wrapper checkpoint (partial integration)

Task `t_eec7d527`, branch `feature/migrated-store-scalability`.
Baseline `6b1a0a0b39160d21bb861bee66ba219050438ad0`.
This continues the shared-option checkpoint; it is NOT completion of this integration task or Milestone A.

## Implemented prerequisite

- `LegacyCollectionEnvelope` separates established `plugins`, `galleries`, `albums` and `migrated` compact wrapper headers from independently indexed `items`. Unknown state keys remain uninterpreted raw values. Invalid known marker/version/type/items refuse instead of dropping fields or falling back to options.
- The unchanged schema stores optional `wrapper = {field_order, values}` in bounded collection root metadata. Exact outer field ordering, typed unknown header keys, unknown header values and item keys are retained. Metadata validates ordered header/key parity and its collection state/type context. Unsupported object headers/items and oversized metadata retain the existing lossless refusal policy.
- Snapshot preflight/staging counts and indexes actual item trees, not `_foogm_compact`, `type`, `items` or unknown headers as records. Explicit bounded snapshot reconstruction restores the original wrapper losslessly.
- Indexed identifiers, source identities, filtered pages and affected-root updates operate on actual compact items. Existing map patches and dense-list appends preserve wrapper metadata. Atomic scalar companions, expected-revision CAS and dirty-digest policy remain unchanged.
- Affected-item admission includes the wrapper's additional canonical nesting depth before removing old rows; a per-record depth check cannot admit a value that later makes the complete wrapper unverifiable.
- Explicit paged digest maintenance streams headers before and after `items` in original order. The canonical digest domain and bytes are unchanged. Runtime affected-root patches do not rehash or hydrate the complete history.
- No table selection, conversion manager, provisioning, option fallback, schema version/index change or common logical adapter dispatch is introduced.

## Verification and provenance

Evidence/runners/logs: `/home/brad/.hermes/profiles/dev/diagnostics/t_eec7d527/wrapper/`.

- New focused unit RED: missing envelope class; initial GREEN and full suite recorded separately.
- Real baseline-package RED: old snapshot preflight rejects a compact plugins wrapper declared as a logical list (`List keys are not a canonical ordered list`). The preserved previous package is checked against the baseline production files before replay. This is an actual failure, not synthesized output.
- Initial integration harness compared `verify_digest`'s returned head to a digest string; corrected to its existing `logical_digest` member. Those harness errors are not reported as production defects.
- Full PHPUnit after metadata context/depth validation: 278 tests / 1254 assertions, exit 0; content-scan and AJAX regressions pass. Existing PHP 8.5 reflection deprecations remain.
- Guarded WordPress/MariaDB wrapper harness: 29 checks on single-site and 29 each on actual registered multisite blogs 1 and 2. Checks include exact wrapper round-trip, typed null/false presence, indexed item projections/source lookup, atomic companion, dirty digest refusal, explicit maintenance, list append, untouched options and fixture cleanup read-back.
- Final serial build, archive CRC/safe extraction/all-file source parity and source/package regression replay are captured in the final machine evidence; do not infer them solely from the initial checks above.

## OPEN / next action

Implement common logical adapters over the indexed item layout, including bounded metadata access, logical history revision/no-op/atomic-companion policy, source-URL admission, log summary/page contract translation, nested galleries/albums, content findings and image-tag queue shapes, then explicit engine adoption. Raw physical counts are not valid-record logical counts.

Previously staged physical wrappers without this optional metadata are still readable as raw maps; this change does not silently rewrite them. A future table admission/conversion manager must explicitly stage/verify the new item-root layout or refuse old header-root layouts for indexed logical history. Do not infer logical adapter admission from a successful physical digest.

Common table logical dispatch, conversion/authority/retention safety acceptance, minimum PHP/WordPress, true multi-network, browser/customer-memory/large-job gates and native independent approval remain OPEN. Explicit snapshots/maintenance retain existing total budgets and are not resumable unlimited exports. This checkpoint must not release downstream acceptance/review/publication tasks.

No push, PR update, merge, deployment, release/SVN, production data or parent closure occurred.
