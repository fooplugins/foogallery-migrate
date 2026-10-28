<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageSnapshotScaleTest.php';

/** Additive qualification probes. Never substitutes for the original scaled test. */
class StorageSnapshotOwnedLifetimeTest extends TestCase {
	public static $measurement;

	/**
	 * Exercise the complete existing production construction with the SAME corpus,
	 * transport and ordinary callback. Deliberately unscaled: measures allocation,
	 * not authorization to bypass scaled admission or acceptance of a forecast.
	 * This exact-host/private-frame timing experiment can exceed the unchanged
	 * 10-second operation deadline. Portable 20K parity/fault rollback and the
	 * independent native conversion journey remain separate release evidence.
	 * @group optional_host_diagnostic
	 */
	public function test_measure_original_retained_construction_before_qualifying_lower_forecast(): void {
		if ( '1' !== getenv( 'FOOGM_SNAPSHOT_OWNED_LIFETIME_AUDIT' ) ) {
			$this->markTestSkipped( 'Private-frame timing probe requires explicit host audit; the unchanged 10-second deadline remains active.' );
		}
		$value = StorageA7Fixture::value( 20000 ); $raw = serialize( $value );
		$this->assertSame( '6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be', hash( 'sha256', $raw ) );
		$db = new SnapshotScaleConnection(); $samples = array();
		$memory = function ( $bytes ) use ( &$samples, $db ) {
			$phase = $db->fetches ? 'readback' : ( $db->emitted ? 'stage' : 'preflight' );
			$frames = 0; $page = false; $generation = false;
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 32 ) as $frame ) {
				if ( 'rebuild_stream_node' === $frame['function'] ) { ++$frames; }
				if ( 'read_generation' === $frame['function'] ) { $generation = true; }
				if ( 'page' === $frame['function'] ) { $page = true; }
			}
			if ( $generation ) { $phase = 'construction'; }
			elseif ( $db->fetches && ! isset( $db->events['publish'] ) ) { $phase = 'complete-digest-after-construction'; }
			if ( ! isset( $samples[$phase] ) ) { $samples[$phase] = array( 'calls' => 0, 'max_used' => 0, 'max_allocated' => 0, 'max_live_node_frames' => 0, 'nested_page' => false, 'legacy_forecast_input' => 0 ); }
			++$samples[$phase]['calls'];
			$samples[$phase]['max_used'] = max( $samples[$phase]['max_used'], memory_get_usage( false ) );
			$samples[$phase]['max_allocated'] = max( $samples[$phase]['max_allocated'], memory_get_usage( true ) );
			$samples[$phase]['max_live_node_frames'] = max( $samples[$phase]['max_live_node_frames'], $frames );
			$samples[$phase]['nested_page'] = $samples[$phase]['nested_page'] || ( $page && $frames > 1 );
			$samples[$phase]['legacy_forecast_input'] = max( $samples[$phase]['legacy_forecast_input'], $bytes );
			return true;
		};
		$snapshot = new TableSnapshot( $db, 7, 20208, 15070958, microtime( true ) + 10.0, $memory );
		$started = microtime( true );
		$result = $snapshot->replace_many( array( array( 'key' => 'galleries', 'order' => 0, 'kind' => 'map', 'value' => $value['galleries'], 'expected_revision' => null ) ) );
		$replace_seconds = microtime( true ) - $started;
		$this->assertCount( 1, $result ); $this->assertCount( 20208, $db->records );
		$this->assertSame( 1, $db->events['publish'] ); $this->assertSame( 1, $db->events['commit'] );
		$this->assertArrayNotHasKey( 'rollback', $db->events );
		$this->assertTrue( $samples['construction']['nested_page'] );
		$this->assertGreaterThan( 1, $samples['construction']['max_live_node_frames'] );
		$this->assertArrayHasKey( 'complete-digest-after-construction', $samples );
		// Publish scalar replacement measurements before testing the unchanged
		// reserve. No partial readback is described as successful acceptance.
		$replacement_samples = $samples;
		$physical = 0; foreach ( $db->records as $row ) { $physical += SnapshotScaleConnection::bytes( $row ); }
		$this->assertSame( 15070354, $physical );
		self::$measurement = array( 'qualification' => 'MEASUREMENT_ONLY_NOT_SCALED_ACCEPTANCE',
			'php_memory_limit' => ini_get( 'memory_limit' ), 'php_binary_sha256' => hash_file( 'sha256', PHP_BINARY ),
			'raw_sha256' => hash( 'sha256', $raw ), 'encoded_record_bytes' => $physical, 'events' => $db->events,
			'replacement_seconds' => $replace_seconds, 'total_seconds' => microtime( true ) - $started,
			'peak_used' => memory_get_peak_usage( false ), 'peak_allocated' => memory_get_peak_usage( true ),
			'phases' => $replacement_samples, 'reserve_bytes_unchanged' => StorageScaleAdmissionTest::decode_policy()['reserve_bytes'] );
		$allocated = 0;
		foreach ( $replacement_samples as $phase ) { $allocated = max( $allocated, $phase['max_allocated'] ); }
		$p = StorageScaleAdmissionTest::decode_policy();
		$floor = StoragePreflight::decode_budget( 0, ini_get( 'memory_limit' ), $allocated, $p );
		self::$measurement['replacement_max_sampled_allocated'] = $allocated;
		self::$measurement['zero_future_floor_refused'] = is_wp_error( $floor );
		self::$measurement['standalone_reads_completed'] = 0;
		$this->assertFalse( is_wp_error( $floor ), 'Even zero future cannot qualify replacement while actual sampled backing plus unchanged reserve exceeds the effective limit.' );
		// Match the original temporary read lifetimes; do not retain an extra output.
		$this->assertSame( $raw, serialize( array( 'galleries' => $snapshot->read_bounded( 'galleries' ) ) ) );
		++self::$measurement['standalone_reads_completed'];
		$this->assertSame( $raw, serialize( array( 'galleries' => $snapshot->read_bounded( 'galleries' ) ) ) );
		++self::$measurement['standalone_reads_completed'];
		self::$measurement['all_phases'] = $samples;
		self::$measurement['peak_used'] = memory_get_peak_usage( false );
		self::$measurement['peak_allocated'] = memory_get_peak_usage( true );
		self::$measurement['total_seconds'] = microtime( true ) - $started;
	}

	/** Zero usage already refuses. Replacement ownership must not change this. */
	public function test_original128_standalone_forecast_has_no_possible_replacement_credit(): void {
		$p = StorageScaleAdmissionTest::decode_policy(); $p['max_raw_bytes'] = 33554432;
		$result = StoragePreflight::decode_budget( 15070958, '128M', 0, $p );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 154122096, 15070958 * $p['amplification'] + $p['reserve_bytes'] );
		$this->assertGreaterThan( StoragePreflight::memory_bytes( '128M' ), 154122096 );
	}
}
