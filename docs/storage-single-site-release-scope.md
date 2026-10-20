# Narrowed table-storage implementation checkpoint

This is a partial implementation checkpoint, not PR15 acceptance or release approval.
The existing table-storage foundation is retained. PHP 5.4 and WordPress 5.0 remain
supported floors; ordinary option-backed migrations remain the default.

## Release scope and operator workflow

- Table mode is single-site only. Multisite administrators cannot provision,
  preflight, switch, recover, replace or discard table storage. These refusals
  occur before storage mutation. Ordinary multisite option reads/writes continue.
- A pre-existing multisite table authority is a recovery error, not permission
  to read stale options. Preserve its tables, markers and other recovery evidence.
- Inspect Settings > Migration Storage or `wp --user=admin foogallery-migrate
  storage status`. Inspection never provisions tables or selects authority.
- Back up, then explicitly provision schema if needed. CLI parity is available:
  `wp --user=admin foogallery-migrate storage provision --target=table
  --confirm=provision-shared-schema`. Provisioning retains option authority.
- Preflight and switch are separate, explicitly confirmed operations. Retain the
  existing verify-before-authority-switch protocol, resumable copying and reversal
  from CURRENT table data, subject to unchanged exactness and resource checks.
- An ambiguous worker is not automatically taken over. Never remove an owner
  because of age, PID absence or a closed browser. Assisted recovery requires
  independent proof of whole-worker and output quiescence.

The intended capacity model is a finite, conservative release-owned policy, not
customer certificates or exact-machine qualification. This slice restricts policy
resolution to single-site installations, but does NOT populate the empty release
class list. Public conversion/recovery still refuse safely on every runtime.
Do not enable an injected test profile or invent coefficients to obtain success.

## Executed evidence and boundaries

The dependency-free `tests/integration/storage-single-site-scope.php` exercises
real WordPress/SQL, the production engine's ordinary options, the public receiver
and CLI facade. It requires an explicit owned disposable database. Its special
WP-CLI argument is restricted to the named task-owned Cove fixtures; it is not
an unrestricted destructive runner. It does not erase retained fixture evidence.

- Baseline native single-site CLI provisioning and multisite refusal assertions
  were RED; the changed built ZIP passes on WordPress 7.1.3, single/multisite.
- The built ZIP passes 10 scope assertions each on literal PHP 5.4.0 / WP 5.0
  and PHP 5.6.20 / WP 6.0 using real InnoDB. These are narrowed-scope checks,
  NOT large table-conversion or complete runtime-floor acceptance.
- Literal testing first exposed a real status defect: PHP5 native SQL object
  results lose the numeric column name from `SELECT 1` during wpdb projection.
  The ownership query now names its result `owned`, without altering the exact
  schema-version predicate. The original failure and alias-control diagnostics
  remain separate evidence; the corrected package passes both literal pairs.
- Focused source tests pass: 188 tests / 25,260 assertions. Package regression
  checks cover source-byte parity and exclusion of development files.
- Full test discovery is NOT PASS: the bounded 150-second run timed out with
  failure markers. No complete failure/assertion total is asserted.

Still required: defensible tested release coefficients/headroom, a nonempty
supported release class, the representative large public single-site journey
(enable, migrate, edit, reverse, scoped cleanup), original applicable large
workloads, interruption/refusal safety, browser workflow execution, and independent
immutable source/package acceptance. Arbitrary-byte exact codec/refusal evidence
remains distinct from native-text public-conversion success. No cap, ownership,
authorization, source-equality or losslessness guarantee is relaxed here.
