<?php
// Fresh-process observer contract, also executable by literal PHP 5.4.0.
// argv: product root, expected allocator (zend/system/unknown), optional wp-load.
if ( isset( $argv[3] ) ) {
	define( 'WP_INSTALLING', true ); // No active plugins or fixture writes.
	require $argv[3];
} else {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
	$wp_version = '5.0';
}
require $argv[1] . '/includes/class-storage-capacity-policy.php';
$expected = 'unknown' === $argv[2] ? null : $argv[2];
$startup_env = getenv( 'USE_ZEND_ALLOC' );
$level = ob_get_level();
ob_start();
$before = \FooPlugins\FooGalleryMigrate\StorageCapacityPolicy::facts();
putenv( 'USE_ZEND_ALLOC=' . ( 'zend' === $expected ? '0' : '1' ) );
$after = \FooPlugins\FooGalleryMigrate\StorageCapacityPolicy::facts();
putenv( 'USE_ZEND_ALLOC=untrusted-after-startup' );
$again = \FooPlugins\FooGalleryMigrate\StorageCapacityPolicy::facts();
$output = ob_get_clean();
$raw_policy = file_get_contents( $argv[1] . '/includes/storage-capacity-policy.json' );
$policy = \FooPlugins\FooGalleryMigrate\StorageCapacityPolicy::release_policy( $raw_policy );
$release_policy = 1 === $policy['release_version'] && 3145728 === $policy['limits']['max_raw_bytes'] &&
	16 === $policy['amplification'] && 134217728 === $policy['reserve_bytes'] && 33554432 === $policy['phase_headroom_bytes'];
$pass = $before['allocator'] === $expected && $after['allocator'] === $expected && $again['allocator'] === $expected && '' === $output && ob_get_level() === $level && $release_policy;
echo json_encode( array( 'pass' => $pass, 'php' => PHP_VERSION, 'wp' => $wp_version, 'sapi' => PHP_SAPI,
	'startup_env' => $startup_env, 'mutated_env' => getenv( 'USE_ZEND_ALLOC' ), 'expected' => $expected,
	'observed' => array( $before['allocator'], $after['allocator'], $again['allocator'] ), 'output_bytes' => strlen( $output ),
	'buffer_restored' => ob_get_level() === $level, 'shipping_release_policy' => $release_policy,
	'memory_limit' => ini_get( 'memory_limit' ), 'max_execution_time' => ini_get( 'max_execution_time' ),
	'used' => memory_get_usage( false ), 'allocated' => memory_get_usage( true ),
	'peak_used' => memory_get_peak_usage( false ), 'peak_allocated' => memory_get_peak_usage( true ),
	'product_policy_sha256' => hash_file( 'sha256', $argv[1] . '/includes/class-storage-capacity-policy.php' ) ) ) . "\n";
exit( $pass ? 0 : 1 );
