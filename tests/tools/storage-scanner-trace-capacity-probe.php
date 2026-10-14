<?php
/** Offline nominal material arithmetic; NOT native PHP5 or retained headroom. */
require dirname( __DIR__ ) . '/support/StorageScannerTraceCapacity.php';
$out = array();
foreach ( array( '5.4.0', '5.6.20' ) as $v ) {
	$m = new \FooPlugins\FooGalleryMigrate\Tests\StorageScannerTraceCapacity( $v );
	$report = $m->envelope( 60000, 16, 256 );
	$report['depth64_rejection_diagnostic'] = $m->envelope( 60000, 64, 256, true );
	$sweep = array( 'cases' => 0, 'monotone' => true, 'max_large_backing_nominal' => 0 );
	$previous = 0;
	// Saturated upper path has 20 distinct raw argument slots. Exercise all
	// lengths without mistaking repeated invocations for simultaneously live.
	for ( $raw = 0; $raw <= 60000; ++$raw ) {
		$now = $report['max_raw_argument_slots'] * $m->nominal( $raw + 1 );
		$sweep['monotone'] = $sweep['monotone'] && $now >= $previous;
		$previous = $now; ++$sweep['cases'];
	}
	$sweep['max_large_backing_nominal'] = $previous;
	$report['raw_length_sweep'] = $sweep; $out[$v] = $report;
}
print json_encode( array( 'actual_runtime' => PHP_VERSION, 'legacy_runtime_executed' => false,
	'task_scope_completed' => false, 'children_released' => false, 'witnesses' => $out ), JSON_PRETTY_PRINT ) . "\n";
