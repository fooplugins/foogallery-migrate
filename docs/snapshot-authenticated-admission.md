# Authenticated snapshot read admission (bounded candidate)

This change is not production profile approval. No profile is shipped or loaded
from options, SQL, requests or a global registry. The default and unsupported
paths retain legacy admission. A source/package test pass is not native SQL,
literal old-PHP or original A1–A8 acceptance.

The producer may build an operation-local SnapshotPageWitness beside its scalar
plan. Its fixed column order covers every physical record column, including
scope, inserted ID, generated identity, structural/projection values, byte counts,
physical and logical hashes, ordering and explicit NULLs. Unsigned decimal and
fixed-width HEX are canonicalized; raw strings are length-prefixed and hashed
incrementally. The INSERT defaults/arguments supply the bytes, not a DB readback.
The index is admitted before construction, retained across standalone reads,
and cleared with producer authority on replacement or rollback.

A bounded raw page is authenticated in its entirety before it can reach key or
native record decoding. Those same rows are then consumed without a refetch.
A matching physical payload self-hash cannot substitute for producer authority.
Scope, cursor, projection, record/root digest, revision and census checks remain.

SnapshotReadPlan separately traverses the COMPLETE logical value, including
children, child keys, unknown fields, wrapper/header order and all scalar digest
encoding. Own-output attachments still have their original, narrower semantics.
Private accounting retains the diagnostic floor and adds the witness, complete
logical frames/scalar scratch, complete children/wrapper container COW and a
separate two-retained-output COW span. These are conservative component FLOORS,
not newly calibrated allocator coefficients. Actual input/backing is not removed.

Only an exact producer-sealed plan/witness pair and a matching finite host profile
can create SnapshotReadPhase. Entry (including raw-root fetch), reconstruction,
prospective pages, digest/envelope and exit then use one remaining-own + WHOLE
private + measured-margin policy. Scale pending liabilities, actual allocated
backing, observer limits, native high-water, deadlines and the >=32MiB reserve
remain additional. Active failures cannot switch to a lower legacy budget or
release an interrupted scope. Every standalone read creates a fresh owner.

SnapshotReadProfile is an internal OFFLINE validator. It binds exact binary,
INI, extensions, architecture, allocator, codec implementation and compiler bytes;
it accepts no runtime interpolation. It checks repeated fresh-process raw trace
hashes, complete before/after row/overlap seam coverage and arithmetic, measured
residual/spread/allocator/observer margins, attachment-only own consumption and
retained private. Its finite successful-read grammar is root-native, page-chain
(including all bounded row/digest/COW seams), envelope, final-digest, then exit.
Each owner must exit before the next read; no work follows exit. Only attach:after
(validated output assigned after the recursive callee returned) consumes own debt;
unwind samples grant no credit and may bracket that event or follow it. All row
attachments/unwinds must finish before envelope/digest/exit, with zero own debt
but unchanged private liability. This does not reconstruct per-row byte costs,
prove arbitrary-hook ordering, or authenticate declared variants/measurements.
Qualification also reconstructs SnapshotReadPlan's exact component algebra from
the supplied features: 4096*(depth+1) digest frames, 16*max(string,scalar encoding)
scratch, containers+max_resize whole COW, and 2*containers retained-output COW,
plus exact legacy-private and witness workspace. The full descriptor must equal
own with only its private total replaced. Zero/understated, inflated or same-sum
redistributed components are refused: none equals the producer plan required by
supports(). This is structural consistency of declared features, not independent
authentication of a graph, observer or measurement. The bounded R2 mismatch is
covered by synthetic API and real repository CLI regressions with valid controls.
Synthetic lifecycle/arithmetic controls are NOT measured qualification.
tests/tools/qualify-snapshot-read-cell.php emits a finite host receipt,
not an installation, production enablement or native/literal-floor approval.

Verification boundary: the mandatory regression cases exercise producer/page
trust, zero native decode entries for the review's exact 733-byte 70->111 token
mutation, consumed-column mutations, fallback, complete overlap and retained
results, and rejection of incomplete cells. Negative/partial probes do NOT
qualify profiles. No complete finite host cell has been established by this
candidate. The original unchanged 20K/256M test remains a separate required gate;
failures must be retained, not relabelled as later fault-boundary coverage.
