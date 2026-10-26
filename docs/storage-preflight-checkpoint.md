# Read-only storage admission checkpoint

This is a prerequisite slice, not opt-in conversion acceptance. Option mode
remains the only production-selected backend. No public storage endpoint,
selector, automatic installation, table adoption or authority switch is added.

## Server-side request contract

`StoragePreflight::option_import()` requires native `manage_options`, the current
WordPress blog ID, a dedicated nonce for
`foogallery_migrate_storage_option_import_<blog_id>`, and exactly these fields:

- `blog_id`: the actual current blog ID (integer or its canonical decimal string)
- `scope`: `site`
- `target`: `table`
- `confirmation`: `switch-to-table`
- `nonce`: a nonempty string valid for the dedicated current-site action

A generic migration nonce, cross-blog scope, network action, arbitrary SQL table,
extra field or coerced confirmation is rejected before database inspection.
This is the future current-site option-import preflight only; it does not grant
network schema provisioning authority or authorize reverse conversion.

The only storage read is a prepared, fixed-option
`LENGTH(option_value) AS option_bytes` query against WordPress's current-site
options table. No option payload, `get_option()`, engine, record hydration,
backup, control write or SQL mutation is used by the preflight primitive.
SQL errors, invalid table identifiers and invalid/overflowing lengths fail closed.

## Decode policy deliberately has no production default

The constructor's optional policy is trusted internal configuration, not a
request field. It must contain three positive PHP integers:

- `max_raw_bytes`: calibrated hard ceiling, even when PHP memory is unlimited
- `amplification`: calibrated peak multiplier including raw bytes and decoded graph
- `reserve_bytes`: protected headroom for staging, validation and response work

The actual PHP `memory_limit` and `memory_get_usage(true)` are inspected. The
pure `decode_budget()` helper also permits deterministic boundary testing.
Unknown/overflowing memory limits, multiplication overflow, insufficient
headroom and oversize sources return a typed refusal with no writes.

The policies in tests are SYNTHETIC arithmetic fixtures, not benchmarks. No
production decode factor or safe-size guarantee is inferred from these tests.
Without an explicit policy, the primitive returns
`foogallery_migrate_storage_decode_policy_required`.

An admitted observation has `decode_admitted=true` and
`conversion_ready=false`. It is NOT a readiness or authority verdict. The
future manager must first prove authority/schema, acquire writer quiescence,
re-read raw size and effective memory immediately before decoding, validate
unsupported structures, prove staging parity, and perform an atomic authority
handoff. Do not cache this observation as permission to decode in a later request.

## Backup-only authority protection

The existing conservative option-authority gate now checks row existence for
both the old history backup and `foogallery-migrate-data-storage-backup`.
Even empty, false, null or corrupt backup rows are prior-conversion evidence:
they require recovery and never implicitly confer option or table authority.
This probe uses `SELECT 1 AS present`, preserving legacy mysqlnd compatibility,
and does not load or restore either backup. The future validated authority
selector must replace this conservative gate, not reinterpret its refusal as
permission to discard recovery evidence.

## Verification and replay

Focused unit regressions live in `tests/StoragePreflightTest.php` and
`tests/StorageAuthorityTest.php`. A real WordPress harness lives in
`tests/integration/storage-preflight.php`. Its disposable-site guards refuse
existing plugin state; it checks native capabilities/nonces, fixed-site SQL,
byte-exact source/autoload preservation, protected option-read traps, backup-only
recovery and invocation-owned option cleanup. The harness requires an existing
administrator and never creates users or migrated output.

The focused Cove runner takes four absolute/exact inputs:

    bash tests/integration/run-storage-preflight-cove.sh /absolute/clean/repo /absolute/candidate.zip /absolute/new/evidence/directory <40-character-candidate-sha>

It archives a clean exact commit, compares runtime source/ZIP bytes, verifies
WordPress core checksums, creates collision-resistant owned single-site and
multisite fixtures, and runs source and package on single/main/subsite scopes.
Each case requires a valid success payload, an exact final sentinel and identical
before/after database DATA dumps. DDL and AUTO_INCREMENT identity are not claimed.
Cove setup credentials are private scratch files and are discarded. No services,
TLS trust, existing sites or older Docker fixtures are changed. Owned roots and
databases are removed with cleanup read-back. Creation failure is explicitly
non-owned and never triggers deletion of a possibly unowned resource.

This focused harness is not the broader release gate or a benchmark, browser,
second-network, persistent-cache, scale or literal PHP 5.4/5.6 acceptance test.
Existing frozen runner evidence remains historical for its own candidate SHA.
