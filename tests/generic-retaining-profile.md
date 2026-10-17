Generic retaining producer checkpoint (not conversion approval)

The finite profile in GenericRetainingModel supports only the pinned PHP 8.5.11,
Darwin arm64 NTS binary, loaded extension name/version fingerprint, Zend allocator,
128M effective PHP limit, opcache CLI off, native memory sampler, exact built-in
record/value codecs, and native JSON. A separately measured exact three-line
native-forwarding test shim is recognized by its source-body hash, not its path.
Real WordPress's encoder, arbitrary wrappers, codec subclasses/altered limits,
injected memory samplers and unknown runtimes use the old conservative admission.
This qualification is not trust in callbacks or a never-OOM theorem.

Coefficient derivation and observations

- Mixed arrays: 128 + 40 * power-of-two capacity (minimum 8 for nonempty arrays).
  Key strings are charged separately. Native mixed growth measurements at 257,
  1024, 4096 and 20000 entries (including newly constructed keys) had live peaks
  above baseline of 41424, 78288, 320488 and 2613912 bytes respectively. Independent
  packed, mixed, COW and tagged JSON training covered 0,1,8,31,64,257,1024,4096,
  20000 entries and 0,31,257,4096,262144 byte strings.
- A row's fixed forecast is 2304: mixed row shell capacity 32 (1408), six new
  64-byte hash strings at 96 bytes (576), five column string headers/alignment at
  64 bytes (320). The body forecast is encoded bytes plus ceil(bytes/2), with an
  additional 4096 per column above the 3072-byte small-allocation threshold.
  Small-column alignment is covered by header/body slack; large-column page
  rounding is covered explicitly. No source field or output identity credit is
  necessary. Descriptor odd-row/large-column counters make the event sum exact.
- Caller-list/COW liability is 768 + 160 * rows. This covers worst mixed old/new
  capacity growth, remains independent of constructed rows in actual usage, and
  becomes legacy pending on close. Extra distinct copies require independent
  caller admission; callbacks are not assumed to retain only one copy.
- Descriptor/frame/arena workspace is 4759552 (4MiB plus 138 frames at 4096).
  Flatten tagged array/string/ancestor/JSON/hash bounds follow the existing
  measured RepositoryFlattenModel construction layout, with pending retaining
  row forecasts in addition, not replacing its private overlap.
- Rebuild hydrates one bounded row in its descriptor workspace, never a decoded
  tree. Workspace is descriptor bytes + 768 per maximum row-native token + eight
  times maximum row bytes. Future own fields use mixed capacity bounds, string
  headers and doubled string bytes; child list construction stays private until
  completion. Index/visited/output-list growth reserves old/new capacities, plus
  one maximum sibling-sort COW. Declared child-count bounds are authenticated
  against indexed parent rows before allocating adjacency buckets; invalid
  topology cannot escape the prospective bound.

Preselected held-outs: wide=1537, deep=17, binary=65537 bytes,
large scalar=393217 bytes, fields=193, sibling COW=3073. The executable probe and
GenericRetainingProducerTest check exact serialized parity, product origins,
component peaks, unchanged reserve, ballast and independently charged copies.
These checks support a finite candidate, not universal allocator certification.

Status: generic original flatten now emits the unchanged 20208 rows and 15070354
encoded bytes. Original rebuild's finite conservative fit is NOT_ESTABLISHED.
The unchanged StorageScaleAdmissionTest original roundtrip/parity/deadline gate
remains mandatory and RED; no smaller test substitutes for it. Snapshot integration
is deliberately absent. Historical tests now characterize the explicit legacy
fallback, not require the qualified generic flatten to fail.
