# Direct media and base-migrator drain — bounded partial checkpoint

Continues `legacy-option-decoder-checkpoint.md` on SAME `t_396d60fe`.
Milestone A is NOT complete or independently accepted.

Direct `Image::create_new_migrated_object()` now retains the existing Migratable
output-operation depth. Returning native attachment insertion, generation,
metadata, import, date and taxonomy callbacks recheck the admitted writer before
any following product output/completion. Source tag callbacks, memory-limit,
filetype and sideload boundaries also recheck. Source/alt metadata reuse the
existing guarded meta writer; no parallel backend/transport is introduced.

Direct base-migrator discovery, queue and migration turns retain request-global
nested depth across cached instances. Engine quiescence consults that depth, so
an inner migrated-history batch flush cannot discharge an outer discovery/output
operation. Discovery rechecks before/after every adapter callback. Exceptions and
modern Errors unwind depth; uncertain authority remains nonquiescent and owned
markers remain blocking. This is synchronous call-boundary protection, not
rollback, interruption inside WordPress/FooGallery calls, cross-process drain or
hard-PHP-death evidence. Existing v1 markers cannot be reclaimed by time/expiry.

Test-first commits `16b01b2` and corrected import-vs-default-reuse setup `f49c6c6`
retain six RED failures on received `689e5a6`. Focused tests cover direct import,
reused attachments, callback pin/blog loss, source callbacks, discovery nested
flush, subsequent-adapter refusal and Exception/Error depth unwind. Full unit
and native exact-candidate results live in diagnostics, not inferred acceptance.
Native fixture extends `storage-output-drain.php` with actual local-file
attachment output, generated metadata, alt/source metadata, taxonomy and reuse
in option/table mode. Its adapters/settings and memory calibration are synthetic;
remote FooGallery import remains unit-double-only. No network/death/floor proof.

Remaining: hard-PHP-death and independent uncertain readback BEFORE stale-owner
admission; full output-path/callback audit and end-to-end authorized public
selection/recovery; literal supported runtime floors in the existing approved
legacy lane; production calibration and independent exact-candidate acceptance.
Do not release `t_7dc60149` or `t_8552160b`, push, change PR, merge, deploy, publish
SVN, touch production, or provision another runtime. `task_scope_completed=false`.
