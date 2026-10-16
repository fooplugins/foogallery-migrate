<?php
/** HOST storage double extending the existing snapshot/schema seam. NOT SQL execution. */
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

require_once dirname( __DIR__ ) . '/LegacyTableSnapshotTest.php';

class SnapshotScaleConnection extends SnapshotTestWpdb {
	public $heads = array();
	public $records = array();
	public $events = array();
	public $emitted = 0;
	public $fetches = 0;
	public $fetched_rows = 0;
	public $fetched_bytes = 0;
	public $after_event;
	public $corrupt_readback = false;
	private $statement;
	private $serial = 0;
	private $saved;
	private $next_id = 1;

	public function prepare( $sql, $args ) {
		$handle = 'host-prepared-' . ++$this->serial;
		$this->statement = array( $handle, $sql, $args );
		return $handle;
	}
	private function statement( $handle ) {
		if ( isset( $this->statement ) && $this->statement[0] === $handle ) {
			$s = $this->statement; $this->statement = null; return array( $s[1], $s[2] );
		}
		return array( $handle, array() );
	}
	private function event( $name ) {
		if ( ! isset( $this->events[$name] ) ) { $this->events[$name] = 0; }
		++$this->events[$name];
		if ( is_callable( $this->after_event ) ) { call_user_func( $this->after_event, $name, $this ); }
	}
	public function query( $handle ) {
		list( $sql, $a ) = $this->statement( $handle );
		if ( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' === $sql ) { $this->event( 'idle' ); return 0; }
		if ( 'START TRANSACTION' === $sql ) {
			$this->saved = array( $this->heads, $this->records ); $this->event( 'start' ); return 0;
		}
		if ( 'ROLLBACK' === $sql ) {
			list( $this->heads, $this->records ) = $this->saved; $this->saved = null; $this->event( 'rollback' ); return 0;
		}
		if ( 'COMMIT' === $sql ) { $this->saved = null; $this->event( 'commit' ); return 0; }
		if ( preg_match( '/^INSERT INTO `([^`]+)` \(([^)]+)\) VALUES \((.*)\)$/D', $sql, $m ) ) {
			$columns = explode( ',', str_replace( '`', '', $m[2] ) );
			$expressions = explode( ',', $m[3] ); $row = array(); $i = 0;
			foreach ( $columns as $n => $column ) { $row[$column] = 'NULL' === $expressions[$n] ? null : $a[$i++]; }
			$row['id'] = $this->insert_id = $this->next_id++;
			if ( 'wp_foogallery_migrate_legacy_state' === $m[1] ) {
				foreach ( $this->heads as $head ) { if ( $head['blog_id'] === $row['blog_id'] && $head['state_key_hash'] === $row['state_key_hash'] ) { throw new \LogicException( 'Duplicate host head.' ); } }
				$this->heads[$row['id']] = $row; $this->event( 'head-insert' );
			} elseif ( 'wp_foogallery_migrate_legacy_records' === $m[1] ) {
				$this->records[] = $row; ++$this->emitted; $this->event( 'record-insert' );
			} else { throw new \LogicException( 'Unexpected host table.' ); }
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE `wp_foogallery_migrate_legacy_state` SET root_payload' ) ) {
			if ( ! isset( $this->heads[$a[2]] ) ) { return 0; } $h =& $this->heads[$a[2]];
			if ( $h['blog_id'] !== $a[1] || $h['revision'] !== $a[3] || $h['active_generation'] !== $a[4] ) { return 0; }
			$changed = $h['root_payload'] !== $a[0]; $h['root_payload'] = $a[0]; $this->event( 'root-update' ); return $changed ? 1 : 0;
		}
		if ( 0 === strpos( $sql, 'UPDATE `wp_foogallery_migrate_legacy_state` SET active_generation' ) ) {
			if ( ! isset( $this->heads[$a[12]] ) ) { return 0; } $h =& $this->heads[$a[12]];
			if ( $h['blog_id'] !== $a[11] || $h['active_generation'] !== $a[13] || $h['revision'] !== $a[14] || $h['state_key_hash'] !== $a[15] ) { return 0; }
			foreach ( array( 'active_generation', 'revision', 'state_order', 'state_kind', 'codec_version', 'record_count', 'payload_bytes', 'snapshot_digest', 'digest_revision', 'root_payload', 'updated_gmt' ) as $i => $field ) { $h[$field] = $a[$i]; }
			$h['digest_status'] = 'current'; $this->event( 'publish' ); return 1;
		}
		throw new \LogicException( 'Unsupported host write: ' . $sql );
	}
	public static function bytes( $row ) {
		$bytes = 0;
		foreach ( array( 'payload', 'mutable_payload', 'key_value', 'legacy_identifier_value', 'source_plugin_value', 'source_id_value' ) as $field ) { $bytes += null === $row[$field] ? 0 : strlen( $row[$field] ); }
		return $bytes;
	}
	public function get_results( $handle, $format ) {
		list( $sql, $a ) = $this->statement( $handle );
		if ( 0 === strpos( $sql, 'SHOW ' ) ) {
			// Existing schema double supplies definitions, not a native schema claim.
			if ( 0 === strpos( $sql, 'SHOW TABLE STATUS' ) ) { $sql = parent::prepare( $sql, $a ); }
			return parent::get_results( $sql, $format );
		}
		if ( 'SELECT @@SESSION.autocommit AS autocommit' === $sql ) { return array( array( 'autocommit' => 1 ) ); }
		if ( false !== strpos( $sql, 'AS record_count' ) ) {
			$stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
			foreach ( $this->records as $r ) {
				if ( $r['blog_id'] === $a[0] && $r['state_id'] === $a[1] && $r['generation'] === $a[2] ) { ++$stats['record_count']; $stats['payload_bytes'] += $r['payload_bytes']; $stats['encoded_bytes'] += self::bytes( $r ); }
			}
			$this->event( 'stats' ); return array( $stats );
		}
		if ( false !== strpos( $sql, 'FROM `wp_foogallery_migrate_legacy_records`' ) ) {
			$rows = array(); $bytes = 0;
			foreach ( $this->records as $r ) {
				if ( $r['blog_id'] === $a[0] && $r['state_id'] === $a[1] && $r['generation'] === $a[2] && self::bytes( $r ) <= $a[3] && count( $rows ) < $a[4] ) {
					// Materialize a driver-style row array, not a reference to source rows.
					$row = array(); foreach ( $r as $k => $v ) { $row[$k] = is_int( $v ) ? (string) $v : $v; } $rows[] = $row; $bytes += self::bytes( $r );
				}
			}
			++$this->fetches; $this->fetched_rows += count( $rows ); $this->fetched_bytes += $bytes;
			if ( $this->corrupt_readback && count( $rows ) ) { array_pop( $rows ); }
			$this->event( 'fetch' ); return $rows;
		}
		if ( false !== strpos( $sql, 'state_key_hash = UNHEX' ) ) {
			$lock = 0 === strpos( $sql, 'SELECT id FROM' );
			foreach ( $this->heads as $h ) {
				if ( $h['blog_id'] === $a[$lock ? 0 : 1] && $h['state_key_hash'] === $a[$lock ? 1 : 2] ) {
					$h['root_payload_bytes'] = strlen( $h['root_payload'] );
					if ( ! $lock && strlen( $h['state_key_value'] ) > $a[0] ) { $h['state_key_value'] = null; }
					unset( $h['root_payload'] ); $this->event( 'head' ); return array( $h );
				}
			}
			$this->event( 'head' ); return array();
		}
		if ( 0 === strpos( $sql, 'SELECT root_payload FROM' ) ) {
			if ( isset( $this->heads[$a[1]] ) && $this->heads[$a[1]]['blog_id'] === $a[0] && strlen( $this->heads[$a[1]]['root_payload'] ) <= $a[2] ) {
				$this->event( 'root-fetch' ); return array( array( 'root_payload' => $this->heads[$a[1]]['root_payload'] ) );
			}
			return array();
		}
		if ( 0 === strpos( $sql, 'SELECT HEX(snapshot_digest)' ) ) {
			if ( isset( $this->heads[$a[1]] ) ) {
				$h = $this->heads[$a[1]];
				if ( $h['blog_id'] === $a[0] && $h['revision'] === $a[2] && $h['active_generation'] === $a[3] ) { return array( array( 'snapshot_digest' => $h['snapshot_digest'], 'digest_revision' => $h['digest_revision'], 'digest_status' => $h['digest_status'] ) ); }
			}
			return array();
		}
		throw new \LogicException( 'Unsupported host read: ' . $sql );
	}
}
