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
					$('#foogallery_migrate_album_form').html(data);
				},
				error: function() {
					//something went wrong! Alert the user and reload the page
					window.alert(albumImportErrorMessage);
					location.reload();
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
					$('#foogallery_migrate_shortcodes_container').html(data);
				},
				complete: function() {
					$('#foogallery_migrate_shortcodes .spinner').removeClass('is-active');
				},
				error: function() {
					//something went wrong! Alert the user and reload the page
					window.alert(findShortcodesErrorMessage);
					location.reload();
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
					$('#foogallery_migrate_shortcodes_container').html(data);
				},
				complete: function() {
					$('#foogallery_migrate_shortcodes .spinner').removeClass('is-active');
				},
				error: function() {
					//something went wrong! Alert the user and reload the page
					window.alert(replaceShortcodesErrorMessage);
					location.reload();
				}
			});
		});

		$('.foo-nav-tabs').on('click', 'a', function (e) {
			$('.foogallery_migrate_container').hide();
			var tab = $(this).data('tab');
			$('#' + tab).show();
			$('.nav-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');
		});

		if (window.location.hash) {
			$('.foo-nav-tabs a[href="' + window.location.hash + '"]').click();
		}
	});
</script>
<?php
$migrator = foogallery_migrate_migrator_instance();
$has_log_tab = $migrator->has_migrated_objects();
$show_debug_tab = $has_log_tab && function_exists( 'foogallery_is_debug' ) && foogallery_is_debug();
?>
<div class="wrap">
	<h2><?php esc_html_e( 'FooGallery Migrate!', 'foogallery-migrate' ); ?></h2>

	<h2 class="foo-nav-tabs nav-tab-wrapper">
		<a href="#sources" data-tab="foogallery_migrate_sources" class="nav-tab nav-tab-active"><?php esc_html_e( 'Plugins', 'foogallery-migrate' ); ?></a>
		<a href="#galleries" data-tab="foogallery_migrate_galleries" class="nav-tab"><?php esc_html_e( 'Galleries', 'foogallery-migrate' ); ?></a>
		<a href="#albums" data-tab="foogallery_migrate_albums" class="nav-tab"><?php esc_html_e( 'Albums', 'foogallery-migrate' ); ?></a>
		<a href="#shortcodes" data-tab="foogallery_migrate_content" class="nav-tab"><?php esc_html_e( 'Blocks / Shortcodes', 'foogallery-migrate' ); ?></a>
		<?php if ( $has_log_tab ) { ?>
			<a href="#log" data-tab="foogallery_migrate_log" class="nav-tab"><?php esc_html_e( 'Log', 'foogallery-migrate' ); ?></a>
		<?php } ?>
		<?php if ( $show_debug_tab ) { ?>
			<a href="#debug" data-tab="foogallery_migrate_debug" class="nav-tab"><?php esc_html_e( 'Debug', 'foogallery-migrate' ); ?></a>
		<?php } ?>
	</h2>
    <div class="foogallery_migrate_container" id="foogallery_migrate_sources">
        <?php require_once 'view-migrate-tab-sources.php'; ?>
    </div>
	<div class="foogallery_migrate_container" id="foogallery_migrate_galleries" style="display: none">
        <?php require_once 'view-migrate-tab-galleries.php'; ?>
	</div>
	<div class="foogallery_migrate_container" id="foogallery_migrate_albums" style="display: none">
        <?php require_once 'view-migrate-tab-albums.php'; ?>
	</div>
	<div class="foogallery_migrate_container" id="foogallery_migrate_content" style="display: none">
        <?php require_once 'view-migrate-tab-content.php'; ?>
	</div>
	<?php if ( $has_log_tab ) { ?>
		<div class="foogallery_migrate_container" id="foogallery_migrate_log" style="display: none">
			<?php require_once 'view-migrate-tab-log.php'; ?>
		</div>
	<?php } ?>
	<?php if ( $show_debug_tab ) { ?>
		<div class="foogallery_migrate_container" id="foogallery_migrate_debug" style="display: none">
			<?php require_once 'view-migrate-tab-debug.php'; ?>
		</div>
	<?php } ?>
</div>
