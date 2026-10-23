<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

class SnapshotInternalStatsTest extends TestCase {
 /**
  * Historical private-scratch path at 128 MiB, not release admission.
  * @group optional_host_diagnostic
  * @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_callback_free_original20k_stats_do_not_admit_retained_output(): void {
  // Full discovery retains unrelated allocator backing. Exercise the original
  // finite workload in a fresh process, never by raising its actual PHP limit.
  $this->assertNotFalse(ini_set('memory_limit', '128M'));
  $this->assertSame('128M', ini_get('memory_limit'));
  $this->assertSame('0', ini_get('opcache.enable_cli'));
  $value = StorageA7Fixture::value(20000);
  $b = StorageScaleAdmissionTest::admission(null, null, function () { return array(ini_get('memory_limit'), memory_get_usage(true)); });
  $c = new LegacyRecordCodec(null, 24576, 16777216, $b);
  $stats = $c->preflight_stats('galleries', 'gallery-009', $value['galleries']['items']['gallery-009'], 0);
  $this->assertSame(20208, $stats['record_count']);
  $this->assertSame(15070354, $stats['encoded_bytes']);
  $this->assertGreaterThan(0, $stats['payload_bytes']);
  // Full unchanged arbitrary-consumer emission on an independent domain.
  $other = StorageScaleAdmissionTest::admission();
  $full = new LegacyRecordCodec(null, 24576, 16777216, $other);
  $seen = 0;
  $this->assertSame($full->flatten_tree('galleries', 'gallery-009', $value['galleries']['items']['gallery-009'], 0, function ($row) use (&$seen) { ++$seen; }), $stats);
  $this->assertSame(20208, $seen);
  $this->assertSame(0, (new \ReflectionProperty(get_class($b), 'retaining_pending'))->getValue($b));
 }

 public static function representativeValues(): array {
  $deep = 'leaf'; for ($i = 0; $i < 20; ++$i) { $deep = array('progress' => array('n' => $i), 'children' => array('node-' . $i => $deep)); }
  return array(
   array(null), array(false), array(-0.0), array(array()),
   array(array('ID' => '00012', 'object_type' => 'image', 'plugin_name' => 'p', 'migrated_id' => '00043', 'children' => array('x' => false, 7 => array('error' => 'no', 'progress' => array(2 => 0.25))))),
   array(array("\xff\0" => "\xfe\x80\0", 'ID' => "\xff", 'plugin_name' => "\x80p", 'progress' => array(PHP_INT_MAX, -0.0, null))),
   array(array('data' => str_repeat("\xff/\0", 40000), 'children' => array('large-child' => str_repeat('x', 50000)))),
   array($deep)
  );
 }
 /** @dataProvider representativeValues */
 public function test_stats_match_full_emission_in_supported_and_fallback_domains($tree): void {
  foreach (array(false, true) as $scaled) {
   $b = $scaled ? StorageScaleAdmissionTest::admission() : null;
   $c = new LegacyRecordCodec(null, 10000, 16777216, $b);
   $rows = array();
   $full = $c->flatten_tree('galleries', "\xffkey", $tree, 2, function ($row) use (&$rows) { $rows[] = $row; });
   $this->assertSame($full, $c->preflight_stats('galleries', "\xffkey", $tree, 2));
   $this->assertSame(count($rows), $full['record_count']);
   $this->assertSame(array_sum(array_column($rows, 'payload_bytes')), $full['payload_bytes']);
  }
 }
 private function refusal($call) {
  try { $call(); } catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); return $e->getMessage(); }
  $this->fail('Expected finite refusal.');
 }
 public static function invalidValues(): array {
  return array(array(new \stdClass()), array(INF), array(NAN), array(array('object_type' => 1)), array(array('migration_status' => str_repeat('s', 33))), array(array('plugin_name' => array())), array(array('ID' => array())), array(array('migrated_id' => -1)), array(array('progress' => new \stdClass())));
 }
 /** @dataProvider invalidValues */
 public function test_invalid_domain_and_projection_refuse_both_operations($tree): void {
  foreach (array('preflight_stats', 'flatten_tree') as $method) {
   $b = StorageScaleAdmissionTest::admission(); $c = new LegacyRecordCodec(null, 24576, 16777216, $b);
   $this->refusal(function () use ($c, $method, $tree) { $c->$method('galleries', 'k', $tree, 0, function () {}); });
  }
 }
 public function test_exact_encoded_and_record_caps_and_plus_one(): void {
  $tree = array('children' => array('one' => 'x', 'two' => false));
  $c = new LegacyRecordCodec(); $stats = $c->preflight_stats('galleries', 'k', $tree, 0);
  $this->assertSame(3, $stats['record_count']);
  foreach (array('preflight_stats', 'flatten_tree') as $method) {
   $exact = new LegacyRecordCodec(null, 3, $stats['encoded_bytes']);
   $this->assertSame($stats, $exact->$method('galleries', 'k', $tree, 0, function () {}));
   foreach (array(array(2, $stats['encoded_bytes']), array(3, $stats['encoded_bytes'] - 1)) as $limits) {
    $small = new LegacyRecordCodec(null, $limits[0], $limits[1]);
    $this->refusal(function () use ($small, $method, $tree) { $small->$method('galleries', 'k', $tree, 0, function () {}); });
   }
  }
 }
 public function test_logical_depth_tokens_and_codec_bytes_refuse_without_output(): void {
  foreach (array('max_depth' => 3, 'max_tokens' => 5) as $field => $limit) {
   $p = StorageScaleAdmissionTest::policy(); $p[$field] = $limit;
   foreach (array('preflight_stats', 'flatten_tree') as $method) {
    $b = StorageScaleAdmissionTest::admission($p); $c = new LegacyRecordCodec(null, 24576, 16777216, $b);
    $this->refusal(function () use ($c, $method) { $c->$method('galleries', 'k', array('children' => array('one' => array('x' => array('nested' => true)))), 0, function () {}); });
   }
  }
  foreach (array('preflight_stats', 'flatten_tree') as $method) {
   $codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(128, 64, 10000);
   $c = new LegacyRecordCodec($codec);
   $this->refusal(function () use ($c, $method) { $c->$method('galleries', 'k', str_repeat('x', 1000), 0, function () {}); });
  }
 }
 public function test_key_order_and_row_byte_limits_refuse(): void {
  foreach (array('preflight_stats', 'flatten_tree') as $method) {
   $c = new LegacyRecordCodec();
   foreach (array(array(1.5, 0, 'x'), array('k', -1, 'x'), array('k', 0, str_repeat('x', 800000))) as $args) {
    $this->refusal(function () use ($c, $method, $args) { $c->$method('galleries', $args[0], $args[2], $args[1], function () {}); });
   }
  }
 }
 /**
  * @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_shared_deadline_interruption_refuses_without_credit(): void {
  // The deadline/refusal is portable; a private pending workspace exists only
  // on the historical qualified allocator and is not a release requirement.
  $this->assertNotFalse(ini_set('memory_limit', '128M'));
  $this->assertSame('128M', ini_get('memory_limit'));
  $this->assertSame('0', ini_get('opcache.enable_cli'));
  $calls = 0; $clock = function () use (&$calls) { return ++$calls > 40 ? 111.0 : 100.0; };
  $b = StorageScaleAdmissionTest::admission(null, $clock); $c = new LegacyRecordCodec(null, 24576, 16777216, $b);
  $this->refusal(function () use ($c) { $c->preflight_stats('galleries', 'k', array('children' => array_fill(0, 100, 'x')), 0); });
  $this->assertGreaterThan(40, $calls);
  $this->refusal(function () use ($b) { $b->admit_memory(0); });
 }
 public function test_observer_does_not_qualify_retaining_or_replace_native_usage_ceiling(): void {
  $p = StorageScaleAdmissionTest::policy(); $p['memory_ceiling_bytes'] = 33554433;
  $b = StorageScaleAdmissionTest::admission($p, null, function () { return array('-1', 0); });
  $c = new LegacyRecordCodec(null, 24576, 16777216, $b);
  $codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1048576, 64, 10000, $b);
  $this->assertFalse(\FooPlugins\FooGalleryMigrate\GenericRetainingModel::supports($c, $codec, $b));
  $this->refusal(function () use ($c) { $c->preflight_stats('galleries', 'k', 'x', 0); });
 }
 public function test_private_workspace_preserves_other_owner_and_legacy_pending(): void {
  $b = StorageScaleAdmissionTest::admission(); $owner = new \stdClass();
  $token = $b->open_retaining_phase($owner, 100); $b->plan_retaining_phase($token, $owner, 20, 100, 0, 1); $b->admit_additional(50);
  $c = new LegacyRecordCodec(null, 24576, 16777216, $b); $c->preflight_stats('galleries', 'k', 'x', 0);
  $this->assertSame(120, (new \ReflectionProperty(get_class($b), 'retaining_pending'))->getValue($b));
  $this->assertSame(50, (new \ReflectionProperty(get_class($b), 'additional_bytes'))->getValue($b));
  $this->refusal(function () use ($b, $token) { $b->close_retaining_phase($token, new \stdClass()); });
  $b->realize_retaining_event($token, $owner, 0, 20); $b->close_retaining_phase($token, $owner);
  $this->assertSame(0, (new \ReflectionProperty(get_class($b), 'retaining_pending'))->getValue($b));
 }
 public static function workspaceShapes(): array {
  return array(array('original', 'native'), array('original', 'observer'), array('deep', 'native'), array('deep', 'observer'), array('binary', 'native'), array('binary', 'observer'), array('fields', 'native'), array('fields', 'observer'));
 }
 private function witness($shape, $observer, $opcache = 0, $ballast = 0) {
  $root = getenv('FOOGM_AUDIT_RUNTIME_ROOT') ?: dirname(__DIR__);
  $args = array(PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'opcache.enable_cli=' . $opcache, __DIR__ . '/tools/snapshot-stats-probe.php', $root, $shape, $observer, (string) $ballast);
  exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $status);
  $r = json_decode(implode("\n", $lines), true);
  if (getenv('FOOGM_STATS_RECEIPTS')) {
   file_put_contents(getenv('FOOGM_STATS_RECEIPTS') . '/stats-' . $shape . '-' . $observer . '-' . $opcache . '-' . $ballast . '-' . getmypid() . '.json', json_encode(array('command' => $args, 'exit' => $status, 'report' => $r, 'raw' => $lines), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }
  $this->assertIsArray($r, implode("\n", $lines));
  foreach ($r['loaded'] as $binding) {
   $this->assertSame(realpath($root) . '/includes', dirname($binding['path']));
   $this->assertSame(hash_file('sha256', $binding['path']), $binding['sha256']);
  }
  return array($status, $r);
 }
 /** @group optional_host_diagnostic
  * @dataProvider workspaceShapes
  */
 public function test_native_private_scratch_bound_is_observed_not_runtime_approval($shape, $observer): void {
  list($status, $r) = $this->witness($shape, $observer);
  $this->assertSame(0, $status); $this->assertTrue($r['supported']); $this->assertSame('complete', $r['stage']);
  $this->assertSame(0, $r['pending']);
  foreach (array('live', 'true') as $kind) { $this->assertLessThanOrEqual($r['workspace'], $r['after']['peak_' . $kind] - $r['before'][$kind]); }
  $this->assertLessThanOrEqual(134217728, $r['after']['peak_true'] + 33554432);
 }
 /** @group optional_host_diagnostic */
 public function test_unsupported_real_runtime_retains_small_fallback_and_large_refusal(): void {
  list($status, $small) = $this->witness('fields', 'observer', 1);
  $this->assertFalse($small['supported']); $this->assertSame(0, $status); $this->assertSame(0, $small['pending']);
  list($status, $large) = $this->witness('original', 'observer', 1);
  $this->assertFalse($large['supported']); $this->assertSame(1, $status); $this->assertSame('refused', $large['stage']);
  $this->assertSame(0, $large['pending']);
 }
 /** @group optional_host_diagnostic */
 public function test_actual_live_ballast_is_never_credited_as_discarded_scratch(): void {
  list($status, $r) = $this->witness('original', 'observer', 0, 88080384);
  $this->assertTrue($r['supported']); $this->assertSame(1, $status); $this->assertSame('refused', $r['stage']);
  $this->assertSame(88080384, $r['ballast_bytes']);
 }
 /**
  * @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_unqualified_codec_and_owner_use_unchanged_safe_aggregate_fallback(): void {
  // Aggregate fallback refuses this workload at actual128M, not at256M.
  // Keep full discovery and the suite budget; pin only this finite boundary.
  $this->assertNotFalse(ini_set('memory_limit', '128M'));
  $this->assertSame('128M', ini_get('memory_limit'));
  $this->assertSame('0', ini_get('opcache.enable_cli'));
  $value = StorageA7Fixture::value(20000); $tree = $value['galleries']['items']['gallery-009'];
  // Even a truthful observer does not optimize an arbitrary discard callback.
  $b = StorageScaleAdmissionTest::admission(null, null, function () { return array(ini_get('memory_limit'), memory_get_usage(true)); });
  $plain = new LegacyRecordCodec(null, 24576, 16777216, $b);
  $this->refusal(function () use ($plain, $tree) { $plain->flatten_tree('galleries', 'gallery-009', $tree, 0, function () {}); });
  foreach (array('owner', 'codec') as $case) {
   $b = StorageScaleAdmissionTest::admission(null, null, function () { return array(ini_get('memory_limit'), memory_get_usage(true)); });
   $codec = 'codec' === $case ? new class(1048576, 64, 10000, $b) extends \FooPlugins\FooGalleryMigrate\LegacyStateCodec {} : null;
   $c = 'owner' === $case ? new class(null, 24576, 16777216, $b) extends LegacyRecordCodec {} : new LegacyRecordCodec($codec, 24576, 16777216, $b);
   $this->assertFalse(\FooPlugins\FooGalleryMigrate\SnapshotStatsModel::supports($c, $codec ?: new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(1048576, 64, 10000, $b), $b));
   $this->refusal(function () use ($c, $tree) { $c->preflight_stats('galleries', 'gallery-009', $tree, 0); });
   $this->assertSame(0, (new \ReflectionProperty(get_class($b), 'retaining_pending'))->getValue($b));
  }
 }
}
