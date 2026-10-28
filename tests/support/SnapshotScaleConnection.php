<?php
/** HOST storage double extending the existing snapshot/schema seam. NOT SQL execution. */
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

require_once dirname( __DIR__ ) . '/TableSnapshotTest.php';
require_once __DIR__ . '/SnapshotScaleRow.php';

class SnapshotScaleConnection extends SnapshotTestWpdb {
	public $heads = array();
	private $records = array();
	private $records_revision = 0;
	private $index_revision = -1;
	private $record_index = array();
	public $index_builds = 0;
	public $index_row_visits = 0;
	public $events = array();
	public $emitted = 0;
	public $fetches = 0;
	public $fetched_rows = 0;
	public $fetched_bytes = 0;
	public $after_event;
	public $corrupt_readback = false;
	public $max_window_rows = 0;
	public $max_window_bytes = 0;
	private $statement;
	private $serial = 0;
	private $saved;
	private $next_id = 1;
	private $record_layouts = array();

	/** Read-only COW view; rewrites assign the whole array through __set.
	 * No mutable reference escapes the revision boundary. Persisted rows AND
	 * offset-only indexes remain held on this connection in the original sampler.
	 */
	public function __get( $name ) {
		if ( 'records' !== $name ) { throw new \LogicException( 'Unknown host property: ' . $name ); }
		return $this->records;
	}
	public function __set( $name, $value ) {
		if ( 'records' !== $name || ! is_array( $value ) ) { throw new \LogicException( 'Invalid host records rewrite.' ); }
		$detached = array();
		foreach ( $value as $position => $row ) {
			if ( $row instanceof SnapshotScaleRow ) { $detached[$position] = $row; continue; }
			if ( ! is_array( $row ) ) { throw new \LogicException( 'Invalid host record row.' ); }
			$copy = array();
			foreach ( $row as $column => $field ) {
				if ( null !== $field && ! is_scalar( $field ) ) { throw new \LogicException( 'Invalid host record column.' ); }
				$copy[$column] = $field;
			}
			$detached[$position] = $copy;
		}
		$this->records = $detached; $this->records_changed();
	}
	public function __isset( $name ) { return 'records' === $name; }
	private function records_changed() {
		++$this->records_revision;
		$this->record_index = array();
	}
	private function scope_key( $blog, $state, $generation, $parent ) {
		return serialize( array( $blog, $state, $generation, null === $parent ? null : strtolower( $parent ) ) );
	}
	private function ensure_record_index() {
		if ( $this->index_revision === $this->records_revision ) { return; }
		$index = array();
		foreach ( $this->records as $position => $row ) {
			++$this->index_row_visits;
			$key = $this->scope_key( $row['blog_id'], $row['state_id'], $row['generation'], $row['parent_node_hash'] );
			$index[$key][] = $position;
		}
		foreach ( $index as &$positions ) {
			usort( $positions, function ( $a, $b ) {
				$x = $this->records[$a]; $y = $this->records[$b];
				return $x['item_order'] === $y['item_order'] ? $x['id'] <=> $y['id'] : $x['item_order'] <=> $y['item_order'];
			} );
		}
		unset( $positions );
		$this->record_index = $index; $this->index_revision = $this->records_revision; ++$this->index_builds;
	}
	private function window_positions( $a, $parent, $p, $metadata, $limit ) {
		$this->ensure_record_index();
		$key = $this->scope_key( $a[0], $a[1], $a[2], $parent );
		if ( ! isset( $this->record_index[$key] ) || $limit <= 0 ) { return array(); }
		$positions = $this->record_index[$key]; $low = 0; $high = count( $positions );
		// First (order,id) strictly after the supplied cursor, not physical ID.
		while ( $low < $high ) {
			$mid = (int) floor( ( $low + $high ) / 2 ); $r = $this->records[$positions[$mid]];
			if ( $r['item_order'] > $a[$p] || ( $r['item_order'] === $a[$p + 1] && $r['id'] > $a[$p + 2] ) ) { $high = $mid; }
			else { $low = $mid + 1; }
		}
		$selected = array();
		for ( $i = $low; $i < count( $positions ) && count( $selected ) < $limit; ++$i ) {
			$position = $positions[$i];
			if ( $metadata || self::bytes( $this->records[$position] ) <= $a[$p + 3] ) { $selected[] = $position; }
		}
		return $selected;
	}

