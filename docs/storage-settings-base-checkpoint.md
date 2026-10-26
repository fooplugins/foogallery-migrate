# Settings callbacks and native base/public selection checkpoint — partial

Continues the received `storage-process-death-checkpoint.md` on SAME
`t_396d60fe`. Milestone A is incomplete; no independent acceptance or child release.

## Test-first findings and fixes

`StorageSettingsCallbackDrainTest` adds six failing continuation cases for gallery
plugin-template/default-template/global-setting/plugin-setting callbacks and album
source-meta reads. The existing writer can lose its transport in a callback even
when that callback returns an empty/default value. Gallery/album now recheck the
existing writer immediately after those boundaries, before subsequent callbacks,
reads or destination writes. Existing output/media drain tests stay green. No new
writer helper, lock policy, engine redesign, schema or lifecycle behavior is added.

The native fixture now invokes the real `WordPressCore` source adapter and base
`get_objects_to_migrate`, keyed queue payload and `migrate` turn. Its source is an
owned published page containing an actual gallery shortcode and attachment ID.
Nested discovery flush must remain non-quiescent; discovery exception/transport
loss and output-post transport loss must not advance completion. Healthy output
is independently read back, then the real authorized public receiver switches
backend and a fresh public engine reads retained history and the checkpoint.
The cached engine must refuse the changed authority, and source content must stay
unchanged. Both initial option and initial table modes are exercised.

The first native attempt exposed an incorrectly unkeyed fixture queue payload;
that was a fixture defect, not product RED. The corrected native attempt exposed
actual product RED: option conversion imported `galleries-state` as a collection
while the runtime requires a scalar checkpoint head. Import now explicitly keeps
both gallery/album summaries and established content-scan/image-tag queue heads
scalar even when their values are arrays. Existing aggregate byte/row/deadline
budgets, codec validation and digest verification still apply; unrelated arrays
retain their prior map/list classification. The native fixture also converts,
reads and updates the three sibling array checkpoints through public settings.
It calculates the real empty album summary rather than using absent state.
Existing converted incompatible heads are not silently reclassified or read from
an option fallback; this change affects a newly verified import only.

## Fixture accounting

Failed attempts are retained and never counted as PASS. The exact-head runner
retains a private DB diff on mismatch instead of hiding failures. Native page
publication demonstrably changes `fresh_site` before any engine writer; core
multisite publish/delete hooks also change this site's `wp_blogs.last_updated`.
Teardown restores only those identified owned-source side effects, with site
scope and timestamp readback, alongside exact owned output/option/table cleanup.
The outer runner still requires full database data equality, including all other
blogs. No blanket database restoration or normalizing/filtering comparison is used.

## Limits and next bounded slice

This is deterministic native WordPress discovery/output and public backend
selection coverage, not an installed FooGallery/browser/remote-media or arbitrary
callback audit. Synthetic small-state calibration is fixture-only. The process
and simulated-transport fixtures retain their prior explicit limits; no physical
WordPress output rollback/exactly-once or network-lost ACK proof is claimed.
V1 dead/aged owner evidence remains blocking; no owner deletion/adoption or safe
stale-owner admission is introduced or justified by this slice. Integrated real
output followed by interrupted authorized public conversion/recovery still needs
its own bounded checkpoint. Supported literal runtimes/delayed teardown and fresh
independent exact-candidate review/publication remain separately gated.

Exact branch/SHA, red/green logs, package binding, native ledgers and seven-part
handoff are retained outside FooProjects in Dev diagnostics
`t_396d60fe/checkpoint-settings-base/`. `task_scope_completed=false`;
`milestone_A_accepted=false`; `children_released=false`. No push, merge, deploy,
release, production writes, SVN, new runtime/VM or duplicate graph is authorized.
