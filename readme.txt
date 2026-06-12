=== FooGallery Migrate ===
Contributors: fooplugins,bradvin,elviiso
Tags: gallery, image gallery, photo gallery, wordpress gallery plugin, migrate
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 5.4
Stable tag: 1.10
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Migrate to FooGallery from other gallery plugins like NextGen, Modula, Envira and other gallery plugins.

== Description ==

Are you tired of other gallery plugins?
Are you looking for a different gallery plugin to try out?
Are you looking for the best gallery plugin with the most free features?

Now you can easily and effortlessly migrate to the best WordPress gallery plugin : [FooGallery!](https://wordpress.org/plugins/foogallery)

Migrate to FooGallery from other gallery plugins, including:

*	Envira Gallery
*	Modula Gallery
*	NextGen Gallery
*	Photo Gallery by 10Web
*	Robo Gallery
*	Album and Image Gallery Plus Lightbox (plugin was closed Apr 2026 due to being compromised)

Features:

* Migrate images and galleries
* Migrate albums
* Migrate blocks / shortcodes in post & page content

= Test It First =

Spin up a demo site with FooGallery Migrate and all supported galleries, so you can see how it works:
[Try Migrate Demo](https://app.instawp.io/launch?s=foogallery-migrate&d=v2)

= Migrate Away From Envira =

The lite version of Envira includes basic gallery features, like unlimited gallery creation with drag n drop reordering. There are a few settings to tweak within Envira, but to get the most out of it, you will need to upgrade to a paid version.
OR, change to FooGallery! The free version of FooGallery has tons more features, including being able to choose different gallery layouts, like masonry, image viewer, portfolio.

= Migrate Away From Modula =

Modula has 4 gallery layouts VS the 7 that come free with FooGallery. Modula also has a ton of settings to customize your galleries, but not as many as FooGallery offers.

= Migrate Away From NextGen =

NextGen has 3 gallery styles and a batch upload feature. More advanced features are only available with the paid versions of NextGen.
FooGallery free has 7 gallery styles and a load of different settings to customize it to look perfect for your website and theme.

= Migrate Away From "Album and Image Gallery Plus Lightbox" =

FooGallery Migrate can detect "Album and Image Gallery Plus Lightbox" galleries directly from WordPress database records, so the source plugin does not need to be active or loaded during migration.

== Installation ==

1. Upload the zip file to the `/wp-content/plugins/` folder and then unzip.
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Done! Enjoy

== Screenshots ==

1. Gallery Plugins Supported
2. Gallery Migrate Page

== Upgrade Notice ==

Update now to get all the latest features, bug fixes and improvements!

== Frequently Asked Questions ==

= I need to import from another plugin. How can I request that? =

[Contact us](https://fooplugins.com/support/) and we will build an importer to help you migrate to FooGallery.

== Changelog ==

= 1.10 =
* Added support for replacing NextGEN singlepic shortcodes with standard WordPress captioned image content that links to the full-size attachment.
* Improved NextGEN shortcode detection for legacy gallery formats and modern [ngg src="galleries" ids="..."] shortcodes.
* Preserved NextGEN singlepic alignment and explicit width/height settings during content migration.
* Defaulted migrated NextGEN singlepic output to the attachment thumbnail when no size is specified.
* Imported NextGEN image descriptions into WordPress attachment captions for captioned single-image replacements.

= 1.9 =
* Added Override Gallery Settings and Override Album Settings options to inherit settings from existing FooGallery galleries and albums during migration.
* Migrated albums can now inherit album template, settings, sort order and custom CSS from a selected source album while preserving the migrated child gallery list.
* Migrated galleries can now inherit settings and custom CSS from a selected source gallery while preserving migrated attachments and source-plugin migration mappings.
* Fixed migrated albums so edited album names entered in the migration form are used for the created FooGallery album.

= 1.8 =
* Added repo-local PHPUnit coverage for migration discovery, queueing, progress, resume behavior, retry handling, migrated object tracking and album migration.
* Reduced the size of newly written migration state by compacting persisted plugin, gallery, album, image and migrated object data while keeping legacy state readable.
* Improved migration AJAX error responses with clearer action details and debug output.
* Optimized Album and Image Gallery Plus Lightbox discovery for large migrations by bulk-loading attachment metadata and deferring gallery image child loading.
* Updated Album and Image Gallery Plus Lightbox album migration to create FooGallery albums from aigpl_cat terms and gallery relationships instead of one-gallery album wrappers.
* Moved migrator settings and migration state storage logic into a dedicated settings class.
* Added an Images Per Turn setting to import multiple images during each migration AJAX request.
* Added gallery and album totals below their migration tables.
* Added preflight reporting for selected galleries, albums, child galleries and images before migration starts.

= 1.7 =
* Added a Settings tab to the migration Page.
* Override the gallery layout for all migrated galleries using the Override Gallery Layout setting.
* Disable migration pagination by setting Page Size setting to 0.
* Fixed PHP warnings when Modula image metadata does not include description or alt values.

= 1.6 =
* Added support for migrating Album and Image Gallery Plus Lightbox galleries, albums, blocks and shortcodes without loading the source plugin.

= 1.5 =
* Added new feature : block / shortcode migration!
* Added new Log tab to see all migrated information.
* Added new debug tab to see migrated info (shown when FooGallery Debug mode is on)
* Added "Migrate" button for each gallery so you can migrate 1 gallery at a time.
* Added a "Check for migration errors" button which checks all attachments after a migration.
* Added a "Check" button for each migrated gallery to check for errors.
* Added error info for attachments on gallery tab under migration progress.
* Made the attachment import more resilient to large files.

= 1.4 =
* Updated NextGen metadata migration improvements
* Fix NextGen alt text mapping to properly populate FooGallery title and alt fields
* Configure FooGallery galleries to display image titles as caption titles

= 1.3 =
* Fixed bug where NextGen image captions were not being migrated

= 1.2 =
* Fixed bug where Envira / Modula / Robo galleries were importing thumbnails

= 1.1 =
* Introduced album migrations. (Supports NextGen Albums & Photo Gallery Groups)

= 1.0 =
* Initial Release. First version.
