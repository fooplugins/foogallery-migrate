# Separate A7 WordPress-storage-representable positive lane

Status: measurement_only. Native test wiring is authorized; no calibration or
method/profile approval is implied. The original unchanged-byte native-success
gate remains OPEN/BLOCKED by normal utf8mb4 incompatibility. This lane neither
passes nor replaces nor waives that gate. Frozen metadata remains unchanged.

## Frozen distinct identities

Original a7-compact-v1 20K remains untouched:
- raw bytes 2998293
- SHA256 6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be
- logical a3d122b4a3219a31ffefd28d1063f753caeb289eefc3597b38582ab1090ff695

Separate a7-options-utf8mb4-20k-v1:
- raw bytes 2998293
- SHA256 60392c9d095f334fed1475ccd5cd1e3db5ed1142922627980f5d0bff64072297
- logical 0b77be8fa38731750a46d6ba9afb96fd6d656738025b02a193acb44aa180ee6c

The separate factory changes ONLY 208 injected ff octets to ASCII x (78), in
208 string leaves. NUL is not replaced. These are real string-semantic changes,
not equivalent semantics. Host regressions compare typed keys/order/non-target
scalars, including negative zero, and all 208 byte differences. No identity is
repaired. Neither frozen manifest nor factory is modified by native wiring.

This is one gallery with 20000 immediate images plus 207 nested boundary images:
20208 object records, 204995 tokens, 20834 arrays, depth 8 from root zero. It is
not a top-level 20K workload, AIGPL discovery, settings, or original scale coverage.

## Existing owned native route

    bash tests/integration/run-storage-preflight-cove.sh SOURCE ACTUAL_ZIP NEW_EVIDENCE_DIR EXPECTED_CLEAN_SHA storage-a7-native-positive

The existing shell -> Python runner -> storage-operations.php -> worker route
selects this separately named lane. The original storage-a7 route is unchanged.
The default executes SOURCE then actual extracted ZIP. A final optional source
or package argument isolates one kind for independent failure reproduction; it
does not bypass worker, fixture, provenance, response or cleanup guards. No
source fallback is permitted for production classes loaded from the ZIP.

Each run uses one owned disposable private normal Cove site. It refuses existing
product options/tables, validates administrator/endpoint/schema, binds an exact
32-hex owner and blog, closes/reaps bounded workers and both output pipes, then
performs exact-owned cleanup and unrelated database row/schema comparison.

The seed writes the representable logical value through update_option and binds
native exact SQL OCTET_LENGTH/SHA2 plus fresh cache-evicted WP logical/serialized
parity to a7-options-seed-v1 outcome SUCCESS. The existing options seam gates each
operation with this receipt and fresh SQL/WP readers. Forward dispatches the real
StorageOperations switch request and requires stable table epoch1 plus the exact
retained backup. The backup stage reads that retained backup under stable epoch1.
Reverse dispatches the real request, requiring stable option epoch2, exact native
and WP parity, and retained table equality. Cleanup is allowed after failure only
when all prior workers are closed; other downstream stages stop immediately.

Receipts bind selected-root class paths/hashes and before/after product bytes.
Native worker-local PCRE backtracking is capped at 16777216 for WordPress's SQL
parser on this known 3MB request; no global/host setting is changed. Calibration
remains the original declared experimental policy, measurement_only and NOT
approved. No caps, guards, production runtime or calibration data are changed.

## Failure and evidence boundary

Wiring is not a positive persistence PASS. Native SOURCE currently seeds exact
bytes and WP value successfully, then the real forward request returns
foogallery_migrate_storage_recovery_required before source-decoded. The product
still constructs the default 30000-token decoder and unscaled snapshot path;
the finite scale domain is not connected through this operation. Raising caps,
inventing calibration, or bypassing refusal is not a valid fix in test wiring.
Backup/reverse must remain unexecuted after that refusal. Retain failed receipts.

Host consumer tests are synthetic, explicitly not native evidence. Original
A1-A8, actual large AIGPL/history/content/settings/scaling, public provisioning,
calibration, recovery/cleanup, literal floors, exact source/package acceptance
and distinct final review remain OPEN. Only partial A1/A4/A5 evidence is in scope.
The historical 15 October deadline remains MISSED. No push/merge/release/deploy
or retained-site cleanup is authorized or performed by this lane.
