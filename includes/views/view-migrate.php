<style>
	.foo-nav-tabs a:focus {
		-webkit-box-shadow: none;
		box-shadow: none;
	}

	.spinner.shown {
		display: inline !important;
		margin: 0;
	}

	.foogallery-migrate-progress-error {
		color: #f00 !important;
	}

	.foogallery-migrate-progress-not_started {
		color: #f60 !important;
	}

	.foogallery-migrate-progress-started {
		color: #f80 !important;
	}

	.foogallery-migrate-progress-completed {
		color: #080 !important;
	}

	.foogallery-migrate-progressbar {
		margin-top: 10px;
		display: inline-block;
		width: 500px;
		height: 10px;
		background: #ddd;
		position: relative;
	}

	.foogallery-migrate-progressbar span {
		position: absolute;
		height: 100%;
		left: 0;
		background: #888;
	}

	#foogallery_migrate_form .dashicons-arrow-right {
		font-size: 2em;
		margin-top: -0.2em;
	}

	.foogallery_migrate_container {
		margin-top: 10px;
	}

	.tablenav .tablenav-pages a,
	.tablenav .tablenav-pages span {
		margin: 0 3px;
		padding: 5px;
	}

	.tablenav-pages span {
		display: inline-block;
		min-width: 17px;
		border: 1px solid #d2d2d2;
		background: #e4e4e4;
		font-size: 16px;
		line-height: 1;
		font-weight: normal;
		text-align: center;
	}

	.tablenav-pages span.selected-page {
		border-color: #5b9dd9;
		color: #fff;
		background: #00a0d2;
		-webkit-box-shadow: none;
		box-shadow: none;
		outline: none;
	}

	.tablenav-pages span.disabled {
		color: #888;
	}

	.foogallery-help {
		margin-bottom: 10px;
	}

    .foogallery-migrate-table th {
        font-weight: 500;
    }

    .foogallery-migrate-table th, .foogallery-migrate-table td {
        padding: 1px;
        border: solid 1px #ddd;
    }

    .foogallery-migrate-table {
        border-collapse: collapse;
    }

