# PR #15 follow-up backlog

**Recorded:** 2026-10-23, after the validated storage branch was pushed at `f448c89`.

PR #15's agreed single-site first-release implementation and scoped QA are complete. Brad's manual testing/merge remain outstanding. This backlog preserves deferred features and explicit capacity/UX boundaries, not unexplained failed release gates. No ticket starts implementation automatically; every new issue is gated on Brad testing and merging PR #15.

Existing [issue #14](https://github.com/fooplugins/foogallery-migrate/issues/14) owns the separate JSON-configured v2 feature; its documentation is [the v2 plan](v2-migration-engine-plan.md). Do not implement it on the storage PR or rewrite issue #14 as a storage bug.

## Priority order

P1/P2/P3 here are suggested ordering within the future backlog, not release-blocker labels or promises of scheduling. V2 does not depend on finishing this whole list.

| Priority | Issue | Boundary retained in PR #15 / next outcome |
|---|---|---|
| P1 | [#16 — Bulk queue and global gallery checks](https://github.com/fooplugins/foogallery-migrate/issues/16) | Fixed-page/exact-target access works above10K roots; bulk selection retains a10K-root/shared-operation cap. Design bounded resumable bulk work without partially publishing logical changes. |
| P1 | [#17 — Source discovery and active-object child paging](https://github.com/fooplugins/foogallery-migrate/issues/17) | Stored-state paging does not bound eager adapters or one huge active tree. Preserve legacy adapter identities/order while introducing paged contracts; coordinate configured-reader work with #14. |
| P1 | [#18 — Oversized legacy-option onboarding](https://github.com/fooplugins/foogallery-migrate/issues/18) | Initial full decode and3MiB raw cap remain. Investigate a measured safe path for already oversized sites, not a blind limit increase or a mandated universal parser. |
| P2 | [#19 — Indexed exact-URL history lookup](https://github.com/fooplugins/foogallery-migrate/issues/19) | URL fallback is bounded-memory but may scan history. Preserve raw/type/first-match semantics with an indexed projection and bounded backfill. |
| P2 | [#20 — Multisite tables and network lifecycle](https://github.com/fooplugins/foogallery-migrate/issues/20) | Multisite stays on options and table operations refuse. Network provisioning, registry/isolation, deletion/orphan audit and prepared large-network uninstall need separate design/testing. |
| P2 | [#21 — No-pagination response safeguards](https://github.com/fooplugins/foogallery-migrate/issues/21) | Internal reads are batched, but page_size=0 output/buffering remains O(N). Measure end-to-end cost and obtain a UX decision; no silent truncation. |
| P2 | [#25 — Runtime/readme declarations](https://github.com/fooplugins/foogallery-migrate/issues/25) | Runtime WordPress5.0/readme6.0 declarations differ. Decide and align policy/evidence; no automatic PHP/WordPress floor increase. |
| P3 | [#22 — Advanced storage advice](https://github.com/fooplugins/foogallery-migrate/issues/22) | Basic filterable1MiB suggestion already ships. Evaluate extra signals, dismissal growth and repeat notices without customer calibration or automatic switching. |
| P3 | [#23 — Convenience maintenance controls](https://github.com/fooplugins/foogallery-migrate/issues/23) | Essential clear/remove-all/stale-resource cleanup/uninstall already work. Evaluate separate reset/inactive-copy buttons without weakening authority or cleanup safety. |
| P3 | [#24 — Optional engineering diagnostics](https://github.com/fooplugins/foogallery-migrate/issues/24) | Maintained portable tests and optional host diagnostics are separated. Improve optional tooling/docs without restoring per-host customer prerequisites. |

## Deliberate exclusions, not forgotten implementation

- A universal streaming PHP-serialization decoder is not commissioned. #18 investigates safe oversized-input onboarding before any algorithm decision.
- Automatic ambiguous-owner takeover is not a promised follow-up. Assisted recovery remains the supported contract; age/PID/browser-only lock clearing stays prohibited. Any future fencing platform needs separate scope approval.
- Run/task journals, stable configured-source identities, content rollback and destination provenance belong to #14, not the legacy storage schema or cleanup PR.
- Nested-album flattening, arbitrary transforms, a visual config builder, background runners and destructive media rollback are not implicitly authorized by #14 or these backlog tickets.
- Broader stress fixtures/host diagnostics are engineering evidence, not a promise that every workload fits every PHP memory setting. Keep positive supported journeys distinct from safe refusal and test-double results.

## Source and maintenance

Derived from the [approved first-release boundary](https://github.com/foo-bender/foogallery-migrate/blob/f448c893b1d259c5b9c070644f0c094f01aaf60d/docs/legacy-storage-upgrade.md#approved-first-release-boundary), [memory limits](https://github.com/foo-bender/foogallery-migrate/blob/f448c893b1d259c5b9c070644f0c094f01aaf60d/docs/legacy-storage-upgrade.md#memory-boundary-and-remaining-limitations), deferred multisite/advisor/convenience sections and [the completed storage tracker](https://github.com/foo-bender/foogallery-migrate/blob/f448c893b1d259c5b9c070644f0c094f01aaf60d/docs/dev-plan.md#final-acceptance-closure), checked against issue #14 and PR #15's human comments.

The source links above are pinned to PR #15's unmerged documentation so this separate docs-only PR does not need to include its implementation or rewrite the original storage proposal on `develop`.

Every issue includes problem context, acceptance criteria, non-goals and the PR dependency. At kickoff, choose one concrete outcome/fixture, check for overlapping work and refresh the actual merged baseline. Finding a new defect during Brad's PR testing is different from scheduling one of these deferred enhancements.
