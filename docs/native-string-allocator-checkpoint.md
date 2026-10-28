# Native string allocator correction (bounded PR15 checkpoint)

The pinned PHP 8.5.11 Darwin arm64 CLI native `LegacyStateCodec::decode()`
path retains 8192 bytes for a 3052-byte string. The inherited `64 + 2*n`
forecast was 6168 bytes; lengths 3052..4063 underforecast across x, binary
ff and quote patterns. This is a forecast counterexample, not a demonstrated
memory-limit violation. Substring allocations do not model this path.

Above the unchanged calibrated 0..231 domain, `string_bytes()` now charges
the maximum of the inherited bound and the next power-of-two allocator
class containing `4*ceil(n/3) + 32`. The native base64 decoder can retain
capacity for its encoded input length. The 32-byte allowance covers the
string header, NUL and alignment; rounding conservatively bounds the
supported Zend allocator classes, rather than doubling decoded length.
The inherited bound remains a floor, so no previously charged string is
cheaper. Arithmetic and allocator-class growth use checked operations.
This is not a universal allocator or never-OOM theorem.

`NativeStringAllocatorTest` uses actual native codec decode at
3051/3052/4063/4064 for all three patterns, independent larger heldouts,
the unchanged encoded-cap refusal for ff393217, overflow refusal and a
2201-row retained-output roundtrip (2200 repeated 3052-byte values).
The batch keeps 9401337 encoded bytes and exact serialized parity. Its
working-tree forecast changes from 16999056 to 21451856, covering measured
retained growth 18092504 and peak growth 18352448. RED-first replay fails
six boundary cases and the batch against the inherited formula; all new
cases pass with the correction. Existing short native calibration remains
covered by `RebuildLifetimeTest` and the reviewer native probe.

No support checks, PHP-floor guards, policy caps, 33554432-byte reserve,
codec domain, deadlines, fixture inputs or snapshot implementation change.
The original 20K snapshot gate remains separately RED; original native
byte-domain/persistence, public UI/CLI calibration, realistic large-state,
AIGPL, recovery, cleanup/isolation, literal floors/deadline/output/data
and complete A1-A8 acceptance remain open. The 15 October deadline is
missed. This is not final acceptance or permission to push/deploy.

Exact source/production ZIP identities, argv/environment, origins, case
sets, native sweep and calibration results are recorded externally at:
`/Users/brad/data/project-evidence/2026-10-14/foogallery-pr15-delivery/resume-today/a7-native-allocator-author/`.