</style>
<script>
	window.foogalleryMigrateAjaxErrorLabels = {
		action: <?php echo wp_json_encode( __( 'Action', 'foogallery-migrate' ) ); ?>,
		status: <?php echo wp_json_encode( __( 'HTTP status', 'foogallery-migrate' ) ); ?>,
		error: <?php echo wp_json_encode( __( 'Error', 'foogallery-migrate' ) ); ?>,
		response: <?php echo wp_json_encode( __( 'Response', 'foogallery-migrate' ) ); ?>,
		noDetails: <?php echo wp_json_encode( __( 'No response details were returned by the server.', 'foogallery-migrate' ) ); ?>,
		reload: <?php echo wp_json_encode( __( 'The page will now reload. If a migration was in progress, use Resume Migration to continue.', 'foogallery-migrate' ) ); ?>
	};

	window.foogalleryMigrateNormalizeAjaxDetails = function (value) {
		var text = '';

		if (value === null || value === undefined || value === '') {
			return '';
		}

		if (typeof value === 'object') {
			if (value.data) {
				if (typeof value.data === 'string') {
					return window.foogalleryMigrateNormalizeAjaxDetails(value.data);
				}
				if (value.data.message) {
					return window.foogalleryMigrateNormalizeAjaxDetails(value.data.message);
				}
				if (value.data.error) {
					return window.foogalleryMigrateNormalizeAjaxDetails(value.data.error);
				}
			}

			if (value.message) {
				return window.foogalleryMigrateNormalizeAjaxDetails(value.message);
			}

			if (value.error) {
				return window.foogalleryMigrateNormalizeAjaxDetails(value.error);
			}

			try {
				text = JSON.stringify(value);
			} catch (e) {
				text = String(value);
			}
		} else {
			text = String(value);
		}

		text = text.trim();

		if (text.charAt(0) === '{' || text.charAt(0) === '[') {
			try {
				return window.foogalleryMigrateNormalizeAjaxDetails(JSON.parse(text));
			} catch (e) {
				// Keep the raw response when it is not valid JSON.
			}
		}

		text = text
			.replace(/<script[\s\S]*?<\/script>/gi, ' ')
			.replace(/<style[\s\S]*?<\/style>/gi, ' ')
			.replace(/<[^>]+>/g, ' ')
			.replace(/&nbsp;/g, ' ')
			.replace(/&#039;/g, "'")
			.replace(/&quot;/g, '"')
			.replace(/&amp;/g, '&')
			.replace(/\s+/g, ' ')
			.trim();

		if (text.length > 1200) {
			text = text.substring(0, 1200) + '...';
		}

		return text;
	};

	window.foogalleryMigrateBuildAjaxErrorMessage = function (xhr, ajaxOptions, thrownError, fallbackMessage, action) {
		var labels = window.foogalleryMigrateAjaxErrorLabels;
		var lines = [ fallbackMessage ];
		var responseText = '';

		if (action) {
			lines.push(labels.action + ': ' + action);
		}

		if (xhr && xhr.status) {
			lines.push(labels.status + ': ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : ''));
		}

		if (thrownError) {
			lines.push(labels.error + ': ' + thrownError);
		} else if (ajaxOptions) {
			lines.push(labels.error + ': ' + ajaxOptions);
		}

		if (xhr) {
			responseText = window.foogalleryMigrateNormalizeAjaxDetails(xhr.responseJSON || xhr.responseText);
		}

		lines.push(labels.response + ': ' + (responseText || labels.noDetails));
		lines.push('');
		lines.push(labels.reload);

		return lines.join("\n");
	};

	window.foogalleryMigrateHandleAjaxError = function (xhr, ajaxOptions, thrownError, fallbackMessage, action) {
		var message = window.foogalleryMigrateBuildAjaxErrorMessage(xhr, ajaxOptions, thrownError, fallbackMessage, action);

		if (window.console && window.console.error) {
			window.console.error('FooGallery Migrate AJAX error', {
				action: action,
				status: xhr ? xhr.status : null,
				statusText: xhr ? xhr.statusText : null,
				responseText: xhr ? xhr.responseText : null,
				responseJSON: xhr ? xhr.responseJSON : null,
				thrownError: thrownError
			});
		}

		window.alert(message);
		location.reload();
	};

	window.foogalleryMigrateHandleAjaxResponse = function (data, fallbackMessage, action) {
		if (data && typeof data === 'object' && data.success === false) {
			window.foogalleryMigrateHandleAjaxError({
				status: 200,
				statusText: 'OK',
				responseJSON: data,
				responseText: ''
			}, 'error', '', fallbackMessage, action);
			return true;
		}

		return false;
	};

	jQuery(function ($) {
		var resetAlbumMessage = <?php echo wp_json_encode( __( 'Are you sure you want to reset all NextGen album import data? This may result in duplicate albums if you decide to import again!', 'foogallery-migrate' ) ); ?>;
		var albumImportErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with the import and the page will now reload.', 'foogallery-migrate' ) ); ?>;
		var findShortcodesErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with finding shortcodes, so the page will now reload.', 'foogallery-migrate' ) ); ?>;
		var replaceShortcodesErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with replacing shortcodes, so the page will now reload.', 'foogallery-migrate' ) ); ?>;

		$('#foogallery_migrate_album_form').on('click', '.reset_album_import', function (e) {
			if (!window.confirm(resetAlbumMessage)) {
				e.preventDefault();
				return false;
			}
		});

		$('#foogallery_migrate_album_form').on('click', '.start_album_import', function (e) {
			e.preventDefault();

			//show the spinner
			$(this).hide();
			var $tr = $(this).parents('tr:first');
			$tr.find('.spinner:first').addClass('is-active');

			var data = {
				action: 'foogallery_nextgen_album_import',
				foogallery_nextgen_album_import: $('#foogallery_nextgen_album_import').val(),
				nextgen_album_id: $tr.find('.foogallery-album-id').val(),
				foogallery_album_name: $tr.find('.foogallery-album-name').val()
			};

			$.ajax({
				type: "POST",
				url: ajaxurl,
				data: data,
				success: function(data) {
					if (window.foogalleryMigrateHandleAjaxResponse(data, albumImportErrorMessage, 'foogallery_nextgen_album_import')) {
						return;
					}
					$('#foogallery_migrate_album_form').html(data);
				},
				error: function(xhr, ajaxOptions, thrownError) {
					window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, albumImportErrorMessage, 'foogallery_nextgen_album_import');
				}
			});
		});

		$('#foogallery_migrate_shortcodes').on('click', '.find-shortcodes', function (e) {
			e.preventDefault();

			//show the spinner
			$('#foogallery_migrate_shortcodes .spinner').addClass('is-active');

			var data = {
				action: 'foogallery_nextgen_find_shortcodes',
				'_wpnonce' : $('#foogallery_nextgen_find_shortcodes').val()
			};

			$.ajax({
				type: "POST",
				url: ajaxurl,
				data: data,
				success: function(data) {
					if (window.foogalleryMigrateHandleAjaxResponse(data, findShortcodesErrorMessage, 'foogallery_nextgen_find_shortcodes')) {
						return;
					}
					$('#foogallery_migrate_shortcodes_container').html(data);
				},
				complete: function() {
					$('#foogallery_migrate_shortcodes .spinner').removeClass('is-active');
				},
				error: function(xhr, ajaxOptions, thrownError) {
					window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, findShortcodesErrorMessage, 'foogallery_nextgen_find_shortcodes');
				}
			});
		});

		$('#foogallery_migrate_shortcodes').on('click', '.replace-shortcodes', function (e) {
			e.preventDefault();

			//show the spinner
			$('#foogallery_migrate_shortcodes .spinner').addClass('is-active');

			var data = {
				action: 'foogallery_nextgen_replace_shortcodes',
				'_wpnonce' : $('#foogallery_nextgen_replace_shortcodes').val()
			};

			$.ajax({
				type: "POST",
				url: ajaxurl,
				data: data,
				success: function(data) {
					if (window.foogalleryMigrateHandleAjaxResponse(data, replaceShortcodesErrorMessage, 'foogallery_nextgen_replace_shortcodes')) {
						return;
					}
					$('#foogallery_migrate_shortcodes_container').html(data);
				},
				complete: function() {
					$('#foogallery_migrate_shortcodes .spinner').removeClass('is-active');
				},
				error: function(xhr, ajaxOptions, thrownError) {
					window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, replaceShortcodesErrorMessage, 'foogallery_nextgen_replace_shortcodes');
				}
			});
		});

	});
