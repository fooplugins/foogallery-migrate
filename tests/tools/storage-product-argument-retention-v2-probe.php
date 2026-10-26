<?php
/** Modern conditional material controls; inputs below are synthetic fixtures. */
require dirname( __DIR__ ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/support/StorageProductArgumentRetentionV2.php';
use FooPlugins\FooGalleryMigrate\Tests\StorageProductArgumentRetentionV2;
use FooPlugins\FooGalleryMigrate\Tests\StorageProductCallerTraceV2;
function block( $n ) { return max( 32, ( ( $n + 16 + 7 ) >> 3 ) << 3 ); }
function tree_upper( $value, $capacity ) {
	$n = block( 32 );
	if ( is_string( $value ) ) { return $n + block( strlen( $value ) + 1 ); }
	if ( is_array( $value ) ) {
		$n += block( 72 ) + block( 8 * $capacity );
		foreach ( $value as $key => $v ) { $n += block( 72 + ( is_string( $key ) ? strlen( $key ) + 1 : 0 ) ) + tree_upper( $v, $capacity ); }
	}
	return $n;
}
$out = array(); $controls = 0;
foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
	$m = new StorageProductArgumentRetentionV2( $version ); $t = new StorageProductCallerTraceV2( $version );
	for ( $bytes = 0; $bytes <= 4096; ++$bytes ) {
		foreach ( array( 8, 16, 32, 512 ) as $capacity ) {
			$s = str_repeat( 'x', $bytes ); $v = array( 'nested' => array( $s, $s ), 'empty' => array() );
			if ( $m->extent( $v, $capacity ) !== tree_upper( $v, $capacity ) ) { throw new RuntimeException( 'independent_tree_arithmetic' ); }
			++$controls;
		}
	}
	$rows = array();
	foreach ( $t->paths() as $id => $path ) {
		$args = array( 'request' => array( 'blog_id' => 1, 'scope' => 'site', 'target' => 'direct' === $path['phase'] ? 'table' : 'option', 'confirmation' => 'synthetic', 'nonce' => str_repeat( 'n', 100000 ) ),
			'record' => array_fill_keys( $t->shapes()['record'], 1 ),
			'source' => $path['source_may_be_null'] ? null : array( 'physical' => str_repeat( 'f', 64 ), 'value' => array( array( str_repeat( 'v', 70000 ) ) ) ) );
		$args['record']['version'] = 2;
		$r = $m->compose( $id, 60000, 16, 1024, $args, array(
			'request' => array( 'array_capacity_upper' => 8, 'string_extra_upper' => 0 ),
			'record' => array( 'array_capacity_upper' => 32, 'string_extra_upper' => 0 ),
			'source' => array( 'array_capacity_upper' => 8, 'string_extra_upper' => 0 ) ) );
		$expected = tree_upper( $args['request'], 8 ) + tree_upper( $path['operation'], 8 );
		if ( 'recover' !== $path['operation'] ) { $expected += block( 32 ); }
		if ( 'direct' !== $path['phase'] ) { $expected += tree_upper( $args['record'], 32 ) + tree_upper( $args['source'], 8 ); }
		if ( $expected !== $r['retention']['existing_argument_graph_nominal_upper'] || count( $r['cases'] ) !== 86 ) { throw new RuntimeException( 'concurrent_roots' ); }
		foreach ( $r['cases'] as $case ) {
			if ( $case['catch_argument_and_trace_nominal_upper'] !== $case['trace']['catch_nominal'] + $expected ||
				$case['construction_argument_and_trace_nominal_upper'] !== $case['trace']['construction_nominal'] + $expected ) { throw new RuntimeException( 'single_composition' ); }
			++$controls;
		}
		$rows[] = array( 'path_id' => $id, 'operation' => $path['operation'], 'phase' => $path['phase'], 'receiver_handle' => $path['receiver_handle'],
			'roots' => $r['retention']['roots'], 'existing_graph_upper' => $expected, 'catch_upper' => $r['catch_argument_and_trace_nominal_upper'],
			'construction_upper' => $r['construction_argument_and_trace_nominal_upper'] );
	}
	$out[$version] = $rows;
}
print json_encode( array( 'actual_runtime' => PHP_VERSION, 'legacy_runtime_executed' => false, 'production_endpoints_executed' => false,
	'input_role' => 'SYNTHETIC_CONDITIONAL_ARITHMETIC_NOT_AUTHORIZATION_OR_POLICY', 'controls' => $controls, 'witnesses' => $out,
	'arena_retention_proven' => false, 'shared_gc_frontier_proven' => false, 'complete_trace_capacity_proven' => false,
	'fits_justified_helper_budget' => false, 'task_scope_completed' => false, 'children_released' => false ), JSON_PRETTY_PRINT ) . "\n";
