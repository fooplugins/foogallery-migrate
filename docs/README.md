# FooGallery Migrate engineering documentation

These documents describe proposed internal architecture and implementation work. The WordPress.org plugin listing and release history remain in [`readme.txt`](../readme.txt).

## Design documents

- [Legacy storage upgrade for large sites](legacy-storage-upgrade.md) — a storage-only plan for reducing option growth, full-state rewrites, and migration-state memory pressure without introducing a new migration engine.

## Separate post-storage planning

- [V2 migration plan](v2-migration-engine-plan.md) — issue #14's versioned migration-config JSON feature, its safety/compatibility boundaries and phased delivery. Documentation only; no implementation started.
- [PR #15 follow-up backlog](pr15-follow-up-backlog.md) — prioritized issues #16–#25 for deferred storage enhancements and documented capacity/UX limits.

PR #15 contains the narrowed single-site storage implementation and its current QA evidence; this branch does not bring that runtime code into `develop`. **V2 implementation remains blocked until Brad has tested and manually merged PR #15**, then starts separately from the actual merged baseline. Merging these planning docs does not authorize implementation, automatic kickoff, merge or release of the feature.

