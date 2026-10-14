# Object-free legacy option decoder — bounded partial checkpoint

Continues `storage-output-drain-checkpoint.md` on SAME `t_396d60fe`.
Milestone A remains incomplete; this is the authorized safe-decoding alternative,
not a supported-floor compatibility receipt or independent acceptance.

## Change and invariant

`StorageConversion::option_snapshot()` retains authorization, trusted calibrated
whole-option memory admission, fresh digest/readback, maintenance/drain checks,
and subsequent logical codec validation. It no longer refuses solely because
PHP lacks `allowed_classes`. `LegacyOptionDecoder` proves that the entire raw
buffer contains only scalar/array tokens before calling PHP's native decoder.
Object/custom-object/enum/reference tokens refuse even when deeply nested;
strings are skipped by exact byte length, so binary/token-like string contents
are preserved. Keys admit only canonical integers and strings; native physical
roundtrip must match exactly (duplicates, normalized keys, trailing bytes and
noncanonical scalars refuse). No historical object wakeup/autoload is allowed.

Admission limits are trusted policy raw bytes, 100 root entries, depth 64 and
30,000 structural nodes including keys. Every overflow/unsupported state refuses
without narrowing source state or publishing destination authority. The scanner
constructs no graph and is not a custom streaming conversion parser: the full
buffer is still decoded by native PHP once, under the existing calibrated
memory budget. No resumable decode-memory reduction, object conversion, public
request policy override, activation provisioning, option fallback or stale-owner
algorithm is introduced. The modern decoder retains `allowed_classes=false` as
defense in depth; the PHP5 native-call branch requires literal-floor validation.

## Test-first evidence

Local test-first commit `5339591` captured 32 failing cases on received clean
`0afc976` (new class absent; errors/expected-exception failures, not a native-floor
security reproduction). After production changes, focused tests pass 32/40;
full PHPUnit passes 507 tests/2,176 assertions on PHP8.5.11, with existing
reflection deprecation diagnostics. Exact final candidate, package hash,
commands/exit codes and any native PASS receipts live in the diagnostic
seven-part handoff and executed verifier, not inferred floor acceptance.

The native conversion fixture additionally drives hostile raw source bytes
through the actual authorized coordinator, verifies no object hooks/autoload,
exact retained source, absent intent/authority/backup/staged current-blog rows
and released owned marker, then restores the valid source and exercises the
existing bidirectional verified conversion/recovery matrix. Use exact source
and package on single/main/subsite fixtures with independent raw database
before/after equality. Calibration/source fixture remains synthetic; this is
not installed-product/browser/large-AIGPL or hard-PHP-death evidence.

## Remaining / fresh SAME-card continuation

Historical object-bearing options remain unsupported and must retain their
recorded authority/evidence. Literal PHP5.4.0/WP5.0 and PHP5.6.20/WP6.0 decoder,
syntax and native conversion receipts, production calibration, delayed legacy
teardown, all-output/batch drain, independent uncertain readback/hard-PHP-death
and safe stale-owner recovery are still OPEN. Public authorized selection/UI and
independent exact-candidate final validation remain outstanding. Existing v1
markers stay blocking; no clock/expiry-only takeover is permitted.

`task_scope_completed=false`, `milestone_A_accepted=false`; preserve dependency
holds on `t_7dc60149` and `t_8552160b`. Next fresh bounded SAME-card slice: complete
actual output/batch drain plus independent hard-death/uncertain-readback evidence
before stale-owner admission, or exact-runtime verification in the existing
legacy owner's approved lane (no runtime provisioning/waiver). Preserve init,
mixed-newline content migrator and paused unrelated work. No push, PR mutation,
merge, deploy, release, SVN, production write or customer contact.
