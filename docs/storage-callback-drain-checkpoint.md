# Active callback drain checkpoint — Milestone A remains incomplete

Continues `storage-runtime-transport-checkpoint.md` on SAME `t_396d60fe`.
This is a bounded request-local source/image-tag output integration, not complete
writer drain, process-death recovery or stale-owner admission.

## Integrated paths

The production engine tracks nested `run_detection()`, `start_image_tag_sync()`
and `continue_image_tag_sync()` calls until they return or throw a supported
exception/error. Quiescence refuses while any such call is active, including a
nested normal WP_Error return. The private boundary is not a public callback,
fault-injection or recovery API. It uses no finally syntax in shipped PHP.

Detection checks writer authority before and after each source callback. Image
term assignment checks before and after each actual `wp_set_object_terms()` call.
A changed/unusable pin refuses the next source/output call and progress write;
already performed output is NOT rolled back or credited as exactly-once output.
The request writer still lasts until existing owner-checked release/shutdown.
Operation depth is not persisted and cannot authorize stale-owner cleanup.

## Test-first evidence and limits

Five of eight new real-engine-path unit cases fail on the received baseline:
source/term callbacks falsely affirm quiescence, nested normal refusal falsely
drains the outer call, and pin loss permits a subsequent callback/output.
Additional cases exercise Exception/Error unwinding and retained progress.
`storage-callback-drain.php` runs native WordPress term callbacks through actual
option and coordinator-selected table engines. Its deliberate real mysqli handle
replacement is NOT a dropped network response or hard-PHP-death proof. Synthetic
fixture calibration is not production calibration. Exact executed receipts and
candidate/package identities are in the adjacent diagnostic seven-part handoff.

## Still unaccepted

Other ordinary option writes, gallery/album/content/retry/error-check output and
batch paths are NOT all integrated with a complete drain/pinned-write boundary.
No complete cross-request drain, hard-PHP-death recovery, arbitrary schedules,
uncertain-readback matrix, ID reuse/clock agreement/separate-process DDL proof,
supported-floor decoding, literal floors, public preflight/selection UI or
independent exact-candidate acceptance is credited. Retain v1 markers without
safe dead-owner/drain proof; no new stale takeover is implemented.

Preserve `task_scope_completed=false`, Milestone A unaccepted and downstream
`t_7dc60149`/`t_8552160b` holds. No push/PR update/merge/deploy/release/SVN/customer
contact. class-init.php and the mixed-newline content migrator remain untouched.
Next bounded slice: integrate remaining actual output/batch paths and independently
exercise hard-PHP-death/uncertain-readback before any stale-owner admission.