	public function prepare( $sql, $args ) {
		$sql = IdentifierSqlFixture::bind( $sql, $args );
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
			list( $this->heads, $this->records ) = $this->saved; $this->records_changed(); $this->saved = null; $this->event( 'rollback' ); return 0;
		}
		if ( 'COMMIT' === $sql ) { $this->saved = null; $this->event( 'commit' ); return 0; }
		if ( preg_match( '/^INSERT INTO `([^`]+)` \(([^)]+)\) VALUES \((.*)\)$/D', $sql, $m ) ) {
			if ( 'wp_foogallery_migrate_records' === $m[1] ) {
				// Cache only SQL shape, never arguments, rows or fixture/source graphs.
				if ( ! isset( $this->record_layouts[$sql] ) ) {
					$columns = explode( ',', str_replace( '`', '', $m[2] ) );
					$layout = array_flip( array_keys( array_fill_keys( array_merge( $columns, array( 'id' ) ), true ) ) );
					$this->record_layouts[$sql] = array( $layout, $columns, explode( ',', $m[3] ) );
				}
				list( $layout, $columns, $expressions ) = $this->record_layouts[$sql];
				$values = array_fill( 0, count( $layout ), null ); $i = 0;
				foreach ( $columns as $n => $column ) { $values[$layout[$column]] = 'NULL' === $expressions[$n] ? null : $a[$i++]; }
				$values[$layout['id']] = $this->insert_id = $this->next_id++;
				$this->records[] = new SnapshotScaleRow( $layout, $values );
				$this->records_changed();
				++$this->emitted; $this->event( 'record-insert' ); return 1;
			}
			$columns = explode( ',', str_replace( '`', '', $m[2] ) );
			$expressions = explode( ',', $m[3] ); $row = array(); $i = 0;
			foreach ( $columns as $n => $column ) { $row[$column] = 'NULL' === $expressions[$n] ? null : $a[$i++]; }
			$row['id'] = $this->insert_id = $this->next_id++;
			if ( 'wp_foogallery_migrate_state' === $m[1] ) {
				foreach ( $this->heads as $head ) { if ( $head['blog_id'] === $row['blog_id'] && $head['state_key_hash'] === $row['state_key_hash'] ) { throw new \LogicException( 'Duplicate host head.' ); } }
				$this->heads[$row['id']] = $row; $this->event( 'head-insert' );
			} else { throw new \LogicException( 'Unexpected host table.' ); }
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE `wp_foogallery_migrate_state` SET root_payload' ) ) {
			if ( ! isset( $this->heads[$a[2]] ) ) { return 0; } $h =& $this->heads[$a[2]];
			if ( $h['blog_id'] !== $a[1] || $h['revision'] !== $a[3] || $h['active_generation'] !== $a[4] ) { return 0; }
			$changed = $h['root_payload'] !== $a[0]; $h['root_payload'] = $a[0]; $this->event( 'root-update' ); return $changed ? 1 : 0;
		}
		if ( 0 === strpos( $sql, 'UPDATE `wp_foogallery_migrate_state` SET active_generation' ) ) {
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
		if ( false !== strpos( $sql, 'FROM `wp_foogallery_migrate_records`' ) ) {
			if ( false !== strpos( $sql, 'ORDER BY item_order ASC, id ASC' ) ) {
				$metadata = 0 === strpos( $sql, 'SELECT id, item_order,' );
				$root = false !== strpos( $sql, 'parent_node_hash IS NULL' ); $p = $root ? 3 : 4;
				$parent = $root ? null : strtolower( $a[3] ); $limit = $a[$p + ( $metadata ? 3 : 4 )];
				$selected = $this->window_positions( $a, $parent, $p, $metadata, $limit );
				$rows = array(); $bytes = 0;
				foreach ( $selected as $s ) {
					$r = $this->records[$s];
					if ( $metadata ) { $rows[] = array( 'id' => (string) $r['id'], 'item_order' => (string) $r['item_order'], 'window_bytes' => (string) self::bytes( $r ) ); }
					else { $row = array(); foreach ( $r as $k => $v ) { $row[$k] = is_int( $v ) ? (string) $v : $v; } $rows[] = $row; $bytes += self::bytes( $r ); }
				}
				if ( $metadata ) { $this->event( 'window-metadata' ); return $rows; }
				++$this->fetches; $this->fetched_rows += count( $rows ); $this->fetched_bytes += $bytes;
				$this->max_window_rows = max( $this->max_window_rows, count( $rows ) ); $this->max_window_bytes = max( $this->max_window_bytes, $bytes );
				if ( $this->corrupt_readback && count( $rows ) ) { array_pop( $rows ); }
				$this->event( 'fetch' ); return $rows;
			}
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
