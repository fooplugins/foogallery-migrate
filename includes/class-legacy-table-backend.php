<?php
/**
 * Compact table backend; production selection requires validated authority.
 * The storage manager must verify authority/manifest and admit reads/writers.
 * No option fallback, provisioning, whole-state facade or implicit conversion.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyTableBackend {
	private $repository;
	private $admission;
	private $read_admission;

	public function __construct( $db, $blog, $max_rows = 10000, $max_bytes = 16777216, $max_seconds = 10, $admission = null, $read_admission = null ) {
		if ( ( null !== $admission && ! is_callable( $admission ) ) || ( null !== $read_admission && ! is_callable( $read_admission ) ) ) { throw new \InvalidArgumentException( 'Invalid table admission callback.' ); }
		$this->admission = $admission; $this->read_admission = $read_admission;
		$this->repository = new LegacyTableRepository( $db, $blog, $max_rows, $max_bytes, $max_seconds );
	}

	private function repository( $write = false ) {
		$callback = $write ? $this->admission : $this->read_admission;
		if ( null !== $callback && true !== call_user_func( $callback ) ) { throw new \RuntimeException( 'Table backend admission failed.' ); }
		return $this->repository;
	}

	/** Small/scalar heads only; collections MUST use bounded root/page APIs. */
	public function read( $key ) {
		if ( LegacyRecordCodec::CONTENT_STATE_KEY === $key ) {
			return $this->repository()->read_content_state();
		}
		return $this->repository()->read_scalar( $key );
	}
	public function metadata_many( $keys ) { return $this->repository()->metadata_many( $keys ); }
	public function history_read( $operation, $request = array() ) { return $this->repository()->history_read( $operation, $request ); }
	public function history_mutate( $operation, $request, $companions = array() ) { return $this->repository( true )->history_mutate( $operation, $request, $companions ); }
	public function mutate_scalars( $values ) { return $this->repository( true )->mutate_scalars( $values ); }
	public function delete_scalar( $key ) { return $this->repository( true )->delete_scalar( $key ); }
	public function content_window( $offset, $limit, $keys = null ) { return $this->repository()->content_window( $offset, $limit, $keys ); }
	public function content_selected( $keys ) { return $this->repository()->content_selected( $keys ); }
	public function content_plugin_window( $plugin, $offset, $limit ) { return $this->repository()->content_window( $offset, $limit, null, $plugin ); }
	public function content_count_plugin( $plugin ) { return $this->repository()->content_count_plugin( $plugin ); }
	public function replace_content_state( $value ) { return $this->repository( true )->replace_content_state( $value ); }
	public function content_batch( $items, $progress, $append, $revision = null ) { return $this->repository( true )->content_batch( $items, $progress, $append, $revision ); }
	public function content_scan_state() { return $this->repository()->content_scan_state(); }
	public function change_content_scan( $change ) { return $this->repository( true )->change_content_scan( $change ); }
	public function change_image_tag_queue( $change ) { return $this->repository( true )->change_image_tag_queue( $change ); }
	public function read_scalars( $keys ) { return $this->repository()->read_scalars( $keys ); }
	public function collection_snapshot( $key ) { return $this->repository()->collection_snapshot( $key ); }
	public function read_collection( $key ) { return $this->repository()->read_collection( $key ); }
	public function replace_collection( $key, $value, $companions = array() ) { return $this->repository( true )->replace_collection( $key, $value, $companions ); }
	public function update_collection_record( $key, $locator, $value ) { return $this->repository( true )->update_collection_record( $key, $locator, $value ); }
	public function history_page( $key, $limit, $filters, $cursor = null ) { return $this->repository()->history_page( $key, $limit, $filters, $cursor ); }
	public function history_source_identity( $type, $plugin, $id ) { return $this->repository()->history_source_identity( $type, $plugin, $id ); }
	public function roots( $key, $keys ) { return $this->repository()->roots( $key, $keys ); }
	public function page( $key, $limit, $cursor = null ) { return $this->repository()->page( $key, $limit, $cursor ); }
	public function count_roots( $key ) { return $this->repository()->count_roots( $key ); }
	public function collection_view_context( $key ) { return $this->repository()->collection_view_context( $key ); }
	public function collection_view_page( $key, $offset, $limit ) { return $this->repository()->collection_view_page( $key, $offset, $limit ); }
	public function queue_collection( $key, $selection, $transition, $summarize ) { return $this->repository( true )->queue_collection( $key, $selection, $transition, $summarize ); }
	public function targeted_collection_context( $key, $uid ) { return $this->repository( true )->targeted_collection_context( $key, $uid ); }
	public function checkpoint_targeted_collection( $key, $context, $value ) { return $this->repository( true )->checkpoint_targeted_collection( $key, $context, $value ); }
	public function migration_turn_context( $key ) { return $this->repository( true )->migration_turn_context( $key ); }
	public function checkpoint_migration_turn( $key, $context, $value, $summary ) { return $this->repository( true )->checkpoint_migration_turn( $key, $context, $value, $summary ); }
	public function identifiers( $key, $identifiers ) { return $this->repository()->identifiers( $key, $identifiers ); }
	public function identifier_presence( $key, $identifiers ) { return $this->repository()->identifier_presence( $key, $identifiers ); }
	public function source_identities( $key, $identities, $numeric = false ) { return $this->repository()->source_identities( $key, $identities, $numeric ); }
	public function filtered_page( $key, $limit, $filters, $cursor = null ) { return $this->repository()->filtered_page( $key, $limit, $filters, $cursor ); }
	public function aggregate( $key, $filters = array() ) { return $this->repository()->aggregate( $key, $filters ); }
	public function projection_summary( $key ) { return $this->repository()->projection_summary( $key ); }
	public function export_page( $key, $limit, $cursor = null ) { return $this->repository()->export_page( $key, $limit, $cursor ); }

	/** Existing heads only; expected-revision CAS and aggregate affected budgets. */
	public function patch_many( $changes ) { return $this->repository( true )->patch_many( $changes ); }
	public function patch_lists_and_scalars( $changes ) { return $this->repository( true )->patch_lists_and_scalars( $changes ); }
}
