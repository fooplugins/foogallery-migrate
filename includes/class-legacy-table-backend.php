<?php
/**
 * Inert compact table-backend seam; NOT selectable by the engine yet.
 * The storage manager must verify authority/manifest and admit reads/writers.
 * No option fallback, provisioning, whole-state facade or implicit conversion.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyTableBackend {
	private $repository;

	public function __construct( $db, $blog, $max_rows = 10000, $max_bytes = 16777216, $max_seconds = 10 ) {
		$this->repository = new LegacyTableRepository( $db, $blog, $max_rows, $max_bytes, $max_seconds );
	}

	/** Small/scalar heads only; collections MUST use bounded root/page APIs. */
	public function read( $key ) { return $this->repository->read_scalar( $key ); }
	public function roots( $key, $keys ) { return $this->repository->roots( $key, $keys ); }
	public function page( $key, $limit, $cursor = null ) { return $this->repository->page( $key, $limit, $cursor ); }
	public function count_roots( $key ) { return $this->repository->count_roots( $key ); }
	public function identifiers( $key, $identifiers ) { return $this->repository->identifiers( $key, $identifiers ); }
	public function identifier_presence( $key, $identifiers ) { return $this->repository->identifier_presence( $key, $identifiers ); }
	public function source_identities( $key, $identities, $numeric = false ) { return $this->repository->source_identities( $key, $identities, $numeric ); }
	public function filtered_page( $key, $limit, $filters, $cursor = null ) { return $this->repository->filtered_page( $key, $limit, $filters, $cursor ); }
	public function aggregate( $key, $filters = array() ) { return $this->repository->aggregate( $key, $filters ); }
	public function projection_summary( $key ) { return $this->repository->projection_summary( $key ); }
	public function export_page( $key, $limit, $cursor = null ) { return $this->repository->export_page( $key, $limit, $cursor ); }

	/** Existing heads only; expected-revision CAS and aggregate affected budgets. */
	public function patch_many( $changes ) { return $this->repository->patch_many( $changes ); }
	public function patch_lists_and_scalars( $changes ) { return $this->repository->patch_lists_and_scalars( $changes ); }
}