</script>
<?php
$migrator = foogallery_migrate_migrator_instance();
$has_log_tab = $migrator->has_migrated_objects();
$show_debug_tab = $has_log_tab && $migrator->is_debug_enabled();
$tabs = array(
	'sources'   => array(
		'label' => __( 'Plugins', 'foogallery-migrate' ),
		'view'  => 'view-migrate-tab-sources.php',
	),
	'galleries' => array(
		'label' => __( 'Galleries', 'foogallery-migrate' ),
		'view'  => 'view-migrate-tab-galleries.php',
	),
	'albums'    => array(
		'label' => __( 'Albums', 'foogallery-migrate' ),
		'view'  => 'view-migrate-tab-albums.php',
	),
	'content'   => array(
		'label' => __( 'Blocks / Shortcodes', 'foogallery-migrate' ),
		'view'  => 'view-migrate-tab-content.php',
	),
	'image-tags' => array(
		'label' => __( 'Image Tags', 'foogallery-migrate' ),
		'view'  => 'view-migrate-tab-image-tags.php',
	),
	'log'       => array(
		'label'   => __( 'Log', 'foogallery-migrate' ),
		'view'    => 'view-migrate-tab-log.php',
		'enabled' => $has_log_tab,
	),
	'debug'     => array(
		'label'   => __( 'Debug', 'foogallery-migrate' ),
		'view'    => 'view-migrate-tab-debug.php',
		'enabled' => $show_debug_tab,
	),
	'settings'  => array(
		'label' => __( 'Settings', 'foogallery-migrate' ),
		'view'  => 'view-migrate-tab-settings.php',
	),
);
$active_tab = 'sources';
if ( array_key_exists( 'tab', $_GET ) ) {
	$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
	if ( isset( $tabs[ $requested_tab ] ) && ( ! isset( $tabs[ $requested_tab ]['enabled'] ) || $tabs[ $requested_tab ]['enabled'] ) ) {
		$active_tab = $requested_tab;
	}
}
?>
<div class="wrap">
	<h2><?php esc_html_e( 'FooGallery Migrate!', 'foogallery-migrate' ); ?></h2>

	<h2 class="foo-nav-tabs nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_key => $tab ) { ?>
			<?php if ( isset( $tab['enabled'] ) && ! $tab['enabled'] ) { continue; } ?>
			<a href="<?php echo esc_url( foogallery_migrate_admin_url( $tab_key ) ); ?>" class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $tab['label'] ); ?></a>
		<?php } ?>
	</h2>
	<div class="foogallery_migrate_container" id="foogallery_migrate_<?php echo esc_attr( $active_tab ); ?>">
		<?php require_once $tabs[ $active_tab ]['view']; ?>
	</div>
</div>
