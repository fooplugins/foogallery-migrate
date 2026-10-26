<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\SnapshotReadPhase;
use FooPlugins\FooGalleryMigrate\SnapshotReadPlan;
use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/support/SnapshotScaleConnection.php';

/** SYNTHETIC arithmetic/lifecycle inputs, NOT measured qualification or enabling. */
class SnapshotReadProfileLifecycleTest extends TestCase {
	public static function fixture( $records = 1 ) {
		$s = new TableSnapshot( new SnapshotScaleConnection(), 7, 100, 1048576 );
		$auth = new \ReflectionProperty( $s, 'snapshot_authentication_enabled' ); if ( PHP_VERSION_ID < 80100 ) { $auth->setAccessible( true ); } $auth->setValue( $s, true );
		$value = array( 'r' => array( 'unknown' => str_repeat( 'x', 12 ) ) );
		if ( 2 === $records ) { $value['s'] = false; }
		$s->replace_many( array( array( 'key' => 'bucket', 'order' => 0, 'kind' => 'map', 'value' => $value, 'expected_revision' => null ) ) );
		$property = new \ReflectionProperty( $s, 'snapshot_plans' ); if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
		$plans = $property->getValue( $s ); $producer = reset( $plans ); $plan = $producer['complete'];
		$runtime = SnapshotReadPlan::runtime_binding(); $identity = SnapshotReadPhase::identity( $producer['binding'] );
		$traces = array();
		for ( $repeat = 0; $repeat < 3; ++$repeat ) {
			$events = array();
			foreach ( array( 'staged', 'read1', 'read2' ) as $read ) {
				$remaining = $plan['own']['future'];
				$emit = function ( $seam, $edge, $row = 0 ) use ( &$events, &$remaining, $plan, $read, $records ) {
					if ( 'attach' === $seam && 'after' === $edge ) { $remaining = $row === $records ? 0 : 1; }
					$events[] = array( 'seq' => count( $events ), 'read' => $read, 'seam' => $seam, 'edge' => $edge, 'row' => $row,
						'remaining' => $remaining, 'private' => $plan['full']['private'], 'page' => 0, 'pending' => 131072,
						'allocated' => 8388608, 'observer_backing' => 8388608, 'native_high_water' => 8388608, 'peak' => 8388608,
						'limit' => 268435456, 'observer_limit' => 268435456, 'ceiling' => 268435456, 'reserve' => 33554432,
						'residual' => 0, 'allocator_growth' => 0, 'retained_outputs' => 'exit' === $seam ? ( 'read2' === $read ? 2 : ( 'read1' === $read ? 1 : 0 ) ) : 0 );
				};
				$pair = function ( $seam, $row = 0 ) use ( $emit ) { $emit( $seam, 'before', $row ); $emit( $seam, 'after', $row ); };
				$pair( 'root-native' ); $emit( 'page-chain', 'before' );
				for ( $row = 1; $row <= $records; ++$row ) { $pair( 'key-native', $row ); $pair( 'payload-native', $row ); }
				$pair( 'whole-record-digest' ); $pair( 'cow-resize' );
				for ( $row = 1; $row <= $records; ++$row ) { $pair( 'attach', $row ); $pair( 'unwind', $row ); }
				$emit( 'page-chain', 'after' ); $pair( 'envelope' ); $pair( 'final-digest' ); $pair( 'exit' );
			}
			$traces[] = array( 'SYNTHETIC_NOT_MEASURED' => true, 'runtime' => $runtime, 'pid' => 900001 + $repeat,
				'identity' => $identity, 'features' => $plan['features'], 'events' => $events,
				'variants' => array( 'original', 'retained-two-outputs', 'deadline-interrupted', 'fragmented-backing' ) );
		}
		$cell = array( 'runtime' => $runtime, 'plan' => $plan, 'identity' => $identity,
			'observer' => array( 'independent' => true, 'bounded_bytes' => 1024, 'allocator_growth_quantum' => 0 ), 'domain' => 'host-double-exact-cell' );
		return self::seal( $cell, $traces );
	}
	public static function seal( $cell, $traces ) {
		$raw = array();
		foreach ( $traces as $trace ) {
			foreach ( $trace['events'] as $i => &$event ) { $event['seq'] = $i; } unset( $event );
			$raw[] = json_encode( $trace );
		}
		$cell['trace_sha256'] = array_map( function ( $r ) { return hash( 'sha256', $r ); }, $raw );
		return array( $cell, $raw );
	}
	public static function invalid_cases() {
		$names = array( 'exit-before-row-work', 'work-after-exit', 'exit-before-final-digest', 'final-before-envelope',
			'envelope-before-page-end', 'row-before-root', 'row-outside-page', 'attach-before-payload',
			'payload-before-key', 'unwind-before-payload', 'early-root-debt', 'early-payload-debt', 'early-attach-before-debt',
			'early-digest-debt', 'early-unwind-debt', 'partial-payload-debt', 'exit-discharges-debt', 'zero-with-unattached-row',
			'new-read-before-exit', 'cross-read-open-seam', 'unknown-row', 'row-seam-zero', 'overlap-seam-row',
			'missing-row-seam', 'duplicate-pid', 'wrong-runtime', 'wrong-hash', 'capacity-minus-one', 'peak-over-guard', 'wrong-growth-quantum' );
		$out = array(); foreach ( $names as $name ) { $out[$name] = array( $name ); } return $out;
	}
	public static function counterexample( $name ) {
		list( $cell, $raw ) = self::fixture( 2 ); $traces = array_map( function ( $r ) { return json_decode( $r, true ); }, $raw );
		foreach ( $traces as &$trace ) {
			$e = &$trace['events'];
			$index = function ( $seam, $edge = 'before', $row = 0 ) use ( &$e ) {
				foreach ( $e as $i => $event ) { if ( 'staged' === $event['read'] && $seam === $event['seam'] && $edge === $event['edge'] && $row === $event['row'] ) { return $i; } }
				throw new \RuntimeException( 'Missing synthetic mutation target.' );
			};
			$move = function ( $seam, $target, $row = 0 ) use ( &$e, $index ) {
				$i = $index( $seam, 'before', $row ); $pair = array_splice( $e, $i, 2 ); array_splice( $e, $index( $target ), 0, $pair );
			};
			if ( 'exit-before-row-work' === $name ) {
				$move( 'exit', 'page-chain' ); $zero = false;
				foreach ( $e as &$event ) { if ( 'staged' === $event['read'] && 'exit' === $event['seam'] ) { $zero = true; } if ( $zero && 'staged' === $event['read'] ) { $event['remaining'] = 0; } } unset( $event );
			} else if ( 'work-after-exit' === $name ) {
				$i = $index( 'cow-resize' ); $pair = array_splice( $e, $i, 2 ); foreach ( $pair as &$event ) { $event['remaining'] = 0; } unset( $event );
				array_splice( $e, $index( 'exit', 'after' ) + 1, 0, $pair );
			} else if ( 'exit-before-final-digest' === $name ) { $move( 'exit', 'final-digest' );
			} else if ( 'final-before-envelope' === $name ) { $move( 'final-digest', 'envelope' );
			} else if ( 'envelope-before-page-end' === $name ) { $move( 'envelope', 'whole-record-digest' );
			} else if ( 'row-before-root' === $name ) { $move( 'key-native', 'root-native', 1 );
			} else if ( 'row-outside-page' === $name ) { $move( 'key-native', 'page-chain', 1 );
			} else if ( 'attach-before-payload' === $name ) {
				$i = $index( 'attach', 'before', 1 ); $pair = array_splice( $e, $i, 2 ); array_splice( $e, $index( 'payload-native', 'before', 1 ), 0, $pair );
			} else if ( 'payload-before-key' === $name || 'unwind-before-payload' === $name ) {
				$seam = 'payload-before-key' === $name ? 'payload-native' : 'unwind'; $i = $index( $seam, 'before', 1 );
				$pair = array_splice( $e, $i, 2 ); array_splice( $e, $index( 'key-native', 'before', 1 ), 0, $pair );
			} else if ( 0 === strpos( $name, 'early-' ) ) {
				$targets = array( 'early-root-debt' => array( 'root-native', 'after', 0 ), 'early-payload-debt' => array( 'payload-native', 'after', 1 ),
					'early-attach-before-debt' => array( 'attach', 'before', 1 ), 'early-digest-debt' => array( 'whole-record-digest', 'after', 0 ), 'early-unwind-debt' => array( 'unwind', 'after', 1 ) );
				$t = $targets[$name]; $i = $index( $t[0], $t[1], $t[2] ); $e[$i]['remaining'] = 0;
				for ( ++$i; $i < count( $e ) && 'staged' === $e[$i]['read']; ++$i ) { $e[$i]['remaining'] = 0; }
			} else if ( 'partial-payload-debt' === $name ) {
				$i = $index( 'payload-native', 'after', 1 );
				for ( ; $i < count( $e ) && 'staged' === $e[$i]['read']; ++$i ) { $e[$i]['remaining'] = min( $e[$i]['remaining'], $cell['plan']['own']['future'] - 1 ); }
			} else if ( 'exit-discharges-debt' === $name ) {
				foreach ( $e as &$event ) { if ( 'staged' === $event['read'] && 'exit' !== $event['seam'] && 0 === $event['remaining'] ) { $event['remaining'] = 1; } } unset( $event );
			} else if ( 'zero-with-unattached-row' === $name ) {
				for ( $i = $index( 'attach', 'after', 1 ); 'staged' === $e[$i]['read']; ++$i ) { $e[$i]['remaining'] = 0; }
			} else if ( 'new-read-before-exit' === $name ) { array_splice( $e, $index( 'exit' ), 2 );
			} else if ( 'cross-read-open-seam' === $name ) { unset( $e[$index( 'exit', 'after' )] ); $e = array_values( $e );
			} else if ( in_array( $name, array( 'unknown-row', 'row-seam-zero', 'overlap-seam-row' ), true ) ) {
				foreach ( $e as &$event ) { if ( ( 'overlap-seam-row' === $name && 'cow-resize' === $event['seam'] ) || ( 'overlap-seam-row' !== $name && 'key-native' === $event['seam'] && 1 === $event['row'] ) ) { $event['row'] = 'row-seam-zero' === $name ? 0 : 3; } } unset( $event );
			} else if ( 'missing-row-seam' === $name ) { $e = array_values( array_filter( $e, function ( $v ) { return 'payload-native' !== $v['seam']; } ) );
			} else if ( 'peak-over-guard' === $name ) { $e[1]['peak'] = 268435456; $e[1]['residual'] = 268435456 - 8388608; }
			// Ordering counterexamples retain otherwise valid monotone debt, so a
			// refusal cannot be attributed merely to moving an old balance sample.
			if ( in_array( $name, array( 'attach-before-payload', 'unwind-before-payload' ), true ) ) {
				$remaining = $cell['plan']['own']['future']; $attached = 0;
				foreach ( $e as &$event ) {
					if ( 'staged' !== $event['read'] ) { continue; }
					if ( 'attach' === $event['seam'] && 'after' === $event['edge'] ) { $remaining = ++$attached === 2 ? 0 : 1; }
					$event['remaining'] = $remaining;
				} unset( $event );
			}
			unset( $e );
		} unset( $trace );
		if ( 'duplicate-pid' === $name ) { $traces[1]['pid'] = $traces[0]['pid']; }
		if ( 'wrong-runtime' === $name ) { $cell['runtime']['binary_sha256'] = str_repeat( '0', 64 ); }
		if ( 'wrong-growth-quantum' === $name ) { $cell['observer']['allocator_growth_quantum'] = 1; }
		if ( 'capacity-minus-one' === $name ) { self::capacity( $traces, -1 ); }
		list( $cell, $raw ) = self::seal( $cell, $traces );
		if ( 'wrong-hash' === $name ) { $cell['trace_sha256'][0] = str_repeat( '0', 64 ); }
		return array( $cell, $raw );
	}
	public static function capacity( &$traces, $offset = 0 ) {
		$max = 0; foreach ( $traces[0]['events'] as $e ) { $max = max( $max, $e['allocated'] + $e['remaining'] + $e['private'] + $e['page'] + $e['pending'] + $e['reserve'] + 1024 ); }
		foreach ( $traces as &$t ) { foreach ( $t['events'] as &$e ) { $e['limit'] = $max + $offset; } unset( $e ); } unset( $t );
	}
	public function test_synthetic_one_and_two_row_controls_and_exact_capacity(): void {
		foreach ( array( 1, 2 ) as $records ) {
			list( $cell, $raw ) = self::fixture( $records );
			$this->assertSame( $records, $cell['plan']['own']['records'] );
			foreach ( array( false, true ) as $exact ) {
				if ( $exact ) { $traces = array_map( function ( $r ) { return json_decode( $r, true ); }, $raw ); self::capacity( $traces ); list( $cell, $raw ) = self::seal( $cell, $traces ); }
				$profile = SnapshotReadProfile::qualify( $cell, $raw );
				$this->assertSame( 1024, $profile->margin() ); $this->assertTrue( $profile->supports( $cell['plan'], $cell['identity'] ) );
				unset( $profile );
			}
		}
	}
	public function test_synthetic_unwind_can_bracket_attachment_and_row_completion_is_not_preorder(): void {
		foreach ( array( 'unwind-brackets-attach', 'reverse-rows' ) as $mode ) {
			list( $cell, $raw ) = self::fixture( 2 ); $traces = array_map( function ( $r ) { return json_decode( $r, true ); }, $raw );
			foreach ( $traces as &$trace ) {
				$e = &$trace['events'];
				if ( 'unwind-brackets-attach' === $mode ) {
					for ( $i = 0; $i < count( $e ); ++$i ) {
						if ( 'attach' === $e[$i]['seam'] && 'before' === $e[$i]['edge'] ) {
							$block = array_slice( $e, $i, 4 );
							array_splice( $e, $i, 4, array( $block[2], $block[0], $block[1], $block[3] ) ); $i += 3;
						}
					}
				} else {
					for ( $i = 0; $i < count( $e ); ++$i ) {
						if ( 'attach' === $e[$i]['seam'] && 'before' === $e[$i]['edge'] && 1 === $e[$i]['row'] ) {
							$block = array_slice( $e, $i, 8 ); array_splice( $e, $i, 8, array_merge( array_slice( $block, 4 ), array_slice( $block, 0, 4 ) ) ); $i += 7;
						}
					}
				}
				$remaining = $cell['plan']['own']['future']; $attached = 0;
				foreach ( $e as &$event ) {
					if ( 'root-native' === $event['seam'] && 'before' === $event['edge'] ) { $remaining = $cell['plan']['own']['future']; $attached = 0; }
					if ( 'attach' === $event['seam'] && 'after' === $event['edge'] ) { $remaining = ++$attached === 2 ? 0 : 1; }
					$event['remaining'] = $remaining;
				} unset( $event, $e );
			} unset( $trace );
			list( $cell, $raw ) = self::seal( $cell, $traces ); $profile = SnapshotReadProfile::qualify( $cell, $raw );
			$this->assertSame( 1024, $profile->margin() ); $this->assertTrue( $profile->supports( $cell['plan'], $cell['identity'] ) ); unset( $profile );
		}
	}
	/** @dataProvider invalid_cases */
	public function test_synthetic_malformed_lifecycle_and_existing_refusals( $name ): void {
		list( $cell, $raw ) = self::counterexample( $name );
		$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Snapshot finite host cell refused:' );
		SnapshotReadProfile::qualify( $cell, $raw );
	}
}
