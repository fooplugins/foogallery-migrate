<?php
/** Offline native stats workspace witness; observation is NOT runtime approval. */
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec as Codec;
use FooPlugins\FooGalleryMigrate\SnapshotStatsModel as Model;
$root = realpath($argv[1]);
define('ABSPATH', $root . '/');
class WP_Error { public function __construct($code, $message) {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($v, $domain) { return $v; }
function absint($v) { return abs((int) $v); }
foreach (array('storage-scalar-observer', 'storage-preflight', 'storage-scale-admission', 'legacy-state-codec', 'legacy-record-codec', 'legacy-table-reader', 'generic-retaining-model', 'snapshot-stats-model', 'legacy-option-decoder') as $file) { require $root . '/includes/class-' . $file . '.php'; }
$shape = $argv[2];
if ('original' === $shape) {
 require dirname(__DIR__) . '/support/StorageA7Fixture.php';
 $store = FooPlugins\FooGalleryMigrate\Tests\StorageA7Fixture::value(20000); $v = $store['galleries']['items']['gallery-009'];
} elseif ('deep' === $shape) {
 $v = 'leaf'; for ($i = 0; $i < 25; ++$i) { $v = array('title' => 'ancestor', 'children' => array('kid' => $v)); }
} elseif ('binary' === $shape) { $v = array('data' => str_repeat("\xff\0/", 50000)); }
elseif ('fields' === $shape) { $v = array(); for ($i = 0; $i < 193; ++$i) { $v['field-' . $i] = $i; } }
else { throw new RuntimeException('Unsupported witness shape.'); }
$limits = array('version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
 'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152,
 'max_physical_bytes' => 33554432, 'memory_ceiling_bytes' => 134217728, 'max_seconds' => 10);
$observer = isset($argv[3]) && 'observer' === $argv[3] ? function () { return array(ini_get('memory_limit'), memory_get_usage(true)); } : null;
$b = new Admission($limits, array('max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432), microtime(true) + 10.0, null, $observer);
$c = new Records(null, 24576, 16777216, $b); $codec = new Codec(1048576, 64, 10000, $b);
$r = array('shape' => $shape, 'supported' => Model::supports($c, $codec, $b), 'runtime' => array('binary' => PHP_BINARY,
 'sha256' => hash_file('sha256', PHP_BINARY), 'version' => PHP_VERSION, 'limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli')));
$d = $c->describe_tree('gallery-009', $v, 'repository-sink'); $r['workspace'] = Model::workspace($d, 'galleries');
$ballast_bytes = isset($argv[4]) ? (int) $argv[4] : 0;
if ($ballast_bytes < 0 || $ballast_bytes > 88080384) { throw new RuntimeException('Unsupported ballast.'); }
$ballast = $ballast_bytes ? str_repeat('B', $ballast_bytes) : ''; $r['ballast_bytes'] = strlen($ballast);
$r['before'] = array('live' => memory_get_usage(false), 'true' => memory_get_usage(true)); memory_reset_peak_usage();
try {
 $r['stats'] = $c->preflight_stats('galleries', 'gallery-009', $v, 0); $r['stage'] = 'complete';
} catch (RuntimeException $e) { $r['stage'] = 'refused'; $r['failure'] = $e->getMessage(); }
$r['after'] = array('live' => memory_get_usage(false), 'true' => memory_get_usage(true), 'peak_live' => memory_get_peak_usage(false), 'peak_true' => memory_get_peak_usage(true));
$r['pending'] = (new ReflectionProperty(Admission::class, 'retaining_pending'))->getValue($b);
$r['loaded'] = array();
foreach (get_declared_classes() as $class) {
 if (0 !== strpos($class, 'FooPlugins\\FooGalleryMigrate\\') || false !== strpos($class, '\\Tests\\')) { continue; }
 $file = realpath((new ReflectionClass($class))->getFileName());
 if (0 !== strpos($file, $root . '/includes/')) { throw new RuntimeException('Mixed product origin.'); }
 $r['loaded'][$class] = array('path' => $file, 'sha256' => hash_file('sha256', $file));
}
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit('complete' === $r['stage'] ? 0 : 1);
