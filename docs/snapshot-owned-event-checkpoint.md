# Disabled snapshot-owned attachment event checkpoint

This is a bounded implementation candidate, not PR15 acceptance or allocator
qualification. The original A1–A8, literal native runtime floors and customer
working acceptance remain OPEN_NOT_WAIVED. No date/ETA is implied.

## Scope and invariants

`LegacyTableSnapshot::$snapshot_events_enabled` is private and false by default.
Only privileged test reflection arms it. There is no request, option, filter,
constant, callback or public configuration switch. No production forecast is
reduced, even when the event path is privately armed. StorageScaleAdmission and
its supported-host/credit policy are unchanged.

A fixed-size `SnapshotOwnedEventPlan` accumulates scalar descriptors inside the
existing stats discard walk, from each already-built own row and own logical
fields. It never retains a record graph, row list, per-node ledger or generation.
Descriptor construction is admitted before allocation. Checked arithmetic covers
own bytes, key/string/field counts, child/root capacities and resize/COW, native
JSON/tagging, ancestor driver/page overlap, envelope and digest maxima. These are
unqualified candidate maxima, not measured allocator guarantees.

After complete preflight digest validation, the immutable scalar descriptor is
bound to its exact key/root/stats/digest. Every read creates a fresh owner bound
to the actual reader and blog/state/generation/root/revision. Missing/stale plans
or unsupported producer classes keep the unchanged aggregate path; no descriptor
is synthesized from a previous owner's remaining credit. Descriptors are bounded
to the latest replacement's admitted heads, not an accumulating global ledger.
A separate repository without a source-derived plan uses the conservative path.

The decoder captures own-only scalar cost before descendants. Only after the
record's existing projection/logical/child-count checks succeed does it return a
completion descriptor. The caller first attaches that value, then emits its own
record event after recursive callee unwind and before the next observer. No
subtree total, constructor return, exception or unvalidated partial value is an
attachment event. Existing sampler calls, fault hooks and forecast inputs stay
unchanged. Completed descendant events do not certify a failing ancestor.

The shadow balance is NOT connected to retaining-credit admission. Private/page/
digest maxima remain recorded through final digest/token validation. Replacement
completion follows `unset($value)`; standalone completion transfers the returned
value to its caller, never to replacement credit. Neither success nor exception
releases private maxima as admission credit. No early event certifies a completed
snapshot. Interrupted owners remain incomplete.

## Executable checks

`vendor/bin/phpunit --do-not-cache-result tests/StorageSnapshotOwnedEventTest.php`
exercises the real producer/decoder/row-reader with the existing synthetic
SnapshotScaleConnection transport. It covers post-unwind visibility, own-only
postorder events, unchanged observer sequence/forecast inputs, source binding,
fresh reads, missing/stale plan fallback, logical/projection/fetch faults, final
digest interruption, actual token-walk deadline interruption, and refusal when a
low observer would hide actual allocator backing. This is not native DB evidence.

Focused unchanged regressions:

    vendor/bin/phpunit --do-not-cache-result --filter 'StorageSnapshotStreamingBoundaryTest|SnapshotInternalStatsTest|LegacyTableSnapshotTest' tests

The authoritative delivery directory holds RED, intermediate failures, actual
final counts, byte hashes and checkpoint handoff. Only the installed PHP 8.5 CLI
is exercised here; declared PHP 5.4 requirements are unchanged, not qualified.

## Next gate

The exact new commit requires a DISTINCT read-only receiving review. Then the
next implementation qualification slice is finite original256 immutable-source
and actual-ZIP event qualification, not another measurement-only loop. Do not
turn on forecast consumption from these tests. Full original suites, source/ZIP
qualification, native runtime floors, calibration, A1–A8/final acceptance, push,
remote PR and CI verification remain pending and require their own gates.
