Coordinator-owned finite storage admission

StorageOperations authorizes the exact request and validates the existing trusted
StorageCalibration profile before constructing one StorageScaleAdmission. No
profile is shipped and no request field can supply limits or policy. The fixed
internal limits reuse the existing validated finite scale ceilings. They are
refusal bounds, not throughput, memory sufficiency, or workload promises.

The same domain and deadline reach raw decode/encode, coordinator logical hashing,
snapshot staging/readback, aggregate head/row/byte checks, and retained physical
census. The native memory path retains the receiver's sampled real backing and
later high-water samples; it neither lowers PHP's effective usage nor clears the
pending/work ledger between phases. A distinct dispatch owns a fresh domain.
Unscaled low-level callers retain their existing defaults.

Calibration identity now includes storage-scale-admission, generic-retaining-model,
repository-flatten-model and snapshot-stats-model. Their existing exact runtime
qualification predicates remain unchanged. A matching fingerprint binds bytes;
it does not confer approval on those experimental models or a new runtime.
Existing calibration approval and evidence requirements still apply independently.

StorageCoordinatorScaleTest uses explicit synthetic routing and query spies,
including a public-dispatch source-decoded checkpoint with no authority writes.
It is not native conversion success. The additive native worker uses separately
bounded read-only evidence operations for census/readback, not fresh domains
inside a product operation. Its factor/reserve remain unchanged and unapproved.
First real native refusal is a blocker, not permission to tune the envelope.
The original exact-byte corpus and all final acceptance gates remain open.
