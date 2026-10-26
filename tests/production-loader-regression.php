<?php
// Standalone PHP 5.4-compatible loadgraph contract. WP functions are startup-only
// doubles: this proves classloading/hook registration, not real WP/SQL acceptance.
$root = isset($argv[1]) ? realpath($argv[1]) : dirname(__DIR__);
$mode = isset($argv[2]) ? $argv[2] : 'nonadmin';
if (!$root || !in_array($mode, array('nonadmin', 'admin', 'native-preflight', 'unsupported-wp'), true)) {
    throw new RuntimeException('Invalid production loader probe target/mode');
}
define('WPINC', 'wp-includes');
define('ABSPATH', $root . '/');
$wp_version = 'unsupported-wp' === $mode ? '4.9' : '5.0';
$actions = array();
$activation = array();
$deactivation = array();
$callback_readback = array();
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.invalid/'; }
function is_admin() { global $mode; return 'admin' === $mode; }
function add_action($hook, $callback) { global $actions; $actions[$hook] = $callback; }
function register_activation_hook($file, $callback) { global $activation; $activation[$file] = $callback; }
function register_deactivation_hook($file, $callback) { global $deactivation; $deactivation[$file] = $callback; }
function loader_check($value, $label) { if (!$value) { throw new RuntimeException($label); } }
if ('native-preflight' === $mode) {
    // Explicit pre-edit completeness check, NOT an entrypoint startup result.
    define('FOOGM_NAMESPACE', 'FooPlugins\\FooGalleryMigrate');
    define('FOOGM_DIR', $root);
    require $root . '/includes/functions.php';
    spl_autoload_register('foogallery_migrate_autoloader');
} else {
    require $root . '/migrate.php';
    loader_check(FOOGM_MIN_PHP === '5.4.0' && FOOGM_MIN_WP === '5.0', 'Declared floors changed');
    if ('unsupported-wp' === $mode) {
        loader_check(isset($actions['admin_notices']) && $actions['admin_notices'] === 'foogallery_migrate_min_wp_admin_notice', 'WP startup refusal lost');
        loader_check(!spl_autoload_functions() && !$activation && !$deactivation, 'Unsupported WP initialized plugin');
        echo json_encode(array('php' => PHP_VERSION, 'mode' => $mode, 'status' => 'pass')) . "\n";
        exit;
    }
    loader_check(!$activation && !$deactivation, 'Storage-neutral lifecycle must not register redundant hooks');
    loader_check(('admin' === $mode) === isset($actions['wp_ajax_foogallery_migrate']), 'Admin init not conditional');
    loader_check(('admin' === $mode) === class_exists('FooPlugins\\FooGalleryMigrate\\Init', false), 'Init startup mismatch');
    foreach ($actions as $hook => $callback) {
        // Record legacy callback defects without expanding a classloader fix into
        // hook remediation. Callback execution belongs to real WP acceptance.
        $callback_readback[$hook] = array('callback' => is_array($callback) ? (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1] : $callback, 'callable' => is_callable($callback));
    }
    if ('admin' === $mode) {
        foreach (array('foogallery_admin_menu_after', 'wp_ajax_foogallery_migrate', 'wp_ajax_foogallery_content_replace', 'admin_post_foogallery_migrate_storage') as $hook) {
            loader_check(isset($actions[$hook]) && is_callable($actions[$hook]), 'Required admin init hook missing: ' . $hook);
        }
    }
}
loader_check(spl_autoload_functions() === array('foogallery_migrate_autoloader'), 'Production registered a non-native loader');
// Enumerate real declarations rather than accepting a hand-picked class subset.
$classes = array();
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $tokens = token_get_all(file_get_contents($file->getPathname()));
    $namespace = '';
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) { continue; }
        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for ($i++; $i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{'; $i++) {
                if (is_array($tokens[$i]) && $tokens[$i][0] !== T_WHITESPACE) { $namespace .= $tokens[$i][1]; }
            }
        } elseif (in_array($token[0], array(T_CLASS, T_INTERFACE, T_TRAIT), true)) {
            // Ignore ::class tokens on modern PHP.
            for ($j = $i + 1; $j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++) {}
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $classes[$namespace . '\\' . $tokens[$j][1]] = realpath($file->getPathname());
            }
        }
    }
}
loader_check(count($classes) >= 38, 'Class declaration inventory incomplete');
foreach ($classes as $class => $file) {
    loader_check(class_exists($class) || interface_exists($class) || trait_exists($class), 'Native loader missed ' . $class);
    $reflection = new ReflectionClass($class);
    loader_check(realpath($reflection->getFileName()) === $file, 'Wrong native namespace mapping for ' . $class);
}
$included = get_included_files();
foreach ($included as $file) {
    loader_check(strpos(str_replace('\\', '/', $file), '/vendor/') === false, 'Production eagerly loaded dev vendor: ' . $file);
}
loader_check(!class_exists('PHPUnit\\Framework\\TestCase', false) && !function_exists('PHPUnit\\Framework\\assertTrue'), 'Production initialized PHPUnit');
echo json_encode(array('php' => PHP_VERSION, 'mode' => $mode, 'status' => 'pass', 'classes' => array_keys($classes), 'included_files' => $included, 'autoloaders' => spl_autoload_functions(), 'admin_hooks' => array_keys($actions), 'callbacks' => $callback_readback, 'scope' => 'standalone startup doubles; actual native plugin classes; no WP/SQL acceptance')) . "\n";
