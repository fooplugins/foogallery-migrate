<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use PHPUnit\Framework\TestCase;

class StorageControlTest extends TestCase {
	private function record() {
		return array( 'version' => 1, 'blog_id' => 1, 'backend' => 'option', 'epoch' => 0,
			'switch_id' => str_repeat( 'a', 32 ), 'from' => 'option', 'to' => 'table', 'phase' => 'copying',
			'table_id' => str_repeat( 'a', 32 ),
			'source_digest' => str_repeat( 'b', 64 ), 'logical_digest' => str_repeat( 'c', 64 ),
			'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1 );
	}

	public function test_valid_pending_control_reports_source_but_never_runtime_admission(): void {
		$record = $this->record();
		$this->assertSame( $record, StorageControl::decode( json_encode( $record ), 1 ) );
		$this->assertFalse( StorageControl::runtime_ready( $record, null ) );
	}

	public function test_table_authority_requires_exact_site_switch_and_epoch_sentinel_even_when_empty(): void {
		$record = $this->record(); $record['backend'] = 'table'; $record['epoch'] = 1; $record['phase'] = 'stable';
		$sentinel = StorageControl::sentinel( $record );
		$this->assertTrue( StorageControl::runtime_ready( $record, json_encode( $sentinel ) ) );
		foreach ( array( null, '', 'false', json_encode( array_merge( $sentinel, array( 'epoch' => 2 ) ) ), json_encode( array_merge( $sentinel, array( 'blog_id' => 2 ) ) ), json_encode( array_merge( $sentinel, array( 'switch_id' => str_repeat( 'z', 32 ) ) ) ) ) as $raw ) {
			$this->assertFalse( StorageControl::runtime_ready( $record, $raw ) );
		}
	}

	public function test_option_reverse_cleanup_still_blocks_runtime_until_sentinel_removed(): void {
		$record = $this->record(); $record['from'] = 'table'; $record['to'] = 'option'; $record['backend'] = 'option'; $record['epoch'] = 2; $record['phase'] = 'flipped';
		$this->assertFalse( StorageControl::runtime_ready( $record, null ) );
		$record['phase'] = 'stable';
		$this->assertTrue( StorageControl::runtime_ready( $record, null ) );
		$this->assertFalse( StorageControl::runtime_ready( $record, '{}' ) );
	}

	/** @dataProvider malformedControls */
	public function test_unknown_corrupt_or_cross_blog_control_is_rejected_without_hydration( $raw ): void {
		$this->expectException( \RuntimeException::class );
		StorageControl::decode( $raw, 1 );
	}

	public static function malformedControls(): array {
		$record = ( new self( 'unused' ) )->record(); $cases = array( '', 'false', 'a:0:{}', str_repeat( 'x', 2049 ), '{"version":1,"version":1}' );
		foreach ( array( 'version' => 2, 'blog_id' => 2, 'backend' => 'unknown', 'epoch' => -1, 'phase' => 'unknown', 'switch_id' => 'bad', 'source_digest' => '', 'codec' => 2, 'schema' => 2, 'updated' => 0, 'from' => 'table' ) as $key => $value ) {
			$bad = $record; $bad[$key] = $value; $cases[] = json_encode( $bad );
		}
		$bad = $record; $bad['extra'] = true; $cases[] = json_encode( $bad );
		$bad = $record; unset( $bad['logical_digest'] ); $cases[] = json_encode( $bad );
		return array_map( function ( $raw ) { return array( $raw ); }, $cases );
	}

	public function test_control_reads_are_fresh_bounded_and_empty_is_not_absent(): void {
		$db = new ControlTestWpdb(); $store = new StorageControl( $db, 1 );
		$this->assertNull( $store->read( StorageAuthority::CONTROL_OPTION ) );
		$db->rows[StorageAuthority::CONTROL_OPTION] = '';
		$this->assertSame( '', $store->read( StorageAuthority::CONTROL_OPTION ) );
		$db->rows[StorageAuthority::CONTROL_OPTION] = str_repeat( 'x', 2049 );
		$this->assertSame( '', $store->read( StorageAuthority::CONTROL_OPTION ) );
		$this->assertStringContainsString( 'LENGTH(option_value) <= 2048', implode( '\n', $db->queries ) );
	}

	public function test_control_cas_is_binary_exact_and_never_overwrites_racing_owner(): void {
		$db = new ControlTestWpdb(); $store = new StorageControl( $db, 1 ); $raw = json_encode( $this->record() );
		$store->cas( StorageAuthority::CONTROL_OPTION, null, $raw );
		$db->rows[StorageAuthority::CONTROL_OPTION] = 'different owner';
		try { $store->cas( StorageAuthority::CONTROL_OPTION, $raw, $raw ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'different owner', $db->rows[StorageAuthority::CONTROL_OPTION] ); }
		$this->assertStringContainsString( 'BINARY option_value = BINARY', implode( '\n', $db->queries ) );
	}

	public function test_readback_failure_is_not_a_success_and_retains_written_evidence(): void {
		$db = new ControlTestWpdb(); $store = new StorageControl( $db, 1 ); $db->fail_after_write = true;
		try { $store->cas( StorageAuthority::CONTROL_OPTION, null, json_encode( $this->record() ) ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertArrayHasKey( StorageAuthority::CONTROL_OPTION, $db->rows ); }
	}

	public function test_production_authority_never_falls_back_from_malformed_or_interrupted_control(): void {
		$db = new ControlTestWpdb(); $GLOBALS['wpdb'] = $db;
		try {
			foreach ( array( '', '{}', json_encode( $this->record() ) ) as $raw ) {
				$db->rows[StorageAuthority::CONTROL_OPTION] = $raw;
				$this->assertInstanceOf( \WP_Error::class, StorageAuthority::runtime_record() );
			}
		} finally { unset( $GLOBALS['wpdb'] ); }
	}
}

/** SQL shape/CAS spy, not a native database atomicity receipt. */
class ControlTestWpdb {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $rows = array();
	public $queries = array();
	public $fail_after_write = false;
	public function prepare( $sql, $args ) {
		foreach ( (array) $args as $arg ) { $sql = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( $this->fail_after_write && count( $this->rows ) ) { $this->last_error = 'failed'; return null; }
		preg_match( "/option_name = '([^']+)'/", $sql, $match );
		if ( ! isset( $match[1] ) || ! array_key_exists( $match[1], $this->rows ) ) { return null; }
		$value = $this->rows[$match[1]];
		return 'v:' . ( strlen( $value ) <= 2048 ? $value : '' );
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 0 === strpos( $sql, 'INSERT' ) ) {
			preg_match( "/VALUES \( '([^']+)', '((?:\\\\.|[^'])*)'/", $sql, $match );
			if ( array_key_exists( $match[1], $this->rows ) ) { return 0; }
			$this->rows[$match[1]] = stripslashes( $match[2] ); return 1;
		}
		preg_match( "/option_name = '([^']+)' AND BINARY option_value = BINARY '((?:\\\\.|[^'])*)'/", $sql, $match );
		if ( ! isset( $this->rows[$match[1]] ) || $this->rows[$match[1]] !== stripslashes( $match[2] ) ) { return 0; }
		if ( 0 === strpos( $sql, 'DELETE' ) ) { unset( $this->rows[$match[1]] ); return 1; }
		preg_match( "/SET option_value = '((?:\\\\.|[^'])*)'/", $sql, $value );
		$this->rows[$match[1]] = stripslashes( $value[1] ); return 1;
	}
}
