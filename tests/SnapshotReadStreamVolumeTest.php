<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use PHPUnit\Framework\TestCase;

/** Executes real exporter/qualification CLI under 256M. Synthetic TRANSPORT ONLY. */
class SnapshotReadStreamVolumeTest extends TestCase {
	private function command( $args, $prefix ) {
		$args = array_merge( array( PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'opcache.enable_cli=0' ), $args );
		$command = implode( ' ', array_map( 'escapeshellarg', $args ) );
		file_put_contents( $prefix . '.command', $command . "\n" );
		$p = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.json', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
		$this->assertIsResource( $p ); fclose( $pipes[0] ); $exit = proc_close( $p );
		$this->assertSame( 0, $exit, file_get_contents( $prefix . '.stderr' ) );
		$this->assertSame( '', file_get_contents( $prefix . '.stderr' ) );
		return json_decode( file_get_contents( $prefix . '.json' ), true );
	}
	public function test_real_cli_streamed_refusals_are_not_profile_approval(): void {
		$keep = (bool) getenv( 'PR15_TRANSPORT_EVIDENCE' );
		$dir = $keep ? getenv( 'PR15_TRANSPORT_EVIDENCE' ) . '/cli-negative-fixtures' : tempnam( sys_get_temp_dir(), 'snapshot-cli-negative-' );
		if ( ! $keep ) { unlink( $dir ); } $this->assertFalse( file_exists( $dir ) ); mkdir( $dir, 0700 );
		try {
			$this->command( array( __DIR__ . '/tools/export-snapshot-read-plan-cell.php', 'transport-small', $dir ), $dir . '/export' );
			$original_cell = json_decode( file_get_contents( $dir . '/cell.json' ), true );
			$bytes = file_get_contents( $dir . '/trace-1.ndjson' ); $receipts = array();
			foreach ( array( 'corruption', 'truncation', 'reorder', 'runtime', 'source', 'connection', 'input', 'lifecycle', 'pending', 'debt', 'private', 'trailing', 'count' ) as $mode ) {
				$lines = explode( "\n", rtrim( $bytes, "\n" ) ); $header = json_decode( $lines[0], true ); $cell = $original_cell;
				if ( $mode === 'runtime' || $mode === 'source' ) { $header['trace']['runtime'][$mode === 'runtime' ? 'ini_sha256' : 'product_compiler_sha256'] = str_repeat( '0', 64 ); }
				if ( $mode === 'connection' || $mode === 'input' ) { $header['trace']['identity'][$mode === 'connection' ? 'connection' : 'digest'] = 'changed'; }
				$lines[0] = json_encode( $header );
				if ( $mode === 'reorder' ) { $tmp = $lines[1]; $lines[1] = $lines[2]; $lines[2] = $tmp; }
				if ( in_array( $mode, array( 'lifecycle', 'pending', 'debt', 'private' ), true ) ) {
					$e = json_decode( $lines[1], true );
					if ( $mode === 'lifecycle' ) { $e['event']['seam'] = 'exit'; }
					if ( $mode === 'pending' ) { $e['event']['pending'] = -1; }
					if ( $mode === 'debt' ) { $e['event']['remaining'] = 0; }
					if ( $mode === 'private' ) { --$e['event']['private']; }
					$lines[1] = json_encode( $e );
				}
				if ( $mode === 'count' ) { $lines[count( $lines ) - 1] = '{"end":0}'; }
				$mutated = implode( "\n", $lines ) . "\n";
				if ( $mode === 'corruption' ) { $mutated = str_replace( '8388608', '8388609', $mutated ); }
				if ( $mode === 'truncation' ) { $mutated = substr( $mutated, 0, -1 ); }
				if ( $mode === 'trailing' ) { $mutated .= "{}\n"; }
				if ( $mode !== 'corruption' ) { $cell['trace_sha256'][0] = hash( 'sha256', $mutated ); }
				$trace = $dir . '/' . $mode . '.ndjson'; $cell_path = $dir . '/' . $mode . '.cell.json';
				file_put_contents( $trace, $mutated ); file_put_contents( $cell_path, json_encode( $cell ) );
				$args = array( PHP_BINARY, '-d', 'memory_limit=256M', '-d', 'opcache.enable_cli=0', __DIR__ . '/tools/qualify-snapshot-read-cell.php', $cell_path, $trace, $dir . '/trace-2.ndjson', $dir . '/trace-3.ndjson' );
				$command = implode( ' ', array_map( 'escapeshellarg', $args ) );
				$p = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
				$this->assertIsResource( $p ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $exit = proc_close( $p );
				$this->assertSame( 2, $exit, $mode . ': ' . $stderr ); $this->assertSame( '', $stdout ); $this->assertStringStartsWith( 'REFUSED:', $stderr );
				$receipts[$mode] = array( 'command' => $command, 'exit' => $exit, 'stderr' => $stderr );
			}
			if ( getenv( 'PR15_TRANSPORT_EVIDENCE' ) ) { file_put_contents( getenv( 'PR15_TRANSPORT_EVIDENCE' ) . '/cli-negative-receipts.json', json_encode( $receipts, JSON_PRETTY_PRINT ) . "\n" ); }
		} finally { if ( ! $keep ) { foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); } rmdir( $dir ); } }
	}
	public function test_original_required_volume_content_parity_and_constant_workspace(): void {
		$root = getenv( 'PR15_TRANSPORT_EVIDENCE' ); $keep = (bool) $root;
		if ( ! $keep ) { $root = tempnam( sys_get_temp_dir(), 'snapshot-volume-' ); unlink( $root ); mkdir( $root, 0700 ); }
		$this->assertDirectoryExists( $root ); $results = array();
		try {
			foreach ( array( 'transport-small' => 66, 'transport-original-volume' => 485034 ) as $case => $count ) {
				$dir = $root . '/' . $case; $this->assertFalse( file_exists( $dir ), 'Do not overwrite evidence.' ); mkdir( $dir, 0700 );
				$export = $this->command( array( __DIR__ . '/tools/export-snapshot-read-plan-cell.php', $case, $dir ), $dir . '/export' );
				$this->assertTrue( $export['SYNTHETIC_NOT_MEASURED'] ); $this->assertFalse( $export['measured_profile_approval'] );
				$this->assertSame( '256M', $export['memory_limit'] ); $this->assertLessThan( 33554432, $export['peak_allocated'] );
				$paths = array();
				foreach ( $export['traces'] as $receipt ) {
					$path = $dir . '/' . $receipt['file']; $paths[] = $path;
					$this->assertSame( $count, $receipt['events'] ); $this->assertSame( $receipt['sha256'], hash_file( 'sha256', $path ) );
					// Independent line traversal/content digest; no SnapshotReadTraceStream reader used.
					$f = fopen( $path, 'rb' ); $header = json_decode( fgets( $f, 65537 ), true ); $digest = hash_init( 'sha256' ); $n = 0; $end = null;
					$this->assertSame( 'snapshot-read-ndjson-v1', $header['format'] );
					while ( false !== ( $line = fgets( $f, 65537 ) ) ) {
						$record = json_decode( $line, true );
						if ( isset( $record['event'] ) ) { hash_update( $digest, json_encode( $record['event'] ) . "\n" ); ++$n; }
						else { $end = $record; break; }
					}
					$this->assertSame( $count, $n ); $this->assertSame( array( 'end' => $count ), $end ); $this->assertFalse( fgetc( $f ) ); fclose( $f );
					$this->assertSame( $receipt['event_content_sha256'], hash_final( $digest ) );
				}
				$report = $this->command( array_merge( array( __DIR__ . '/tools/qualify-snapshot-read-cell.php', $dir . '/cell.json' ), $paths ), $dir . '/qualify' );
				$this->assertTrue( $report['transport_schema_valid'] ); $this->assertFalse( $report['measured_profile_approval'] );
				$this->assertFalse( $report['production_enabled'] ); $this->assertFalse( $report['native_or_literal_floor_approval'] );
				$this->assertSame( '256M', $report['memory_limit'] ); $this->assertSame( 1024, $report['margin'] );
				$this->assertLessThan( 33554432, $report['peak_allocated'] ); $results[$case] = array( 'export' => $export, 'qualify' => $report );
			}
			$this->assertLessThanOrEqual( $results['transport-small']['export']['peak_allocated'] + 2097152, $results['transport-original-volume']['export']['peak_allocated'] );
			$this->assertLessThanOrEqual( $results['transport-small']['qualify']['peak_allocated'] + 2097152, $results['transport-original-volume']['qualify']['peak_allocated'] );
			file_put_contents( $root . '/volume-receipt.json', json_encode( array( 'SYNTHETIC_NOT_MEASURED' => true, 'content_parity' => true, 'bounded_workspace' => true, 'results' => $results ), JSON_PRETTY_PRINT ) . "\n" );
		} finally {
			if ( ! $keep ) { foreach ( glob( $root . '/*/*' ) as $file ) { unlink( $file ); } foreach ( glob( $root . '/*' ) as $path ) { is_dir( $path ) ? rmdir( $path ) : unlink( $path ); } rmdir( $root ); }
		}
	}
}
