<?php
$migrator = new FooPlugins\FooGalleryMigrate\Migrator();
if ( isset( $_POST['foogallery_migrate_reset'] ) ) {

	if ( check_admin_referer( 'foogallery_migrate_reset', 'foogallery_migrate_reset' ) ) {
        $migrator->reset();
	}
//} else if ( isset( $_POST['foogallery_nextgen_reset_album'] ) ) {
//
//	if ( check_admin_referer( 'foogallery_nextgen_album_reset', 'foogallery_nextgen_album_reset' ) ) {
//		$nextgen->reset_album_import();
//	}
}
?>
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

</style>
<script>
	jQuery(function ($) {

		function nextgen_ajax(action, success_callback) {
			var data = jQuery("#foogallery_migrate_form").serialize();

			$.ajax({
				type: "POST",
				url: ajaxurl,
				data: data + "&action=" + action,
				success: success_callback,
				error: function() {
					//something went wrong! Alert the user and reload the page
					alert('<?php _e( 'Something went wrong with the import and the page will now reload. Once it has reloaded, click "Resume Import" to continue with the import.', 'foogallery-migrate' ); ?>');
					location.reload();
				}
			});
		}

		function foogallery_migrate_continue(dont_check_progress) {
			nextgen_ajax('foogallery_foogallery_migrate_refresh', function (data) {
				$('#foogallery_migrate_form').html(data);

				if (dont_check_progress != true) {
					//check if we need to carry on polling
					var percentage = parseInt($('#foogallery_migrate_progress').val());
					if (percentage < 100) {
						foogallery_migrate_continue();
					} else {
						foogallery_migrate_continue(true);
					}
				}
			});
		}

		$('#foogallery_migrate_form').on('click', '.start_import', function (e) {
			e.preventDefault();

			//show the spinner
			$('#foogallery_migrate_form .button').hide();
			$('#import_spinner .spinner').addClass('is-active');

			nextgen_ajax('foogallery_foogallery_migrate', function (data) {
				$('#foogallery_migrate_form').html(data);
				foogallery_migrate_continue();
			});
		});

		$('#foogallery_migrate_form').on('click', '.continue_import', function (e) {
			e.preventDefault();
			foogallery_migrate_continue();
		});

		$('#foogallery_migrate_form').on('click', '.cancel_import', function (e) {
			if (!confirm('<?php _e( 'Are you sure you want to cancel?', 'foogallery-migrate' ); ?>')) {
				e.preventDefault();
				return false;
			}
		});

		$('#foogallery_migrate_form').on('click', '.reset_import', function (e) {
			if (!confirm('<?php _e( 'Are you sure you want to reset all NextGen gallery import data? This may result in duplicate galleries and media attachments!', 'foogallery-migrate' ); ?>')) {
				e.preventDefault();
				return false;
			}
		});

		$('#foogallery_migrate_album_form').on('click', '.reset_album_import', function (e) {
			if (!confirm('<?php _e( 'Are you sure you want to reset all NextGen album import data? This may result in duplicate albums if you decide to import again!', 'foogallery-migrate' ); ?>')) {
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
					alert('<?php _e( 'Something went wrong with the import and the page will now reload.', 'foogallery-migrate' ); ?>');
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
					alert('<?php _e( 'Something went wrong with finding shortcodes, so the page will now reload.', 'foogallery-migrate' ); ?>');
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
					alert('<?php _e( 'Something went wrong with replacing shortcodes, so the page will now reload.', 'foogallery-migrate' ); ?>');
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
<div class="wrap about-wrap">
	<?php
	$galleries = $nextgen->get_galleries();
	$albums = $nextgen->get_albums();
	$gallery_count = '';
	if ( count( $galleries ) > 0 ) {
		$gallery_count = ' (' . count( $galleries ) . ')';
	}
	$album_count = '';
	if ( count( $albums ) > 0 ) {
		$album_count = ' (' . count( $albums ) . ')';
	}
	?>

	<h2><?php _e( 'FooGallery Migrate!', 'foogallery-migrate' ); ?></h2>

	<h2 class="foo-nav-tabs nav-tab-wrapper">
        <a href="#sources" data-tab="foogallery_migrate_sources" class="nav-tab nav-tab-active"><?php _e('Sources', 'foogallery-migrate'); ?></a>
		<a href="#galleries" data-tab="foogallery_migrate_galleries" class="nav-tab"><?php _e('Galleries', 'foogallery-migrate'); ?></a>
		<a href="#albums" data-tab="foogallery_migrate_albums" class="nav-tab"><?php _e('Albums', 'foogallery-migrate'); ?></a>
		<a href="#shortcodes" data-tab="foogallery_migrate_shortcodes" class="nav-tab"><?php _e('Shortcodes', 'foogallery-migrate'); ?></a>
	</h2>
    <div class="foogallery_migrate_container" id="foogallery_migrate_sources">
        <?php require_once 'view-migrate-tab-sources.php'; ?>
    </div>
	<div class="foogallery_migrate_container" id="foogallery_migrate_galleries">
	<?php
	if ( ! $galleries ) {
		_e( 'There are no galleries to import!', 'foogallery-migrate' );
	} else { ?>
		<div class="foogallery-help">
			<?php _e( 'Importing galleries is really simple:', 'foogallery-migrate' ); ?>
			<ol>
				<li><?php printf( __( 'Choose the galleries you want to import into %s by checking their checkboxes.', 'foogallery-migrate' ), foogallery_plugin_name() ); ?></li>
				<li><?php _e( 'Click the Start Import button to start the import process.', 'foogallery-migrate' ); ?></li>
				<li><?php printf( __( 'Once a gallery is imported, you can click on the link under the %s Name column to edit the gallery.', 'foogallery-migrate' ), foogallery_plugin_name() ); ?></li>
			</ol>
			<?php _e('Please note: importing large galleries with lots of images can take a while!', 'foogallery-migrate' ); ?>
		</div>

		<form id="foogallery_migrate_form" method="POST">
			<?php $nextgen->render_import_form( $galleries ); ?>
		</form>
	<?php } ?>
	</div>
	<div class="foogallery_migrate_container" id="foogallery_migrate_albums" style="display: none">
	<?php
	if ( ! $albums ) {
		_e( 'There are no albums to import!', 'foogallery-migrate' );
	} else { ?>
		<div class="foogallery-help">
			<?php _e( 'Importing albums is also really simple:', 'foogallery-migrate' ); ?>
			<ol>
				<li><?php _e( 'For all the albums you wish to import, make sure all the galleries have been imported FIRST. If not, then go back to the Galleries tab.', 'foogallery-migrate' ); ?></li>
				<li><?php _e( 'Click the Import Album button for each album to import the album and link all the galleries. If you do not see the button, then that means you first need to import the galleries.', 'foogallery-migrate' ); ?></li>
				<li><?php _e( 'Once an album is imported, you can click on the link under the Album Name column to edit the album.', 'foogallery-migrate'); ?></li>
			</ol>
		</div>

		<form id="foogallery_migrate_album_form" method="POST">
			<?php $nextgen->render_album_import_form( $albums ); ?>
		</form>
	<?php } ?>
	</div>
	<div class="foogallery_migrate_container" id="foogallery_migrate_shortcodes" style="display: none">
		<div class="foogallery-help">
			<?php _e('Replacing shortcodes will only work with galleries that have already been imported.', 'foogallery-migrate' ); ?>
			<br/>
			<?php _e('Supported NextGen shortcodes: [ngg_images], [nggallery], [slideshow], [imagebrowse]', 'foogallery-migrate' ); ?>
		</div>
		<div id="foogallery_migrate_shortcodes_container">
			<input type="submit" class="button button-primary find-shortcodes" value="<?php _e( 'Find Shortcodes', 'foogallery-migrate' ); ?>">
			<?php wp_nonce_field( 'foogallery_nextgen_find_shortcodes', 'foogallery_nextgen_find_shortcodes' ); ?>
			<div style="width:40px; position: absolute;"><span class="spinner"></span></div>
		</div>
	</div>
</div>
