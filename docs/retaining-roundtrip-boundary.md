# Retaining roundtrip: finite host boundary, not a fix or waiver

Status: ORIGINAL20K128M RED / BLOCKED_NOT_WAIVED. No product admission, resource policy, representation, fixture or existing test is changed by this evidence slice. This is a measured incompatibility handoff, not a claim that the data physically cannot fit. New diagnostic tests passing do not satisfy the original retaining test.

## First real failure

At parent d1ac511cc5e3fddf3c167946afb9a47d79525c2a, the exact unchanged `StorageScaleAdmissionTest::test_original_20k_tree_roundtrip_and_shared_mid_traversal_deadline` fails at line 61. The stack ends in `flatten_tree`'s generic `$this->admit( $preflight['encoded_bytes'] )` before any callback invocation. The descriptor, prior accepted fixture and unchanged assertions identify 20,208 rows and 15,070,354 encoded bytes.

The implementation reuses the raw-decode amplification of 8 against the total sequential encoded output. 15,070,354 * 8 + 33,554,432 = 154,117,264 bytes, already above 134,217,728 with no live usage or pending reservation. This is a lower bound on an estimate, NOT on real allocation. The original effective memory limit remains 128M; the original test's finite ceiling remains 2,147,483,647. Both ceilings, original deadlines, all data checks and the 32 MiB reserve are unchanged.

## New finite retention/lifetime observations

`tests/tools/retaining-roundtrip-boundary.php` executes only public guarded product methods. It uses the unmodified factory's existing 1000/2000/10000/20000 cells. It never invokes private traversal, injects memory samples, changes the deadline, raises a cap, or applies the non-retaining model. Input and emitted rows remain co-live during rebuild and exact serialization parity. Every invocation is an isolated real128M PHP process. The fixed additional caller allocations below are adversarial retained-usage checks, NOT synthetic calibration or replacement acceptance corpora.

Initial uninstrumented PHP8.5.11 Darwin arm64 CLI/native-JSON receipts:

| Original cell | Additional co-live caller state | Outcome | Peak true bytes through parity |
| --- | --- | --- | --- |
| 1000 | none, one retained copy | roundtrip PASS, 1001 rows | 6,291,456 |
| 2000 | none, one retained copy | roundtrip PASS, 2001 rows | 10,485,760 |
| 10000 | none, one retained copy | roundtrip PASS, 10001 rows / 7,396,357 encoded bytes | 46,137,344 |
| 20000 | none, one retained copy | flatten REFUSED, zero emitted | 12,582,912 at refusal |
| 10000 | 16,777,216-byte live ballast | flatten completes; rebuild REFUSED with 10001 rows still live | 50,348,032 at refusal |
| 10000 | two distinct row-shell/string copies | flatten completes; rebuild REFUSED with both 10001-row copies live | 60,817,408 at refusal |
| 2000 | 33,554,432-byte ballast AND two distinct copies | roundtrip PASS | 50,348,032 |

These are finite witnesses, not a monotone size threshold: the 20K cell also contains extra binary/nested/typed-key cases. Ten thousand is the largest existing cell demonstrated passing here, not a claimed maximum. Diagnostic instrumentation, PHPUnit and caller state change sampled usage; frozen lane receipts carry their own measurements. No observed peak exceeded the limit. No original20K retained output/rebuild measurement exists because the guard refuses first. In particular, extrapolating the 10K measured peak into an approved 20K allocation coefficient would be invalid.

The added-ballast/double-copy cases identify a separate readback lifetime boundary: a flatten-only correction cannot assume rebuild will fit or reuse already-spent headroom. At 10K, the old rebuild estimate requests 59,170,856 additional bytes before its reserve while caller rows remain sampled live. The real guard correctly refuses the two larger co-live cases.

## Smallest original-storage design alternative (NOT implemented/approved)

A dedicated final product-owned retaining collector could give a finite ownership contract: one packed row list, one emitted row shell/string set, no arbitrary user code, no duplicate caches, explicit refusal and staged cleanup. It would retain exactly the same v1 row bytes, rather than substituting the digest sink. Before allocation its bound must cover list capacity growth, row hash tables, strings/hashes/projections, active ancestors, per-row tagged/native-JSON scratch and the co-live original tree. A separately justified rebuild bound must cover caller rows, positional index/adjacency/visited/sort COW, maximum decoded envelope scratch, growing logical result, hashing and final serialization overlap. Both must use one operation budget with no sample-based pending credit or reservation reset, unchanged reserve and both ceilings. A reservation-to-owned-live transfer would require an explicit ownership proof; it is not implied by a higher memory sample.

That collector alone would NOT fix the unchanged arbitrary `rows[]` closure call. The original test must remain RED until a defensible admission/lifetime argument covers that original call without weakening generic callback safety. Callback source inspection/test-hash special cases, a new opt-in label, lowering 8 globally, crediting measured usage, a larger limit, or replacing the test with the trusted collector are not solutions. This slice does not provide that missing argument and makes no universal arbitrary-hook/never-OOM claim. Native-host observations do not approve wp_json_encode, SQL/driver ownership, literal legacy runtimes, persistence or production activation.

## Reproduction and interpretation

Run the original test unchanged with real128M/opcache CLI off. Run the new diagnostic tests separately; they characterize boundaries, not conversion success. The sibling author evidence directory contains exact commands, source/package/interpreter hashes, JUnit executed tests and process origin receipts. Source and actual gulp ZIP must both be tested. All original broader gates remain open. No waiver, deployment, review self-approval or acceptance decision is encoded here.
