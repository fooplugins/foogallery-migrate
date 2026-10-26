<?php
/**
 * Bounded read-only table primitives. Not an authoritative engine backend yet.
 * Returns compact base/mutable envelopes separately; reconstruction, projection
 * parity and complete logical-hash checks belong to the future repository layer.
 * No reads provision resources, select authority or fall back to legacy options.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyTableReader {
	const MAX_ROWS = 100;
	const MAX_RECORD_BYTES = 1048576;
	private $wpdb;
	private $blog_id;
	private $codec;
	private $max_page_bytes;
	private $state_table;
	private $record_table;

	public function __construct( $wpdb, $blog_id, $codec = null, $max_page_bytes = 4194304 ) {
		if ( ! is_int( $blog_id ) || $blog_id < 1 || ! is_int( $max_page_bytes ) || $max_page_bytes < 1 ) {
			$this->fail( 'Invalid site or page byte budget.' );
		}
		$schema = new LegacyStorageSchema( $wpdb );
		$tables = array_keys( $schema->definitions() );
		$this->wpdb = $wpdb;
		$this->blog_id = $blog_id;
		$this->codec = $codec instanceof LegacyStateCodec ? $codec : new LegacyStateCodec();
		$this->max_page_bytes = $max_page_bytes;
		$this->state_table = $tables[0];
		$this->record_table = $tables[1];
	}

	/** Metadata only: root payload is sized in SQL, never hydrated here. */
	public function head( $state_key ) {
		$value = $this->codec->key_value( $state_key );
		$sql = 'SELECT id, blog_id, state_order, state_kind, active_generation, revision, codec_version, record_count, payload_bytes, '
			. 'OCTET_LENGTH(root_payload) AS root_payload_bytes, '
			. 'CASE WHEN OCTET_LENGTH(state_key_value) <= %d THEN state_key_value ELSE NULL END AS state_key_value '
			. 'FROM `' . $this->state_table . '` WHERE blog_id = %d AND state_key_hash = UNHEX(%s) LIMIT 2';
		$rows = $this->read( $this->wpdb->prepare( $sql, array( strlen( $value ), $this->blog_id, $this->codec->key_hash( $state_key ) ) ) );
		if ( ! count( $rows ) ) {
			return false;
		}
		if ( count( $rows ) !== 1 || $rows[0]['state_key_value'] !== $value ) {
			$this->fail( 'State-key collision or corrupt head.' );
		}
		$head = $rows[0];
		foreach ( array( 'id', 'blog_id', 'state_order', 'active_generation', 'revision', 'codec_version', 'record_count', 'payload_bytes', 'root_payload_bytes' ) as $field ) {
			$head[ $field ] = $this->number( $head[ $field ] );
		}
		if ( $head['blog_id'] !== $this->blog_id || $head['id'] < 1 || $head['active_generation'] < 1 || $head['codec_version'] !== LegacyStateCodec::VERSION ) {
			$this->fail( 'Wrong site or unsupported state head.' );
		}
		return $head;
	}

	/**
	 * Ordered root/child page. Cursor is tied to site, state, parent, generation
	 * and revision. Fail/retry if a logical write commits during this read.
	 * The caller must never use this to imply complete reconstruction parity.
	 */
	public function record_page( $state_key, $limit, $cursor = null, $parent_hash = null ) {
		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_ROWS ) {
			$this->fail( 'Page row budget must be between 1 and 100.' );
		}
		if ( null !== $parent_hash ) {
			$parent_hash = $this->hex( $parent_hash );
		}
		$head = $this->head( $state_key );
		if ( false === $head ) {
			$this->fail( 'Missing state head; no stale option fallback is permitted.' );
		}
		$identity = array( 'blog_id' => $this->blog_id, 'state_id' => $head['id'], 'generation' => $head['active_generation'],
			'revision' => $head['revision'], 'state_key_hash' => $this->codec->key_hash( $state_key ), 'parent_hash' => $parent_hash );
		$args = array( $this->blog_id, $head['id'], $head['active_generation'] );
		$where = 'blog_id = %d AND state_id = %d AND generation = %d';
		$where .= null === $parent_hash ? ' AND parent_node_hash IS NULL' : ' AND parent_node_hash = UNHEX(%s)';
		if ( null !== $parent_hash ) {
			$args[] = $parent_hash;
		}
		$last_order = -1;
		$last_id = 0;
		if ( null !== $cursor ) {
			if ( ! is_array( $cursor ) || array_diff_key( $identity, $cursor ) ) {
				$this->fail( 'Malformed page cursor.' );
			}
			foreach ( $identity as $field => $value ) {
				if ( $cursor[ $field ] !== $value ) {
					$this->fail( 'Stale or cross-scope page cursor; restart the read.' );
				}
			}
			if ( ! isset( $cursor['item_order'], $cursor['id'] ) || ! is_int( $cursor['item_order'] ) ||
				! is_int( $cursor['id'] ) || $cursor['item_order'] < 0 || $cursor['id'] < 1 ) {
				$this->fail( 'Malformed ordering cursor.' );
			}
			$last_order = $cursor['item_order'];
			$last_id = $cursor['id'];
			$where .= ' AND (item_order > %d OR (item_order = %d AND id > %d))';
			$args = array_merge( $args, array( $last_order, $last_order, $cursor['id'] ) );
		}
		$metadata = 'id, blog_id, state_id, generation, item_order, HEX(node_hash) AS node_hash, HEX(parent_node_hash) AS parent_node_hash, '
			. 'HEX(payload_hash) AS payload_hash, HEX(logical_hash) AS logical_hash, payload_bytes';
		$bytes = 'OCTET_LENGTH(payload) + OCTET_LENGTH(mutable_payload) + OCTET_LENGTH(key_value) + '
			. 'COALESCE(OCTET_LENGTH(legacy_identifier_value),0) + COALESCE(OCTET_LENGTH(source_plugin_value),0) + COALESCE(OCTET_LENGTH(source_id_value),0)';
		$args[] = $limit;
		$rows = $this->read( $this->wpdb->prepare( 'SELECT ' . $metadata . ', ' . $bytes . ' AS encoded_bytes FROM `'
			. $this->record_table . '` WHERE ' . $where . ' ORDER BY item_order ASC, id ASC LIMIT %d', $args ) );
		if ( count( $rows ) > $limit ) {
			$this->fail( 'Database exceeded the requested row budget.' );
		}
		$total = 0;
		// Size all selected rows before retrieving any LONGTEXT. An oversized
		// record or page is a refusal, never a silently truncated result.
		foreach ( $rows as &$row ) {
			foreach ( array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'payload_bytes', 'encoded_bytes' ) as $field ) {
				$row[ $field ] = $this->number( $row[ $field ] );
			}
			$this->row_scope( $row, $head );
			if ( $row['item_order'] < $last_order || ( $row['item_order'] === $last_order && $row['id'] <= $last_id ) ||
				$row['encoded_bytes'] > self::MAX_RECORD_BYTES || $row['encoded_bytes'] > $this->max_page_bytes - $total ) {
				$this->fail( 'Invalid sibling ordering or record/page byte budget exceeded.' );
			}
			$total += $row['encoded_bytes'];
			$last_order = $row['item_order'];
			$last_id = $row['id'];
			foreach ( array( 'node_hash', 'payload_hash', 'logical_hash' ) as $field ) {
				$row[ $field ] = $this->hex( $row[ $field ] );
			}
			$row['parent_node_hash'] = null === $row['parent_node_hash'] ? null : $this->hex( $row['parent_node_hash'] );
			if ( $row['parent_node_hash'] !== $parent_hash ) {
				$this->fail( 'Parent boundary mismatch.' );
			}
		}
		unset( $row );
		$result = array();
		$next = $cursor;
		foreach ( $rows as $row ) {
			$fetch = 'SELECT ' . $metadata . ', ' . $bytes . ' AS encoded_bytes, key_type, key_value, payload, mutable_payload, '
				. 'legacy_identifier_value, source_plugin_value, source_id_value FROM `' . $this->record_table . '` '
				. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND id = %d AND (' . $bytes . ') <= %d LIMIT 1';
			$records = $this->read( $this->wpdb->prepare( $fetch, array( $this->blog_id, $head['id'], $head['active_generation'], $row['id'], $row['encoded_bytes'] ) ) );
			if ( count( $records ) !== 1 ) {
				$this->fail( 'Record changed or disappeared during the bounded read.' );
			}
			$record = $records[0];
			foreach ( $row as $field => $expected ) {
				$actual = $record[ $field ];
				if ( is_int( $expected ) ) { $actual = $this->number( $actual ); }
				else if ( null !== $expected ) { $actual = $this->hex( $actual ); }
				if ( $actual !== $expected ) { $this->fail( 'Record metadata changed during read.' ); }
			}
			if ( hash( 'sha256', $record['payload'] ) !== $row['payload_hash'] ||
				strlen( $record['payload'] ) + strlen( $record['mutable_payload'] ) !== $row['payload_bytes'] ) {
				$this->fail( 'Base payload integrity or byte-count mismatch.' );
			}
			$key = $this->record_key( $record );
			if ( $this->codec->node_hash( $identity['state_key_hash'], $parent_hash, $key ) !== $row['node_hash'] ) {
				$this->fail( 'Record node identity mismatch.' );
			}
			$mutable = $this->codec->decode( $record['mutable_payload'] );
			if ( ! is_array( $mutable ) ) { $this->fail( 'Invalid mutable envelope.' ); }
			$result[] = array( 'metadata' => $row, 'key' => $key, 'base' => $this->codec->decode( $record['payload'] ), 'mutable' => $mutable,
				'legacy_identifier_value' => $record['legacy_identifier_value'], 'source_plugin_value' => $record['source_plugin_value'], 'source_id_value' => $record['source_id_value'] );
			$next = array_merge( $identity, array( 'item_order' => $row['item_order'], 'id' => $row['id'] ) );
		}
		if ( $this->head( $state_key ) !== $head ) {
			$this->fail( 'State changed during read; restart the page.' );
		}
		return array( 'head' => $head, 'rows' => $result, 'cursor' => $next, 'encoded_bytes' => $total );
	}

	private function record_key( $record ) {
		if ( ! is_string( $record['key_value'] ) ) { $this->fail( 'Invalid array key bytes.' ); }
		$bytes = base64_decode( $record['key_value'], true );
		if ( false === $bytes || base64_encode( $bytes ) !== $record['key_value'] ) { $this->fail( 'Invalid array key bytes.' ); }
		if ( 'integer' === $record['key_type'] && (string) (int) $bytes === $bytes ) { return (int) $bytes; }
		if ( 'string' === $record['key_type'] ) {
			$probe = array( $bytes => true );
			$keys = array_keys( $probe );
			if ( $keys[0] === $bytes ) { return $bytes; }
		}
		$this->fail( 'Invalid or coercible array key.' );
	}

	private function row_scope( $row, $head ) {
		if ( $row['id'] < 1 || $row['blog_id'] !== $this->blog_id || $row['state_id'] !== $head['id'] || $row['generation'] !== $head['active_generation'] ) {
			$this->fail( 'Record site/state/generation boundary mismatch.' );
		}
	}

	private function number( $value ) {
		if ( ! is_scalar( $value ) || (string) (int) $value !== (string) $value || (int) $value < 0 ) {
			$this->fail( 'Unsupported unsigned integer value.' );
		}
		return (int) $value;
	}

	private function hex( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-fA-F0-9]{64}$/D', $value ) ) { $this->fail( 'Malformed record hash.' ); }
		return strtolower( $value );
	}

	private function read( $sql ) {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $this->wpdb->last_error ) || ! is_array( $rows ) ) { $this->fail( 'Database read failed.' ); }
		return $rows;
	}

	private function fail( $message ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- All private call sites supply fixed diagnostic literals, not SQL results or input; this constructs an exception, not markup.
		throw new \RuntimeException( 'Legacy storage reader: ' . $message );
	}
}
