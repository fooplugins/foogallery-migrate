# FooGallery Migrate engineering documentation

These documents describe the storage-only architecture, PR #15 implementation status and remaining work. The WordPress.org plugin listing and release history remain in [`readme.txt`](../readme.txt).

Latest release validation: [1.16.4 upgrade, browser and WordPress 6.2 results](release-testing/1.16.4-final-validation.md). The requested gates passed; existing Plugin Check findings are recorded there. The subsequent [1.16.4 SVN tag staging record](release-testing/1.16.4-svn-tag.md) covers the authorized Git push and version-tag upload, with SVN trunk unchanged.

## Design documents

- [Legacy storage upgrade for large sites](legacy-storage-upgrade.md) — the five approved simplification cuts, single-site first-release requirements, and a revision-pinned inventory of PR #15. Deferred design is clearly labelled.
- [First-release development plan](dev-plan.md) — the sole forward tracker: attempt the real journey immediately, reach a testable integrated candidate, then complete supported paths and release acceptance. Includes test classification and workflow rules to avoid repeated blockers.

Brad approved the five cuts on 2026-10-22. This documentation approval does not start implementation. Multisite table support, advanced advice/re-notification and convenience controls are deferred backlog, not requirements for this first release.

The PR checkpoint and handoff documents record historical experiments and limitations at their own source revisions. They are not current release acceptance or alternative task plans, and superseded requirements do not override the approved boundary. No v2 migration engine or migration-config JSON is included in this work.

## Separate post-storage planning

- [V2 migration plan](v2-migration-engine-plan.md) — issue #14's versioned migration-config JSON feature, its safety/compatibility boundaries and phased delivery. Documentation only; no implementation started.
- [PR #15 follow-up backlog](pr15-follow-up-backlog.md) — prioritized issues #16–#25 for deferred storage enhancements and documented capacity/UX limits.

PR #15 contains the narrowed single-site storage implementation and its current QA evidence; this branch does not bring that runtime code into `develop`. **V2 implementation remains blocked until Brad has tested and manually merged PR #15**, then starts separately from the actual merged baseline. Merging these planning docs does not authorize implementation, automatic kickoff, merge or release of the feature.

