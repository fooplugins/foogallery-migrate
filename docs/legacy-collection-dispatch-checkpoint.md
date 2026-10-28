# Bounded collection/deadline integration checkpoint

This is partial progress on existing PR15, not integration/Milestone A acceptance. Options remain the default authority; no public selection, conversion, provisioning, bootstrap, schema, lifecycle, new adapter or issue14 change is included.

## Exercised scope

- Existing gallery/album facades dispatch to table-scoped, cumulatively budgeted collection reconstruction and exact containing-root updates. Typed `09`/integer `9`, nested children, compact unknown binary data and unchanged roots are covered by real SQL option/table comparisons. Collection replacement uses existing root patches and its matching scalar checkpoint atomically. No history hydration or option fallback is introduced.
- Existing scalar heads support bounded aggregate reads, distinguishing missing from false/null, and existing scalar writes. Missing-head creation/deletion are NOT implemented; unsupported content-scan/image-tag partial mutations explicitly refuse rather than falling through to the option API.
- The existing injected engine constructor and single-setting getter/setter exercise an AIGPL-owned gallery collection in real WordPress. This does NOT prove the complete approved opt-in/AIGPL migration vertical slice.
- Explicit bounded history clear propagates the caller's existing deadline into snapshot admission, preflight, staging and atomic publication, including its existing logical revision companion. Expired admission and delayed real metadata/staging/publication SQL refuse; timeout rollback preserves all physical heads/records/companions. This is cooperative checking, not a server-side SQL cancellation or strict wall-clock limit. Successful in-flight COMMIT is not falsely reported as rolled back.
- A guarded disposable multisite harness registers a real second network, temporarily moves the already initialized owned site 2 there, and explicitly reads the same two installation-wide base-prefix tables from both positive blog scopes. Typed identities, wrong-blog cursors/heads, zero scope, no per-blog duplicate tables, physical-byte refusal preservation and topology cleanup readback are verified. It tests scope isolation, NOT plugin provisioning. An initial new-site fixture attempt failed on WordPress's missing `UPLOADBLOGSDIR`; the owned orphan was removed and verified, without any core/runtime/config/guard fix.

## Verification and preservation

Authoritative commands, RED/GREEN logs, exact candidate SHA, package SHA-256/byte parity, cumulative unit/regression/build/syntax and source/extracted-package SQL matrix are in:

`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/ops-integration-collections-20261007/verification-evidence.json`

The adjacent `implementation-handoff.md` owns the concise seven-part specialist handoff. Receiving Default owns card lifecycle and independent acceptance. Generated POT changes are source-line references only; the original creation timestamp and message bytes are retained. Bootstrap/readme, schema, authority, no-op lifecycle/deactivation retention, source-URL refusal and preserved historical patches remain outside this checkpoint's implementation delta.

## Finite OPEN acceptance rows

1. Common parity: missing scalar create/delete; bounded content-scan affected segments/progress; image-tag partial queue operations; collection reorder/list-removal semantics required by actual callers.
2. Approved injected-engine opt-in/AIGPL vertical, beyond the exercised collection setter/readback.
3. Declared minimum runtime: readme WP6.0/PHP5.4 conflicts with bootstrap WP5.0/PHP5.4. Official WordPress 5.0 requires PHP5.2.4; WordPress 6.0 requires PHP5.6.20. This host supplies PHP8.5.11 only and no available Docker/Podman/Colima. WP5.0/PHP5.4 and WP6.0/PHP5.6.20 execution remains OPEN; no metadata floor was raised.
4. Receiving exact-candidate independent native review/acceptance; public conversion/UI, large AIGPL, publication and actual merge remain separate/deferred gates.

Resume the next finite behavioral slice from the clean SHA in the adjacent handoff, first adding OPTION/TABLE missing/set/delete contracts. Existing scalar mutation requires existing heads; deletion explicitly refuses. Do not invent engine methods or add a new backend/authority model. No push, PR update, merge, deploy, release, production/customer action or profile persistence is authorized by this checkpoint.
