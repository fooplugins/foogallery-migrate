<?php
/** Evaluated fixed-source OFFLINE descriptors, NOT a runtime/admission receipt. */
require dirname( __DIR__ ) . '/support/StorageFixedHelperBounds.php';
$out = array();
foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
	$m = new \FooPlugins\FooGalleryMigrate\Tests\StorageFixedHelperBounds( $version );
	$out[$version] = array( 'bindings' => $m->bindings(), 'descriptors' => $m->descriptors(),
		'helper_domain_depth16' => $m->envelope( 16 ), 'decoder_ceiling_depth64' => $m->envelope( 64 ) );
	foreach ( array( 16, 64 ) as $depth ) {
		$path = array_merge( array( 'decode' ), array_fill( 0, $depth + 1, 'scan' ), array( 'unsigned', 'token', 'fail' ) );
		$sweep = array( 'residuals_checked' => 0, 'peak_added_pages' => 0, 'first_peak_residual' => null );
		for ( $free = 0; $free <= 130944; $free += 8 ) {
			$r = $m->path( $path, $free ); ++$sweep['residuals_checked'];
			if ( $r['peak_added_pages'] > $sweep['peak_added_pages'] ) {
				$sweep['peak_added_pages'] = $r['peak_added_pages']; $sweep['first_peak_residual'] = $free;
			}
		}
		$out[$version]['upper_descriptor_residual_sweep_depth' . $depth] = $sweep;
	}
	if ( '5.4.0' === $version ) { $out[$version]['split_argument_witness'] = $m->arguments54( 8, 5 ); }
}
print json_encode( array( 'status' => 'source_bound_prerequisite_requires_independent_review',
	'actual_runtime' => PHP_VERSION, 'legacy_runtime_executed' => false,
	'task_scope_completed' => false, 'children_released' => false, 'source_bounds' => $out ), JSON_PRETTY_PRINT ) . "\n";
