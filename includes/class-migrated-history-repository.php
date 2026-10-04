<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared history shape, with OptionMigratedStore as the sole option-history owner. */
class MigratedHistoryRepository {
	private $store;
	public function __construct( $backend, $settings ) {
		if ( ! $backend instanceof LegacyOptionBackend || ! $settings instanceof MigratorSettings ) {
			throw new \RuntimeException( 'Unsupported history backend. No option fallback.' );
		}
		$this->store = new OptionMigratedStore( false, $settings, $backend );
	}
	public function get( $identifier ) { return $this->store->get( $identifier ); }
	public function has( $identifier ) { return $this->store->has( $identifier ); }
	public function get_many( $identifiers ) { return $this->store->get_many( $identifiers ); }
	public function find_by_source_identity( $type, $plugin_name, $source_id ) { return $this->store->find_by_source_identity( $type, $plugin_name, $source_id ); }
	public function find_by_source_url( $type, $source_url ) { return $this->store->find_by_source_url( $type, $source_url ); }
	public function upsert_batch( $records, $companions = array() ) { return $this->store->upsert_batch( $records, $companions ); }
	public function upsert( $record, $companions = array() ) { return $this->store->upsert( $record, $companions ); }
	public function upsert_many( $records, $companions = array() ) { return $this->store->upsert_many( $records, $companions ); }
	public function iterate_page( $limit = 100, $cursor = null ) { return $this->store->iterate_page( $limit, $cursor ); }
	public function page_log( $type = '', $status = '', $limit = 100, $cursor = null ) { return $this->store->page_log( $type, $status, $limit, $cursor ); }
	public function count() { return $this->store->count(); }
	public function update_status( $identifier, $status ) { return $this->store->set_status( $identifier, $status ); }
	public function set_status( $identifier, $status ) { return $this->update_status( $identifier, $status ); }
	public function delete( $identifier ) { return $this->store->delete( $identifier ); }
	public function delete_for_retry( $identifier ) { return $this->store->delete_for_retry( $identifier ); }
	public function clear() { return $this->store->clear(); }
	public function revision() { return $this->store->revision(); }
	public function log_summary() { return $this->store->log_summary(); }
	public function log_page( $type = '', $status = '', $offset = 0, $limit = 100 ) { return $this->store->log_page( $type, $status, $offset, $limit ); }
	public function read_snapshot() { return $this->store->get_all(); }
	// Existing engine compatibility names. OptionMigratedStore remains the sole owner.
	public function get_all( $type = '' ) { return $this->store->get_all( $type ); }
	public function get_by_parent( $identifier ) { return $this->store->get_by_parent( $identifier ); }
	public function summary() { return $this->store->summary(); }
	public function manages_revision() { return $this->store->manages_revision(); }
}
