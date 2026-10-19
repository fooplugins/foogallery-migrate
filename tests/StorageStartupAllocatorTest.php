<?php
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use PHPUnit\Framework\TestCase;

final class StorageStartupAllocatorTest extends TestCase {
	/** Real fresh engine startup, not an injected facts() or regex-only test. */
	private function process( $startup, $expected, $disabled = '' ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -n -d memory_limit=128M -d max_execution_time=10';
		if ( '' !== $disabled ) { $cmd .= ' -d ' . escapeshellarg( 'disable_functions=' . $disabled ); }
		$cmd .= ' ' . escapeshellarg( __DIR__ . '/fixtures/startup-allocator-observer.php' ) . ' ' . escapeshellarg( dirname( __DIR__ ) ) . ' ' . escapeshellarg( $expected );
		$env = getenv(); $env['USE_ZEND_ALLOC'] = $startup;
		$p = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
		$this->assertIsResource( $p ); fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $p );
		$this->assertSame( 0, $exit, $out . $err ); $this->assertSame( '', $err );
		$r = json_decode( $out, true ); $this->assertIsArray( $r ); $this->assertTrue( $r['pass'] );
		$this->assertSame( $startup, $r['startup_env'] );
		$this->assertSame( 'unknown' === $expected ? null : $expected, $r['observed'][0] );
		$this->assertSame( array_fill( 0, 3, $r['observed'][0] ), $r['observed'] );
		$this->assertSame( 0, $r['output_bytes'] ); $this->assertTrue( $r['buffer_restored'] );
		$this->assertTrue( $r['shipping_classes_empty'] );
	}
	public function test_real_zend_mode_survives_conflicting_mutable_environment() { $this->process( '1', 'zend' ); }
	public function test_real_disabled_mode_survives_conflicting_mutable_environment() { $this->process( '0', 'system' ); }
	public function test_missing_native_observer_is_unknown_in_both_modes() {
		$this->process( '1', 'unknown', 'phpinfo' ); $this->process( '0', 'unknown', 'phpinfo' );
	}
	public function test_unavailable_buffer_cannot_emit_phpinfo() { $this->process( '1', 'unknown', 'ob_end_clean' ); }
	public function test_unknown_and_ambiguous_rows_fail_closed() {
		$m = new ReflectionMethod( StorageCapacityPolicy::class, 'allocator_row' );
		foreach ( array( '', 'Zend Memory Manager => maybe', "Zend Memory Manager => enabled\nZend Memory Manager => disabled", '<div>Zend Memory Manager => enabled</div>' ) as $raw ) {
			$this->assertNull( $m->invoke( null, $raw ) );
		}
		$this->assertSame( 'zend', $m->invoke( null, "Zend Memory Manager => enabled\n" ) );
		$this->assertSame( 'system', $m->invoke( null, '<tr><td class="e">Zend Memory Manager </td><td class="v">disabled </td></tr>' ) );
	}
	public function test_existing_outer_output_buffer_and_restrictions_are_preserved() {
		$level = ob_get_level(); $called = 0;
		ob_start( function ( $chunk ) use ( &$called ) { ++$called; return $chunk; } );
		echo 'before'; $facts = StorageCapacityPolicy::facts(); echo 'after';
		$this->assertSame( 0, $called ); $this->assertSame( 'beforeafter', ob_get_clean() );
		$this->assertSame( $level, ob_get_level() );
		$this->assertContains( $facts['allocator'], array( 'zend', 'system', null ) );
		$this->assertInstanceOf( WP_Error::class, StorageCapacityPolicy::resolve() );
	}
}
