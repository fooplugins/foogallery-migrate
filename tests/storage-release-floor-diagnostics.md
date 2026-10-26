# Native PHP 5 release-policy diagnostics

**Historical runtime fixtures:** The plugin now requires WordPress 6.2. The
WP 5.0/6.0 fixture pairs and matching `storage-release-floor-*` scripts below
are historical diagnostics, not current minimum-version acceptance. Do not
relabel their results or merely change their hard-coded versions. Native current
floor validation needs a fresh WordPress 6.2 fixture on a PHP version supported
by that WordPress release; standalone PHP 5.4 syntax probes are not that evidence.

This is a negative-only diagnostic checkpoint, not a release-policy qualification
or proof of the large customer journey. No release class or capacity coefficient
is introduced by these tools. An empty shipped policy remains fail-closed.

## Separate corpus identities

`tests/support/StorageReleaseNativeFixture.php` defines
`a7-options-native-php5-20k-v1`, a text-oriented 20,000-image corpus built and pinned
on literal PHP 5.4.0 and PHP 5.6.20. Its serialized identity is:

- Bytes: 2998086.
- SHA256: `b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677`.

It is not equivalent to either the modern pinned corpus or the arbitrary-byte
corpus. In particular, these PHP 5 builds serialize negative zero as `d:0;`,
whereas the modern corpus includes `d:-0;`. The existing modern/binary fixture
pins have not been changed. Both the unmodified native source serialization and
the separate text-oriented serialization are checked before the fixture is used.
Other PHP runtimes are rejected rather than silently receiving another corpus.

The fixture unit tests check distinct identities, text bytes, deterministic shape
and rejection on other runtimes. Host PHPUnit is not execution proof for either
legacy PHP runtime.

## Owned-site public-path probe

`tests/integration/storage-release-policy.php` is CLI-only. It requires one of the
two fixed task-owned single-site databases, an exact PHP/WordPress pair, a real
administrator and an explicit operation. The pairs are PHP 5.4.0/WordPress 5.0 and
PHP 5.6.20/WordPress 6.0. Do not repoint it at a customer or production database.
`seed` overwrites the owned test option only after its scope checks.

Operations are `facts`, `measure`, `seed`, `preflight`, `switch`, `recover` and
`status`. The last four use the actual `StorageCli`/`StorageAdmin` paths, not a
replacement storage implementation. They require the exact seeded raw bytes,
options authority and absent control state; they record raw/control preservation
after the request and fail if a refused request changes either. Public responses
and separate test-only observer observations are retained as distinct fields.

On the retained source and built ZIP, both runtime pairs refused preflight,
switch and recovery with `foogallery_migrate_storage_calibration_required`.
Status remained uncalibrated, stable options storage with the exact 2998086-byte
option intact. These are expected negative receipts, not successful migration
receipts. The probe is deliberately not a future end-to-end success harness:
once legitimate migration changes authority or edits the fixture, its unchanged
raw/control preconditions no longer describe the customer journey.

## Measurement tools and limits

- `tests/tools/storage-release-primitives.php`: fresh-process array, scalar,
  string and raw-byte observations, with a native serialization roundtrip.
- `tests/tools/storage-release-layout.c`: sizes from the exact runtime headers.
- `tests/tools/storage-release-codec.php`: fresh-process tagged-codec shapes,
  including nested values, byte strings and deliberately invalid tagged JSON.

These are observations, not allocator certification or customer prerequisites.
A native option decode measurement is not the peak of conversion, row insertion,
verification, generation activation, edit, reverse conversion or cleanup.
Likewise, a few primitive/codec shapes do not bound every legal PHP graph,
allocator size class, SQL-driver buffer or source-controlled copy lifetime.
Do not turn their maxima into release-policy coefficients without covering the
complete legal phase domain and validating the actual public path.

All tools and fixtures are test-only and excluded from the production ZIP. The
release remains unqualified until a defensible release-owned policy is shipped,
finite-resource refusal and scratch/SQL admission are exercised, and the original
large workloads and complete customer journey pass on source and actual package.
