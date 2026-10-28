<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageProductCallerTraceV3.php';

/** Additive diagnostic-successor controls; native samples, not approved admission. */
class StorageCurrentDiagnosticNativeScaleTest extends TestCase {
	private function limits() {
		// Exact fixed dispatch:44-47 values, NOT a larger request budget.
		return array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
			'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
			'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
			'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 );
	}
	private function scale( $limits = null, $deadline = null, $baseline = null ) {
		return new StorageScaleAdmission( null === $limits ? $this->limits() : $limits,
			array( 'max_raw_bytes' => 60000, 'amplification' => 8, 'reserve_bytes' => 33554432 ),
			null === $deadline ? microtime( true ) + 10.0 : $deadline, null, null, null === $baseline ? new StorageMemoryBaseline() : $baseline );
	}
	private function trace( $error ) {
		$out = array();
		foreach ( $error->getTrace() as $frame ) {
			if ( ! isset( $frame['class'] ) || ! in_array( $frame['class'], array( LegacyOptionDecoder::class, StorageScaleAdmission::class ), true ) ) { continue; }
			$short = substr( $frame['class'], strrpos( $frame['class'], '\\' ) + 1 );
			$out[] = $short . '::' . $frame['function'];
			$r = new \ReflectionMethod( $frame['class'], $frame['function'] );
			$this->assertCount( $r->getNumberOfParameters(), $frame['args'] );
		}
		return $out;
	}
	public function test_native_scale_ordinary_depth_sibling_and_decoder_failure_reuse() {
		$baseline = new StorageMemoryBaseline(); $before = $baseline->sample();
		$scale = $this->scale( null, null, $baseline ); $decoder = $scale->decoder( 60000 );
		$nested = null; for ( $i = 0; $i < 16; ++$i ) { $nested = array( $nested ); }
		foreach ( array( array(), array( 'x' ), $nested, array( array(), array(), array() ) ) as $value ) {
			$this->assertSame( serialize( $value ), serialize( $decoder->decode( serialize( $value ) ) ) );
		}
		foreach ( array( 'a:x:{}', 'a:1:{i:0;N!}', str_repeat( 'a:1:{i:0;', 65 ) . 'N;' . str_repeat( '}', 65 ) ) as $raw ) {
			try { $decoder->decode( $raw ); $this->fail( 'Malformed/depth input accepted' ); }
			catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'Unsupported or unbounded', $e->getMessage() ); }
			$this->assertSame( array(), $decoder->decode( 'a:0:{}' ) );
		}
		$this->assertGreaterThanOrEqual( $before, $baseline->sample() );
	}
	/** @dataProvider refusal_sites
	 * @group optional_host_diagnostic
	 */
	public function test_native_work_deadline_and_memory_refusal_frames_are_not_decoder_failure( $site ) {
		StorageNativeScaleTrace::assert_domain();
		$limits = $this->limits(); $deadline = null;
		if ( 'memory' === $site ) { $limits['memory_ceiling_bytes'] = memory_get_usage( true ) + 33554432 + 4194304; }
		if ( 'deadline' === $site ) { $deadline = microtime( true ) + 0.05; }
		$scale = $this->scale( $limits, $deadline ); $decoder = $scale->decoder( 60000 );
		$pressure = 'memory' === $site ? str_repeat( 'x', 8388608 ) : null;
		if ( null !== $deadline ) { usleep( 70000 ); }
		if ( in_array( $site, array( 'entry', 'admission-tick', 'scan' ), true ) ) {
			// Explicit seeded-counter boundary control, NOT a claim that this
			// workload naturally consumed 16M ticks before the ten-second deadline.
			$r = new \ReflectionMethod( $scale, 'tick' );
			$body = implode( '', array_slice( file( $r->getFileName() ), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1 ) );
			$this->assertSame( 1, preg_match( '/\+\+\$this->work > ([0-9]+)/', $body, $match ) );
			$work = new \ReflectionProperty( $scale, 'work' );
			$allow = array( 'entry' => 0, 'scan' => 1, 'admission-tick' => 2 ); // decode:36, scan:108, admit_memory:141
			$work->setValue( $scale, (int) $match[1] - $allow[$site] );
		}
		$old = ini_get( 'zend.exception_ignore_args' ); ini_set( 'zend.exception_ignore_args', '0' );
		try {
			try { $decoder->decode( 'a:0:{}' ); $this->fail( 'Native scale refusal was not reached' ); }
			catch ( \RuntimeException $e ) {
				$this->assertSame( 'Finite storage scale admission refused; retain authority and all evidence.', $e->getMessage() );
				$trace = $this->trace( $e ); $this->assertContains( $trace, StorageNativeScaleTrace::paths( 64 ) );
				$this->assertNotContains( 'LegacyOptionDecoder::fail', $trace );
				foreach ( array( 'StorageScaleAdmission::now', 'StorageScaleAdmission::limit', 'StorageScaleAdmission::memory_sample', 'StorageScaleAdmission::checked_add', 'StorageScaleAdmission::decoder' ) as $returned ) { $this->assertNotContains( $returned, $trace ); }
				if ( 'memory' === $site ) { $this->assertSame( array( 'StorageScaleAdmission::fail', 'StorageScaleAdmission::admit_memory', 'LegacyOptionDecoder::decode' ), $trace ); }
				if ( 'scan' === $site ) { $this->assertContains( 'LegacyOptionDecoder::scan', $trace ); }
				if ( 'admission-tick' === $site ) { $this->assertContains( 'StorageScaleAdmission::admit_memory', $trace ); }
				$this->assertNotEmpty( $e->getTrace() ); // retained beyond unwind
			}
		} finally { ini_set( 'zend.exception_ignore_args', $old ); }
	}
	public static function refusal_sites() { return array_map( function ( $x ) { return array( $x ); }, array( 'entry', 'admission-tick', 'scan', 'deadline', 'memory' ) ); }
	/** @group optional_host_diagnostic */
	public function test_enabled_observer_does_not_silently_enter_the_disabled_native_domain() {
		foreach ( array( 'enabled', 'milestones_enabled' ) as $name ) {
			$p = new \ReflectionProperty( StorageScalarObserver::class, $name ); $old = $p->getValue();
			try {
				$p->setValue( null, true );
				try { new StorageProductCallerTraceV3( '5.4.0' ); $this->fail( 'Enabled observer accepted' ); }
				catch ( \RuntimeException $e ) { $this->assertSame( 'diagnostic_observer_domain', $e->getMessage() ); }
			} finally { $p->setValue( null, $old ); }
		}
	}
	/** @group optional_host_diagnostic */
	public function test_zero_arg_tick_material_has_no_raw_slot_and_receiver_joins_scale_origin_once() {
		$m = new StorageScannerTraceCapacityV2( '5.4.0' );
		$entry = StorageNativeScaleTrace::cases( $m, 60000, 16, 256 )[0];
		$this->assertSame( 1, $entry['material']['raw_argument_slots'] );
		$this->assertSame( 1, $entry['material']['argument_slots'] );
		$r = ( new StorageProductCallerTraceV3( '5.4.0' ) )->envelope( 60000, 16, 256 );
		$this->assertTrue( $r['domain']['native_scale_refusals_included'] );
		$this->assertFalse( $r['domain']['arbitrary_clock_memory_hooks_included'] );
		$counts = array( 'decoder_refusal' => 0, 'native_scale_refusal' => 0 );
		$paths = ( new StorageProductCallerTraceV3( '5.4.0' ) )->paths();
		foreach ( $r['cases'] as $case ) {
			++$counts[$case['failure_origin']];
			$this->assertNull( $case['native_hydration_overlap_nominal_upper'] );
			$this->assertSame( $case['local']['frames'] + count( $paths[$case['path_id']]['frames'] ), $case['joined_frames'] );
		}
		$this->assertSame( count( $m->paths( 16 ) ) * 18, $counts['decoder_refusal'] );
		$this->assertSame( count( StorageNativeScaleTrace::paths( 16 ) ) * 18, $counts['native_scale_refusal'] );
	}
}
