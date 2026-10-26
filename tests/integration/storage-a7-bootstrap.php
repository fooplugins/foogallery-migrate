<?php
/** WP-CLI --require observation, before WP bootstrap; never resets/subtracts peaks. */
if ( PHP_SAPI !== 'cli' || PHP_VERSION_ID < 70000 || ! defined( 'WP_CLI' ) || ! WP_CLI ) { throw new RuntimeException( 'A7 requires native PHP>=7 WP-CLI.' ); }
$GLOBALS['foogm_a7_bootstrap'] = array( 'time' => microtime( true ), 'live' => memory_get_usage( false ), 'allocated' => memory_get_usage( true ),
	'peak_live_cumulative' => memory_get_peak_usage( false ), 'peak_allocated_cumulative' => memory_get_peak_usage( true ),
	'php_binary' => PHP_BINARY, 'php_binary_sha256' => hash_file( 'sha256', PHP_BINARY ), 'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI,
	'rusage' => getrusage(), 'rss_unit' => 'Darwin' === PHP_OS ? 'bytes' : 'KiB', 'observation' => 'WP-CLI --require before WordPress, not process allocation origin' );
