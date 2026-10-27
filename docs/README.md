# FooGallery Migrate engineering documentation

These documents describe the storage-only architecture, PR #15 implementation status and remaining work. The WordPress.org plugin listing and release history remain in [`readme.txt`](../readme.txt).

Latest release validation: [1.16.4 upgrade, browser and WordPress 6.2 results](release-testing/1.16.4-final-validation.md). The requested gates passed; existing Plugin Check findings are recorded there. The subsequent [1.16.4 SVN tag staging record](release-testing/1.16.4-svn-tag.md) covers the authorized Git push and version-tag upload, with SVN trunk unchanged.

## Design documents

- [Legacy storage upgrade for large sites](legacy-storage-upgrade.md) — the five approved simplification cuts, single-site first-release requirements, and a revision-pinned inventory of PR #15. Deferred design is clearly labelled.
- [First-release development plan](dev-plan.md) — the sole forward tracker: attempt the real journey immediately, reach a testable integrated candidate, then complete supported paths and release acceptance. Includes test classification and workflow rules to avoid repeated blockers.

Brad approved the five cuts on 2026-10-22. This documentation approval does not start implementation. Multisite table support, advanced advice/re-notification and convenience controls are deferred backlog, not requirements for this first release.

The PR checkpoint and handoff documents record historical experiments and limitations at their own source revisions. They are not current release acceptance or alternative task plans, and superseded requirements do not override the approved boundary. No v2 migration engine or migration-config JSON is included in this work.

