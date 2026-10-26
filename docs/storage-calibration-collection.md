Evidence-only finite conversion collection entry

Scope
  tests/integration/storage-calibration-entry.php is an include-only CLI test
  adapter. It is never registered/loaded by the plugin and creates no public
  route, server profile, filter or production runtime exception. PHP<7 refusal
  in StorageCalibration and authorization-before-calibration in StorageOperations
  are unchanged. Collection is not approval or useful-public-path acceptance.

Bootstrap contract (trusted synthetic owner, not request fields)
  Load the exact candidate and WordPress/test API bootstrap first. Define
  FOOGM_STORAGE_TEST_DISPOSABLE=true and
  FOOGM_STORAGE_CALIBRATION_EVIDENCE_ONLY=true only inside that owned process.
  Supply $fgm_calibration_case: representative, held-out, refuse-object or
  refuse-size. Run exactly one case per fresh process; never reuse the namespace.
  The entry RETURNS an evidence array; the bound output producer must encode it
  with a fixed byte limit, export it once and preserve stderr and process exit.

  Supply $fgm_calibration_binding with:
    candidate: exact immutable 40-character SHA (outer source/package receiver
      must verify the relationship to the file manifest; this is not Git attestation)
    database, prefix, blog_id: exact owned synthetic DB/site identity, compared
      to DB_NAME/current wpdb/current blog. No multisite/shared production prefix.
      Prefix domain: fgm_cal_[a-z0-9_]+ or retained g1pa_20261016_off_.
    files: ordered path=>SHA256 map:
      includes/class-storage-calibration.php
      includes/class-storage-operations.php
      includes/class-storage-conversion.php
      tests/support/StorageCalibrationCollection.php
      tests/integration/storage-calibration-entry.php
    producers: ordered bootstrap, worker, phase, authority, database, output map;
      each value is {path:absolute_local_path,sha256:exact_file_hash}.
      Files are hashed before source writes. Bind the ACTUAL producers, not
      convenient stand-ins. The runtime identity additionally hashes the existing
      production coordinator/codec/admission/SQL implementation set.

  Existing verified shared schema must already exist and be empty. The five
  migration source/control/authority/maintenance/backup options must be absent;
  no writer marker is permitted. No schema provisioning, other fixture-owner
  creation, stale-owner takeover or automatic destructive cleanup is performed.
  Successful collection retains synthetic final state for the outer owner's
  exact cleanup/readback. Failed collection also retains its evidence; never
  retry over it. No customer data or production namespace is eligible.

Behavior
  A fixed experimental 1MiB/64x/32MiB-reserve policy bounds only this synthetic
  experiment. It is not empirically justified as a production envelope and is
  never returned in an approved-profile shape or installed. Authorized public
  switch calls with missing, unapproved and wrong-runtime controls must refuse.
  Actual StorageConversion authorizes each internal forward/reverse call and
  retains production memory, structural, schema, CAS, transaction, quiescence,
  cooperative-deadline and output-drain checks. Instrumentation changes only the
  confined gate connection to an inherited pinned SQL observer; it does not
  synthesize control commits or override public validation.

  Representative data: finite 128-record mixed keyed/binary/Unicode/scalar state.
  Held-out data: 17 records plus a distinct nested shape. Both must round-trip
  logical identity with actual copying/verified/flipped/stable SQL readbacks in
  both directions, stable authority/sentinel parity and preserved backup bytes.
  Object and over-byte controls must refuse without publishing control/authority,
  staging table data or changing the input. They do not establish all boundary,
  recovery/replacement, hook, legacy-runtime or large-state coverage.

Accounting / runner
  Reuse the retained bounded run-one.py, phase_timing.py and phase-markers.php;
  do not reset allocator peaks, subtract startup, relax thresholds or claim full
  process-group teardown. The native fixture invokes its existing command-test
  interface, preserving injected_protocol and useful_conversion_verified=false.
  Historical scan_call_start/end wire labels bracket the WHOLE collection call
  in that fixture; they do not describe another scan or approve a timing method.
  Whole-worker launch/reap/EOF time remains authoritative; method_approved=false.
  PHP peaks are bootstrap-inclusive process-so-far observations, not terminal
  allocator samples. Kernel exact-child wait4 RSS is recorded separately.
  Native FD3/FD4 exports are empty without the legacy instrumented binary/ledger;
  no terminal allocator/accounting evidence is inferred from these files.

Verification boundary
  Native SQL regression fixtures/logs are kept externally in the slice evidence
  directory, including native-verification.py and native-collector.php. They use
  PHP8 API doubles and same-session MariaDB TEMPORARY tables only, no WordPress
  install or durable fixture owner. SHOW TABLE STATUS is normalized from actual
  SHOW CREATE TEMPORARY TABLE engine metadata; this is explicitly NOT native
  WordPress/schema acceptance. Existing production SQL, coordinator, snapshots,
  decoders, transactions and eight successful phase writes execute against real
  SQL. Per-phase observations are same-session readbacks, not independent
  cross-session durability receipts.
  PHPUnit regressions exercise authority/identity/hash/producer refusals before
  SQL and missing/unapproved/wrong-runtime public receiver refusal.

  Literal PHP5.4 syntax is intended; native PHP lint/tests do not prove it.
  Independent exact-candidate read-only review is mandatory BEFORE any VM run.
  Known G1 timing FAIL, accounting INCOMPLETE, teardown-unproved and methodology
  unapproved remain open. This adapter is not an approval or full-gate claim.
