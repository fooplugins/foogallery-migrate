Repository-sink flatten phase model (prospective, not integrated)

Boundary
  RepositoryFlattenModel is an inert, executable host experiment, not an
  approved production admission profile. No existing call site uses it.
  It plans describe_tree, preflight_tree/make_row and flatten_node with an
  empty non-retaining sink. A sink retains no earlier callback rows; recursive
  ancestors still retain their encoded rows. SQL/wpdb, arbitrary callbacks,
  WordPress encoders/hooks, retained-row output, rebuild, decoder, snapshot,
  driver allocations, source mutation/aliases and external native allocations
  are NOT certified. Inputs must be unchanged, compact, reference-free trees
  already admitted to the existing logical domain. The descriptor is trusted
  internal metadata, NOT a request/policy/validation boundary.

Runtime and observations
  Only PHP CLI 8.5.11, Darwin arm64, 64-bit NTS non-debug, Zend allocator,
  CLI opcache off, exact binary SHA256 and extension/version fingerprint in
  the class are recognized. The constructor rejects all supplied codecs,
  unknown profiles and any wp_json_encode (including the PHPUnit shim).
  Real WordPress and literal PHP floors remain unverified/unsupported here.
  Construct/load the model before the operation's memory baseline sample.
  Runtime identification is not calibration approval. No coefficient override
  or 'approved' flag exists. Source/package receipts bind the exact producers
  and loaded classes; changing the codec requires a new observation/review.

  The C producer tests/tools/repository-flatten-sizes.c compiles against the
  installed headers. It observes zval16, Bucket32, zend_array56, string header24,
  execute_data80, mixed table8=320/table32=1280, page4096/chunk2097152 bytes.
  Installed zend_types.h uses capacity powers of two, >=8; each mixed slot is
  Bucket32 plus TWO 4-byte hash positions, i.e.40, not36. zend_alloc_sizes.h
  supplies the actual bin/run sizes. These are runtime representation facts,
  not measurements of PHP5 or an arbitrary allocator/extension.

  A(n) = 768 + 160*n reserves an array including old/new growth overlap:
  mixed old+new capacities <3n imply <120n data bytes; >=205 entries the
  two page-rounding tails <=8192 fit the remaining40n. At smaller power-of-two
  capacity transitions the concrete bins/table sizes fit the 768 intercept.
  Packed fixed tag/pair arrays are smaller; using the same bound is deliberate.
  Newly generated tag integer strings have <=20 digits; header24 + NUL and
  the observed bins fit64 bytes/node. The source string/key zvals themselves
  are shared by field_order/values/mutable, not recursively copied.

  Descriptor scratch reserves 4096 bytes/frame for two depth spans plus eight
  fixed callees. The frozen opcode producer checks the relevant codec/domain
  methods' CV+temporary slots fit251: 80+16*251=4096. No custom memory/clock
  callback allocation is certified (arithmetic tests use seams separately).
  Four MiB is a heap/run slack allowance, not a fitted multiplier: 30 bins,
  <=7 pages/run leave <861KiB of partial new runs; a chunk rounding tail is
  <2MiB, leaving >1MiB for chunk headers, fixed metadata and VM-stack rounding
  in this finite operation. Existing heap pages are already in the sample.
  Held-out measured true AND live peaks test this allowance; it is not a
  universal fragmentation/RSS/never-OOM theorem.

  Native JSON/output ownership uses 8*max_row_bytes + 4*4096: up to four
  overlapping encoded/string buffers, with twofold allocation-capacity slack
  and page tails, covering native JSON old/new buffers, retained payload and
  mutation, projection output, typed-key/hash concatenation at their distinct
  high-water phases. Base64 tag bodies <= JSON bytes and have a separate
  2*row-byte term, plus per-node64 headers. Final/ancestor encoded strings
  are additionally charged at2*max_ancestor_row_bytes plus three20-entry
  row shells per active frame (including array_merge/current-row overlap).
  Maxima need not occur on the same row: summing them over-reserves.
  The streaming logical digest retains no whole-tree encoded string, but
  re-encodes each scalar/key. Six scalar buffers, each <=8/3 input bytes
  after base64 and slash escaping, give16*max_scalar_bytes + four pages.
  logical_max_string_bytes includes logical keys AND mutable/deep values;
  state_key length is an additional explicit input. The floor32 covers
  integer/float/boolean/null textual scratch. Aggregate logical_string_bytes
  would needlessly charge all sampled source strings again.

  Native buffer capacity/growth and slack are supported by actual per-process
  peak observations of this exact native encoder/representation, including
  binary size and capacity transitions, NOT by a WordPress allocator contract.
  This is a conservative finite host phase model, NOT synthetic-approved
  calibration or proof over every combination within the logical ceilings.
  Held-out corpus sizes are fixed before the frozen/package measurement run;
  the coefficients are structural ownership bounds, not fitted peak ratios.

