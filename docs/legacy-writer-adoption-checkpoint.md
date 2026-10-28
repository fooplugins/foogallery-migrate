# Production writer-adoption checkpoint (Milestone A still incomplete)

This checkpoint integrates the accepted request writer/maintenance barrier into the production options factory. It does not implement or accept bidirectional conversion, authoritative table selection, calibrated decoding, interrupted-state recovery or safe stale-owner takeover. Public table opt-in remains disabled.

## Production contract

- Each of the 21 mutating AJAX/admin-post methods authenticates and checks its existing nonce before obtaining request writer admission, and before constructing/accessing the engine or invoking source adapters. AJAX admission failures return the existing structured message shape with HTTP 503; non-AJAX failures terminate without mutation.
- The source-screen detect/clear/error-check POST path also admits after capability/nonce checks. The engine and base migrators defensively admit before detection, source discovery, queue/cancel/migrate, content scan/status/replacement, tag synchronization and migrated-history changes. Gallery/album/image output entrypoints admit too; image downloads recheck before sideload/attachment writes.
- The default production engine binds its captured database/blog gate to settings and history option-backend writes. Settings-option saves also require admission. Inert explicitly injected repository seams stay inert; they cannot provide an affirmative production-engine quiescence verdict.
- Nested production work renews one request marker rather than accumulating nested leases. The already admitted writer may finish behind a newly acquired maintenance gate. Maintenance cannot become ready until the marker is released, and cannot treat a pending request-local history batch as drained. No expired marker is silently pruned or replaced with a fresh owner.
- A cached engine rejects cross-blog writes; its quiescence verdict also requires the captured current context. Shutdown uses the accepted owner-checked release, including switched-blog termination.
- Control/sentinel/migration/schema option presence is checked through fresh bounded SQL, not `get_option()`, object hydration or a cached negative. Empty/corrupt control evidence refuses options-mode work. This remains conservative existence-based authority, not the versioned control/CAS phase machine required for conversion.

## Verification and evidence boundary

`tests/StorageWriterAdoptionTest.php` covers all 21 endpoint denials for maintenance, persisted-control evidence, and maintenance racing the writer insert, with engine-access and retained-state traps. Additional tests exercise production writer continuity/readiness, pending batches, cached-engine context, lost/expired ownership, and strict backend callback admission.

`tests/integration/storage-writer-adoption.php` runs those real endpoint denials using native WordPress capabilities/nonces/SQL and production engine persistence, retained raw state/autoload comparisons, actual query-intercept races, writer drain/batch verdicts and multisite cached-engine rejection. The allowlisted Cove runner checks exact clean source/ZIP parity, a final validated payload/sentinel, full scoped database-data equality and invocation-owned cleanup. It does not provide separate-process or HTTP/browser race acceptance.

Actual candidate identifiers, frozen ZIP hash, executed receipts and cleanup records belong in the seven-part durable handoff under `/Users/brad/.hermes/profiles/dev/diagnostics/t_396d60fe/checkpoint-writer-adoption/`; do not infer native success from this protocol document. PHP8.5 PHPUnit ReflectionMethod deprecation notices are existing test compatibility noise, not a literal-floor receipt.

## Finite remaining Milestone A map

1. Versioned bounded control validation, site/epoch/switch identity, matching table-authority sentinel, fresh raw CAS and interruption phase rules. Only validated controls may select an authoritative backend; unknown/corrupt/mismatched evidence must block without fallback.
2. Calibrated authorized source decode behind the existing admission and maintenance barriers. Raw size evidence alone must not grant decoding or promise resumable decode-memory reduction.
3. Stage option-to-table generations, reconstruct typed-key/order/revision/logical parity, prove immutable source immediately before the flip, retain a distinct backup, then sentinel/control flip and removal of the live stale option name.
4. Reverse export from the current authoritative table state, never from retained backup; verify a fresh complete option and source revisions before the option flip and sentinel removal.
5. Authoritative interrupted-state recovery and explicitly safe stale-owner takeover without source/destination merging or automatic loss of ownership evidence. Recheck the engine verdict and writer drain before every flip.
6. Complete native interruption/failure/race matrix and independent exact-candidate review, including separate-process evidence where required. Literal PHP5.4/WP5.0 proof and all final source/package/release gates remain hard.

Continue the SAME `t_396d60fe`; do not create another writer card or release `t_7dc60149` / `t_8552160b` from a partial checkpoint. Public lifecycle/UI, large-AIGPL acceptance, PR publication, actual merge and downstream work remain their separately authorized gated stages. No deployment, remote publication, new runtime provisioning or compatibility waiver is authorized here.
