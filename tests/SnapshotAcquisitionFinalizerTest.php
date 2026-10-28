<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

/** Executes the actual emitter footer and real trace writer; all SQL is a named host-only spy. */
final class SnapshotAcquisitionFinalizerTest extends TestCase {
    /** @dataProvider failureModes */
    public function test_finalizer_preserves_errors_and_attempts_every_owned_cleanup( $mode ): void {
        $dir = tempnam( getenv( 'TMPDIR' ), 'seed-finalizer-' ); unlink( $dir ); mkdir( $dir );
        try {
            $args = array( PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'opcache.enable_cli=0',
                __DIR__ . '/tools/snapshot-acquisition-finalizer-probe.php', $dir, $mode );
            $process = proc_open( implode( ' ', array_map( 'escapeshellarg', $args ) ),
                array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
            $this->assertIsResource( $process );
            fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
            $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $exit = proc_close( $process );
            $this->assertSame( '', $stderr ); $this->assertSame( 'success' === $mode ? 0 : 2, $exit, $stdout );
            $this->assertFileExists( $dir . '/receipt.json' );
            $receipt = json_decode( file_get_contents( $dir . '/receipt.json' ), true );
            $calls = json_decode( file_get_contents( $dir . '/calls.json' ), true );
            $summary = json_decode( $stdout, true );
            $this->assertSame( 'success' === $mode, $receipt['ok'] );
            $this->assertSame( $receipt['ok'], $summary['ok'] );
            $this->assertTrue( $calls['collector_detached'] );
            $this->assertSame( array(
                'DROP TABLE `owned_c`', "SHOW TABLES LIKE 'owned_c'",
                'DROP TABLE `owned_b`', "SHOW TABLES LIKE 'owned_b'",
                'DROP TABLE `owned_a`', "SHOW TABLES LIKE 'owned_a'",
            ), $calls['sql'] );
            $cleanup_faults = false !== strpos( $mode, 'cleanup-faults' );
            $this->assertSame( array( 'owned_c' => ! $cleanup_faults, 'owned_b' => ! $cleanup_faults, 'owned_a' => true ), $receipt['cleanup_drop'] );
            $this->assertSame( array_fill_keys( array( 'owned_c', 'owned_b', 'owned_a' ), ! $cleanup_faults ), $receipt['cleanup_readback'] );
            if ( 'success' === $mode ) {
                $this->assertArrayNotHasKey( 'first_failure', $receipt );
                $this->assertArrayNotHasKey( 'secondary_failures', $receipt );
                $this->assertNull( $summary['failure'] );
            } else {
                $expected = 'cleanup-faults' === $mode ? 'Error: owned_c drop fault' :
                    ( 'primary-after-finish' === $mode ? 'RuntimeException: post-trace primary fault' : 'RuntimeException: initial trace write fault' );
                $this->assertSame( $expected, $receipt['first_failure'] );
                $this->assertSame( $receipt['first_failure'], $summary['failure'] );
            }
            if ( 'trace-faults' === $mode || 'trace-cleanup-faults' === $mode ) {
                $this->assertSame( 3, $calls['writes'], 'Initial header, failing first event, failing finalizer all executed.' );
                $this->assertSame( array( 'phase' => 'trace_finish', 'error' => 'RuntimeException: trace finalizer fault' ), $receipt['secondary_failures'][0] );
                $this->assertArrayNotHasKey( 'trace_sha256', $receipt );
                $this->assertSame( 1, $receipt['events'], 'Collector sequence includes the attempted event, not a sealed trace count.' );
            } elseif ( 'header-fault' === $mode ) {
                $this->assertSame( 1, $calls['writes'] );
                $this->assertArrayNotHasKey( 'trace_sha256', $receipt );
            } else {
                $this->assertSame( hash_file( 'sha256', $dir . '/trace.ndjson' ), $receipt['trace_sha256'] );
            }
            if ( $cleanup_faults ) {
                $errors = $receipt['secondary_failures'];
                if ( 'trace-cleanup-faults' === $mode ) {
                    $this->assertCount( 6, $errors ); array_shift( $errors );
                    $this->assertSame( array( 'phase' => 'drop:owned_c', 'error' => 'Error: owned_c drop fault' ), array_shift( $errors ) );
                } else { $this->assertCount( 4, $errors ); }
                $this->assertSame( array( 'readback:owned_c', 'drop:owned_b', 'readback:owned_b', 'readback:owned_a' ), array_column( $errors, 'phase' ) );
                $this->assertStringContainsString( 'Owned table readback failed', $errors[2]['error'] );
                $this->assertStringContainsString( 'Owned table remains', $errors[3]['error'] );
                $this->assertSame( $receipt['secondary_failures'], $summary['secondary_failures'] );
            }
        } finally {
            foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); } rmdir( $dir );
        }
    }
    public static function failureModes(): array {
        return array( array( 'trace-faults' ), array( 'header-fault' ), array( 'cleanup-faults' ),
            array( 'trace-cleanup-faults' ), array( 'primary-after-finish' ), array( 'success' ) );
    }
}
