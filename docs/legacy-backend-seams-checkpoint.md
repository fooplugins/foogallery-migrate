# Raw backend seams checkpoint (partial Milestone A)

Bounded continuation of t_2ac0ed80 from
`921a214b4b03f0fb4b9e45efa12ca57ddcb66c7b` on
`feature/migrated-store-scalability`. This is NOT full backend acceptance.
Options remain authoritative. Conversion/QA children must remain gated; there is
no engine table selection, authority flip, provisioning or option fallback.

## Contracts and owned changes

- `LegacyOptionBackend` owns only physical raw option reads/replacements. It
  preserves historical objects, unknown fields, PHP key types/order, false/null
  and presence. It does not compact, hydrate, select or interpret wrappers.
  Whole-option memory cost and WordPress option concurrency semantics remain;
  a read/modify/write operation is NOT a CAS or a writer lease.
- `MigratorSettings` still owns compaction/hydration and coupled history revision.
  Its multi-key setter commits through one nonautoloaded backend update.
  `OptionMigratedStore` retains its history format, error handling and revision
  policy, but uses the same physical backend instead of duplicating persistence.
- Mutation callbacks return an array to persist, false to refuse, or true for a
  no-write logical no-op. Clearing already absent/empty history does not create
  state. Explicit full settings clear still creates an empty option.
- Exact serialized read-back supports WordPress caches that deserialize objects
  into fresh identities. Unknown historical object fields survive unrelated
  changes; object identity alone no longer falsely reports persistence failure.
- `LegacyTableBackend` is an inert compact-only seam around the existing affected
  root repository. It has NO `read_all`, `replace_all` or callback mutation API.
  Historical objects still refuse in compact table codecs. A future manager must
  verify authority and the required-head manifest before admitting operations.
- Common raw APIs are `read(key)` and `roots(state_key, keys)`. Scalar presence is
  explicit, independent of false/null. The table scalar read refuses collections;
  a physically missing scalar returns `found=false`, not option fallback. The
  existing repository `scalar()` keeps its missing-head health refusal.
- Multi-root reads accept at most 100 distinct exact PHP keys and preserve request
  order. The table implementation uses one shared head and aggregate row/encoded
  byte/time budget for all complete root trees, then rechecks generation/revision.
  It refuses concurrent generation changes without returning a partial/mixed
  result. These limits are not query cancellation or PHP heap guarantees.

Shared schema, proposal, lifecycle hooks and preserved historical patch are
unchanged. This slice does not interpret compact collection wrappers, implement
list/queue mutations or expose public table runtime settings/history adapters.

Owned source/test files: the two new backend classes; settings facade; option
history store; table repository; backend contract tests; repository unit tests;
option test bootstrap; repository integration test; this checkpoint note.
Generated POT reference shifts are owned bookkeeping, not behavior changes.

## Executed RED/GREEN evidence

Diagnostics:
`/home/brad/.hermes/profiles/dev/diagnostics/t_2ac0ed80/continuation-2026-10-04/`.

- Initial raw option contract RED: 3 missing-class errors, 4 tests/6 assertions.
- Multi-root RED: 2 missing-method errors, 2 tests/0 assertions.
- Inert table seam RED: 1 missing-class error, 6 tests/42 assertions.
- Historical-object/no-op RED: 2 assertion failures, 2 tests/2 assertions. Both
  regressions passed after serialized read-back and explicit no-write handling.
- Full GREEN: 237 tests/959 assertions on PHP 8.5.4. Existing reflection
  deprecations in `MigrationFlowTest` remain; no minimum-runtime claim.
- Disposable WordPress 7.1.2-src/MariaDB 11.8.6 source repository harness: 58
  checks pass, including raw parity, real WP historical-object/no-op behavior,
  aggregate multi-root row/byte refusal and an independent concurrent writer.
  Existing rollback/CAS/projection/corruption/atomic-reader/tainted-connection and
  fixture cleanup checks still pass. Multi-blog IDs are NOT true multisite tests.

Replay unit/regression commands from the worktree:

    vendor/bin/phpunit --colors=never --do-not-cache-result
    php tests/content-scan-regression.php
    node tests/ajax-js-regression.js
    npm run build

The WP integration requires an explicitly disposable instance, WP-CLI and
`FOOGM_STORAGE_TEST_DISPOSABLE=true` defined by a runner. Never run it on a live
site. The diagnostic runner paths and exact source/ZIP execution evidence are
recorded in the seven-part external handoff and `verification-evidence.json`.
Package integrity and byte parity are checked against the generated ZIP before
replaying the same integration harness with its actual packaged bootstrap.

## Remaining scope and next action

Continue the SAME backend card in a fresh bounded run. Next: define and test the
common logical/runtime contracts for compact wrappers and historical-object
representation/refusal, then bounded indexed history getMany/source-identity/log
APIs, list/queue mutations and dirty-digest verification/export. Do not route hot
paths through whole-state reconstruction or snapshot replacement. Secure public
selection/conversion/recovery remains the separate t_a2140dc0 lane, released only
when this backend card's complete acceptance passes.

Actual multisite/multi-network, minimum-runtime, browser, customer memory and
large-AIGPL acceptance, plus native independent exact-candidate approval remain
unverified. This checkpoint is not self-approval or a never-OOM promise. No push,
merge, deployment, release, SVN, production write or customer action occurred.
