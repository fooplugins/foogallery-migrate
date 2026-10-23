# V2 migration plan — versioned migration-config JSON

**Status:** Planning only. No implementation authorized or started.

**Recorded:** 2026-10-23

**Feature:** [Issue #14 — JSON-configured migration adapters for unsupported gallery plugins](https://github.com/fooplugins/foogallery-migrate/issues/14)

**Prerequisite:** Brad must finish testing and manually merge [PR #15](https://github.com/fooplugins/foogallery-migrate/pull/15) before any v2 implementation begins. As recorded, that PR is open at `f448c89`. Its internal QA pass is not Brad's manual acceptance or a merge.

**Delivery boundary:** Separate feature branch and PR, started from the actual merged `develop` baseline. Do not add v2 runtime changes to PR #15. Documentation and issue creation now do not start development, schedule an automatic kickoff, authorize a release, or require all deferred storage enhancements to be completed first.

## 1. Outcome and meaning of “v2 engine”

Let an authorized administrator describe an otherwise unsupported gallery plugin using a versioned, declarative JSON mapping. The feature must discover galleries/albums, resolve ordered images and dependencies, migrate through FooGallery's existing destination objects, and safely replace supported content references.

Issue #14 proposes a **configured adapter behind the existing source contract**, reusing the current UI and queueing pipeline. It does not request a wholesale replacement of every existing adapter/engine path. Use that proposal as the baseline. “V2” here names the config-driven feature and the bounded execution capabilities it needs; it is not permission to rewrite the proven legacy engine.

Before coding, V0 must settle whether a small extension of existing orchestration can meet bounded discovery, stable identities and journaled retry, or whether a narrowly isolated configured-run coordinator is necessary behind the same UI/batching interface. Recommend the smallest compatible path that proves those requirements. Record the decision; a general replacement engine or different transport requires Brad's separate approval. Do not introduce an adapter that merely rebuilds all source objects into one array to fit an old interface.

### Current integration seams, not an implementation file list

Issue #14's original source inventory predates the storage work. These seams were checked against local `f448c89`; inspect them again at the actual merged baseline in V0.

| Seam | Reuse / compatibility obligation |
|---|---|
| [Source registration](../includes/functions.php) | Register active configured sources and retained pinned definitions before saved objects hydrate; keep existing adapter order/defaults. |
| [Plugin contract](../includes/objects/class-plugin.php) and [base migrator](../includes/migrators/class-migrator-base.php) | Existing discovery returns arrays and child fallback may rediscover all objects. A configured paging boundary must be real, not an eager wrapper. Preserve existing callers. |
| [Object identity](../includes/objects/class-migratable.php) and [settings hydration](../includes/class-migrator-settings.php) | Current identifiers use plugin names and current hydration resolves names. Introduce configured stable keys without rewriting built-in identifiers or old compact payloads. |
| [Content migrator](../includes/migrators/class-content-migrator.php), [admin actions](../includes/class-init.php), [sources view](../includes/views/view-migrate-tab-sources.php) | Reuse content safeguards, nonce/capability patterns and existing tabs rather than a parallel application. Add configured extraction through a narrow compatible seam. |

No classes, methods, schema files or runtime hooks described here are being added by this planning change.

### Required outcomes from issue #14

- `manage_options` administrators can upload, validate, preflight, activate, version and disable mappings.
- Read WordPress-backed sources and constrained current-site custom tables without accepting executable database/application code.
- Produce the existing `Gallery`, `Album` and `Image` object semantics and preserve their destination behavior.
- Preserve gallery media order and album gallery order; selecting an album makes its dependencies visible and included.
- Parse exact shortcode tags and Gutenberg block names; replace references only after successful source-to-destination resolution.
- Dry run reports counts, samples, unsafe media, duplicates, orphans and proposed content effects without destination/content/media writes.
- Stable identities, exclusive run ownership and durable intent/effect records make supported retries idempotent across interruption.
- Rollback is conditional and ownership-aware, not a promise to undo arbitrary later edits; media is retained by default.
- Existing PHP adapters, persisted state and migration-history identifiers remain backward compatible.

## 2. Scope and non-goals

The first configured release targets single-site WordPress, one real unsupported-plugin fixture, galleries/media first, then albums and exact content references. The complete issue #14 feature includes dry run, resumability and safe rollback; an earlier gallery-only milestone is a controlled vertical slice, not a claim that the whole feature is finished.

Reuse PR #15's validated storage infrastructure, lifecycle conventions and bounded-query patterns. Its **legacy** codecs/tables are not a generic home for new run/task/mapping/journal records. New logical records need purpose-built, versioned repositories/storage with their own cleanup inventory. Do not repurpose legacy state columns or reinterpret old history as new run provenance.

Not included automatically:

- Converting every existing PHP adapter into JSON, changing their history keys, or replacing their UI/execution path.
- Raw SQL, PHP callbacks/classes, arbitrary regex, replacement HTML, filesystem paths, credentials or authenticated remote APIs in uploaded JSON.
- A visual query builder, general ETL language, arbitrary joins, recursive transforms or customer-defined executable plugins.
- Nested-album flattening, multisite table support, background/cron/Action Scheduler migration transport, distributed workers or a universal decoder.
- Automatic deletion of attachments or automatic recovery based only on lease age/PID absence.
- An unapproved PHP/WordPress minimum-version increase. Track declaration reconciliation separately in [#25](https://github.com/fooplugins/foogallery-migrate/issues/25).

## 3. Configuration contract

“V2 feature” and `schema_version: 1` are different versions: the first published mapping format is schema v1. Version the format explicitly; reject unsupported versions rather than guessing. Document a local schema and runtime validation semantics together. Never fetch a supplied `$schema` URL.

| Section | Contract |
|---|---|
| `source` | Immutable namespaced `key`, editable display `label`, allowlisted detection checks. One active config version per source key. |
| `objects.galleries` | Bounded source reader, canonical string source ID, title and allowlisted FooGallery template/settings, ordered lazy images. |
| `objects.albums` | Bounded album reader and ordered references to galleries. Reject missing dependencies/cycles and unsupported nesting explicitly. |
| Image mapping | Existing attachment ID or validated source URL, source ID, title/caption/alt/date and supported tags. Record whether output was created or reused. |
| `content` | Allowed post types/statuses and exact shortcode/block rules; fixed FooGallery replacement kinds, not user-provided markup. |
| Reader/expression limits | Server-enforced bounds on bytes, depth, batches, lookup/fan-out and execution. JSON cannot enlarge product safety limits. |

Start with the issue's vocabulary: `posts`, `terms`, constrained `table`, and only narrowly allowlisted `option` readers; `field`, `parent`, `literal`, `coalesce`, `lookup`, and named allowlisted resolvers. Add a primitive only against a real source fixture. Resolve attachments through WordPress, not arbitrary reads of sensitive/core tables. If a reader is not yet implemented, reject it clearly; do not advertise it as supported.

Proposed scalar types are `string`, `key`, `absint`, `bool`, `url` and `mysql_datetime`. Initial named resolver candidates from #14 are `uploads_url_join`, `site_url_join`, `json_path`, `csv` and `attachment_url`; each needs a closed argument grammar and size/depth/fan-out bounds. A resolver name is a registry key approved by installed PHP code, never a supplied callable/class name. A path/CSV resolver must not become an embedded scripting language.

An illustrative **gallery-only slice**, using the vocabulary proposed in issue #14, follows. It is planning material, not a shipped schema, runnable configuration, or a second specification replacing the issue's fuller album/content example.

```json
{
  "schema_version": 1,
  "source": {
    "key": "acme/legacy-gallery",
    "label": "Acme Gallery",
    "detection": {
      "all": [
        { "kind": "table_exists", "table": "acme_galleries" },
        { "kind": "table_exists", "table": "acme_images" }
      ]
    }
  },
  "objects": {
    "galleries": {
      "source": {
        "kind": "table",
        "table": "acme_galleries",
        "columns": ["id", "name"],
        "order_by": [{ "column": "id", "direction": "ASC" }],
        "batch_size": 100
      },
      "map": {
        "source_id": {
          "value": { "field": "id" },
          "type": "string",
          "required": true
        },
        "title": { "value": { "field": "name" }, "type": "string" }
      },
      "images": {
        "source": {
          "kind": "table",
          "table": "acme_images",
          "columns": ["id", "gallery_id", "attachment_id", "sort_order"],
          "where": [
            { "column": "gallery_id", "operator": "=", "value": { "parent": "source_id" } }
          ],
          "order_by": [
            { "column": "sort_order", "direction": "ASC" },
            { "column": "id", "direction": "ASC" }
          ],
          "batch_size": 100
        },
        "map": {
          "source_id": { "value": { "field": "id" }, "type": "string", "required": true },
          "existing_attachment_id": {
            "value": { "field": "attachment_id" },
            "type": "absint",
            "required": true
          }
        }
      }
    }
  }
}
```

This example assumes valid existing attachment IDs. Missing attachments must fail preflight, not be silently omitted. A later URL-capable fixture must exercise the media policy before download support is considered complete. Stable ordering requires a real unique tie-breaker verified against source metadata; declaring `order_by` alone does not prove uniqueness.

### Validation and version lifecycle

1. Require capability, dedicated nonce and an explicit bounded JSON upload (issue #14 proposes 256 KiB); check content, not just extension/MIME.
2. Reject excessive depth/size, malformed input, duplicate JSON member names, unknown fields, invalid types/identifiers, unregistered readers/resolvers and unsupported schema versions. Select a floor-compatible validator/decoder deliberately; ordinary decoding/canonicalization alone does not detect duplicate members.
3. Compile only validated data into an internal plan. Quote metadata-validated identifiers, prepare every value, and allow only fixed operators/directions. Table names are suffixes under the current site's prefix, not arbitrary qualified names or network selectors.
4. Canonicalize and hash the validated config. Retain bounded version metadata, uploader, timestamp and report; never save executable PHP or public upload files. Decide bounded retention/storage before version history can grow without limit.
5. Save inactive → complete preflight → explicit activation. A fingerprint change creates a new version and requires preflight again.
6. Pin each run to its exact config version/fingerprint. Activation changes must not mutate a running plan. Disabling blocks new runs but does not erase provenance or make pinned history unrecoverable. Incompatible identity changes require explicit remapping/new source identity, not silent reuse.

## 4. Runtime and storage boundaries

### Identity and compatibility

Configured identity is `(site, source.key, object_type, canonical source_id)` with exact canonical string IDs; destination IDs are positive WordPress IDs. Preserve distinctions such as `7` versus `07` where the mapping's source-ID contract distinguishes them, and avoid numeric precision loss.

Define an unambiguous tagged tuple encoding for new configured identities and verify retained raw values after indexed/hash lookup. Delimiter concatenation must not collapse different source-key/ID pairs; test underscores, slashes, Unicode and long IDs. This is a new configured namespace, not permission to re-encode old built-in identifiers.

Display names and config fingerprints are not source identity. A compatible config edit must not duplicate destination entities; changing the fingerprint does not create a new identity namespace. Built-in adapters retain their exact existing name/ID and URL-based history semantics. Add a legacy-default identity hook only if required and prove old identifiers byte-for-byte unchanged. Load configured definitions/pinned versions before hydrating saved configured objects.

### Bounded readers and preflight

- Stable keyset cursors, explicit unique ordering, fixed projections, bounded batches and lazy child/dependency pages. Do not materialize all galleries, all children or all history to hand data to the old engine.
- Enforce aggregate relationship/lookup fan-out, decoded bytes and deadlines; a JSON `batch_size` is a request, not authority to bypass limits.
- Configured source access is read-only. Deny arbitrary/core/sensitive table access and arbitrary option names; use narrow WordPress readers for approved core entities/options. A PHP-registered named extension is trusted installed code, not executable content uploaded in JSON.
- Exclude PHP object unserialization from built-in readers at the existing floor. Serialized formats need a separately reviewed implementation/extension; do not raise the PHP minimum silently to obtain a convenience API.
- A full duplicate/orphan/content audit may need many bounded requests and persisted cursors/indexes. Label incomplete/sample-only evidence honestly; samples are not proof of whole-source validity. Activation/execution gates require the applicable audit to finish.
- Bind preflight to config, source scope and relevant source revision/fingerprint evidence. Define behavior when source data changes before or during execution: revalidate/refuse affected work, never silently execute an obsolete preview.
- Dry run may persist internal config/audit/report records, but must perform zero destination, source-content, media-library, download or business-output writes. Assert that boundary in tests, not just a UI label.

### Destination and content effects

Reuse existing object writers and allowlisted FooGallery settings. JSON cannot choose arbitrary post types, statuses, meta keys, capabilities or post HTML. Preserve ordered media and references; expose implicit album dependencies before execute. Nested albums reject by default rather than silently flattening.

Use WordPress shortcode/block parsing with exact registered tags/names and bounded nested traversal, preserving unrelated/escaped content. Resolve configured string source IDs through successful mappings before replacement. Fixed replacement builders produce supported FooGallery output. Recheck content at the write boundary and protect against intervening editor changes; a preflight-only hash followed by an unconditional write is insufficient.

Default to existing attachments/same-site media. External media needs a declared, explicitly approved host policy, validated redirects/resolution, size/time/MIME bounds and WordPress-safe HTTP handling. Reject credentials, stream wrappers, traversal and private/loopback/link-local destinations; configs cannot supply arbitrary filesystem paths. Test SSRF and redirect/DNS edge cases before enabling remote download. Do not promise download support based only on an attachment-reuse example.

### Run ownership, idempotency and crash safety

Keep the first runner synchronous and bounded through the existing administrator/AJAX model; do not add a worker platform merely for JSON mappings.

Purpose-built logical records must cover config/version, run owner/status/cursors, source-to-target identity mapping, item attempts and effect provenance, content before/after evidence and rollback progress. Final physical table count/schema is a V0/V3 decision, not prescribed by PR #15's two legacy tables. Use bounded repositories, explicit provisioning, scope/uniqueness constraints and an enumerated cleanup manifest. Small settings may remain non-autoloaded options; an unbounded journal must not become another giant option.

Distinguish a durable mapping unique by source identity from per-run attempts. A uniqueness key that includes only the run ID will not prevent duplicates on rerun. Pin config/version and owner token with every effect/checkpoint. Integrate with PR #15's storage-maintenance/output guard so switching/cleanup cannot overlap configured writes, without treating that guard as a new engine journal.

Record intent before an effect and reconcile target provenance after it. “Save the journal after `wp_insert_post()`” alone leaves a crash window. Inject death/failure between intent, target creation, target metadata, relationship/content writes and checkpoint. Recover an existing owned target or stop with actionable ambiguity; never blindly create another target. Define the transaction/provenance mechanism from actual WordPress behavior, including relevant hooks, before claiming idempotency. External third-party side effects are not covered by a database rollback promise.

A lease/expiry alone does not prove a stopped worker cannot resume. Reject overlapping owners. For uncertain hard crashes, retain assisted recovery unless a specifically tested fencing/idempotency design justifies safe automatic recovery; never remove locks solely because they are old or a PID/browser disappeared.

### Rollback and lifecycle

- Persist content before/after hashes and original content. Restore only if live content still matches the recorded post-migration value; otherwise report a conflict and leave the editor's work intact.
- Trash only entities this run created, whose current relevant fields still match the recorded result and whose references/ownership permit removal. Never delete reused entities. Any supported update to reused data needs explicit before-state and conditional restoration; do not imply it is automatically reversible.
- Retain attachments by default. Optional deletion of run-created attachments is a separate advanced feature requiring reference checks and second confirmation, not first-release scope.
- Preview rollback, require capability/nonce/confirmation, keep rollback resumable, and retain conflict/effect receipts. Test failures midway through rollback as well as execution.
- Keep clear-history/internal cleanup distinct from rollback: neither implies destination deletion. Explain that deleting provenance can make a later safe rollback impossible; require an appropriate warning/confirmation.
- Preserve deactivation/resume. Clear/remove-all/uninstall must enumerate and remove every new config/run/mapping/journal/lock/table resource while retaining source/output/media. Use minimal uninstall dependencies; do not bootstrap the v2 engine from uninstall. Never make cleanup depend on a giant in-memory journal.

## 5. Ordered work and acceptance gates

All implementation rows are **not started / gated by PR #15**. V0 is a design checkpoint after merge, not an instruction to start coding now.

| Order | Deliverable | Exit evidence / dependency |
|---|---|---|
| V0 | Confirm Brad's PR #15 test acceptance and merge; branch from merged `develop`; select real unsupported-plugin fixture; settle minimal adapter/coordinator integration, floor/storage/identity decisions | Recorded merge SHA, fixture/license-safe data, narrow scope and approved compatibility decisions. No pre-merge implementation. |
| V1 | Schema v1, floor-compatible strict validator, canonical config/version repository and one example | Positive and hostile fixtures; bounded validation; unknown/version/duplicate-key refusal; no executable vocabulary. No migration writes. |
| V2 | Bounded table/WordPress readers, lazy mapping and resumable read-only preflight | Real fixture yields exact ordered galleries/media; whole-audit completion distinguished from samples; duplicates/orphans/unsafe media found; zero business writes. |
| V3 | Minimal run/mapping journal and one configured gallery execute/retry slice behind existing UI pipeline | Real SQL/WP positive migration and rerun without duplicates; fault injection covers target-write/checkpoint gaps, concurrent requests and config pinning. Existing adapters stay green. |
| V4 | Album dependency pages and exact shortcode/block content references | Ordered dependencies, cycle/nesting/missing-reference handling, configured string IDs, stale-content protection, unchanged legacy content behavior. |
| V5 | Ownership-aware rollback plus complete minimal operator workflow | Upload → validate → preflight → activate → confirm dependencies → migrate → retry/report → rollback preview/execute. Conditional rollback conflicts, default media retention, disable/version changes and lifecycle tested. No visual builder. |
| V6 | Independent source/ZIP/runtime/browser/security/scaling qualification | Maintained regressions, actual unsupported-plugin journey, supported floors/current runtime, fresh-request crash/retry/rollback, cleanup/uninstall and protected output checks. Separate feature PR; Brad reviews/merges/releases manually. |

V1/V2 may be tested as inactive/read-only capabilities, but execute must remain disabled until V3's durable ownership/idempotency gate passes. The full feature is not complete until V4–V6 pass. Reassess reader breadth at V0: if only one reader ships in a milestone, label it as partial issue #14 delivery rather than claiming the full WordPress/custom-table feature.

## 6. Validation and workflow

Start with one source fixture and the smallest observable user path; do not design a universal DSL before proving it. Add failed tests before each capability, fix the first real integration failure, and preserve failed fixtures rather than replaying/resetting them to manufacture green evidence.

- Security: authorization/nonces/upload limits; unknown and duplicate fields; identifier/value separation; sensitive source denial; resolver allowlist; invalid media and SSRF; escaped errors without SQL/secrets.
- Mapping/compatibility: typed and large string IDs, duplicates, sparse ordering, config/version pinning, old option/table state, built-in identifiers and object hydration.
- Boundedness: many unrelated galleries and one huge gallery/album; child/dependency cursor continuation; bounded audit/report storage; 10x unrelated data at fixed batch size. Do not infer source-memory bounds from PR #15's stored-state benchmark.
- Effects: media created versus reused, album/content ordering, editor conflicts, source drift, concurrent requests and death after each durable boundary. No duplicates or silent ambiguous-owner takeover.
- Rollback/lifecycle: unchanged owned output versus reused/edited/shared output, partial resume, media retained, clear/remove-all/inactive uninstall, zero owned remnants and protected user data.
- Regression/package: maintained PHPUnit suite plus `php tests/content-scan-regression.php`, `node tests/ajax-js-regression.js` and applicable native/package runners. Add new checks to the maintained suite, not only optional host diagnostics. Reuse unchanged evidence explicitly; rerun affected paths on the exact frozen source/ZIP.

Keep one supervisor and one v2 execution tracker once development is authorized. Each work package has owned files, a failing user-path step, concrete exit evidence and separate independent QA. Serialize shared engine/settings/storage edits. Do not ask for routine continuation approval inside an authorized package; escalate only an unresolved product decision/access gap or separately controlled action. Two unsuccessful repairs trigger a change of approach, not a bigger unbounded test framework or weaker guarantees.

## 7. Open decisions before implementation

1. Adapter-only orchestration versus a narrow configured-run coordinator: prove the chosen route meets issue #14's reuse requirement and bounded reader/journal requirements. A wholesale engine rewrite is not the default.
2. Confirm actual initial unsupported-plugin fixture, source read capabilities and required reader/resolver subset. Avoid speculative DSL expansion.
3. Choose the strict validator/duplicate-member handling at the existing PHP floor; serialized PHP remains excluded unless separately approved.
4. Specify purpose-built schema/ownership/provenance transactions and the crash reconciliation protocol before enabling execute. Do not confuse legacy storage authority with run-effect ownership.
5. Define source-drift policy, supported content/block variants, external-media allowlists and the exact relevant-field/reference checks for rollback.
6. Approve any different runtime support policy separately; no dependency on completing multisite or optional storage UI work first.

## 8. Deferred storage work is separate

See [the PR #15 follow-up backlog](pr15-follow-up-backlog.md) for issues #16–#25. Those tickets retain explicit first-release limits and future enhancements; they do not imply PR #15's approved R0–R4 work is unfinished. Issue #14 remains the parent feature for JSON/configured migration, journaling and safe rollback—do not duplicate those as “missing storage” tasks.

**Next action:** Brad tests and manually merges PR #15. Only after that gate may a separately authorized v2 development run take V0. No automatic kickoff, implementation, merge or release is created by this document.
