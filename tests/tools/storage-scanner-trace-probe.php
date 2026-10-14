<?php
/** Conditional retained-source witnesses, NOT literal legacy runtime receipts. */
require dirname( __DIR__ ) . '/support/StorageScannerTraceWitness.php';
$out = array();
foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
	$m = new \FooPlugins\FooGalleryMigrate\Tests\StorageScannerTraceWitness( $version );
	$sweep = array( 'cases' => 0, 'first_uncovered_raw_length' => null,
		'max_nominal_block_bytes' => 0, 'alignment_equality_cases' => 0, 'alignment_just_over_cases' => 0 );
	for ( $bytes = 0; $bytes <= 60000; ++$bytes ) {
		$r = $m->argument( $bytes, 2, false, false );
		++$sweep['cases'];
		if ( ! $r['covered_by_short_backing_premise'] && null === $sweep['first_uncovered_raw_length'] ) {
			$sweep['first_uncovered_raw_length'] = $bytes;
		}
		$sweep['max_nominal_block_bytes'] = max( $sweep['max_nominal_block_bytes'], $r['nominal_block_bytes'] );
		if ( 0 === ( $r['new_backing_request'] + 16 ) % 8 ) { ++$sweep['alignment_equality_cases']; }
		if ( 1 === ( $r['new_backing_request'] + 16 ) % 8 ) { ++$sweep['alignment_just_over_cases']; }
	}
	$out[$version] = array( 'bindings' => $m->bindings(), 'report' => $m->report(), 'raw_length_sweep' => $sweep );
}
print json_encode( array( 'actual_runtime' => PHP_VERSION, 'legacy_runtime_executed' => false,
	'task_scope_completed' => false, 'children_released' => false, 'witnesses' => $out ), JSON_PRETTY_PRINT ) . "\n";
