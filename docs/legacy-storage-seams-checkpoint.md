# Milestone A: shared-storage primitives checkpoint

Task: t_3a8f9a3c. Branch: feature/migrated-store-scalability.
This continues the partial option-default foundation at 18c3d2f.
This is NOT Milestone A acceptance or a selectable table backend. Do not release
Milestone B, publish this checkpoint, or bypass existing authority markers.

The next bounded slice is recorded in `legacy-record-codec-checkpoint.md`.
It adds compact tree flatten/rebuild and logical digests; the limitations below
describe this earlier primitives checkpoint, not full Milestone A acceptance.

## Delivered in this bounded slice

- `LegacyStorageSchema`: the approved base-prefix `legacy_state` and
  `legacy_records` columns and ordered indexes, explicit InnoDB DDL, and read-only
  metadata health verification. A wrong engine, column type/nullability, missing
  column, missing/partial/reordered/nonunique required index or DB error refuses
  health. Construction and inspection do not create tables or options.
- `LegacyStateCodec`: versioned tagged JSON for already-compact scalar/array
  values, exact typed keys, order, arbitrary string bytes and finite doubles
  (including negative zero). Strings are base64; doubles are big-endian binary
  base64. Key/node hashes are typed and parent-scoped. Unknown array data is kept;
  objects/resources, nonfinite doubles, malformed tags, duplicate/coercible keys,
  unsupported versions and exceeded budgets fail closed. No `unserialize()` is
  used. This is not yet the lossless allowlisted encoder for historical objects.
- `LegacyTableReader`: metadata-only head lookup and bounded root/child keyset
  pages. Every row query is blog/state/generation scoped; cursors also bind the
  key, parent and revision. `(item_order, id)` is the ordering tuple. Size queries
  run before any LONGTEXT payload fetch, with row/page caps; changed metadata,
  base checksum/node identity failures or a changed head cause refusal/retry.
  No reads select authority, provision storage or fall back to legacy options.
- Focused fake-WP tests and a guarded real WP/MariaDB integration harness. The
  harness refuses preexisting owned tables, creates only disposable fixture
  tables, and reads back their removal and unchanged options on cleanup.

The schema's DDL strings are not an installation/provisioning API. Their eventual
production executor must enforce capability, main-site option-lock arbitration,
transaction-engine verification and the cleanup manifest. No current hook or
engine path calls these new primitives, so activation and option authority remain
unchanged.

## Limits and intentionally incomplete contracts

Codec defaults per record: 1 MiB encoded bytes, depth 64, 10,000 tagged value nodes.
Reader: at most 100 rows, at most 1 MiB aggregate encoded columns per row, default
4 MiB aggregate columns per page. All limits refuse rather than truncate. Limits
are implementation defaults, not a never-OOM or whole-migration bound. Encoded
size is not decoded heap size; engine/adapter and option-decoding costs remain.

The reader returns `base` and `mutable` envelopes separately, plus metadata. It
verifies the base-payload checksum but does NOT reconstruct canonical full records
or verify their logical hash or denormalized projections yet. There is no tree
flatten/rebuild layer, staged writer, digest stream, checkpoint coordinator,
installation-wide provisioning lock or conversion/authority manager here.
No service may adopt these raw reads as complete logical legacy state.

## Verification performed

- RED/GREEN for all three new classes; complete PHPUnit suite passed:
  130 tests, 690 assertions on PHP 8.5.4. Existing ReflectionMethod deprecations
  in MigrationFlowTest remain unchanged.
- A short read-only reasoning review found a non-string discriminator accepted by
  PHP's loose switch matching and overly strict single-column order validation.
  Both were reproduced RED and fixed GREEN, including real-DB order ties within
  and across keyset pages. This was a checkpoint code check, NOT Milestone A's
  required fresh independent native acceptance review.
- Real WordPress 7.1.2-src / MariaDB 11.8.6 / PHP 8.5.4 harness passed exact DDL and
  ordered-index checks, dropped-index refusal/repair, typed keys/arbitrary bytes,
  site-row isolation, root/child/cursor paths, hash corruption and pre-fetch size
  refusals, plus read-back fixture cleanup. It is single-site WP with explicit
  multiple-blog-ID table fixtures, not full multisite/network lifecycle proof.
- JavaScript AJAX and PHP content-scan standalone regressions passed.
- `php -l`, `git diff --check`, `npm run build`, and ZIP integrity checks passed.
  The same guarded integration harness also ran against the extracted final
  build using the package's real plugin bootstrap/autoloader.
- No PHP 5.4 runtime/container is installed here. New production syntax was kept
  conservative, but minimum-runtime compatibility remains unexecuted.

Diagnostics: `/home/brad/.hermes/profiles/dev/diagnostics/t_3a8f9a3c/`.
Evidence: `seams-codec-red.log`, `seams-codec-green.log`, `seams-schema-red.log`,
`seams-schema-green.log`, `seams-reader-red.log`, `seams-reader-green.log`,
`seams-review-codec-red.log`, `seams-review-codec-green.log`,
`seams-review-reader-red.log`, `seams-final-unit.log`,
`seams-final-integration.json`, `seams-package-integration.json`,
`seams-final-build.log`, and `seams-final-lint.log`.
The preserved superseded deactivation patch was not changed.

## Next bounded continuation (same card)

Implement and test the lossless compact/historical-object record split, nested
flatten/rebuild and canonical logical/projection parity, then staged repositories
with atomic generation/checkpoint writes. Only after those seams are complete,
wire authorized main-site schema provisioning, raw-option byte preflight, explicit
opt-in, writer draining, conversion verification/authority flip and old-PR15
recovery. Keep options authoritative and conservative gates intact meanwhile.

Complete real interruption/failure/multisite/uninstall/browser/minimum-runtime
checks and freeze a full Milestone A candidate for fresh native independent
review before task completion. No push, PR update, merge, deployment or production
operation was performed. Continuation is already authorized; this checkpoint
requires no new product decision and must not be routed as a human approval gate.
