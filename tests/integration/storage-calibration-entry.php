<?php
/** Include only from an owned fresh-process bootstrap under the bounded worker.
 * No WP-CLI auto-registration, public route, production filter or approved profile.
 * The bootstrap supplies $fgm_calibration_binding and $fgm_calibration_case;
 * this entry returns bounded evidence for its output producer to encode/drain.
 */
if ( 'cli' !== PHP_SAPI || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ||
	! defined( 'FOOGM_STORAGE_CALIBRATION_EVIDENCE_ONLY' ) || true !== FOOGM_STORAGE_CALIBRATION_EVIDENCE_ONLY ||
	! isset( $fgm_calibration_binding, $fgm_calibration_case ) ) { throw new RuntimeException( 'collection_authority_required' ); }
require_once dirname( __DIR__ ) . '/support/StorageCalibrationCollection.php';
$fgm_calibration_evidence = \FooPlugins\FooGalleryMigrate\Tests\StorageCalibrationCollection::run( $fgm_calibration_binding, $fgm_calibration_case );
$fgm_calibration_encoded = json_encode( $fgm_calibration_evidence );
if ( ! is_string( $fgm_calibration_encoded ) || strlen( $fgm_calibration_encoded ) + 1 > 65536 ) { throw new RuntimeException( 'collection_output_limit' ); }
unset( $fgm_calibration_encoded );
return $fgm_calibration_evidence;
