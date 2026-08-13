=== FooGallery Migrate ===
Contributors: fooplugins,bradvin,elviiso
Tags: gallery, image gallery, photo gallery, wordpress gallery plugin, migrate
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 5.4
Stable tag: 1.7
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
