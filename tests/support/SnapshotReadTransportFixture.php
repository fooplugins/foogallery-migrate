<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\SnapshotReadPlan;
use FooPlugins\FooGalleryMigrate\SnapshotReadTraceStream;
use FooPlugins\FooGalleryMigrate\SnapshotPageWitness;
require_once dirname( __DIR__ ) . '/SnapshotReadProfileLifecycleTest.php';

/** SYNTHETIC_NOT_MEASURED. Only original-volume transport/schema coverage.
 * No native product workload, independent observer, or approved calibration. */
final class SnapshotReadTransportFixture {
	public static function export( $dir, $records ) {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 1 );
		$header = json_decode( $raw[0], true ); $template = $header['events'][0]; unset( $header['events'], $raw );
		$p = &$cell['plan'];
		$p['own']['records'] = $records; $p['own']['future'] = $records * 1024; $p['features']['records'] = $records;
		$p['parts']['witness_construction_lifetime_lookup'] = SnapshotPageWitness::workspace( $records );
		$p['full'] = $p['own']; $p['full']['private'] = array_sum( $p['parts'] ); unset( $p );
		$cell['SYNTHETIC_NOT_MEASURED'] = true; $cell['transport'] = SnapshotReadTraceStream::FORMAT;
		$header['features'] = $cell['plan']['features']; $receipts = array();
		for ( $repeat = 0; $repeat < 3; ++$repeat ) {
			$header['pid'] = 900001 + $repeat; // Explicit synthetic IDs, not acquired child measurements.
			$path = $dir . '/trace-' . ( $repeat + 1 ) . '.ndjson';
			$writer = SnapshotReadTraceStream::writer( $path, $header ); $content = hash_init( 'sha256' ); $seq = 0;
			foreach ( array( 'staged', 'read1', 'read2' ) as $read ) {
				$remaining = $cell['plan']['own']['future'];
				$emit = function ( $seam, $edge, $row = 0 ) use ( $writer, $content, &$seq, &$remaining, $template, $cell, $records, $read ) {
					if ( $seam === 'attach' && $edge === 'after' ) { $remaining = ( $records - $row ) * 1024; }
					$e = $template; $e['seq'] = $seq++; $e['read'] = $read; $e['seam'] = $seam; $e['edge'] = $edge; $e['row'] = $row;
					$e['remaining'] = $remaining; $e['private'] = $cell['plan']['full']['private'];
					$e['retained_outputs'] = $seam === 'exit' ? ( $read === 'read2' ? 2 : ( $read === 'read1' ? 1 : 0 ) ) : 0;
					hash_update( $content, json_encode( $e ) . "\n" ); $writer->append( $e );
				};
				$pair = function ( $seam, $row = 0 ) use ( $emit ) { $emit( $seam, 'before', $row ); $emit( $seam, 'after', $row ); };
				$pair( 'root-native' ); $emit( 'page-chain', 'before' );
				for ( $row = 1; $row <= $records; ++$row ) { $pair( 'key-native', $row ); $pair( 'payload-native', $row ); }
				$pair( 'whole-record-digest' ); $pair( 'cow-resize' );
				for ( $row = 1; $row <= $records; ++$row ) { $pair( 'attach', $row ); $pair( 'unwind', $row ); }
				$emit( 'page-chain', 'after' ); $pair( 'envelope' ); $pair( 'final-digest' ); $pair( 'exit' );
			}
			$cell['trace_sha256'][$repeat] = $writer->finish();
			$receipts[] = array( 'file' => basename( $path ), 'events' => $seq, 'bytes' => filesize( $path ),
				'sha256' => $cell['trace_sha256'][$repeat], 'event_content_sha256' => hash_final( $content ) );
		}
		file_put_contents( $dir . '/cell.json', json_encode( $cell, JSON_PRETTY_PRINT ) . "\n" );
		return array( 'SYNTHETIC_NOT_MEASURED' => true, 'transport_only' => true, 'measured_profile_approval' => false,
			'records' => $records, 'traces' => $receipts, 'runtime' => SnapshotReadPlan::runtime_binding(), 'memory_limit' => ini_get( 'memory_limit' ),
			'peak_used' => memory_get_peak_usage( false ), 'peak_allocated' => memory_get_peak_usage( true ) );
	}
}
