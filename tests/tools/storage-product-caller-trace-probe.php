<?php
/** Modern arithmetic/source-path control, NOT production endpoint or PHP5 execution. */
require dirname( __DIR__ ) . '/support/StorageProductCallerTrace.php';
$out = array(); $controls = 0;
foreach ( array( '5.4.0', '5.6.20' ) as $v ) {
	$m = new \FooPlugins\FooGalleryMigrate\Tests\StorageProductCallerTrace( $v );
	$local = new \FooPlugins\FooGalleryMigrate\Tests\StorageScannerTraceCapacity( $v );
	// Explicit illustrative file extent, not an installation cap.
	$r = $m->envelope( 60000, 16, 1024 ); $path_count = count( $r['paths'] );
	foreach ( array( 0, 1, 7, 8, 12, 16, 64 ) as $depth ) {
		foreach ( array( false, true ) as $reject ) {
			$a = $m->envelope( 60000, $depth, 1024, $reject );
			if ( count( $a['cases'] ) !== count( $local->paths( $depth, $reject ) ) * $path_count ) { throw new \RuntimeException( 'path_join' ); }
			foreach ( $a['cases'] as $c ) {
				$vector = $local->hash_vector( $c['joined_frames'] );
				if ( $c['construction_nominal'] !== $c['catch_nominal'] + $vector['old_vector_overlap'] ) { throw new \RuntimeException( 'shared_vector_overlap' ); }
			}
			++$controls;
		}
	}
	$previous = 0;
	for ( $raw = 0; $raw <= 60000; ++$raw ) {
		// Suffix arguments never receive raw directly. Raw-copy term remains
		// the received local20-slot term, not a deep copy of source['value'].
		$n = 20 * $local->nominal( $raw + 1 );
		if ( $n < $previous ) { throw new \RuntimeException( 'raw_monotonicity' ); } $previous = $n;
	}
	$growth = array();
	foreach ( array( 7, 8, 9, 15, 16, 17, 31, 32, 33 ) as $frames ) {
		foreach ( array( 0, 1, 4, 6 ) as $suffix ) { $growth[] = $m->join_vector( $frames, $suffix ); ++$controls; }
	}
	$file_sweep = array();
	foreach ( array( 255, 256, 1024, 4096 ) as $bytes ) {
		$a = $m->envelope( 60000, 16, $bytes ); $file_sweep[$bytes] = $a['construction_nominal_upper']; ++$controls;
	}
	$r['joined_cases_checked'] = count( $r['cases'] ); unset( $r['cases'] );
	$r['raw_sweep'] = array( 'cases' => 60001, 'max_new_local_raw_backing_nominal' => $previous );
	$r['growth_controls'] = $growth; $r['conditional_filename_sweep'] = $file_sweep; $out[$v] = $r;
}
print json_encode( array( 'actual_runtime' => PHP_VERSION, 'legacy_runtime_executed' => false,
	'production_endpoints_executed' => false, 'task_scope_completed' => false, 'children_released' => false,
	'controls' => $controls, 'witnesses' => $out ), JSON_PRETTY_PRINT ) . "\n";
