<?php
/** Current-byte semantic witness + invalidation controls. Modern offline only. */
require dirname( __DIR__ ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/support/StorageReceiverNewCompositionV2.php';
use FooPlugins\FooGalleryMigrate\Tests\StorageProductCallerTrace;
use FooPlugins\FooGalleryMigrate\Tests\StorageProductCallerTraceV2;
use FooPlugins\FooGalleryMigrate\Tests\StorageProductArgumentRetentionV2;
use FooPlugins\FooGalleryMigrate\Tests\StorageReceiverNewCompositionV2;

$root = dirname( __DIR__, 2 ); $out = array();
foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
	$m = new StorageProductCallerTraceV2( $version ); $paths = $m->paths(); $bindings = $m->bindings();
	$m->assert_witness( $paths, $bindings, $root );
	$old = new StorageProductCallerTrace( $version );
	$mutants = array( 'original_stale_binding' => array( $old->paths(), $old->bindings() ), 'hash_only_rebound_historical_paths' => array( $old->paths(), $bindings ) );
	$bad = $paths; $bad[0]['edges'][2]['actual_arguments'] = array( '$request', 'true' );
	$mutants['misbound_replacement_argument'] = array( $bad, $bindings );
	$bad = $paths; $bad[0]['edges'][3]['callee'] = 'replace_retained';
	$mutants['misbound_dispatch_target'] = array( $bad, $bindings );
	$bad = $paths; --$bad[1]['edges'][4]['line'];
	$mutants['stale_handle_line'] = array( $bad, $bindings );
	$negative = array();
	foreach ( $mutants as $name => $input ) {
		try { $m->assert_witness( $input[0], $input[1], $root ); }
		catch ( RuntimeException $error ) { $negative[$name] = array( 'rejected' => true, 'reason' => $error->getMessage() ); continue; }
		throw new RuntimeException( 'negative_control_accepted:' . $name );
	}
	$a = new StorageProductArgumentRetentionV2( $version ); $r = new StorageReceiverNewCompositionV2( $version );
	if ( $a->bindings() !== $r->bindings()['retained_helpers'] ) { throw new RuntimeException( 'dependent_composition_binding' ); }
	$out[$version] = array( 'caller_bindings' => $bindings, 'argument_composition_bindings' => $a->bindings(),
		'receiver_composition_bindings' => $r->bindings(), 'fresh_paths' => $paths, 'negative_controls' => $negative );
}
print json_encode( array( 'diagnostic_version' => 'candidate-diagnostic-v2', 'actual_runtime' => PHP_VERSION,
	'production_base_commit' => '054d41c8f51cff316ce696d7a0932c63f2f6a16b', 'legacy_runtime_executed' => false,
	'full_conversion_executed' => false, 'original_gates_completed' => false, 'witnesses' => $out ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
