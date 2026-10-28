<?php
/** Focused real-output/public-switch hard-death evidence, not stale takeover. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || defined( 'FOOGM_STORAGE_NATIVE_DEATH_ONLY' ) ) { throw new RuntimeException( 'Fresh disposable fixture required.' ); }
define( 'FOOGM_STORAGE_NATIVE_DEATH_ONLY', true );
require __DIR__ . '/storage-output-drain.php';
