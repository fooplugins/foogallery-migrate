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
		StorageScalarObserver::refusal_scope();
		if ( in_array( $operation, array( 'clear', 'remove_all' ), true ) ) { return StorageCleanup::dispatch( $operation, $request ); }
		// Schema DDL is a separate explicit operation: no state decode, capacity
		// profile, conversion or authority selection is involved.
		if ( 'provision' === $operation ) { return StorageProvisioning::provision( $request ); }
		StorageScalarObserver::milestone( 'receiver-entry' );
		$memory = null === $this->incoming_memory ? new StorageMemoryBaseline() : $this->incoming_memory;
		$this->incoming_memory = null; $memory->sample();
		if ( ! in_array( $operation, array( 'preflight', 'switch', 'recover', 'replace', 'discard' ), true ) ||
			( 'preflight' === $operation && ( ! is_array( $request ) || ! isset( $request['target'] ) || 'table' !== $request['target'] ) ) ) {
			return self::invalid();
		}
		$authorized = StorageConversion::authorize_request( $request, 'recover' === $operation, null, 'replace' === $operation, 'discard' === $operation );
		if ( is_wp_error( $authorized ) ) { return $authorized; }
		StorageScalarObserver::milestone( 'authorized' );
		$memory->sample();
		// Explicit constructor profiles are a legacy private test dependency.
		// UI, registered HTTP and CLI use the fixed release-owned policy.
		$capacity = null;
		if ( null === $this->calibration ) {
			$class = StorageCapacityPolicy::resolve(); if ( is_wp_error( $class ) ) { return $class; }
			if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
			$policy = array( 'max_raw_bytes' => $class['limits']['max_raw_bytes'],
				'amplification' => $class['amplification'], 'reserve_bytes' => $class['reserve_bytes'] );
			$headroom = StoragePreflight::decode_budget( 0, ini_get( 'memory_limit' ), $memory->sample(), $policy );
			if ( is_wp_error( $headroom ) ) { return $headroom; }
			try { $capacity = new StorageCapacityBudget( $class ); }
			catch ( \Exception $error ) { return self::refused(); }
			catch ( \Throwable $error ) { return self::refused(); }
		} else { $policy = StorageCalibration::policy( $this->calibration ); }
		if ( is_wp_error( $policy ) ) { return $policy; }
		StorageScalarObserver::milestone( 'policy' );
		$memory->sample();
		if ( 'preflight' === $operation ) { return ( new StoragePreflight( null, $policy, $memory, $capacity ) )->option_import( $request ); }
		// Fixed implementation bounds, NOT calibration coefficients or approval.
		// One fresh domain per dispatch; the fingerprint binds these exact bytes.
		try {
			$limits = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
				'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
				'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
				'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 );
			if ( null !== $capacity ) { foreach ( $limits as $key => $value ) { if ( 'version' !== $key ) { $limits[$key] = $capacity->limit( $key ); } } }
			$scale = new StorageScaleAdmission( $limits, $policy, null === $capacity ? microtime( true ) + 10.0 : $capacity->deadline(), null, null, $memory, $capacity );
			$manager = new StorageConversion( $policy, $memory, $scale );
		} catch ( \Exception $error ) { return self::refused(); }
		catch ( \Throwable $error ) { return self::refused(); }
		if ( 'discard' === $operation ) { $result = $manager->discard_copy( $request ); }
		elseif ( 'replace' === $operation ) { $result = $manager->replace_retained( $request ); }
		else { $result = 'recover' === $operation ? $manager->recover( $request ) : $manager->switch_backend( $request ); }
		if ( is_wp_error( $result ) ) { return $result; }
		StorageScalarObserver::milestone( 'response' );
		if ( null !== $capacity ) {
			try { $capacity->response( 512 ); }
			catch ( \Exception $error ) { return self::refused(); }
			catch ( \Throwable $error ) { return self::refused(); }
		}
		// Do not expose digests, switch IDs, source payloads or internal errors.
		return array( 'blog_id' => $result['blog_id'], 'backend' => $result['backend'], 'epoch' => $result['epoch'], 'phase' => $result['phase'] );
	}

	/** Authenticated admin-post only; GET/REQUEST/cookies cannot supply operations. */
	public static function handle() {
		// Sample BEFORE capability/unslash/auth/calibration work. Carry the sample
		// forward, but do not claim it bounds allocations made by that work.
		$receiver = new self(); $receiver->incoming_memory = new StorageMemoryBaseline();
		StorageScalarObserver::refusal_scope();
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
		if ( is_array( $result ) && isset( $result['complete'] ) && false === $result['complete'] ) {
			wp_send_json_error( $result, 409 );
			return;
		}
		wp_send_json_success( $result );
	}

	private static function refused() {
		return new \WP_Error( 'foogallery_migrate_storage_recovery_required', __( 'Finite migration storage admission refused. Retain authority and all storage evidence.', 'foogallery-migrate' ) );
	}

	private static function invalid() {
		return new \WP_Error( 'foogallery_migrate_storage_invalid_request', __( 'Invalid migration storage request.', 'foogallery-migrate' ) );
	}
}
