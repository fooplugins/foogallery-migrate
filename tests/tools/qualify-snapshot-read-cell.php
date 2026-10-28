<?php
/** Offline only. Never installs a cell or changes production admission defaults.
 * php -d memory_limit=256M tests/tools/qualify-snapshot-read-cell.php cell.json trace.json ...
 * Use the SAME runtime/INI as the measured cell; no cross-runtime relabelling.
 */
require getenv( 'QUAL_BOOTSTRAP' ) ? getenv( 'QUAL_BOOTSTRAP' ) : dirname( __DIR__ ) . '/bootstrap.php';
use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
try {
	if ( $argc < 5 ) { throw new RuntimeException( 'Expected cell and at least three fresh-process raw traces.' ); }
	if ( filesize( $argv[1] ) > 65536 ) { throw new RuntimeException( 'Cell metadata bound.' ); }
	$cell = json_decode( file_get_contents( $argv[1] ), true ); $paths = array_slice( $argv, 2 );
	// Explicit format dispatch; old bounded JSON remains compatible.
	if ( isset( $cell['transport'] ) && $cell['transport'] === 'snapshot-read-ndjson-v1' ) {
		$profile = SnapshotReadProfile::qualify_files( $cell, $paths );
	} else {
		$raw = array(); foreach ( $paths as $path ) { if ( filesize( $path ) > 16777216 ) { throw new RuntimeException( 'Legacy trace bound; use streamed transport.' ); } $raw[] = file_get_contents( $path ); }
		$profile = SnapshotReadProfile::qualify( $cell, $raw );
	}
	print json_encode( array( 'status' => 'EXACT_FINITE_HOST_CELL_ONLY', 'margin' => $profile->margin(),
		'identity' => $cell['identity'], 'runtime' => $cell['runtime'], 'trace_sha256' => $cell['trace_sha256'],
		'transport_schema_valid' => true, 'measured_profile_approval' => false, 'memory_limit' => ini_get( 'memory_limit' ),
		'peak_used' => memory_get_peak_usage( false ), 'peak_allocated' => memory_get_peak_usage( true ),
		'production_enabled' => false, 'native_or_literal_floor_approval' => false ), JSON_PRETTY_PRINT ) . "\n";
} catch ( Exception $e ) {
	fwrite( STDERR, 'REFUSED: ' . $e->getMessage() . "\n" ); exit( 2 );
}
