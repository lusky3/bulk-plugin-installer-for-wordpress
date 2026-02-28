=== Bulk Plugin Installer ===
Contributors: lusky3
Tags: bulk, plugin, installer, upload, batch
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload and install multiple WordPress plugin ZIP files in a single operation with preview, rollback, and profile support.

== Description ==

Bulk Plugin Installer lets you upload and install multiple WordPress plugin ZIP files at once. Instead of installing plugins one at a time, drag and drop a batch of ZIPs, review a preview with compatibility checks, and install them all in one go.

= Key Features =

* Drag-and-drop bulk upload of multiple plugin ZIP files
* Preview screen with compatibility checks before installation
* Sequential processing with per-plugin status tracking
* Automatic rollback on failed updates
* Batch rollback to restore all plugins from a batch operation
* Installation profiles for repeatable plugin sets
* Dry run mode to simulate installations without making changes
* Changelog extraction with semantic version classification (major/minor/patch)
* Email notifications for batch operations
* WP-CLI integration for command-line workflows
* WordPress Multisite and Network Admin support
* Activity logging with filterable log viewer
* Configurable settings for auto-activate, file size limits, and rollback retention

== Installation ==

1. Upload the plugin ZIP via Plugins > Add New > Upload Plugin
2. Activate the plugin through the Plugins menu
3. Navigate to Plugins > Add New > Bulk Upload to start

Or install via WP-CLI:

    wp plugin install bulk-plugin-installer.zip --activate

== Frequently Asked Questions ==

= How many plugins can I upload at once? =

The default limit is 20 plugins per batch, configurable up to 100 in Settings > Bulk Plugin Installer.

= What happens if an installation fails? =

If auto-rollback is enabled (the default), failed updates are automatically rolled back to the previous version. Failed new installs have their partial files cleaned up. Other plugins in the batch continue processing.

= Can I undo an entire batch? =

Yes. After a batch completes, a "Rollback Entire Batch" button is available on the summary screen. Batch rollbacks are available for the duration configured in the rollback retention setting (default: 24 hours).

= Does it work with Multisite? =

Yes. When used from Network Admin, plugins are installed to the network plugin directory and can be network-activated.

= Can I use it from the command line? =

Yes. The plugin registers WP-CLI commands under `wp bulk-plugin`. Run `wp bulk-plugin install --help` for usage details.

= What is Dry Run mode? =

Dry Run simulates the installation process without making any changes to the filesystem. It shows you what would happen — which plugins would be installed, updated, or flagged as incompatible — so you can review before committing.

= Will my data be preserved if I deactivate the plugin? =

Yes. Deactivation only cleans up temporary data (queue transients and backup files). Your settings, profiles, and activity logs are preserved. If you want all data removed when deleting the plugin, enable "Delete Data on Uninstall" in the settings before deleting.

== Screenshots ==

1. Bulk upload screen with drag-and-drop zone
2. Preview screen with compatibility checks and changelogs
3. Processing screen with per-plugin status indicators
4. Settings page with configuration options

== Changelog ==

= 1.0.1 =
* Fix preview screen showing empty plugin names, versions, and authors
* Modernize admin UI with card-based layout and improved visual design
* Fix release build to only include runtime files

= 1.0.0 =
* Initial release
* Bulk ZIP upload with drag-and-drop
* Preview screen with compatibility checks
* Sequential processing with rollback support
* Installation profiles
* Dry run mode
* WP-CLI integration
* Multisite support
* Email notifications
* Activity logging

== Upgrade Notice ==

= 1.0.1 =
Fixes empty preview data and modernizes the admin interface.

= 1.0.0 =
Initial release.