Executable boundary and future integration (separate authorization/review)
  1. Sample memory_get_usage(true) AFTER loading code and obtaining the source.
     It already includes caller-owned input, decoded source, and any raw strings
     still live. source_charge_bytes is exactly0. Never subtract that source
     from the sample, and never add a second sampled-input amplification term.
  2. Before describe_tree, domain->admit_additional(model->descriptor_bytes(64)).
     Keep raw/reserve/depth/row/token/deadline/floor guards unchanged. The
     descriptor uses the SAME operation/deadline and no fresh domain/reset.
  3. plan = model->project(descriptor,state_key). Before preflight_tree/make_row,
     admit_additional(plan.after_descriptor_bytes), NOT the full plan again:
     descriptor scratch remains pending. Use this reservation ONCE per tree,
     not once per row/pass. Both passes' overlapping ownership is included.
  4. Every later legacy prospective/raw-memory gate must ALSO account pending
     deltas. reserve_legacy is an executable bridge experiment: first keep the
     original admit_memory(raw), then admit_additional(checked(raw*the exact
     unchanged trusted policy amplification)). The amplification argument is
     internal policy, NEVER a request value or a new calibration. Do not use
     this bridge as a substitute for decoder raw-byte caps. Existing production
     admit_memory is intentionally pending-blind; tests make that gap explicit.
     No integration is permitted until all relevant call sites share this rule.
     Increases in sampled memory do not release pending bytes. No credits/reset
     are invented; conservative double charging of realized NEW allocations can
     over-refuse. A refused bridge spends no new delta, though existing tick/work
     and deadline checks still run. Repeated raw checks may also over-refuse.
  5. Neither modeled admission nor a projection below128M bypasses current raw
     admission. Original20K conversion remains RED. No codec/SQL/VM/native
     activation or claim of original-product completion follows this experiment.

Reproduction
  php -d memory_limit=128M tests/tools/repository-flatten-model.php "$PWD" regression
  php -d memory_limit=128M tests/tools/repository-flatten-model.php "$PWD" allocator
  php -d memory_limit=128M tests/tools/repository-flatten-model.php "$PWD" original20k 20000
  Other modes: tiny, wide, deep, logical-deep, binary, mutable plus finite size.
  The standalone producer deliberately loads exact product includes without
  the WP shim. Its only WP stand-ins are error/translation arithmetic helpers;
  no fake encoder or fake memory is used for phase measurements. Preflight uses
  Reflection to invoke the ACTUAL private method; flatten uses the public path
  with an empty sink. Neither changes guards or measures a hand-written clone.
  Original20K runs descriptor/model only, under actual128M with its live sample.
  See the author evidence manifest for commands, frozen source/ZIP hashes,
  held-out cells, failure logs and parent-matched inherited RED tests.

Open gates
  A1-A8/final/native OPEN; original20K128M RED; native unchanged-byte
  BLOCKED_NOT_WAIVED; positive persistence corpus pending; 15 October MISSED.
  Host observations are not persistence, final acceptance or literal-floor proof.
