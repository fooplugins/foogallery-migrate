# Inert physical table runtime checkpoint

Task: `t_72558c41`, branch `feature/migrated-store-scalability`.
Baseline: `6017fe2b3aae8ddfbd9630d564d2b088e6975d07`.
This completes the allocated physical capability phase, not Milestone A or public table-mode acceptance. Options remain authoritative; no selection, conversion, provisioning or engine/facade change is included.

## Physical integration surface

`LegacyTableBackend` delegates to `LegacyTableRepository`:

- `identifiers('migrated', $keys)` returns ordered `{key, found, value}` compact tuples, preserving int/string keys and false/null versus absence, using the identifier index and raw typed-byte checks.
- `identifier_presence('migrated', $keys)` returns `{key, found}` without fetching logical record payloads. Presence is not a substitute for full record integrity verification.
- `source_identities($key, $identities, $numeric = false)` accepts at most 100 `{type, plugin, id}` identities (string type/plugin, scalar/null ID), returns ordered found/key/value tuples, selects eligible roots in legacy insertion order, verifies source values after hash lookup, and applies the existing string-cast or image absint matching rule. It does not add a source-URL index.
- `filtered_page($key, $limit, $filters, $cursor)` returns the existing rows/cursor/revision envelope. Whitelisted filters: string `object_type`/`migration_status` (32-byte maximum) and integer 0/1 `is_selected`/`is_migrated`/`has_error`. Canonical filter identity binds the cursor to blog/state/generation/revision/filter. `page` retains the unfiltered cursor shape.
- `aggregate` provides SQL root total/migrated/selected/errors counts; `projection_summary` returns bounded distinct object_type/migration_status groups in first-occurrence order. Neither hydrates full history. The integration adapter must translate these physical groups into the existing option log-summary contract.
- `patch_many` retains existing map/scalar patch admission. `patch_lists_and_scalars` also allows dense existing list root replacements and sequential appends, never list deletes, sparse keys or silent reindexing. All expected-revision heads and scalar companions commit together. Existing missing-head admission still refuses. Nested changes replace their containing complete root tree; unchanged physical roots retain IDs/bytes. Runtime changes mark snapshot digests dirty without whole-history hashing.
- `export_page` is explicit, generation/revision-bound and adds verified subtree record/payload/encoded-byte accounting to the unfiltered page envelope. It returns compact raw roots, not a claimed verified complete snapshot.

Callers must already own the manager writer/maintenance guard and supply an idle pinned non-replaying connection. Defaults remain 10,000 aggregate affected rows, 16 MiB encoded bytes and cooperative 10 seconds; pages enforce 100 roots and 4 MiB aggregate encoded bytes. These are encoded-data budgets, not heap guarantees or database-call cancellation.

`LegacyTableSnapshot::verify_digest($key, $expected_revision, $max_seconds = 10)` explicitly streams cursor-bound roots into the exact canonical typed snapshot hash without assembling the complete value. It verifies root order/list keys, tree integrity/projections, total generation rows/payload bytes (including orphan refusal), the head and revision, and publishes digest metadata via a locked CAS without bumping the logical revision. A current but invalid digest refuses rather than being replaced. Unknown START/COMMIT/ROLLBACK outcomes quarantine the object until a fresh connection is reconciled.

Maintenance is one bounded call across pages, with total configured row/byte/time admission. No resumable cross-request hash state or unlimited large-history maintenance is supplied; oversized states refuse. Explicit bounded snapshot reads reuse paged reconstruction and require a current digest.

## Actual verification

Evidence: `/home/brad/.hermes/profiles/dev/diagnostics/t_72558c41/`.

- Test-first runtime API, maintenance admission, metadata API, orphan-row integrity, aggregate page bytes and uncertain transaction-start RED logs are preserved there.
- Focused table/schema PHPUnit: 68 tests, 152 assertions, exit 0.
- Full PHPUnit: 261 tests, 1,126 assertions, exit 0.
- Content-scan and AJAX/JavaScript regressions: PASS, exit 0.
- All six changed PHP files pass `php -l`; `git diff --check` passes.
- Guarded real WordPress/MariaDB runtime: single-site 73 checks, actual multisite 76 checks, both PASS. This includes independent-writer races, transaction uncertainty, collision/projection corruption, ordering, budgets, untouched public options/authority, cross-blog cursors and fixture cleanup read-back.
- Existing real repository/snapshot/seams/option-runtime harnesses: exit 0 (58/59/33/39 checks respectively).
- Actual runtime: PHP 8.5.4, PHPUnit 9.6.34, Node v22.22.3, MariaDB 11.8.6, WordPress 7.1.2-src single-site / 7.1.2 multisite. Existing ReflectionMethod deprecation notices remain.
- Approved shared schema/codecs/fixtures, production settings/engine/bootstrap/POT remain unchanged from the baseline. Both preserved historical six-file patches retain SHA-256 `6de47194708179d97c7b28deaa98ef81ea146f198b15fce1aa02aff0a68fa540`.

## Next gate / explicit OPEN rows

The existing integration lane owns common adapter table dispatch, lossless `_foogm_compact/type/items` hydration/translation, logical history revision and atomic companion policy, nested collection/content/queue shape handling, engine adoption, and build/ZIP/package parity. Physical parity fixtures are not proof of that logical wiring. Do not implement table adapters by whole-history hydration or fall back to options.

Minimum PHP/WordPress, true multi-network topology, browser/customer-memory/large-job gates, resumable maintenance for states exceeding admission, and native independent approval remain OPEN. No fresh build is run in this worker (reserved for serial integration). No push, PR update, merge, deployment, release or production writes occurred.
