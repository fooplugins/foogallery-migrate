<?php
/** Site-local operator controls over the existing bounded public receiver. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageAdmin {
	const ADVICE_THRESHOLD_BYTES = 1048576;
	const ADVICE_THRESHOLD_GALLERIES = 100;
	const ADVICE_THRESHOLD_IMAGES = 1000;

	public static function register() {
		// Core checks page access while building menus, before admin_init.
		self::redirect_legacy();
		add_action( 'admin_notices', array( __CLASS__, 'recommendation_notice' ) );
	}

	public static function settings_url() {
		return admin_url( 'edit.php?post_type=foogallery&page=foogallery-migrate&tab=settings#migration-storage' );
	}

	public static function troubleshooting_url() {
		return admin_url( 'edit.php?post_type=foogallery&page=foogallery-migrate&tab=troubleshooting' );
	}

	/** Old bookmarks remain useful; redirects never perform a storage operation. */
	public static function redirect_legacy() {
		if ( current_user_can( 'manage_options' ) && isset( $_SERVER['REQUEST_METHOD'], $_GET['page'] ) &&
			'GET' === $_SERVER['REQUEST_METHOD'] && is_string( $_GET['page'] ) && 'foogallery-migrate-storage' === wp_unslash( $_GET['page'] ) ) {
			wp_safe_redirect( self::settings_url() );
			exit;
		}
	}

	/** Settings must leave recovery/cleanup reachable without constructing an engine. */
	public static function settings_available( $status ) {
		return in_array( $status['backend'], array( 'option', 'table' ), true ) && 'stable' === $status['phase'] &&
			'' === $status['problem'] && null === StorageCleanup::pending_scope();
	}

	/** A suggestion only; this value never changes conversion admission. */
	public static function advice_threshold() {
		$threshold = apply_filters( 'foogallery_migrate_storage_advice_threshold_bytes', self::ADVICE_THRESHOLD_BYTES );
		return is_int( $threshold ) && $threshold > 0 ? $threshold : self::ADVICE_THRESHOLD_BYTES;
	}

	/** Keep the size query and its output on the migration/storage screens only. */
	public static function recommendation_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ||
			'foogallery-migrate' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { return; }
		if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) && in_array( sanitize_key( wp_unslash( $_GET['tab'] ) ), array( 'settings', 'troubleshooting' ), true ) ) { return; }
		if ( is_multisite() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Table storage recommendations and operations are unavailable on multisite. Existing option-mode flows remain supported, and this screen does not change storage authority.', 'foogallery-migrate' ) . '</p></div>';
			return;
		}
		$status = self::status( false );
		$message = self::recommendation_message( $status );
		if ( '' === $message ) { return; }
		$url = self::settings_url();
		echo '<div class="notice notice-info"><h3>' . esc_html__( 'Large Migrations Detected!', 'foogallery-migrate' ) . '</h3><p>' . esc_html( $message ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Change storage', 'foogallery-migrate' ) . '</a></p></div>';
	}

	/** Advice only: never read migration state, run source discovery or alter admission. */
	private static function recommendation_message( $status ) {
		if ( is_multisite() || empty( $status['calibrated'] ) || 'option' !== $status['backend'] || 'stable' !== $status['phase'] || ! empty( $status['problem'] ) || null !== StorageCleanup::pending_scope() ) { return ''; }
		$recommendation = __( 'We recommend changing your migration storage to use database tables before migrating.', 'foogallery-migrate' );
		if ( self::recommendation_eligible( $status ) ) {
			/* translators: %s: formatted size of saved migration data. */
			return sprintf( __( 'Saved migration data: %s.', 'foogallery-migrate' ), self::format_bytes( $status['option_bytes'] ) ) . ' ' . $recommendation;
		}
		$saved = StorageDiscoverySummary::read();
		$counts = null === $saved ? StorageSourceCounts::measure() : array( 'galleries' => $saved['galleries'], 'images' => $saved['images'] );
		$gallery_limit = apply_filters( 'foogallery_migrate_storage_advice_threshold_galleries', self::ADVICE_THRESHOLD_GALLERIES );
		$image_limit = apply_filters( 'foogallery_migrate_storage_advice_threshold_images', self::ADVICE_THRESHOLD_IMAGES );
		$gallery_limit = is_int( $gallery_limit ) && $gallery_limit > 0 ? $gallery_limit : self::ADVICE_THRESHOLD_GALLERIES;
		$image_limit = is_int( $image_limit ) && $image_limit > 0 ? $image_limit : self::ADVICE_THRESHOLD_IMAGES;
		if ( $counts['galleries'] < $gallery_limit && $counts['images'] < $image_limit ) { return ''; }
		$summary = '';
		if ( $counts['galleries'] > 0 ) {
			/* translators: %s: source gallery record count, formatted for the locale. */
			$summary .= sprintf( null === $saved ? __( 'Detected gallery records: %s.', 'foogallery-migrate' ) : __( 'Discovered galleries: %s.', 'foogallery-migrate' ), self::format_count( $counts['galleries'] ) ) . ' ';
		}
		if ( $counts['images'] > 0 || null !== $saved && ! $saved['images_complete'] ) {
			/* translators: %s: source image entry count, formatted for the locale. */
			$summary .= sprintf( null === $saved ? __( 'Detected image records: %s (partial estimate).', 'foogallery-migrate' ) : ( $saved['images_complete'] ? __( 'Image entries: %s.', 'foogallery-migrate' ) : __( 'Known image entries: %s (partial).', 'foogallery-migrate' ) ), self::format_count( $counts['images'] ) ) . ' ';
		} elseif ( null === $saved ) {
			$summary .= __( 'Image entry total is unknown or incomplete.', 'foogallery-migrate' ) . ' ';
		}
		return $summary . $recommendation;
	}

	private static function format_count( $count ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( $count ) : number_format( $count );
	}

	private static function recommendation_eligible( $status ) {
		return is_array( $status ) && isset( $status['backend'], $status['phase'], $status['option_bytes'], $status['calibrated'] ) &&
			! empty( $status['calibrated'] ) && 'option' === $status['backend'] && 'stable' === $status['phase'] && self::above_advice_threshold( $status['option_bytes'] );
	}

	private static function above_advice_threshold( $bytes ) {
		if ( ! is_string( $bytes ) && ! is_int( $bytes ) ) { return false; }
		$bytes = (string) $bytes;
		if ( ! preg_match( '/^[0-9]+$/D', $bytes ) ) { return false; }
		$bytes = ltrim( $bytes, '0' );
		if ( '' === $bytes ) { $bytes = '0'; }
		$threshold = (string) self::advice_threshold();
		return strlen( $bytes ) > strlen( $threshold ) || ( strlen( $bytes ) === strlen( $threshold ) && strcmp( $bytes, $threshold ) >= 0 );
	}

	private static function format_bytes( $bytes ) {
		if ( ! is_string( $bytes ) && ! is_int( $bytes ) ) { return __( 'Unavailable', 'foogallery-migrate' ); }
		if ( ! preg_match( '/^[0-9]+$/D', (string) $bytes ) ) { return __( 'Unavailable', 'foogallery-migrate' ); }
		$size = (float) $bytes; $units = array( 'B', 'KB', 'MB', 'GB', 'TB' ); $unit = 0;
		while ( $size >= 1024 && $unit < 4 ) { $size /= 1024; $unit++; }
		$decimals = $unit > 0 && $size < 10 && floor( $size ) !== $size ? 1 : 0;
		$size = round( $size, $decimals );
		return ( function_exists( 'number_format_i18n' ) ? number_format_i18n( $size, $decimals ) : number_format( $size, $decimals ) ) . ' ' . $units[$unit];
	}

	/** Informational metadata only: never hydrate the legacy state or run DDL. */
	public static function status( $include_schema = true ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Storage access denied.', 'foogallery-migrate' ) ); }
		global $wpdb;
		$blog = get_current_blog_id();
		$s = array( 'backend' => 'unavailable', 'phase' => 'unavailable', 'target' => 'option', 'retained' => false, 'discardable' => false, 'option_bytes' => null, 'records' => null, 'schema' => 'not provisioned', 'problem' => '', 'calibrated' => false );
		try {
			$control = new StorageControl( $wpdb, $blog );
			$raw = $control->read( StorageAuthority::CONTROL_OPTION );
			$r = null === $raw ? null : StorageControl::decode( $raw, $blog );
			$runtime = StorageAuthority::runtime_record();
			if ( is_wp_error( $runtime ) && ( null === $r || 'stable' === $r['phase'] ) ) { throw new \RuntimeException( $runtime->get_error_message() ); }
			$s['backend'] = null === $r ? 'option' : $r['backend'];
			$s['phase'] = null === $r ? 'stable' : $r['phase']; $s['target'] = null === $r ? 'option' : $r['to'];
			$s['discardable'] = null !== $r && 4 === $r['version'] && 'copying' === $r['phase'] && 'option' === $r['backend'] && 'table' === $r['to'];
			$s['retained'] = null !== $r && in_array( $r['version'], array( 2, 4 ), true ) && 'stable' === $r['phase'] && 'table' === $r['from'] && 'option' === $r['to'];
			$bytes = $wpdb->get_var( $wpdb->prepare( 'SELECT OCTET_LENGTH(option_value) FROM `' . $wpdb->prefix . 'options` WHERE option_name=%s LIMIT 1', FOOGALLERY_MIGRATE_OPTION_DATA ) );
			if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Storage size is unavailable.', 'foogallery-migrate' ) ); }
			$s['option_bytes'] = null === $bytes ? '0' : $bytes;
			if ( $include_schema ) {
				$schema = new LegacyStorageSchema( $wpdb );
				try {
					$main = is_multisite() ? get_main_site_id( get_main_network_id() ) : $blog;
					$metadata = $wpdb->get_blog_prefix( $main ) . 'options';
					// PHP5's native SQL object result loses numeric property names when
					// wpdb projects them through get_object_vars(). Keep the exact byte
					// ownership predicate, but give its literal result a named column.
					if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $metadata ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS owned FROM %i WHERE option_name=%s AND BINARY option_value=BINARY %s', $metadata, LegacyStorageSchema::VERSION_OPTION, (string) LegacyStorageSchema::VERSION ) ) || $wpdb->last_error ) { throw new \RuntimeException( 'Shared schema ownership is not verified.' ); }
					$schema->verify(); $s['schema'] = 'verified';
					$tables = array_keys( $schema->definitions() ); $table = $tables[1];
					$s['records'] = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE blog_id=%d', $table, $blog ) );
					if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Record count is unavailable.', 'foogallery-migrate' ) ); }
				} catch ( \RuntimeException $error ) {
					$s['schema'] = 'not ready';
					if ( null !== $r ) { $s['problem'] = __( 'Shared tables need attention. No automatic repair or cleanup has been attempted.', 'foogallery-migrate' ); }
				}
			}
			$s['calibrated'] = ! is_wp_error( StorageCapacityPolicy::resolve() );
		} catch ( \Exception $error ) { $s['backend'] = 'unavailable'; $s['problem'] = $error->getMessage(); }
		catch ( \Throwable $error ) { $s['backend'] = 'unavailable'; $s['problem'] = __( 'Storage health could not be read. Current authority has not been changed.', 'foogallery-migrate' ); }
		return $s;
	}

	/** Display choices never authorize a mutation; the receiver revalidates all. */
	public static function actions( $s, $pending_scope = null ) {
		$remove_all = array( 'operation' => 'remove_all', 'target' => 'all', 'confirmation' => 'remove-all-internal-data',
			'purpose' => 'remove_all', 'label' => __( 'Remove all FooGallery Migrate internal data (keep galleries and media)', 'foogallery-migrate' ) );
		$clear = array( 'operation' => 'clear', 'target' => 'state', 'confirmation' => 'clear-current-state',
			'purpose' => 'clear', 'label' => __( 'Clear migration state (this may allow duplicate galleries and media)', 'foogallery-migrate' ) );
		if ( is_multisite() ) { return array(); }
		if ( 1 === func_num_args() ) { $pending_scope = StorageCleanup::pending_scope(); }
		if ( 'remove_all' === $pending_scope ) { return array( $remove_all ); }
		if ( 'clear' === $pending_scope ) { $clear['label'] = __( 'Continue clearing migration state', 'foogallery-migrate' ); return array( $clear ); }
		if ( ! in_array( $s['backend'], array( 'option', 'table' ), true ) ) { return array( $remove_all ); }
		if ( empty( $s['calibrated'] ) ) { return array( $clear, $remove_all ); }
		if ( 'stable' !== $s['phase'] ) {
			$a = array( array( 'operation' => 'recover', 'target' => $s['target'], 'confirmation' => 'recover-storage', 'purpose' => 'recover', 'label' => __( 'Recover recorded switch', 'foogallery-migrate' ) ) );
			if ( ! empty( $s['discardable'] ) ) { $a[] = array( 'operation' => 'discard', 'target' => 'table', 'confirmation' => 'discard-copy', 'purpose' => 'discard', 'label' => __( 'Discard this incomplete table copy (keep current options)', 'foogallery-migrate' ) ); }
			$a[] = $remove_all;
			return $a;
		}
		if ( 'table' === $s['backend'] ) { return array( $clear, array( 'operation' => 'switch', 'target' => 'option', 'confirmation' => 'switch-to-option', 'purpose' => 'table_export', 'label' => __( 'Switch this site to options', 'foogallery-migrate' ) ), $remove_all ); }
		if ( $s['retained'] ) { return array( $clear, array( 'operation' => 'replace', 'target' => 'table', 'confirmation' => 'replace-retained-with-table', 'purpose' => 'option_reimport', 'label' => __( 'Replace retained table copy and switch this site to tables', 'foogallery-migrate' ) ), $remove_all ); }
		return array( $clear, array( 'operation' => 'switch', 'target' => 'table', 'confirmation' => 'switch-to-table', 'purpose' => 'option_import', 'label' => __( 'Switch this site to tables', 'foogallery-migrate' ) ), $remove_all );
	}

	private static function render_action( $a, $s, $blog, $hidden = false ) {
		$labels = array( 'clear' => __( 'Reset migration progress', 'foogallery-migrate' ),
			'remove_all' => __( 'Remove all migration data', 'foogallery-migrate' ),
			'recover' => __( 'Continue storage change', 'foogallery-migrate' ),
			'discard' => __( 'Cancel storage change', 'foogallery-migrate' ) );
		$confirmations = array(
			'clear' => __( 'Reset migration progress and history? Galleries and media will be kept. Migrating again may create duplicates.', 'foogallery-migrate' ),
			'remove_all' => __( 'Remove all migration data, plugin settings and storage tables? Galleries and media will be kept. Migrating again may create duplicates.', 'foogallery-migrate' ),
			'discard' => __( 'Cancel this unfinished storage change? Current storage and migration data will be kept.', 'foogallery-migrate' ),
		);
		if ( 'clear' === $a['operation'] && ! empty( $s['cleanup_pending'] ) ) { $labels['clear'] = __( 'Continue cleanup', 'foogallery-migrate' ); }
		$to_tables = 'table' === $a['target'] && in_array( $a['operation'], array( 'switch', 'replace' ), true );
		echo '<form class="foogm-storage-form" id="foogm-storage-' . esc_attr( $a['operation'] ) . '" method="post" action="' . esc_attr( admin_url( 'admin-post.php' ) ) . '"' . ( $hidden ? ' hidden' : '' );
		if ( isset( $confirmations[$a['operation']] ) ) { echo ' data-confirm="' . esc_attr( $confirmations[$a['operation']] ) . '"'; }
		if ( $to_tables ) {
			echo ' data-preflight-nonce="' . esc_attr( wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) ) . '"';
			if ( 'verified' !== $s['schema'] ) { echo ' data-provision-nonce="' . esc_attr( wp_create_nonce( 'foogallery_migrate_storage_provision_schema_' . $blog ) ) . '"'; }
		}
		if ( in_array( $a['operation'], array( 'switch', 'replace', 'recover' ), true ) ) { echo ' data-recover-nonce="' . esc_attr( wp_create_nonce( 'foogallery_migrate_storage_recover_' . $blog ) ) . '"'; }
		echo '>';
		foreach ( array( 'action' => 'foogallery_migrate_storage', 'operation' => $a['operation'], 'blog_id' => $blog, 'scope' => 'site', 'target' => $a['target'], 'confirmation' => $a['confirmation'] ) as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		wp_nonce_field( 'foogallery_migrate_storage_' . $a['purpose'] . '_' . $blog, 'nonce', false );
		$label = isset( $labels[$a['operation']] ) ? $labels[$a['operation']] : __( 'Change storage', 'foogallery-migrate' );
		echo '<p><button type="submit" class="button ' . ( 'recover' === $a['operation'] ? 'button-primary' : 'button-secondary' ) . '">' . esc_html( $label ) . '</button></p></form>';
	}

	public static function render( $status = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Storage access denied.', 'foogallery-migrate' ) ); }
		$s = is_array( $status ) ? $status : self::status();
		$blog = get_current_blog_id();
		$pending = is_multisite() ? null : StorageCleanup::pending_scope();
		$s['cleanup_pending'] = $pending;
		$actions = self::actions( $s, $pending ); $change = null;
		foreach ( $actions as $a ) {
			if ( '' === $s['problem'] && in_array( $a['operation'], array( 'switch', 'replace' ), true ) ) { $change = $a; }
		}
		echo '<section id="migration-storage" aria-labelledby="migration-storage-heading"><table class="form-table" role="presentation"><tbody><tr><th scope="row" id="migration-storage-heading">' . esc_html__( 'Migration Storage', 'foogallery-migrate' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'Choose where migration progress and history are stored.', 'foogallery-migrate' ) . '</p>';
		if ( null !== $change ) {
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Storage mode', 'foogallery-migrate' ) . '</legend>';
			foreach ( array(
				'option' => array( __( 'WordPress options', 'foogallery-migrate' ), __( 'Default storage for smaller migrations.', 'foogallery-migrate' ) ),
				'table' => array( __( 'Database tables', 'foogallery-migrate' ), __( 'Recommended for larger migrations to reduce memory use.', 'foogallery-migrate' ) ),
			) as $mode => $copy ) {
				echo '<p><label><input type="radio" name="foogm-storage-mode" value="' . esc_attr( $mode ) . '"' . ( $mode === $s['backend'] ? ' checked' : '' ) . '> <strong>' . esc_html( $copy[0] ) . '</strong><br><span class="description">' . esc_html( $copy[1] ) . '</span></label></p>';
			}
			echo '</fieldset>';
			self::render_action( $change, $s, $blog, true );
			echo '<p><button id="foogm-storage-change" type="submit" form="foogm-storage-' . esc_attr( $change['operation'] ) . '" class="button button-primary" disabled>' . esc_html__( 'Change storage', 'foogallery-migrate' ) . '</button> <span id="foogm-storage-spinner" class="spinner" style="float:none" aria-hidden="true"></span></p>';
		} else {
			$mode_name = 'table' === $s['backend'] ? __( 'Database tables', 'foogallery-migrate' ) : ( 'option' === $s['backend'] ? __( 'WordPress options', 'foogallery-migrate' ) : __( 'Unavailable', 'foogallery-migrate' ) );
			echo '<p>' . esc_html__( 'Current storage:', 'foogallery-migrate' ) . ' <strong>' . esc_html( $mode_name ) . '</strong></p>';
		}
		if ( 'table' === $s['backend'] && null !== $s['records'] ) {
			echo '<p class="description">' . esc_html__( 'Migration records:', 'foogallery-migrate' ) . ' ' . esc_html( $s['records'] ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Migration data:', 'foogallery-migrate' ) . ' ' . esc_html( self::format_bytes( $s['option_bytes'] ) ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Changing storage does not change your galleries or media.', 'foogallery-migrate' ) . '</p>';
		$recommendation = self::recommendation_message( $s );
		if ( '' !== $recommendation ) { echo '<p>' . esc_html( $recommendation ) . '</p>'; }
		if ( is_multisite() ) {
			echo '<p>' . esc_html__( 'Table storage is not available on multisite. Continue using WordPress options.', 'foogallery-migrate' ) . '</p>';
		} elseif ( ! empty( $pending ) ) {
			echo '<p>' . esc_html__( 'Migration data cleanup is unfinished. Continue it in Troubleshooting.', 'foogallery-migrate' ) . '</p>';
		} elseif ( ! in_array( $s['backend'], array( 'option', 'table' ), true ) || '' !== $s['problem'] ) {
			echo '<p role="alert">' . esc_html__( 'Storage needs attention. See Troubleshooting for details.', 'foogallery-migrate' ) . '</p>';
		} elseif ( empty( $s['calibrated'] ) ) {
			echo '<p>' . esc_html__( 'Storage switching is not available on this site. See Troubleshooting for details.', 'foogallery-migrate' ) . '</p>';
		} elseif ( 'stable' !== $s['phase'] ) {
			echo '<p>' . esc_html__( 'A storage change is unfinished. Continue it in Troubleshooting.', 'foogallery-migrate' ) . '</p>';
		}
		echo '<p id="foogm-storage-result" role="status" aria-live="polite"></p>';
		echo '</td></tr></tbody></table></section>';
		self::render_script( $s, $blog );
	}

	/** This screen deliberately needs no engine, history or source-object hydration. */
	public static function render_troubleshooting( $status = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Storage access denied.', 'foogallery-migrate' ) ); }
		$s = is_array( $status ) ? $status : self::status();
		$blog = get_current_blog_id();
		$pending = is_multisite() ? null : StorageCleanup::pending_scope();
		$s['cleanup_pending'] = $pending;
		$actions = self::actions( $s, $pending );
		echo '<p>' . esc_html__( 'If you are having trouble with a migration, try the following:', 'foogallery-migrate' ) . '</p><ul class="ul-disc">';
		foreach ( array(
			__( 'If a migration stops, pause any other migrations and refresh the page before continuing.', 'foogallery-migrate' ),
			__( 'If you see a timeout or memory error, reduce Images Per Turn in Settings and keep Page Size above zero.', 'foogallery-migrate' ),
			__( 'If you are migrating a lot of galleries or images, try changing your migration storage to use tables', 'foogallery-migrate' ),
		) as $tip ) { echo '<li>' . esc_html( $tip ) . '</li>'; }
		echo '</ul>';
		$cleanup = array(
			'clear' => array( __( 'Reset migration progress', 'foogallery-migrate' ), __( 'Clear progress and history while keeping the selected storage mode.', 'foogallery-migrate' ) ),
			'remove_all' => array( __( 'Remove all migration data', 'foogallery-migrate' ), __( 'Remove plugin settings, progress, history and owned storage tables.', 'foogallery-migrate' ) ),
			'discard' => array( __( 'Cancel storage change', 'foogallery-migrate' ), __( 'Discard the unfinished table copy and keep current option data.', 'foogallery-migrate' ) ),
		);
		echo '<section id="foogm-storage-cleanup" aria-labelledby="foogm-storage-cleanup-heading"><h3 id="foogm-storage-cleanup-heading">' . esc_html__( 'Reset & cleanup', 'foogallery-migrate' ) . '</h3><p>' . esc_html__( 'Galleries and media are kept. Migrating again after a reset or removal may create duplicates.', 'foogallery-migrate' ) . '</p>';
		if ( null !== $pending ) {
			echo '<p>' . esc_html__( 'Cleanup is unfinished. Continue the recorded cleanup below.', 'foogallery-migrate' ) . '</p>';
		} elseif ( '' !== $s['problem'] || ! in_array( $s['backend'], array( 'option', 'table' ), true ) ) {
			echo '<p role="alert">' . esc_html__( 'Storage needs attention. Review the details below before continuing a migration.', 'foogallery-migrate' ) . '</p>';
		} elseif ( 'stable' !== $s['phase'] ) {
			echo '<p>' . esc_html__( 'A storage change is unfinished. Pause migration tabs, then continue the recorded change.', 'foogallery-migrate' ) . '</p>';
		}
		foreach ( $actions as $a ) { if ( 'recover' === $a['operation'] ) { self::render_action( $a, $s, $blog ); } }
		echo '<p id="foogm-storage-result" role="status" aria-live="polite"></p>';
		if ( '' !== $s['problem'] ) { echo '<p>' . esc_html( $s['problem'] ) . '</p>'; }
		echo '<details id="foogm-storage-last-error" hidden><summary>' . esc_html__( 'Last storage request (this browser)', 'foogallery-migrate' ) . '</summary><p id="foogm-storage-error-detail"></p></details>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$offered = false;
		foreach ( $actions as $a ) {
			if ( ! isset( $cleanup[$a['operation']] ) ) { continue; }
			$copy = $cleanup[$a['operation']]; $offered = true;
			echo '<tr><th scope="row">' . esc_html( $copy[0] ) . '</th><td><p>' . esc_html( $copy[1] ) . '</p>';
			self::render_action( $a, $s, $blog ); echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! $offered ) { echo '<p>' . esc_html__( 'These storage cleanup controls are not available on this site.', 'foogallery-migrate' ) . '</p>'; }
		echo '</section>';
		global $wp_version;
		$unknown = __( 'Unavailable', 'foogallery-migrate' );
		$saved = StorageDiscoverySummary::read();
		$counts = null === $saved ? StorageSourceCounts::measure() : array( 'galleries' => $saved['galleries'], 'images' => $saved['images'] );
		$rows = array(
			__( 'Plugin version', 'foogallery-migrate' ) => defined( 'FOOGM_VERSION' ) ? FOOGM_VERSION : $unknown,
			__( 'WordPress version', 'foogallery-migrate' ) => isset( $wp_version ) ? $wp_version : $unknown,
			__( 'PHP version', 'foogallery-migrate' ) => PHP_VERSION,
			__( 'PHP memory limit', 'foogallery-migrate' ) => ini_get( 'memory_limit' ),
			__( 'Site type', 'foogallery-migrate' ) => is_multisite() ? __( 'Multisite (options only)', 'foogallery-migrate' ) : __( 'Single site', 'foogallery-migrate' ),
			__( 'Site ID', 'foogallery-migrate' ) => $blog,
			__( 'Backend', 'foogallery-migrate' ) => $s['backend'],
			__( 'Switch phase', 'foogallery-migrate' ) => $s['phase'],
			__( 'Shared schema', 'foogallery-migrate' ) => $s['schema'],
			__( 'Option bytes', 'foogallery-migrate' ) => $s['option_bytes'],
			__( 'Site table records (including retained copies)', 'foogallery-migrate' ) => $s['records'],
			( null === $saved ? __( 'Detected gallery records', 'foogallery-migrate' ) : __( 'Discovered galleries', 'foogallery-migrate' ) ) => null === $counts['galleries'] ? null : self::format_count( $counts['galleries'] ),
			( null === $saved ? __( 'Detected image records (partial estimate)', 'foogallery-migrate' ) : ( $saved['images_complete'] ? __( 'Image entries', 'foogallery-migrate' ) : __( 'Known image entries (partial)', 'foogallery-migrate' ) ) ) => null === $counts['images'] ? null : self::format_count( $counts['images'] ),
		);
		echo '<section id="foogm-storage-details"><h3>' . esc_html__( 'Technical details', 'foogallery-migrate' ) . '</h3><table class="widefat striped"><tbody>';
		$report = array();
		foreach ( $rows as $label => $value ) {
			$value = null === $value || false === $value || '' === $value ? $unknown : $value;
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			$report[] = $label . ': ' . $value;
		}
		echo '</tbody></table>';
		if ( ! empty( $s['retained'] ) ) { echo '<p>' . esc_html__( 'An inactive table copy is retained. Switching to tables replaces it with current migration data.', 'foogallery-migrate' ) . '</p>'; }
		if ( null !== $saved ) {
			/* translators: %s: UTC date and time when gallery discovery counts were saved. */
			$refreshed = sprintf( __( 'Discovery counts saved: %s UTC.', 'foogallery-migrate' ), gmdate( 'Y-m-d H:i', $saved['refreshed_at'] ) );
			echo '<p class="description">' . esc_html( $refreshed ) . '</p><p class="description">' . esc_html__( 'Counts come from saved gallery discovery, not unique media or remaining migration work. Use Refresh Galleries to update them.', 'foogallery-migrate' ) . '</p>';
			$report[] = $refreshed;
		} else {
			echo '<p class="description">' . esc_html__( 'Source counts are advisory estimates, not the selected migration queue. Serialized image lists and unscanned WordPress galleries are not loaded to calculate counts.', 'foogallery-migrate' ) . '</p>';
		}
		echo '</section>';
		echo '<details><summary>' . esc_html__( 'Information for support', 'foogallery-migrate' ) . '</summary><p><label for="foogm-support-report">' . esc_html__( 'Copy this summary when contacting support. It contains no gallery content, credentials or server paths.', 'foogallery-migrate' ) . '</label></p><textarea id="foogm-support-report" class="large-text code" rows="10" readonly>' . esc_textarea( implode( "\n", $report ) ) . '</textarea></details>';
		self::render_script( $s, $blog );
	}

	private static function render_script( $s, $blog ) {
		?>
		<script>
		(function () {
			var forms = document.querySelectorAll('.foogm-storage-form'), result = document.getElementById('foogm-storage-result'), busy = false;
			var radios = document.querySelectorAll('input[name="foogm-storage-mode"]'), change = document.getElementById('foogm-storage-change'), spinner = document.getElementById('foogm-storage-spinner'), errorDetail = document.getElementById('foogm-storage-error-detail');
			var current = <?php echo wp_json_encode( $s['backend'] ); ?>, noticeKey = <?php echo wp_json_encode( 'foogm-storage-notice-' . $blog ); ?>, errorKey = <?php echo wp_json_encode( 'foogm-storage-error-' . $blog ); ?>;
			var lastError = document.getElementById('foogm-storage-last-error');
			try { var notice = window.sessionStorage.getItem(noticeKey); window.sessionStorage.removeItem(noticeKey); if (notice) { result.textContent = notice; } } catch (ignored) {}
			function rememberError(message) {
				try { window.sessionStorage.setItem(errorKey, message); } catch (ignored) {}
				if (errorDetail) { errorDetail.textContent = message; }
				if (lastError) { lastError.hidden = !message; }
			}
			try { var previousError = window.sessionStorage.getItem(errorKey); if (previousError && errorDetail) { errorDetail.textContent = previousError; if (lastError) { lastError.hidden = false; } } } catch (ignored) {}
			function updateSelection() {
				if (!change) { return; }
				var selected = current;
				for (var r = 0; r < radios.length; r++) { if (radios[r].checked) { selected = radios[r].value; } }
				change.disabled = busy || selected === current;
			}
			for (var r = 0; r < radios.length; r++) { radios[r].addEventListener('change', updateSelection); }
			updateSelection();
			function setBusy(value) {
				busy = value;
				for (var n = 0; n < forms.length; n++) { forms[n].querySelector('button').disabled = value; }
				for (var r = 0; r < radios.length; r++) { radios[r].disabled = value; }
				if (spinner) { spinner.classList.toggle('is-active', value); }
				updateSelection();
			}
			function requireReload(message) {
				result.textContent = message;
				rememberError(message);
				if (spinner) { spinner.classList.toggle('is-active', false); }
				// Keep controls locked when the response cannot prove the current phase.
			}
			for (var i = 0; i < forms.length; i++) { forms[i].addEventListener('submit', function (event) {
				event.preventDefault();
				if (busy) { return; }
				var form = this, steps = [], turns = 0, recovery = form.getAttribute('data-recover-nonce'), preflight = form.getAttribute('data-preflight-nonce'), provision = form.getAttribute('data-provision-nonce');
				var confirmation = form.getAttribute('data-confirm');
				if (confirmation && !window.confirm(confirmation)) { return; }
				if (preflight) { steps.push({ operation: 'preflight', scope: 'site', confirmation: 'switch-to-table', nonce: preflight }); }
				if (provision) { steps.push({ operation: 'provision', scope: 'installation', confirmation: 'provision-shared-schema', nonce: provision }); }
				steps.push(null); setBusy(true);
				function sendStep() {
					var step = steps.shift(), fields = new FormData(form), xhr = new XMLHttpRequest();
					if (step) {
						// Each request retains the receiver's exact seven fields and purpose-specific nonce.
						fields.set('operation', step.operation); fields.set('scope', step.scope); fields.set('confirmation', step.confirmation); fields.set('nonce', step.nonce);
					}
					result.textContent = recovery ? <?php echo wp_json_encode( __( 'Changing storage…', 'foogallery-migrate' ) ); ?> : <?php echo wp_json_encode( __( 'Updating migration data…', 'foogallery-migrate' ) ); ?>;
					xhr.open('POST', form.getAttribute('action')); xhr.timeout = 30000;
					xhr.onload = function () {
						var data, detail;
						try { data = JSON.parse(xhr.responseText); if (!data || typeof data.success !== 'boolean' || !data.data || typeof data.data !== 'object') { throw new Error('Invalid response'); } detail = data.data; }
						catch (error) { requireReload(<?php echo wp_json_encode( __( 'The response could not be read. Reload to check storage before continuing.', 'foogallery-migrate' ) ); ?>); return; }
						if (xhr.status >= 200 && xhr.status < 300 && data.success && detail.complete !== false) {
							if (recovery && (!step || step.operation === 'recover')) {
								if (String(detail.blog_id) !== fields.get('blog_id') || ['option', 'table'].indexOf(detail.backend) === -1 || ['stable', 'copying', 'verified', 'flipped'].indexOf(detail.phase) === -1 || (detail.phase === 'stable' && detail.backend !== fields.get('target'))) {
									requireReload(<?php echo wp_json_encode( __( 'Storage returned an unexpected status. Reload to check progress before continuing.', 'foogallery-migrate' ) ); ?>); return;
								}
								if (detail.phase !== 'stable') {
									if (++turns >= 256) { requireReload(<?php echo wp_json_encode( __( 'Storage has saved its progress. Reload and choose Continue storage change.', 'foogallery-migrate' ) ); ?>); return; }
									steps.push({ operation: 'recover', scope: 'site', confirmation: 'recover-storage', nonce: recovery });
								}
							}
							if (steps.length) { sendStep(); } else {
								try { window.sessionStorage.removeItem(errorKey); } catch (ignored) {}
								try { window.sessionStorage.setItem(noticeKey, recovery ? <?php echo wp_json_encode( __( 'Storage updated.', 'foogallery-migrate' ) ); ?> : <?php echo wp_json_encode( __( 'Migration data updated.', 'foogallery-migrate' ) ); ?>); } catch (ignored) {}
								window.location.reload();
							}
							return;
						}
						result.textContent = detail.code === 'foogallery_migrate_storage_busy' ? <?php echo wp_json_encode( __( 'Storage is busy. Pause any running migration, then reload to check progress.', 'foogallery-migrate' ) ); ?> : <?php echo wp_json_encode( __( 'The operation could not finish. Reload to check progress. More information is available in Troubleshooting.', 'foogallery-migrate' ) ); ?>;
						rememberError((detail.code || '') + ' ' + (detail.message || '') + (Array.isArray(detail.failed) ? ' ' + detail.failed.join(', ') : '') + (Array.isArray(detail.residual) ? ' ' + detail.residual.join(', ') : ''));
						setBusy(false);
					};
					xhr.onerror = xhr.ontimeout = function () { requireReload(<?php echo wp_json_encode( __( 'The connection ended. Reload to check storage before continuing.', 'foogallery-migrate' ) ); ?>); };
					xhr.send(fields);
				}
				// No retry on errors, timeouts or malformed responses. Opening settings never calls this.
				sendStep();
			}); }
		}());
		</script>
		<?php
	}
}
