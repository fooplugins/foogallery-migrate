# Milestone A: atomic bounded snapshot checkpoint

Task: t_d3ba5f56. Branch: feature/migrated-store-scalability.
Continues clean compact-codec checkpoint d9312ff2f24699a84200bbb4a4b2511db59b67fd.
This is NOT complete Milestone A, a selectable backend or acceptance/publication.

## Implemented

- Inert `LegacyTableSnapshot::replace_many()` accepts explicit top-level keys,
  orders, kinds, logical values and expected revisions. Null expected revision
  means the head MUST be absent. Scalar/map/list envelopes retain explicit false,
  null, empty and unknown fields; map/list roots use the existing split tree codec.
- All affected replacement buckets and scalar revision/checkpoint companions are
  preflighted, locked in deterministic hash order, staged, read back from the real
  DB, verified and compare-and-swapped in one transaction. Every logical head
  advances once; stale revisions, key collisions, occupied next generations and
  generation/revision overflow fail closed. No unrelated bucket is rewritten.
- Count/byte parity, full logical hashes, node/parent/key identity, exact ordering
  and every existing codec projection are checked before head publication. Old
  generations are retained. No automatic adoption, orphan cleanup or fallback.
- `read_bounded()` refuses missing/dirty/stale digest metadata and verifies a
  complete bounded snapshot, with before/after head checks. It is NOT the future
  high-cardinality history/queue/page API or a cross-key consistent-read API.
- `LegacyTransactionConnection` pins an already-open mysqli handle and executes
  directly on it. It never uses wpdb's query/reconnect/replay path. Unknown COMMIT
  acknowledgement or failed rollback taints the repository; reuse is refused and
  fresh authoritative reconciliation is required, never automatic retry.
- Disabled autocommit is refused. Unqualified next-transaction isolation SET is
  refused inside an ambient transaction, before START could implicitly commit it.
  The caller must still exclusively own this connection and acquire the future
  manager's maintenance/writer guard. The classes have no admitted runtime caller.

No production selection/provisioning hook, option write, historical object
normalization, unserialization or destination mutation was added. Options remain
normal-mode authority. Unsupported non-compact objects are refused before SQL.

## Bounds and limitations

Defaults: 10,000 physical records and 16 MiB encoded columns across the COMPLETE
replacement, including affected companion keys; per-row/root scalar envelopes
retain the 1 MiB codec limit and depth/node guards. Read limits include state-key
bytes. Actual DB column sizes are preflighted before record payload retrieval.
These are encoded-byte limits, NOT decoded-heap/OOM or large-job performance
acceptance. A scalar array remains indivisible and may be refused when too large.

This checkpoint replaces bounded whole buckets, not one root inside a huge
bucket. Future high-cardinality repositories MUST patch/query only affected roots
and indexed pages; do not wire existing whole-array runtime methods to this API.
Per-generation exact-history identifier/deduplication projections, journal/log
repositories, leases/checkpoints for multi-request conversion, writer draining,
verified authority switching, old-PR15 recovery, UI, cleanup/uninstall scope and
lossless allowlisted historical-object representation are not implemented here.
A root's schema projection columns alone are not a complete migrated-history API.
No actual network-multisite, browser, PHP 5.4 or MySQL-server acceptance was run.
The connection adapter refuses non-mysqli/drop-in connections instead of claiming
transaction safety; portability of the guard remains unverified beyond MariaDB.

## Verification

Replay from the source worktree:

    vendor/bin/phpunit
    php tests/content-scan-regression.php
    node tests/ajax-js-regression.js
    npm run build

RED: new snapshot class absent, 21 initial unit cases error. GREEN: 25 new unit
cases / 56 assertions. Full source suite: 211 tests / 864 assertions, PHP 8.5.4;
preexisting ReflectionMethod deprecation notices remain. Syntax and diff checks
pass. Existing content-scan and JavaScript/AJAX regressions pass.

`tests/integration/legacy-storage-snapshots.php` exercises the source and extracted
package bootstraps in explicitly disposable WP 7.1.2-src / MariaDB 11.8.6 fixtures.
It refuses ANY preexisting owned tables and reads back cleanup/unchanged owned
options. It exercises exact nested/binary/typed/order/negative-zero round trips;
blog-ID isolation; independent-connection visibility before/after commit;
rollback at record insertion and between head flips; stale writes; real unique
constraint failure; ambient transaction/autocommit refusal; real connection KILL
after the first flip and before COMMIT, with unchanged committed data and refused
repository reuse; checksum/mutable/projection/digest corruption; bounds; and orphan
refusal. This is storage-primitive evidence, not opt-in/lifecycle/UI acceptance.

Replayable runners and raw logs are under:
`/home/brad/.hermes/profiles/dev/diagnostics/t_d3ba5f56/` (`snapshot-*`). The future
native exact-SHA independent review remains required. A short internal reasoning
check found the wpdb replay hazard; it is not an acceptance verdict.

Next: continue on the SAME card with bounded root/history repositories and the
manager's guarded conversion/runtime selection contract. Keep t_f09f7555 gated;
do not complete implementation or mark Milestone A accepted from this checkpoint.
