<?php
/** Fresh-process modern CLI observations, NOT calibration or production admission. */
if ( PHP_SAPI !== 'cli' || $argc !== 2 ) { fwrite( STDERR, "Usage: php tests/tools/storage-envelope-observe.php SHAPE\n" ); exit( 2 ); }
$shape = $argv[1];
switch ( $shape ) {
	case 'tiny-integers': $value = array( 'state' => array_fill( 0, 4096, 0 ) ); break;
	case 'binary-slashes': $value = array( 'state' => str_repeat( "\xff\xff\xff", 87381 ) ); break;
	case 'deep-arrays':
		$value = 0;
		for ( $i = 0; $i < 60; ++$i ) { $value = array( $value ); }
		$value = array( 'state' => $value ); break;
	case 'root-width':
		$value = array();
		for ( $i = 0; $i < 100; ++$i ) { $value[$i] = array_fill( 0, 40, false ); } break;
	default: fwrite( STDERR, "Unknown synthetic shape.\n" ); exit( 2 );
}
$counts = array( 'nodes' => 0, 'depth' => 0, 'entries' => 0, 'arrays' => 0, 'string_bytes' => 0 );
$count = function ( $value, $depth ) use ( &$count, &$counts ) {
	++$counts['nodes']; $counts['depth'] = max( $counts['depth'], $depth );
	if ( is_array( $value ) ) {
		++$counts['arrays']; $counts['entries'] += count( $value );
		foreach ( $value as $key => $item ) { $count( $key, $depth + 1 ); $count( $item, $depth + 1 ); }
	} else if ( is_string( $value ) ) { $counts['string_bytes'] += strlen( $value ); }
};
$count( $value, 0 ); unset( $count );
$raw = serialize( $value ); unset( $value );
$root = dirname( dirname( __DIR__ ) ); define( 'ABSPATH', $root . '/' );
require_once $root . '/includes/class-legacy-option-decoder.php';
require_once $root . '/includes/class-legacy-state-codec.php';
$before = memory_get_usage( true ); $start = microtime( true );
$decoder = new \FooPlugins\FooGalleryMigrate\LegacyOptionDecoder();
$decoded = $decoder->decode( $raw );
$codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
$digest = $codec->snapshot_digest( $decoded );
$encoded = $codec->encode( $decoded );
if ( serialize( $codec->decode( $encoded ) ) !== $raw || serialize( $decoded ) !== $raw ) {
	fwrite( STDERR, "Synthetic roundtrip failed.\n" ); exit( 1 );
}
$elapsed = microtime( true ) - $start;
$identity = array( 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'integer_bytes' => PHP_INT_SIZE,
	'os' => PHP_OS, 'machine' => php_uname( 'm' ), 'kernel' => php_uname( 'r' ), 'debug' => PHP_DEBUG, 'zts' => PHP_ZTS,
	'use_zend_alloc' => getenv( 'USE_ZEND_ALLOC' ), 'memory_limit' => ini_get( 'memory_limit' ),
	'max_execution_time' => ini_get( 'max_execution_time' ), 'opcache.enable_cli' => ini_get( 'opcache.enable_cli' ),
	'decoder_sha256' => hash_file( 'sha256', $root . '/includes/class-legacy-option-decoder.php' ),
	'codec_sha256' => hash_file( 'sha256', $root . '/includes/class-legacy-state-codec.php' ) );
echo json_encode( array( 'status' => 'measurement_only', 'conversion_ready' => false, 'shape' => $shape,
	'scope' => 'synthetic whole-option decode/canonical roundtrip/logical digest/whole-value codec; NO WordPress, SQL, staging, public receiver, web SAPI or floor runtime',
	'identity' => $identity, 'counts' => $counts, 'raw_bytes' => strlen( $raw ), 'encoded_bytes' => strlen( $encoded ),
	'memory_before_decode_bytes' => $before, 'process_peak_allocated_bytes_including_fixture_factory' => memory_get_peak_usage( true ),
	'observed_elapsed_seconds' => $elapsed, 'roundtrip' => true ), JSON_PRETTY_PRINT ) . "\n";
