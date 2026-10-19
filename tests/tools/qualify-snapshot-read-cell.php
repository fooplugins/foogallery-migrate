<?php
/** Offline only. Never installs a cell or changes production admission defaults.
 * php -d memory_limit=-1 tests/tools/qualify-snapshot-read-cell.php cell.json trace.json ...
 * Use the SAME runtime/INI as the measured cell; no cross-runtime relabelling.
 */
require getenv( 'QUAL_BOOTSTRAP' ) ? getenv( 'QUAL_BOOTSTRAP' ) : dirname( __DIR__ ) . '/bootstrap.php';
use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
try {
	if ( $argc < 5 ) { throw new RuntimeException( 'Expected cell and at least three fresh-process raw traces.' ); }
	$cell = json_decode( file_get_contents( $argv[1] ), true ); $raw = array();
	for ( $i = 2; $i < $argc; ++$i ) { $raw[] = file_get_contents( $argv[$i] ); }
	$profile = SnapshotReadProfile::qualify( $cell, $raw );
	print json_encode( array( 'status' => 'EXACT_FINITE_HOST_CELL_ONLY', 'margin' => $profile->margin(),
		'identity' => $cell['identity'], 'runtime' => $cell['runtime'], 'trace_sha256' => $cell['trace_sha256'],
		'production_enabled' => false, 'native_or_literal_floor_approval' => false ), JSON_PRETTY_PRINT ) . "\n";
} catch ( Exception $e ) {
	fwrite( STDERR, 'REFUSED: ' . $e->getMessage() . "\n" ); exit( 2 );
}
