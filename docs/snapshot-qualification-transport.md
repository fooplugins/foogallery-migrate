# Finite snapshot qualification transport

This is offline transport/schema tooling, NOT measured profile approval. No
profile is shipped, installed or read from options, SQL, HTTP or a registry.
Original20K/256M, native persistence, literal floors and original acceptance
remain independent gates. Synthetic exports are explicitly SYNTHETIC_NOT_MEASURED.

## Contract

SnapshotReadTraceStream::writer(path, header), append(event), finish() writes
one bounded JSON record at a time. append can receive an actual finite seam
sample without storing the trace. finish closes the file before returning its
whole-file SHA256. Store that digest in the corresponding cell.trace_sha256
entry. Set cell.transport to snapshot-read-ndjson-v1 for the qualification CLI.
The header preserves the existing runtime/INI/source/connection/input identity,
features, process and variants fields, but excludes events. Records are:

    {"format":"snapshot-read-ndjson-v1","trace":{...existing metadata...}}
    {"event":{...one complete existing event...}}
    ...all events, no sampling or truncation...
    {"end":485034}

Every record, including the final one, ends in LF. Maximum record size is 65536
bytes INCLUDING LF; oversized metadata/events refuse, never truncate. The
external SHA256 covers every exact byte (header, events, footer and newlines).
The reader verifies the hash incrementally at EOF before returning a profile.
It requires exactly 6*(7+4*records) events per trace, the footer's exact count,
no trailing bytes and all original ordering/coverage/debt/lifecycle checks.
Missing, duplicate, reordered, corrupted or truncated data refuse even when a
malformed file is re-sealed. A hash is integrity/binding, NOT proof that claimed
measurements, PID freshness, variants or an observer are genuine.

SnapshotReadProfile::qualify_files(cell, paths) consumes one event at a time.
Its private anonymous disk workspace holds fixed-width integer records for seen
coverage, open before-samples/LIFO nesting and repeat residual min/max/count.
Only the current record, scalar extrema, three read balances and PID metadata
remain in PHP memory. The exact tightest capacity slack replaces the old complete
sample array; margin arithmetic remains max residual + repeat spread + allocator
quantum + independently established observer bound. Disk offsets use checked
arithmetic; tmpfile/seek/read/write failures refuse and handles close on exit or
exception. Disk use is finite O(records), not zero; no universal disk/never-OOM
claim is made. Configure TMPDIR to an owned scratch location when operating it.

The bounded legacy qualify(cell, raw_strings) API and legacy CLI JSON dispatch
remain available with the existing 16MiB-per-trace cap. Only the new explicit
streamed path scales to the original required volume. New transport/workspace
source bytes join runtime_binding's product-compiler hash.

## Reproduction

    php -d memory_limit=256M -d opcache.enable_cli=0 tests/tools/export-snapshot-read-plan-cell.php transport-original-volume EXISTING_EMPTY_DIR
    php -d memory_limit=256M -d opcache.enable_cli=0 tests/tools/qualify-snapshot-read-cell.php DIR/cell.json DIR/trace-1.ndjson DIR/trace-2.ndjson DIR/trace-3.ndjson

This exporter remains SYNTHETIC_NOT_MEASURED: 20208 synthetic record slots,
485034 full events PER repeat, three synthetic repeat IDs, not a product
calibration. transport-small emits the equivalent one-slot control. The volume
regression independently traverses every event and checks its content digest,
whole-file hash and count, then executes the real qualification CLI. Both tools
report actual memory peaks under unchanged 256M. Qualification explicitly reports
transport_schema_valid=true and measured_profile_approval=false; it does not
install a profile. Existing plan-floor exporter modes remain compatible.

## Separate finite acquisition blocker

The current source cannot bootstrap the refused real cell through its qualified
phase: LegacyTableSnapshot::begin_read_phase requires an already matching
SnapshotReadProfile at lines 526-530. Without it, read_generation:457 and the
row-page callback at 471-478 retain legacy 8*encoded admission, including the
real prospective page workspace at LegacySnapshotRowReader::page:53. The named
receiving evidence establishes refusal even without an observer, before native
row reconstruction. Transport does not change those code paths.

The next admission delta must break that concrete circular acquisition contract
with independently justified finite acquisition authority, not fake qualified
cells or reflection into the private profile constructor. Exact boundary is the
pre-read selector (begin_read_phase), read_generation's prospective page callback,
and SnapshotReadPhase::__construct/workspace. A new authority would have to prove
its own full prospective bound for this exact source/runtime/connection, keep
actual backing/pending/page/private/reserve and all existing refusal checks, and
refuse on stale/missing/changed authority before decoding. The existing numeric
component FLOORS alone do NOT supply that proof. Do not implement a selector-only
bypass. Until an independent bound exists, the current gate contract is circular
for this refused cell; it is not evidence that useful20K is impossible in principle.

Once justified authority exists, finite real seam acquisition must instrument the
actual root/page/record decode+attach+unwind/envelope/digest/exit boundaries in
LegacyTableSnapshot::read_generation and LegacyRecordCodec::rebuild_snapshot_stream
and its recursive worker, wiring samples directly to append. The present scalar
memory callback is not that complete telemetry. Do not relabel the synthetic
exporter or repeat unchanged diagnostics as acquisition or original acceptance.
