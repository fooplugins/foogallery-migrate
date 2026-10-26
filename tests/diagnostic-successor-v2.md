# Candidate diagnostic successor v2 (offline only)

Production-byte base: 054d41c8f51cff316ce696d7a0932c63f2f6a16b.
The final author commit/archive manifest identifies the whole diagnostic candidate;
the base identifies unchanged production bytes, not a self-referential final SHA.

StorageProductCallerTraceV2 pins all inherited product/source-model bindings,
with a separately versioned current StorageOperations binding. Before returning
paths it checks fingerprints, reflects CURRENT method source and tokenizes real
object calls (comments/strings are not call tokens). It derives source lines,
actual argument expressions and argument counts in their enclosing methods,
checks concrete manager/receiver/decoder construction and phase/direction guards,
and checks callee formal arity/nonreference signatures. The existing 18-path
finite topology and conditional arithmetic are inherited explicitly, not claimed
as newly observed end-to-end execution. Replacement advance option-decode paths
remain excluded. Original unresolved fields stay null/false.

Both dependent compositions have explicit V2 names/dependencies. Their arithmetic
is copied unchanged rather than editing the historical witnesses or injecting a
new binding through a historical class. Existing test assertions are retained;
current-candidate tests and the scalar-event consumer now select V2 explicitly.
The three original support files, original direct probes and published docs stay
byte-identical/historical, including their original fingerprints and provenance.

Fresh commands (PHP8 only, test bootstrap/WordPress stubs, no SQL/VM):

    php tests/tools/storage-diagnostic-successor-v2-probe.php
    php tests/tools/storage-product-caller-trace-v2-probe.php
    php tests/tools/storage-product-argument-retention-v2-probe.php
    vendor/bin/phpunit --do-not-cache-result

The first probe exports freshly derived semantic paths and both composition
bindings, and must reject original stale fingerprints, hash-only rebound old
paths, a replacement-boolean mismatch, a wrong dispatch target and stale handle
line. The other probes are separately named re-executions of the original
concrete arithmetic/control programs against V2, not relabelled historical logs.

StorageDiagnosticSuccessorTest additionally executes six real built-in
switch/replace/recover receiver/caller suffixes (dispatch and admin-post) as far
as coordinator begin. It captures actual class/method/argument/callsite data and
compares it to V2. The existing no-connection test pattern deliberately refuses a
pinned connection; no transaction/conversion is faked as successful. The existing
scalar-event consumer exercises actual handle-to-begin event propagation. The
bootstrap unslash is an identity stub: no native WordPress/PHP5 unslash claim.

This is source-bound finite diagnostic material, not calibrated admission,
allocator/GC capacity proof, original A1-A8/G1-G4 acceptance, runtime-floor replay,
full conversion/recovery or final delivery approval. Original floors/native/VM
receipts remain bound to their original candidates only. No runtime changes.
All successor files live under tests/ and must be absent from the real gulp ZIP.
