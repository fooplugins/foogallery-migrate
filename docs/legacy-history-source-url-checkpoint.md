# Explicit table source-URL refusal checkpoint (partial integration)

Task `t_04219c53`, branch `feature/migrated-store-scalability`.
Baseline `82e6536f8abd657e3d7a8eaabd931c814c6bc2de`.
One authorized source-URL refusal contract, NOT Milestone A acceptance.

## Contract and scope

`MigratedHistoryRepository::find_by_source_url()` on an explicitly injected table
backend returns `false` for an empty string or non-string URL without backend
reads. These inputs cannot match under the existing option contract, irrespective
of type or history state. It does not cast scalars or stringify objects.

ALL nonempty strings throw `RuntimeException` with the operation-specific message
`Unindexed table source URL lookup is not admitted. No option fallback.` BEFORE
any backend metadata read, SQL, canonical hydration or option lookup. This includes
an exact known option match, absent/case-different URLs, whitespace-only strings,
binary data and long strings. This refusal is NOT a negative match, success,
URL validation, normalization or permission to scan small histories.

Schema v1 has no source-URL projection/index. The existing source identity hashes
encode type/plugin/ID, not URL. Successful scalable URL lookup would need a
separately specified and verified admission path; it cannot simply reuse those
hashes or silently scan/materialize history. This checkpoint deliberately does
not introduce a schema upgrade, derive a new projection, or adopt a whole-history
scan. An explicitly capped canonical small-history policy would also be a new
admission contract, NOT proof of scalable URL lookup; it is not implemented here.

Option dispatch and exact-case/first-positive lookup remain byte-unchanged.
Existing bounded source-ID contracts (including empty IDs and null plugins),
physical table admission, all other operation-specific refusals, schema/options,
no-op lifecycle, authority and public engine selection are untouched. Only the
history facade, unit/read harnesses and this note are owned in this slice.
`includes/class-legacy-table-repository.php` remains byte-unchanged; its existing
serialization hotspot must be respected by any continuation.

## Verification

Replayable commands, actual RED/GREEN stdout/stderr and machine evidence:
`/Users/brad/.hermes/profiles/dev/diagnostics/t_04219c53/source-url/`.

Focused PHPUnit RED (exit 2) and guarded real WordPress RED (exit 1) both failed on
empty URL input BEFORE production edits. GREEN unit tests verify invalid-input
parity and explicit nonempty-string refusal using the real table backend with a
connection that records/throws on SQL. Guarded disposable WordPress reads verify
zero observed backend SQL, unchanged physical/logical metadata heads, exact
option bytes and no option writes, even when the option backend finds a match.
Prior read/summary/mutation/wrapper/shared/options/table/lifecycle harnesses are
replayed on single-site and actual registered multisite blogs 1 and 2, for source
and separately extracted test package. Exact totals and ZIP digest live in
`verification-evidence.json`; seven-part handoff in `implementation-handoff.md`.

Implementation verification is not independent acceptance. Minimum supported
PHP/WordPress, actual MySQL, true multi-network, browser/customer-memory/OOM and
10x large-job gates remain unexecuted. Existing candidate/budget checks elsewhere
do not prove bounded SQL scan work, query duration or peak heap.

## Open / next action

The authorized source-URL/refusal slice is complete, but successful table URL
lookup, retry/clear/export, remaining adapters, public engine adoption,
preflight/conversion/authority/recovery/nested flows and cumulative integration
remain OPEN. Fresh SAME-card continuation requires orchestrator allocation of
ONE next contract; do not automatically re-run this refusal slice or expand its
scope. Preserve this refusal until a replacement admission contract is verified.
Keep `t_39cfe477` and downstream validation, native independent review, publication
and actual-merge gates HELD. No push, PR update, merge, deploy, release/SVN,
production/customer, cron, profile or spend action is authorized.
