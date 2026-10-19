<?php
/**
 * Inert, bounded snapshot write primitive for the approved shared schema.
 * No provisioning, backend selection, option reads or destination mutations.
 * NOT the high-cardinality history/queue repository or resumable importer.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyTableSnapshot {
	private $wpdb;
	private $blog_id;
	private $codec;
	private $records;
	private $definitions;
	private $state_table;
	private $record_table;
	private $max_rows;
	private $max_bytes;
	private $deadline;
	private $memory_admission;
	private $scale;
	private $busy = false;
	private $tainted = false;

	// Qualification-only private seam. No request/filter/constant activation.
	// Even when armed by a harness, events NEVER lower production forecasts.
	private $snapshot_events_enabled = false;
	private $snapshot_plans = array();
	private $snapshot_owner;
	// Internal author/qualification seam only. No shipped profiles, options or SQL input.
	private $snapshot_authentication_enabled = false;
	private $snapshot_read_profile;
	private $snapshot_read_phase;
	private $snapshot_witnesses = array();

	/** Bounds apply to the entire affected replacement, including companion keys.
	 * Optional scale is operation-owned refusal admission, NOT calibration approval.
	 * Unscaled callers retain their existing contract.
	 */
	public function __construct( $wpdb, $blog_id, $max_rows = 10000, $max_bytes = 16777216, $deadline = null, $memory_admission = null, $scale = null ) {
		if ( ! ( $wpdb instanceof LegacyTransactionConnection ) ) { $this->fail( 'A pinned, non-replaying transaction connection is required.' ); }
		foreach ( array( $blog_id, $max_rows, $max_bytes ) as $number ) {
			if ( ! is_int( $number ) || $number < 1 ) { $this->fail( 'Invalid site or snapshot budget.' ); }
		}
		$this->wpdb = $wpdb;
		$this->blog_id = $blog_id;
		$this->max_rows = $max_rows;
		$this->max_bytes = $max_bytes;
		if ( null !== $scale && ( ! $scale instanceof StorageScaleAdmission || $max_rows > $scale->limit( 'max_rows' ) || $max_bytes > $scale->limit( 'max_bytes' ) ) ) { $this->fail( 'Invalid finite snapshot scale.' ); }
		$this->scale = $scale;
		if ( null !== $scale ) { $scale->bind_snapshot_blog( $blog_id ); }
		if ( null !== $deadline && ( ! is_float( $deadline ) || ! is_finite( $deadline ) ) ) { $this->fail( 'Invalid shared deadline.' ); }
		$this->deadline = $deadline;
		if ( null !== $memory_admission && ! is_callable( $memory_admission ) ) { $this->fail( 'Invalid snapshot memory admission.' ); }
		$this->memory_admission = $memory_admission;
		$this->codec = new LegacyStateCodec( 1048576, 64, 10000, $scale );
		$this->records = new LegacyRecordCodec( $this->codec, $max_rows, $max_bytes, $scale );
		$this->definitions = ( new LegacyStorageSchema( $wpdb ) )->definitions();
		$tables = array_keys( $this->definitions );
		$this->state_table = $tables[0];
		$this->record_table = $tables[1];
	}

	/**
	 * Each change explicitly supplies key, top-level order, kind (scalar/map/list),
	 * value and expected_revision (null means MUST be absent). Every key, including
	 * history revision/checkpoint companions, flips in ONE transaction after all
	 * staged values pass DB read-back logical/projection/count/byte verification.
	 *
	 * Caller must exclusively own the connection's transaction boundary and have
	 * acquired the manager's writer/maintenance guard. Ambient transactions and
	 * disabled autocommit are refused before START, which could otherwise commit
	 * caller-owned work implicitly. No reentrant use is supported.
	 * No public production caller is admitted until that manager contract exists.
	 */
	public function replace_many( $changes, $guards = array() ) {
		$this->admit();
		if ( null !== $this->scale ) { $this->scale->begin_snapshot_write(); }
		$prepared = $this->preflight( $changes );
		StorageScalarObserver::milestone( 'snapshot-preflight' );
		if ( ! is_array( $guards ) || count( $guards ) > 100 ) { $this->fail( 'Invalid snapshot read dependencies.' ); }
		$dependencies = array();
		foreach ( $guards as $entry ) {
			if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'key', 'head' ) ) { $this->fail( 'Invalid snapshot read dependency.' ); }
			$this->typed_key( $entry['key'] ); $hash = $this->codec->key_hash( $entry['key'] );
			if ( isset( $dependencies[$hash] ) ) { $this->fail( 'Duplicate snapshot read dependency.' ); }
			$dependencies[$hash] = $entry;
		}
		if ( null !== $this->scale && count( array_unique( array_merge( array_keys( $prepared ), array_keys( $dependencies ) ) ) ) > $this->scale->limit( 'max_heads' ) ) { $this->fail( 'Snapshot lock heads exceed finite admission.' ); }
		( new LegacyStorageSchema( $this->wpdb ) )->verify();
		$mode = $this->read( 'SELECT @@SESSION.autocommit AS autocommit' );
		if ( count( $mode ) !== 1 || $this->number( $mode[0]['autocommit'] ) !== 1 ) { $this->fail( 'Disabled autocommit; caller must supply an idle connection.' ); }
		// Unqualified SET TRANSACTION sets only the NEXT transaction and is
		// refused by MySQL/MariaDB inside an active transaction. Unlike START,
		// this guard cannot implicitly commit caller-owned work.
		$this->write( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->busy = true;
		try {
			$this->write( 'START TRANSACTION' );
		} catch ( \Exception $error ) {
			$this->busy = false; $this->tainted = true;
			$this->fail( 'Transaction start failed; connection state is uncertain. Reconcile using a fresh connection.' );
		}
		try {
			// Hash order is stable across callers to reduce multi-key deadlocks.
			$this->tick();
			ksort( $prepared, SORT_STRING );
			$locks = $dependencies;
			foreach ( $prepared as $hash => $change ) { if ( ! isset( $locks[$hash] ) ) { $locks[$hash] = array( 'key' => $change['key'] ); } }
			ksort( $locks, SORT_STRING );
			foreach ( $locks as $entry ) {
				$this->read( $this->wpdb->prepare( 'SELECT id FROM `' . $this->state_table . '` WHERE blog_id = %d AND state_key_hash = UNHEX(%s) LIMIT 2 FOR UPDATE', array( $this->blog_id, $this->codec->key_hash( $entry['key'] ) ) ) );
			}
			$reader = new LegacyTableReader( $this->wpdb, $this->blog_id, $this->codec );
			foreach ( $dependencies as $entry ) { if ( $reader->head( $entry['key'] ) !== $entry['head'] ) { $this->fail( 'Snapshot read dependency changed.' ); } $this->tick(); }
			$staged = array();
			foreach ( $prepared as $hash => $change ) {
				$this->admit_memory( $change['encoded_bytes'] );
				$staged[ $hash ] = $this->stage( $change );
			}
			StorageScalarObserver::milestone( 'snapshot-staged' );
			foreach ( $staged as $hash => $stage ) {
				$change = $prepared[ $hash ];
				$value = $this->read_generation( $change['key'], $stage['id'], $stage['generation'], $change['root_payload'], $change['stats'], $change['kind'], $stage['revision'] + 1, $change['digest'] );
				$this->admit_memory( $change['encoded_bytes'] );
				if ( $this->snapshot_digest( $change['key'], $value ) !== $change['digest'] ) { $this->fail( 'Staged logical snapshot does not match its source.' ); }
				unset( $value );
				if ( null !== $this->snapshot_owner ) { $this->snapshot_owner->finish(); }
				$this->end_read_phase();
			}
			StorageScalarObserver::milestone( 'snapshot-readback' );
			$result = array();
			foreach ( $staged as $hash => $stage ) {
				$this->admit_memory( 0 );
				$change = $prepared[ $hash ];
				$args = array( $stage['generation'], $stage['revision'] + 1, $change['order'], $change['kind'],
					LegacyStateCodec::VERSION, $change['stats']['record_count'], $change['stats']['payload_bytes'] + strlen( $change['root_payload'] ),
					$change['digest'], $stage['revision'] + 1, $change['root_payload'], gmdate( 'Y-m-d H:i:s' ),
					$this->blog_id, $stage['id'], $stage['old_generation'], $stage['revision'], $hash );
				$sql = 'UPDATE `' . $this->state_table . '` SET active_generation = %d, revision = %d, state_order = %d, state_kind = %s, '
					. 'codec_version = %d, record_count = %d, payload_bytes = %d, snapshot_digest = UNHEX(%s), digest_revision = %d, '
					. "digest_status = 'current', root_payload = %s, updated_gmt = %s WHERE blog_id = %d AND id = %d "
					. 'AND active_generation = %d AND revision = %d AND state_key_hash = UNHEX(%s)';
				if ( 1 !== $this->write( $this->wpdb->prepare( $sql, $args ) ) ) { $this->fail( 'State-head compare-and-swap failed.' ); }
				$result[] = array( 'key' => $change['key'], 'id' => $stage['id'], 'generation' => $stage['generation'], 'revision' => $stage['revision'] + 1 );
			}
			// A detected timeout still belongs to the rollback-safe transaction.
			$this->tick();
		} catch ( \Exception $error ) {
			$this->rollback( $error );
		} catch ( \Throwable $error ) {
			// PHP 7+ callback failures need the same cleanup. Separate catches
			// retain PHP 5 syntax, where engine errors are not catchable objects.
			$this->rollback( $error );
		}
		try {
			// Check immediately before issuing COMMIT, while rollback is still safe.
			// Do not turn a successful in-flight COMMIT into a rollback claim.
			$result_commit = $this->wpdb->query( 'COMMIT' );
			if ( false === $result_commit || ! empty( $this->wpdb->last_error ) ) { $this->fail( 'Database commit failed.' ); }
		} catch ( \Exception $error ) {
			// The server may have committed but its reply may have been lost.
			// Never report a safe retry or silently replay the logical operation.
			$this->tainted = true; $this->busy = false;
			$this->wpdb->query( 'ROLLBACK' );
			$this->fail( 'Commit outcome is uncertain. Reconcile heads/digests on a fresh connection before retrying.' );
		}
		$this->busy = false;
		StorageScalarObserver::milestone( 'snapshot-committed' );
		return $result;
	}

	public function __clone() { if ( count( $this->snapshot_witnesses ) || null !== $this->snapshot_read_phase ) { $this->fail( 'Cannot clone producer authority.' ); } }
	public function __sleep() { if ( count( $this->snapshot_witnesses ) || null !== $this->snapshot_read_phase ) { $this->fail( 'Cannot serialize producer authority.' ); } return array_keys( get_object_vars( $this ) ); }
	public function __wakeup() { if ( count( $this->snapshot_witnesses ) || null !== $this->snapshot_read_phase ) { $this->fail( 'Cannot restore producer authority.' ); } }

	/** Small/explicit snapshot reads only, not an unbounded collection API. */
	public function read_bounded( $key ) {
		$this->admit();
		$this->typed_key( $key );
		$key_bytes = strlen( $this->codec->key_value( $key ) );
		if ( $key_bytes > $this->max_bytes ) { $this->fail( 'State key exceeds the encoded byte budget.' ); }
		$reader = new LegacyTableReader( $this->wpdb, $this->blog_id, $this->codec );
		$head = $reader->head( $key );
		if ( false === $head ) { $this->fail( 'Missing state head; no option fallback.' ); }
		if ( $head['record_count'] > $this->max_rows || $head['payload_bytes'] > $this->max_bytes - $key_bytes ) { $this->fail( 'Snapshot exceeds the row/byte budget.' ); }
		$sql = 'SELECT HEX(snapshot_digest) AS snapshot_digest, digest_revision, digest_status FROM `' . $this->state_table . '` WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d LIMIT 1';
		$digests = $this->read( $this->wpdb->prepare( $sql, array( $this->blog_id, $head['id'], $head['revision'], $head['active_generation'] ) ) );
		if ( count( $digests ) !== 1 || 'current' !== $digests[0]['digest_status'] || $this->number( $digests[0]['digest_revision'] ) !== $head['revision'] || ! is_string( $digests[0]['snapshot_digest'] ) || ! preg_match( '/^[0-9a-f]{64}$/Di', $digests[0]['snapshot_digest'] ) ) { $this->fail( 'Snapshot digest is absent, dirty or stale.' ); }
		$hash = $this->codec->key_hash( $key );
		if ( isset( $this->snapshot_plans[$hash]['root'] ) ) { $this->begin_read_phase( $key, $head['id'], $head['active_generation'], $this->snapshot_plans[$hash]['root'], $head['revision'], strtolower( $digests[0]['snapshot_digest'] ) ); }
		$this->admit_memory( $head['payload_bytes'] + $key_bytes );
		$root = $this->root_payload( $head['id'] );
		if ( null !== $this->snapshot_read_phase && $root !== $this->snapshot_plans[$hash]['root'] ) { $this->fail( 'Authenticated raw root changed before decode.' ); }
		StorageScalarObserver::acquisition( 2, strlen( $root ) );
		$metadata = 'scalar' === $head['state_kind'] ? $this->codec->decode( $root ) : $this->codec->decode_collection_root( $root );
		if ( ! is_array( $metadata ) || ! isset( $metadata['kind'] ) || $metadata['kind'] !== $head['state_kind'] ) { $this->fail( 'Root metadata does not match state kind.' ); }
		StorageScalarObserver::acquisition( 3, strlen( $root ) );
		$stats = array( 'record_count' => $head['record_count'], 'payload_bytes' => $head['payload_bytes'] - strlen( $root ) );
		$value = $this->read_generation( $key, $head['id'], $head['active_generation'], $root, $stats, $head['state_kind'], $head['revision'], strtolower( $digests[0]['snapshot_digest'] ) );
		if ( $reader->head( $key ) !== $head ) { $this->fail( 'Snapshot changed during read; restart.' ); }
		$this->admit_memory( $head['payload_bytes'] + $key_bytes );
		StorageScalarObserver::acquisition( 27 );
		if ( $this->snapshot_digest( $key, $value ) !== strtolower( $digests[0]['snapshot_digest'] ) ) { $this->fail( 'Complete snapshot digest mismatch.' ); }
		StorageScalarObserver::acquisition( 28 );
		// No temporary reconstructed copy survives. The result transfers to the
		// caller; private maxima are recorded, never released as memory credit.
		if ( null !== $this->snapshot_owner ) { $this->snapshot_owner->finish(); }
		$this->end_read_phase();
		StorageScalarObserver::acquisition( 19 );
		return $value;
	}

	/** Dirty heads are verified explicitly, never rehashed by runtime reads/writes.
	 * Traverses one complete root at a time, with total rows/bytes/time admission.
	 * This is a bounded maintenance operation, NOT a resumable hash context or a
	 * large-state runtime facade. export_page provides revision-bound continuation.
	 */
	public function verify_digest( $key, $expected_revision, $max_seconds = 10 ) {
		if ( null !== $this->scale ) { $this->fail( 'Finite scale propagation is not admitted for maintenance.' ); }
		$this->admit(); $this->typed_key( $key );
		if ( ! is_int( $expected_revision ) || $expected_revision < 1 || ! is_int( $max_seconds ) || $max_seconds < 1 ) { $this->fail( 'Invalid maintenance admission.' ); }
		$deadline = microtime( true ) + $max_seconds;
		$head = $this->maintenance_head( $key );
		if ( false === $head || $head['revision'] !== $expected_revision || ! in_array( $head['state_kind'], array( 'map', 'list' ), true ) ) { $this->fail( 'Missing, stale or noncollection maintenance head.' ); }
		$root = $this->codec->decode( $head['root_payload'] );
		$stats = $this->generation_stats( $head['id'], $head['active_generation'] );
		if ( $stats['record_count'] !== $head['record_count'] || $stats['payload_bytes'] + strlen( $head['root_payload'] ) !== $head['payload_bytes'] ||
			$stats['record_count'] > $this->max_rows || $stats['encoded_bytes'] > $this->max_bytes - strlen( $head['root_payload'] ) - strlen( $this->codec->key_value( $key ) ) ) { $this->fail( 'Maintenance physical statistics or admission mismatch.' ); }
		$encoded_bytes = $stats['encoded_bytes'] + strlen( $head['root_payload'] ) + strlen( $this->codec->key_value( $key ) );
		$this->admit_memory( $encoded_bytes );
		$envelope = new LegacyCollectionEnvelope(); $envelope->validate( $root, $key );
		if ( $root['kind'] !== $head['state_kind'] ) { $this->fail( 'Invalid maintenance root.' ); }
		$bytes = strlen( $head['root_payload'] ) + strlen( $this->codec->key_value( $key ) );
		if ( $head['record_count'] > $this->max_rows || $bytes > $this->max_bytes ) { $this->fail( 'Maintenance exceeds total row/byte budget.' ); }
		$repository = new LegacyTableRepository( $this->wpdb, $this->blog_id, $this->max_rows, $this->max_bytes, $max_seconds );
		$hash = hash_init( 'sha256' ); hash_update( $hash, "legacy-logical-v1\0" ); $envelope->begin_digest( $hash, $root );
		$cursor = null; $roots = 0; $rows = 0; $payload = strlen( $head['root_payload'] );
		do {
			if ( microtime( true ) > $deadline ) { $this->fail( 'Maintenance time budget exceeded.' ); }
			// export_page hydrates and reconstructs one complete root internally.
			// Recheck the existing policy before that work and again while the
			// returned page and previous loop locals remain live, before hashing.
			$this->admit_memory( $encoded_bytes );
			$page = $repository->export_page( $key, 1, $cursor );
			$this->admit_memory( $encoded_bytes );
			if ( $page['revision'] !== $expected_revision ) { $this->fail( 'State changed during maintenance.' ); }
			if ( $page['record_count'] > $this->max_rows - $rows || $page['encoded_bytes'] > $this->max_bytes - $bytes ) { $this->fail( 'Maintenance exceeds total row/byte budget.' ); }
			$rows += $page['record_count']; $payload += $page['payload_bytes']; $bytes += $page['encoded_bytes'];
			foreach ( $page['rows'] as $item ) {
				if ( $roots >= $root['root_count'] || ( 'list' === $head['state_kind'] && ( $item['key'] !== $roots || $page['cursor']['order'] !== $roots ) ) ) { $this->fail( 'List/root count parity failed.' ); }
				$envelope->digest_item( $hash, $root, $item['key'], $item['value'] ); ++$roots;
			}
			$cursor = $page['cursor'];
		} while ( count( $page['rows'] ) );
		if ( $roots !== $root['root_count'] || $rows !== $head['record_count'] || $payload !== $head['payload_bytes'] ||
			$this->generation_stats( $head['id'], $head['active_generation'] ) !== $stats || $this->maintenance_head( $key ) !== $head ) { $this->fail( 'Maintenance physical parity or revision changed.' ); }
		$this->admit_memory( $encoded_bytes );
		$envelope->end_digest( $hash, $root ); $digest = hash_final( $hash );
		if ( 'current' === $head['digest_status'] && $digest !== $head['logical_digest'] ) { $this->fail( 'Current logical digest mismatch; not repairing corruption.' ); }
		( new LegacyStorageSchema( $this->wpdb ) )->verify();
		$mode = $this->read( 'SELECT @@SESSION.autocommit AS autocommit' );
		if ( count( $mode ) !== 1 || 1 !== $this->number( $mode[0]['autocommit'] ) ) { $this->fail( 'Idle autocommit connection required.' ); }
		$this->write( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->busy = true;
		try { $this->write( 'START TRANSACTION' ); }
		catch ( \Exception $error ) {
			$this->busy = false; $this->tainted = true;
			$this->fail( 'Transaction start failed; connection state is uncertain. Reconcile using a fresh connection.' );
		}
		try {
			$this->read( $this->wpdb->prepare( 'SELECT id FROM `' . $this->state_table . '` WHERE blog_id = %d AND id = %d FOR UPDATE', array( $this->blog_id, $head['id'] ) ) );
			if ( microtime( true ) > $deadline || $this->maintenance_head( $key ) !== $head ) { $this->fail( 'Maintenance deadline or head changed before publication.' ); }
			if ( 'dirty' === $head['digest_status'] ) {
				$sql = 'UPDATE `' . $this->state_table . '` SET snapshot_digest = UNHEX(%s), digest_revision = %d, digest_status = %s WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d AND digest_status = %s';
				if ( 1 !== $this->write( $this->wpdb->prepare( $sql, array( $digest, $expected_revision, 'current', $this->blog_id, $head['id'], $expected_revision, $head['active_generation'], 'dirty' ) ) ) ) { $this->fail( 'Maintenance digest CAS failed.' ); }
			}
			$verified = $this->maintenance_head( $key );
			if ( $verified['logical_digest'] !== $digest || $verified['digest_revision'] !== $expected_revision || 'current' !== $verified['digest_status'] ) { $this->fail( 'Digest publication read-back failed.' ); }
			$commit = $this->wpdb->query( 'COMMIT' ); $this->busy = false;
			if ( false === $commit || ! empty( $this->wpdb->last_error ) ) { $this->tainted = true; $this->fail( 'Commit outcome uncertain; reconcile on a fresh connection.' ); }
			return $verified;
		} catch ( \Exception $error ) { if ( $this->busy ) { $this->rollback( $error ); } throw $error; }
		catch ( \Throwable $error ) { if ( $this->busy ) { $this->rollback( $error ); } throw $error; }
	}

	/** Bounded physical metadata; existing reader head/cursor shapes stay unchanged. */
	private function maintenance_head( $key ) {
		$head = ( new LegacyTableReader( $this->wpdb, $this->blog_id ) )->head( $key );
		if ( false === $head || $head['revision'] < 1 || $head['root_payload_bytes'] > min( $this->max_bytes, LegacyTableReader::MAX_RECORD_BYTES ) ) { $this->fail( 'Missing or oversized maintenance head.' ); }
		$key_bytes = strlen( $this->codec->key_value( $key ) );
		if ( $head['record_count'] > $this->max_rows || $head['payload_bytes'] < $head['root_payload_bytes'] || $head['payload_bytes'] > $this->max_bytes - $key_bytes ) { $this->fail( 'Maintenance exceeds total row/byte budget.' ); }
		$this->admit_memory( $head['payload_bytes'] + $key_bytes );
		$sql = 'SELECT root_payload, digest_status, digest_revision, HEX(snapshot_digest) AS logical_digest FROM `' . $this->state_table . '` WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d AND OCTET_LENGTH(root_payload) = %d LIMIT 1';
		$rows = $this->read( $this->wpdb->prepare( $sql, array( $this->blog_id, $head['id'], $head['revision'], $head['active_generation'], $head['root_payload_bytes'] ) ) );
		if ( count( $rows ) !== 1 || ! in_array( $rows[0]['digest_status'], array( 'dirty', 'current' ), true ) ) { $this->fail( 'Missing or invalid digest metadata.' ); }
		$this->admit_memory( $head['payload_bytes'] + $key_bytes );
		$fields = $rows[0]; $fields['digest_revision'] = $this->number( $fields['digest_revision'] );
		if ( ! is_string( $fields['logical_digest'] ) || ! preg_match( '/^[a-f0-9]{64}$/Di', $fields['logical_digest'] ) || $fields['digest_revision'] > $head['revision'] ||
			( 'current' === $fields['digest_status'] && $fields['digest_revision'] !== $head['revision'] ) ) { $this->fail( 'Invalid digest revision/hash.' ); }
		$fields['logical_digest'] = strtolower( $fields['logical_digest'] ); return array_merge( $head, $fields );
	}


	private function preflight( $changes ) {
		if ( $this->snapshot_authentication_enabled || $this->snapshot_read_profile instanceof SnapshotReadProfile ) { $this->snapshot_events_enabled = true; }
		if ( $this->snapshot_events_enabled ) { $this->snapshot_plans = array(); $this->snapshot_witnesses = array(); }
		if ( ! is_array( $changes ) || ! count( $changes ) || count( $changes ) > 100 ) { $this->fail( 'Replacement needs 1 to 100 explicit changes.' ); }
		$this->admit_memory( 0 );
		$prepared = array(); $orders = array(); $total_rows = 0; $total_bytes = 0;
		foreach ( $changes as $change ) {
			$fields = array( 'key', 'order', 'kind', 'value', 'expected_revision' );
			if ( ! is_array( $change ) || count( $change ) !== count( $fields ) || array_diff( $fields, array_keys( $change ) ) ) { $this->fail( 'Malformed replacement change.' ); }
			$hash = $this->codec->key_hash( $change['key'] );
			$this->typed_key( $change['key'] );
			$change['order'] = $this->number( $change['order'] );
			if ( null !== $change['expected_revision'] && ( ! is_int( $change['expected_revision'] ) || $change['expected_revision'] < 1 || $change['expected_revision'] >= PHP_INT_MAX ) ) { $this->fail( 'Invalid expected revision.' ); }
			if ( isset( $prepared[ $hash ] ) || isset( $orders[ $change['order'] ] ) ) { $this->fail( 'Duplicate state key or top-level order.' ); }
			$orders[ $change['order'] ] = true;
			$stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
			$event_plan = null;
			if ( $this->snapshot_events_enabled && PHP_INT_SIZE >= 8 && get_class( $this ) === __CLASS__ && get_class( $this->records ) === __NAMESPACE__ . '\\LegacyRecordCodec' && get_class( $this->codec ) === __NAMESPACE__ . '\\LegacyStateCodec' && 'scalar' !== $change['kind'] ) {
				// Admit descriptor construction BEFORE allocating/walking it.
				SnapshotOwnedEventPlan::admit_descriptor( $this->scale );
				$event_plan = new SnapshotOwnedEventPlan();
			}
			if ( 'scalar' === $change['kind'] ) {
				$root = array( 'version' => 1, 'kind' => 'scalar', 'value' => $change['value'] );
			} else if ( in_array( $change['kind'], array( 'map', 'list' ), true ) && is_array( $change['value'] ) ) {
				$split = ( new LegacyCollectionEnvelope( $this->codec ) )->split( $change['key'], $change['kind'], $change['value'] );
				$value = $split['items']; $change['items'] = $value;
				if ( count( $value ) > $this->max_rows - $total_rows ) { $this->fail( 'Snapshot exceeds the record budget.' ); }
				$order = 0;
				foreach ( $value as $key => $record ) {
				$this->tick();
					$tree = $this->records->preflight_stats( $change['key'], $key, $record, $order++, $event_plan );
					if ( 'migrated' === $change['key'] ) { $tree['encoded_bytes'] += strlen( $this->codec->key_value( $key ) ); }
					foreach ( $stats as $field => $amount ) { $stats[ $field ] += $tree[ $field ]; }
					if ( $stats['record_count'] > $this->max_rows - $total_rows || $stats['encoded_bytes'] > $this->max_bytes - $total_bytes ) { $this->fail( 'Snapshot exceeds the row/byte budget.' ); }
				}
				$root = $split['root'];
			} else { $this->fail( 'Unsupported snapshot kind or value.' ); }
			$change['root_payload'] = 'scalar' === $change['kind'] ? $this->codec->encode( $root ) : $this->codec->encode_collection_root( $root );
			if ( strlen( $change['root_payload'] ) > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Root metadata exceeds the row byte budget.' ); }
			$total_rows += $stats['record_count'];
			$bytes = strlen( $change['root_payload'] ) + strlen( $this->codec->key_value( $change['key'] ) ) + $stats['encoded_bytes'];
			if ( $bytes > $this->max_bytes - $total_bytes ) { $this->fail( 'Snapshot exceeds the encoded byte budget.' ); }
			$total_bytes += $bytes;
			$this->admit_snapshot( $hash, $stats['record_count'], $bytes );
			$change['encoded_bytes'] = $bytes;
			$change['stats'] = $stats;
			if ( null !== $event_plan ) {
				$this->snapshot_plans[$hash] = array( 'descriptor' => $event_plan->descriptor( count( $value ), strlen( $change['root_payload'] ) ),
					'key' => $change['key'], 'root' => $change['root_payload'], 'stats' => $stats, 'digest' => null );
				if ( $this->snapshot_authentication_enabled || $this->snapshot_read_profile instanceof SnapshotReadProfile ) {
					// Descriptor arena already admitted. Charge scalar encode/walk scratch
					// with the existing full preflight guard before the complete traversal.
					$this->admit_memory( $bytes );
					$this->snapshot_plans[$hash]['complete'] = SnapshotReadPlan::compile( $change['value'], $this->snapshot_plans[$hash]['descriptor'], $this->codec, $this->scale );
				}

			}
			unset( $event_plan );
			$prepared[ $hash ] = $change;
		}
		// Complete digests only after every affected bucket passes preflight.
		foreach ( $prepared as &$change ) {
			$this->tick(); $this->admit_memory( $total_bytes );
			$change['digest'] = $this->snapshot_digest( $change['key'], $change['value'] );
			if ( $this->snapshot_events_enabled ) {
				$hash = $this->codec->key_hash( $change['key'] );
				if ( isset( $this->snapshot_plans[$hash] ) ) { $this->snapshot_plans[$hash]['digest'] = $change['digest']; }
			}
			$this->tick();
		}
		unset( $change );
		return $prepared;
	}

	private function stage( $change ) {
		$hash = $this->codec->key_hash( $change['key'] );
		$key_value = $this->codec->key_value( $change['key'] );
		$sql = 'SELECT id, active_generation, revision, codec_version, state_order, state_kind, '
			. 'CASE WHEN OCTET_LENGTH(state_key_value) <= %d THEN state_key_value ELSE NULL END AS state_key_value '
			. 'FROM `' . $this->state_table . '` WHERE blog_id = %d AND state_key_hash = UNHEX(%s) LIMIT 2 FOR UPDATE';
		$heads = $this->read( $this->wpdb->prepare( $sql, array( strlen( $key_value ), $this->blog_id, $hash ) ) );
		if ( count( $heads ) > 1 ) { $this->fail( 'Duplicate state heads.' ); }
		if ( count( $heads ) ) {
			$head = $heads[0];
			foreach ( array( 'id', 'active_generation', 'revision', 'codec_version', 'state_order' ) as $field ) { $head[ $field ] = $this->number( $head[ $field ] ); }
			if ( $head['state_key_value'] !== $key_value || $head['codec_version'] !== LegacyStateCodec::VERSION || $head['id'] < 1 || $head['active_generation'] < 1 || $head['revision'] < 1 || ! in_array( $head['state_kind'], array( 'scalar', 'map', 'list' ), true ) ) { $this->fail( 'Corrupt or unsupported state head.' ); }
			if ( $head['revision'] !== $change['expected_revision'] ) { $this->fail( 'Stale state revision; restart using current authority.' ); }
			if ( $head['active_generation'] >= PHP_INT_MAX || $head['revision'] >= PHP_INT_MAX ) { $this->fail( 'Generation/revision exhausted; refusing overflow.' ); }
		} else {
			if ( null !== $change['expected_revision'] ) { $this->fail( 'Expected state head is missing.' ); }
			$id = $this->insert( $this->state_table, array( 'blog_id' => $this->blog_id, 'state_key_hash' => $hash,
				'state_key_value' => $key_value, 'state_order' => $change['order'], 'state_kind' => 'staging',
				'active_generation' => 0, 'revision' => 0, 'codec_version' => LegacyStateCodec::VERSION,
				'digest_status' => 'dirty', 'root_payload' => $change['root_payload'] ) );
			$head = array( 'id' => $id, 'active_generation' => 0, 'revision' => 0 );
		}
		$generation = $head['active_generation'] + 1;
		if ( $head['revision'] > 0 ) {
			$sql = 'UPDATE `' . $this->state_table . '` SET root_payload = %s WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d';
			$affected = $this->write( $this->wpdb->prepare( $sql, array( $change['root_payload'], $this->blog_id, $head['id'], $head['revision'], $head['active_generation'] ) ) );
			// The locked head may retain byte-identical root metadata. MySQL's
			// affected-row count is then 0; read-back and final revision CAS still
			// prove the exact staged root and locked expected head before commit.
			if ( 0 !== $affected && 1 !== $affected ) { $this->fail( 'Root staging affected an unexpected row count.' ); }
		}
		$stats = $this->generation_stats( $head['id'], $generation );
		if ( $stats['record_count'] !== 0 ) { $this->fail( 'Inactive generation already contains rows; explicit recovery is required.' ); }
		if ( isset( $this->snapshot_plans[$hash]['complete'] ) ) {
			$this->admit_witness_lifetime( SnapshotPageWitness::workspace( $change['stats']['record_count'] ) );
			$this->snapshot_witnesses[$hash] = new SnapshotPageWitness( $this->definitions[$this->record_table]['columns'], array( $this->blog_id, $head['id'], $generation ), $change['stats']['record_count'] );
		}
		if ( 'scalar' !== $change['kind'] ) {
			$repo = $this; $id = $head['id']; $order = 0;
			foreach ( $change['items'] as $key => $record ) {
				$state_key = $change['key'];
				$this->records->flatten_tree( $state_key, $key, $record, $order++, function ( $row ) use ( $repo, $id, $generation, $state_key ) {
					$repo->insert_record( $id, $generation, $row, $state_key );
				} );
			}
		}
		if ( isset( $this->snapshot_witnesses[$hash] ) ) {
			$binding = $this->read_binding( $change['key'], $head['id'], $generation, $change['root_payload'], $change['stats'], $head['revision'] + 1, $change['digest'] );
			$this->snapshot_witnesses[$hash]->seal( $binding, $this->snapshot_plans[$hash]['complete'] );
			$this->snapshot_plans[$hash]['binding'] = $binding;
		}
		return array( 'id' => $head['id'], 'old_generation' => $head['active_generation'], 'generation' => $generation, 'revision' => $head['revision'] );
	}

	private function insert_record( $id, $generation, $row, $state_key ) {
		if ( ! $this->busy || $this->tainted ) { $this->fail( 'Record writes require an owned staged transaction.' ); }
		if ( 'migrated' === $state_key && null === $row['parent_node_hash'] ) {
			$key = 'integer' === $row['key_type'] ? (int) base64_decode( $row['key_value'], true ) : base64_decode( $row['key_value'], true );
			$row['legacy_identifier_value'] = $this->codec->key_value( $key ); $row['legacy_identifier_hash'] = $this->codec->key_hash( $key );
		}
		$witness = null;
		if ( count( $this->snapshot_witnesses ) ) { $hash = $this->codec->key_hash( $state_key ); $witness = isset( $this->snapshot_witnesses[$hash] ) ? $this->snapshot_witnesses[$hash] : null; }
		$this->insert( $this->record_table, array_merge( $row, array( 'blog_id' => $this->blog_id, 'state_id' => $id, 'generation' => $generation ) ), $witness );
	}

	private function read_generation( $key, $id, $generation, $root, $expected, $kind, $revision, $digest ) {
		$this->snapshot_owner = null;
		$this->begin_read_phase( $key, $id, $generation, $root, $revision, $digest );
		$stats = $this->generation_stats( $id, $generation );
		if ( $stats['record_count'] !== $expected['record_count'] || $stats['payload_bytes'] !== $expected['payload_bytes'] ) { $this->fail( 'Staged record count/byte parity failed.' ); }
		if ( isset( $expected['encoded_bytes'] ) && $stats['encoded_bytes'] !== $expected['encoded_bytes'] ) { $this->fail( 'Staged encoded byte parity failed.' ); }
		if ( $stats['record_count'] > $this->max_rows || $stats['encoded_bytes'] > $this->max_bytes - strlen( $root ) - strlen( $this->codec->key_value( $key ) ) ) { $this->fail( 'Snapshot exceeds the row/byte budget before payload fetch.' ); }
		$encoded_bytes = $stats['encoded_bytes'] + strlen( $root ) + strlen( $this->codec->key_value( $key ) );
		$hash = $this->codec->key_hash( $key );
		if ( null !== $this->snapshot_read_phase && $stats !== $this->snapshot_plans[$hash]['stats'] ) { $this->fail( 'Authenticated read statistics changed.' ); }
		$this->admit_snapshot( $hash, $stats['record_count'], $encoded_bytes );
		$this->admit_memory( $encoded_bytes );
		$stored_root = $this->root_payload( $id );
		if ( $stored_root !== $root ) { $this->fail( 'Root metadata changed or failed staged read-back.' ); }
		StorageScalarObserver::acquisition( 2, strlen( $root ) );
		$metadata = 'scalar' === $kind ? $this->codec->decode( $root ) : $this->codec->decode_collection_root( $root );
		StorageScalarObserver::acquisition( 3, strlen( $root ) );
		if ( ! is_array( $metadata ) || ! isset( $metadata['version'], $metadata['kind'] ) || 1 !== $metadata['version'] || $kind !== $metadata['kind'] ) { $this->fail( 'Malformed root metadata.' ); }
		if ( 'scalar' === $metadata['kind'] ) {
			if ( array_keys( $metadata ) !== array( 'version', 'kind', 'value' ) || 0 !== $stats['record_count'] ) { $this->fail( 'Malformed scalar snapshot.' ); }
			return $metadata['value'];
		}
		$envelope = new LegacyCollectionEnvelope( $this->codec ); $envelope->validate( $metadata, $key );
		$columns = array();
		foreach ( $this->definitions[ $this->record_table ]['columns'] as $name => $spec ) {
			$columns[] = 'binary(32)' === $spec[0] ? 'HEX(`' . $name . '`) AS `' . $name . '`' : '`' . $name . '`';
		}
		$admit = function ( $workspace ) use ( $encoded_bytes ) {
			$this->tick(); $this->admit_memory( $encoded_bytes );
			if ( null !== $this->snapshot_read_phase && $workspace ) {
				$this->scale->admit_snapshot_stats_workspace( $this->snapshot_read_phase->workspace( $workspace ) );
			} else if ( null !== $this->scale && $workspace ) {
				$this->scale->admit_snapshot_stats_workspace( StorageScaleAdmission::checked_add( StorageScaleAdmission::checked_multiply( 8, $encoded_bytes ), $workspace ) );
			}
		};
		$witness = null;
		if ( isset( $this->snapshot_witnesses[$hash], $this->snapshot_plans[$hash]['complete'] ) ) {
			$binding = $this->read_binding( $key, $id, $generation, $root, $stats, $revision, $digest );
			if ( $this->snapshot_witnesses[$hash]->matches( $binding, $this->snapshot_plans[$hash]['complete'] ) ) { $witness = $this->snapshot_witnesses[$hash]; }
		}
		if ( null !== $this->snapshot_read_phase && null === $witness ) { $this->fail( 'Active authenticated read lost producer authority.' ); }
		$source = new LegacySnapshotRowReader( $this->wpdb, $this->record_table, implode( ', ', $columns ), $this->record_bytes(), array( $this->blog_id, $id, $generation ), $this->max_bytes, $admit, $witness );
		// Immutable source descriptors do not carry an event balance. Each read
		// binds a NEW owner to this exact reader and concrete database identity.
		if ( $this->snapshot_events_enabled && isset( $this->snapshot_plans[$hash] ) ) {
			$plan = $this->snapshot_plans[$hash];
			if ( isset( $plan['descriptor'] ) && $plan['key'] === $key && $plan['root'] === $root && $plan['stats'] === $stats && $plan['digest'] === $digest ) {
				SnapshotOwnedEventPlan::admit_descriptor( $this->scale );
				$owner = new SnapshotOwnedEventPlan();
				if ( $owner->bind( $plan['descriptor'], array( 'key' => $key, 'digest' => $digest, 'stats' => $stats,
					'blog' => $this->blog_id, 'id' => $id, 'generation' => $generation, 'root' => $root, 'revision' => $revision ), $source ) ) {
					$this->snapshot_owner = $owner;
				}
			}
		}
		if ( null !== $this->snapshot_read_phase ) {
			if ( null === $this->snapshot_owner ) { $this->fail( 'Active authenticated read lost its event owner.' ); }
			$this->snapshot_read_phase->attach_owner( $this->snapshot_owner, $source );
		}
		return $this->records->rebuild_snapshot_stream( $key, $metadata, $source, $stats, $admit, $this->snapshot_owner );
	}

	/** Only an exact producer-sealed pair may select a finite read profile.
	 * Missing/stale/unsupported pairs keep the legacy path BEFORE a read starts. */
	private function read_binding( $key, $id, $generation, $root, $stats, $revision, $digest ) {
		$f = new \ReflectionClass( $this->wpdb ); $file = $f->getFileName();
		return array( 'key' => $key, 'digest' => $digest, 'stats' => $stats, 'blog' => $this->blog_id,
			'id' => $id, 'generation' => $generation, 'root' => $root, 'revision' => $revision,
			'compiler_runtime' => SnapshotReadPlan::runtime_binding(),
			'connection' => array( get_class( $this->wpdb ), $file ? hash_file( 'sha256', $file ) : null ) );
	}
	private function begin_read_phase( $key, $id, $generation, $root, $revision, $digest ) {
		StorageScalarObserver::acquisition( 1, null === $this->snapshot_read_profile ? 0 : 1 );
		if ( ! count( $this->snapshot_witnesses ) && null === $this->snapshot_read_phase ) { return; }
		$hash = $this->codec->key_hash( $key );
		if ( ! isset( $this->snapshot_plans[$hash]['binding'], $this->snapshot_plans[$hash]['complete'], $this->snapshot_witnesses[$hash] ) ) {
			if ( null !== $this->snapshot_read_phase ) { $this->fail( 'Active authenticated phase lost authority.' ); } return;
		}
		$p = $this->snapshot_plans[$hash];
		$b = $this->read_binding( $key, $id, $generation, $root, $p['stats'], $revision, $digest );
		if ( null !== $this->snapshot_read_phase ) {
			if ( ! $this->snapshot_read_phase->matches( $b ) ) { $this->fail( 'Active authenticated phase changed binding.' ); } return;
		}
		if ( null === $this->scale || ! $this->snapshot_read_profile instanceof SnapshotReadProfile || $p['descriptor'] !== $p['complete']['own'] ||
			! $this->snapshot_witnesses[$hash]->matches( $b, $p['complete'] ) ||
			! $this->snapshot_read_profile->supports( $p['complete'], SnapshotReadPhase::identity( $b ) ) ) { return; }
		$this->snapshot_read_phase = new SnapshotReadPhase( $p['complete'], $this->snapshot_witnesses[$hash], $b, $this->snapshot_read_profile );
		$this->scale->open_authenticated_snapshot_read( $this->snapshot_read_phase );
	}
	private function end_read_phase() {
		if ( null !== $this->snapshot_read_phase ) {
			// Complete private + zero remaining + actual backing still enter the
			// final guard. Only a fully completed operation can leave this scope.
			$this->scale->close_authenticated_snapshot_read( $this->snapshot_read_phase );
			$this->snapshot_read_phase = null;
		}
	}
	private function admit_witness_lifetime( $bytes ) {
		if ( null !== $this->scale ) { $this->scale->admit_additional( $bytes ); return; }
		$policy = array( 'max_raw_bytes' => PHP_INT_MAX, 'amplification' => 1, 'reserve_bytes' => 33554432 );
		if ( is_wp_error( StoragePreflight::decode_budget( $bytes, ini_get( 'memory_limit' ), memory_get_usage( true ), $policy ) ) ) { $this->fail( 'Producer witness construction refused.' ); }
	}

	private function rebuild_root( $key, $hash, $tree, &$result ) {
		$row = $tree[0]; $bytes = base64_decode( $row['key_value'], true );
		if ( false === $bytes || base64_encode( $bytes ) !== $row['key_value'] ) { $this->fail( 'Malformed root key bytes.' ); }
		$root_key = 'integer' === $row['key_type'] ? (int) $bytes : $bytes;
		$this->typed_key( $root_key );
		if ( 'migrated' === $key && ( $row['legacy_identifier_value'] !== $this->codec->key_value( $root_key ) ||
			strtolower( $row['legacy_identifier_hash'] ) !== $this->codec->key_hash( $root_key ) ) ) { $this->fail( 'History identifier projection mismatch.' ); }
		if ( array_key_exists( $root_key, $result ) ) { $this->fail( 'Duplicate root key.' ); }
		$result[ $root_key ] = $this->records->rebuild_tree( $key, $hash, $tree );
	}

	private function root_payload( $id ) {
		$sql = 'SELECT root_payload FROM `' . $this->state_table . '` WHERE blog_id = %d AND id = %d AND OCTET_LENGTH(root_payload) <= %d LIMIT 1';
		$rows = $this->read( $this->wpdb->prepare( $sql, array( $this->blog_id, $id, min( LegacyTableReader::MAX_RECORD_BYTES, $this->max_bytes ) ) ) );
		if ( count( $rows ) !== 1 || ! is_string( $rows[0]['root_payload'] ) ) { $this->fail( 'Missing or oversized root payload.' ); }
		return $rows[0]['root_payload'];
	}

	private function record_bytes() {
		return 'OCTET_LENGTH(payload) + OCTET_LENGTH(mutable_payload) + OCTET_LENGTH(key_value) + '
			. 'COALESCE(OCTET_LENGTH(legacy_identifier_value),0) + COALESCE(OCTET_LENGTH(source_plugin_value),0) + COALESCE(OCTET_LENGTH(source_id_value),0)';
	}

	/**
	 * Optional coordinator-owned policy recheck, not a new numerical envelope.
	 * Encoded bytes reuse the EXISTING calibrated policy as an extra refusal;
	 * the callback samples material co-live graphs at the actual consumer seam.
	 * This does not bound preflight encoding, driver transients, VM/observer NEW,
	 * arena retention, arbitrary hooks or failure/cleanup allocations. No callback
	 * means the existing standalone repository contract, not safe-memory approval.
	 */
	private function admit_memory( $bytes ) {
		if ( null !== $this->snapshot_read_phase ) { $this->scale->admit_snapshot_stats_workspace( $this->snapshot_read_phase->workspace( 0 ) ); }
		else if ( null !== $this->scale ) { $this->scale->admit_memory( $bytes ); }
		if ( null !== $this->memory_admission && true !== call_user_func( $this->memory_admission, $bytes ) ) {
			$this->fail( 'Snapshot memory admission was not affirmed.' );
		}
	}

	private function admit_snapshot( $hash, $rows, $bytes ) {
		if ( null !== $this->scale ) { $this->scale->admit_snapshot( $hash, $rows, $bytes ); }
	}

	private function snapshot_digest( $key, $value ) {
		if ( null === $this->scale ) { return $this->codec->snapshot_digest( $value ); }
		$footprint = $this->codec->snapshot_footprint( $value );
		$this->scale->admit_snapshot_tokens( $this->codec->key_hash( $key ), $footprint['tokens'] );
		return $footprint['digest'];
	}

	private function generation_stats( $id, $generation ) {
		$sql = 'SELECT COUNT(*) AS record_count, COALESCE(SUM(payload_bytes),0) AS payload_bytes, COALESCE(SUM('
			. $this->record_bytes() . '),0) AS encoded_bytes FROM `' . $this->record_table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d';
		$rows = $this->read( $this->wpdb->prepare( $sql, array( $this->blog_id, $id, $generation ) ) );
		if ( count( $rows ) !== 1 ) { $this->fail( 'Missing generation statistics.' ); }
		$stats = $rows[0];
		foreach ( array( 'record_count', 'payload_bytes', 'encoded_bytes' ) as $field ) { $stats[ $field ] = $this->number( $stats[ $field ] ); }
		return $stats;
	}

	private function insert( $table, $values, $witness = null ) {
		$columns = array(); $expressions = array(); $args = array(); $raw = array();
		foreach ( $this->definitions[ $table ]['columns'] as $name => $spec ) {
			if ( 'id' === $name ) { continue; }
			$value = $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? 0 : ( 'datetime' === $spec[0] ? gmdate( 'Y-m-d H:i:s' ) : '' ) );
			if ( array_key_exists( $name, $values ) ) { $value = $values[ $name ]; }
			if ( null !== $witness ) { $raw[$name] = $value; }
			$columns[] = '`' . $name . '`';
			if ( null === $value ) { $expressions[] = 'NULL'; continue; }
			$expressions[] = 'binary(32)' === $spec[0] ? 'UNHEX(%s)' : ( false !== strpos( $spec[0], 'int' ) ? '%d' : '%s' );
			$args[] = $value;
		}
		$sql = 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $expressions ) . ')';
		if ( 1 !== $this->write( $this->wpdb->prepare( $sql, $args ) ) ) { $this->fail( 'Staged insert did not write exactly one row.' ); }
		$id = $this->number( $this->wpdb->insert_id );
		if ( $id < 1 ) { $this->fail( 'Missing staged row ID.' ); }
		if ( null !== $witness ) { $raw['id'] = $id; $witness->append( $raw ); }
		return $id;
	}

	private function admit() {
		if ( $this->busy || $this->tainted ) { $this->fail( 'Busy or uncertain connection; use a fresh reconciled repository.' ); }
		$this->tick();
	}

	private function rollback( $error ) {
		$this->snapshot_witnesses = array(); $this->snapshot_plans = array();
		// An interrupted active phase remains charged on its scale object.
		$result = $this->wpdb->query( 'ROLLBACK' );
		$this->busy = false;
		if ( false === $result || ! empty( $this->wpdb->last_error ) ) { $this->tainted = true; $this->fail( 'Rollback failed; connection state is uncertain. Reconcile on a fresh connection.' ); }
		throw $error;
	}

	private function write( $sql ) {
		$this->tick();
		$result = $this->wpdb->query( $sql );
		if ( false === $result || ! empty( $this->wpdb->last_error ) ) { $this->fail( 'Database write failed.' ); }
		if ( 'START TRANSACTION' !== $sql ) { $this->tick(); }
		return $result;
	}

	private function read( $sql ) {
		$this->tick();
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		$this->tick();
		if ( ! is_array( $rows ) || ! empty( $this->wpdb->last_error ) ) { $this->fail( 'Database read failed.' ); }
		return $rows;
	}

	private function tick() {
		if ( null !== $this->scale ) { $this->scale->tick(); }
		if ( null !== $this->deadline && microtime( true ) > $this->deadline ) { $this->fail( 'Shared operation time budget exceeded.' ); }
	}

	private function typed_key( $key ) {
		$this->codec->key_value( $key );
		$probe = array( $key => true ); $keys = array_keys( $probe );
		if ( $keys[0] !== $key ) { $this->fail( 'Coercible typed key.' ); }
	}

	private function number( $value ) {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || (string) (int) $value !== (string) $value || (int) $value < 0 ) { $this->fail( 'Invalid unsigned integer.' ); }
		return (int) $value;
	}

	private function fail( $message ) {
		throw new \RuntimeException( 'Legacy table snapshot: ' . $message );
	}
}
