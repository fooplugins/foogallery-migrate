# FooGallery Migrate engineering documentation

These documents describe the storage-only architecture, PR #15 implementation status and remaining work. The WordPress.org plugin listing and release history remain in [`readme.txt`](../readme.txt).

## Design documents

- [Legacy storage upgrade for large sites](legacy-storage-upgrade.md) — requirements and a revision-pinned inventory of what PR #15 already implements, what remains incomplete, and the storage-only boundary.
- [Prioritised completion plan](dev-plan.md) — the sole forward execution tracker: cleanup/uninstall, bounded production access, qualified switching, recommendations, single-site acceptance, then multisite.

The PR checkpoint and handoff documents record historical experiments and limitations at their own source revisions. They are not current release acceptance or alternative task plans. No v2 migration engine or migration-config JSON is included in this work.

