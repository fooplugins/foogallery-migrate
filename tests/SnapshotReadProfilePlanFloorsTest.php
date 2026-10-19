<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/SnapshotReadProfileLifecycleTest.php';

/** SYNTHETIC_NOT_MEASURED: compiler-algebra checks, never production qualification. */
class SnapshotReadProfilePlanFloorsTest extends TestCase {
	public static function cases() {
		$out = array();
		foreach ( array( 'control-one', 'control-two', 'capacity-exact', 'zero-whole-logical-floors', 'same-sum-transfer', 'full-descriptor-drift' ) as $name ) { $out[$name] = array( $name ); }
		foreach ( array( 'legacy_private_floor', 'witness_construction_lifetime_lookup', 'whole_logical_digest_frames', 'whole_logical_scalar_hash_scratch', 'whole_children_wrapper_cow', 'retained_two_outputs_cow' ) as $part ) {
			foreach ( array( 'zero', 'minus-one', 'plus-one' ) as $change ) { $name = $part . ':' . $change; $out[$name] = array( $name ); }
		}
		return $out;
	}
	public static function accepted( $name ) { return in_array( $name, array( 'control-one', 'control-two', 'capacity-exact' ), true ); }
	public static function fixture( $name ) {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 'control-one' === $name ? 1 : 2 );
		$compiled = $cell['plan']; $p = &$cell['plan'];
		if ( 'zero-whole-logical-floors' === $name ) {
			foreach ( array( 'whole_logical_digest_frames', 'whole_logical_scalar_hash_scratch', 'whole_children_wrapper_cow', 'retained_two_outputs_cow' ) as $part ) { $p['parts'][$part] = 0; }
		} else if ( 'same-sum-transfer' === $name ) {
			--$p['parts']['whole_logical_digest_frames']; ++$p['parts']['retained_two_outputs_cow'];
		} else if ( 'full-descriptor-drift' === $name ) { ++$p['full']['future'];
		} else if ( false !== strpos( $name, ':' ) ) {
			list( $part, $change ) = explode( ':', $name );
			$p['parts'][$part] = 'zero' === $change ? 0 : $p['parts'][$part] + ( 'minus-one' === $change ? -1 : 1 );
		}
		$p['full']['private'] = array_sum( $p['parts'] );
		$traces = array_map( function ( $r ) { return json_decode( $r, true ); }, $raw );
		foreach ( $traces as &$t ) { foreach ( $t['events'] as &$e ) { $e['private'] = $p['full']['private']; } unset( $e ); } unset( $t, $p );
		if ( 'capacity-exact' === $name ) { SnapshotReadProfileLifecycleTest::capacity( $traces ); }
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::seal( $cell, $traces );
		return array( $cell, $raw, $compiled );
	}
	/** @dataProvider cases */
	public function test_synthetic_api_requires_exact_compiler_floors( $name ): void {
		list( $cell, $raw, $compiled ) = self::fixture( $name );
		if ( self::accepted( $name ) ) {
			$profile = SnapshotReadProfile::qualify( $cell, $raw );
			$this->assertSame( 1024, $profile->margin() ); $this->assertTrue( $profile->supports( $compiled, $cell['identity'] ) );
		} else {
			$this->assertNotSame( $compiled, $cell['plan'] );
			$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Snapshot finite host cell refused: overlap floors' );
			SnapshotReadProfile::qualify( $cell, $raw );
		}
	}
	/** @dataProvider cases */
	public function test_synthetic_real_repository_cli_requires_exact_compiler_floors( $name ): void {
		$dir = tempnam( sys_get_temp_dir(), 'snapshot-plan-' ); unlink( $dir ); mkdir( $dir, 0700 );
		try {
			// Export and qualify in separate real processes with identical INI. Do
			// not mistake stale parent PHPUnit INI for a floor-specific refusal.
			list( $exit, $stdout, $stderr ) = $this->command( array( __DIR__ . '/tools/export-snapshot-read-plan-cell.php', $name, $dir ) );
			$this->assertSame( 0, $exit, $stderr ); $this->assertSame( '', $stderr );
			$api = json_decode( $stdout, true );
			$this->assertSame( self::accepted( $name ), $api['accepted'] );
			if ( $api['accepted'] ) { $this->assertTrue( $api['supports_actual_compiled_plan'] ); }
			else { $this->assertSame( 'Snapshot finite host cell refused: overlap floors', $api['reason'] ); }
			list( $exit, $stdout, $stderr ) = $this->command( array_merge( array( __DIR__ . '/tools/qualify-snapshot-read-cell.php', $dir . '/cell.json' ), array_map( function ( $i ) use ( $dir ) { return $dir . '/trace-' . $i . '.json'; }, array( 1, 2, 3 ) ) ) );
			$this->assertSame( self::accepted( $name ) ? 0 : 2, $exit, $stderr );
			if ( self::accepted( $name ) ) {
				$report = json_decode( $stdout, true ); $this->assertSame( 'EXACT_FINITE_HOST_CELL_ONLY', $report['status'] );
				$this->assertFalse( $report['production_enabled'] ); $this->assertSame( '', $stderr );
			} else { $this->assertSame( '', $stdout ); $this->assertStringContainsString( 'REFUSED: Snapshot finite host cell refused: overlap floors', $stderr ); }
		} finally {
			foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); } rmdir( $dir );
		}
	}
	private function command( $args ) {
		$args = array_merge( array( PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'opcache.enable_cli=0' ), $args );
		$command = implode( ' ', array_map( 'escapeshellarg', $args ) );
		$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new \RuntimeException( 'Cannot run snapshot plan CLI regression.' ); }
		fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		return array( proc_close( $process ), $stdout, $stderr );
	}
}
