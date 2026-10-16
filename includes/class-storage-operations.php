<?php
/**
 * Explicit current-site administrator storage receiver. No UI, implicit DDL,
 * activation conversion, request policy, purge or expired-owner recovery.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageOperations {
	private $calibration;
	private $incoming_memory;
	public function __construct( $calibration = null ) { $this->calibration = $calibration; }

	/** Strict semantic request: exactly the coordinator's five existing fields. */
	public function dispatch( $operation, $request ) {
		// Schema DDL is a separate explicit operation: no state decode, capacity
		// profile, conversion or authority selection is involved.
		if ( 'provision' === $operation ) { return StorageProvisioning::provision( $request ); }
		$memory = null === $this->incoming_memory ? new StorageMemoryBaseline() : $this->incoming_memory;
		$this->incoming_memory = null; $memory->sample();
		if ( ! in_array( $operation, array( 'preflight', 'switch', 'recover', 'replace' ), true ) ||
			( 'preflight' === $operation && ( ! is_array( $request ) || ! isset( $request['target'] ) || 'table' !== $request['target'] ) ) ) {
			return self::invalid();
		}
		$authorized = StorageConversion::authorize_request( $request, 'recover' === $operation, null, 'replace' === $operation );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
		$memory->sample();
		// Never pass POST to configuration. There is deliberately no shipped policy.
		$profile = null === $this->calibration ? apply_filters( 'foogallery_migrate_storage_calibration', null ) : $this->calibration;
		$policy = StorageCalibration::policy( $profile );
		if ( is_wp_error( $policy ) ) { return $policy; }
		$memory->sample();
		if ( 'preflight' === $operation ) { return ( new StoragePreflight( null, $policy, $memory ) )->option_import( $request ); }
		$manager = new StorageConversion( $policy, $memory );
		if ( 'replace' === $operation ) { $result = $manager->replace_retained( $request ); }
		else { $result = 'recover' === $operation ? $manager->recover( $request ) : $manager->switch_backend( $request ); }
		if ( is_wp_error( $result ) ) { return $result; }
		// Do not expose digests, switch IDs, source payloads or internal errors.
		return array( 'blog_id' => $result['blog_id'], 'backend' => $result['backend'], 'epoch' => $result['epoch'], 'phase' => $result['phase'] );
	}

	/** Authenticated admin-post only; GET/REQUEST/cookies cannot supply operations. */
	public static function handle() {
		// Sample BEFORE capability/unslash/auth/calibration work. Carry the sample
		// forward, but do not claim it bounds allocations made by that work.
		$receiver = new self(); $receiver->incoming_memory = new StorageMemoryBaseline();
		if ( ! current_user_can( 'manage_options' ) ) {
			$result = new \WP_Error( 'foogallery_migrate_storage_forbidden', __( 'You are not authorized to manage migration storage.', 'foogallery-migrate' ) );
		} else if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! is_array( $_POST ) || 7 !== count( $_POST ) || ! isset( $_POST['action'], $_POST['operation'] ) ||
			'foogallery_migrate_storage' !== $_POST['action'] || ! is_string( $_POST['operation'] ) ) {
			$result = self::invalid();
		} else {
			$receiver->incoming_memory->observe_receiver( $_POST );
			$request = wp_unslash( $_POST ); $operation = $request['operation'];
			unset( $request['action'], $request['operation'] );
			$receiver->incoming_memory->sample();
			$result = $receiver->dispatch( $operation, $request );
		}
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code(); $status = 409;
			if ( 'foogallery_migrate_storage_forbidden' === $code || 'foogallery_migrate_storage_invalid_nonce' === $code ) { $status = 403; }
			else if ( 'foogallery_migrate_storage_invalid_request' === $code ) { $status = 400; }
			wp_send_json_error( array( 'code' => $code, 'message' => $result->get_error_message() ), $status );
			return;
		}
		wp_send_json_success( $result );
	}

	private static function invalid() {
		return new \WP_Error( 'foogallery_migrate_storage_invalid_request', __( 'Invalid migration storage request.', 'foogallery-migrate' ) );
	}
}
