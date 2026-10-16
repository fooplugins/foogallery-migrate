# Separate A7 representable options proposal — host preparation only

Status: measurement_only; contract decision PENDING; no method/profile approval.
No native worker/command route is enabled by this addition. This is not permission
to run the adapter against a retained or new site. The original unchanged-byte
native-success gate remains OPEN/BLOCKED by normal utf8mb4 incompatibility. It is
not passed, replaced, waived, deleted or made equivalent by this proposal.

## Frozen distinct identities

Original a7-compact-v1 20K remains untouched:
- raw bytes 2998293
- SHA256 6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be
- logical a3d122b4a3219a31ffefd28d1063f753caeb289eefc3597b38582ab1090ff695

Separate a7-options-utf8mb4-20k-v1:
- raw bytes 2998293
- SHA256 60392c9d095f334fed1475ccd5cd1e3db5ed1142922627980f5d0bff64072297
- logical 0b77be8fa38731750a46d6ba9afb96fd6d656738025b02a193acb44aa180ee6c

The separate factory derives the original graph and changes ONLY its 208 injected
ff octets to ASCII x (78), in 208 string leaves. NUL is not replaced. These are
real string-semantic changes, NOT equivalent semantics. The verifier rejects
identity drift and never repairs expected hashes. The original factory, manifest,
parser/codec/malformed/refusal tests and native gate expectations are unchanged.

Host regression compares every typed key in order and serializes every non-target
scalar, including negative zero; it also checks all 208 raw differences. Unicode,
NUL, unknown fields, nested boundary nodes, string IDs and URLs are preserved.
This is one gallery with 20000 immediate images plus 207 nested boundary images:
20208 object records, 204995 tokens, 20834 arrays, depth 8 from root zero.
It is NOT a 20000-top-level workload, actual AIGPL discovery, a settings workload,
or complete original scaling coverage. Both default 20K decoders still refuse.
No cap, admission, calibration, provider or production runtime code changes.

## Preparation and dependency seam

Offline fixture freeze (NEW owned directory under TMPDIR only):

    php tests/integration/storage-a7-options-fixtures.php "$TMPDIR/NEW-OWNED-DIR"

The producer checks the frozen manifest and both positive identities before any
output; existing directories/symlinks are refused. No SQL or WP bootstrap occurs.

storage-a7-options-harness.php is a test-only callable seam, not a native worker.
A future owned worker must first seed via the normal logical product/options write,
verify its exact scoped owner and successful seed outcome, and record a bound
'a7-options-seed-v1' receipt (owner, blog, corpus, fixture, outcome SUCCESS,
first_failure null, persisted_sql bytes/hash, wp_logical_sha256). SQL/process success
alone is insufficient. No receipt author or seed writer is supplied in this slice.

For each forward/backup/reverse callback, run() rejects a missing/failed/mismatched
receipt BEFORE reads, then requires fresh exact SQL binary length/SHA256 and a full
WP logical readback BEFORE invoking downstream. Reverse reads the retained backup;
forward and backup read the active option. No positive result is accepted from an
empty conversion; bounded stable response fields or exact backup bytes/hash are
required. The WP adapter queries OCTET_LENGTH and SHA2(CAST(... AS BINARY),256),
checks SQL errors and evicts positive/alloptions/notoptions caches before get_option.
These reads do not supply a continuously held storage lock or a TOCTOU proof.
A future native integration must retain existing owned isolation, locks, runtime
floors, worker/deadline/output/data bounds and perform destination parity checks.
The seam is not sufficient native acceptance or authority to run native work.

Host tests call the real seam and real codec with inert reader/operation callbacks;
the WP adapter is exercised with the existing bootstrap get_option and an inert DB
object with no connection method. These are not SQL, native WP, calibration,
provider, measured-profile or arbitrary-hook acceptance. Synthetic SUCCESS receipts
are explicitly test input only. The original runner is not routed to this proposal.

## Open contract and evidence boundaries

Explicit Brad disposition of the separate positive lane remains pending. Original
A1-A8, realistic multi-root/history/content/top-level/AIGPL/settings/scaling,
public calibration/UI/CLI/recovery/cleanup, literal floors, deadline/output/data/
isolation, final exact source/package acceptance and distinct final review remain
OPEN; this slice cannot satisfy them. The 15 October deadline was MISSED.
Retained fg-a7-native20k-fa0f3424bd and wrong-byte/EMPTY evidence are untouched;
no cleanup or native acceptance is claimed. No push/merge/release/production action.
