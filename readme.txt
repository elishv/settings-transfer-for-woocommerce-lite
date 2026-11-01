=== Settings Transfer for WooCommerce ===
Contributors: elishvarzman
Donate link: https://a-in.co.il/plugins/settings-transfer/
Tags: woocommerce, settings, export, import, migration
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WooCommerce settings (gateways, shipping, emails, integrations) to a JSON file and import them on another store, with a report.

== Description ==

Moving a store from staging to production, or setting up a new shop like an existing one? Settings Transfer exports the settings of your payment gateways, shipping methods, emails and integrations to one JSON file and imports them on another WooCommerce site, then shows what was added, updated or skipped.

* **Export** every WooCommerce settings option (`woocommerce_settings` and all `woocommerce_*_settings` options) to one JSON file.
* **Import** the file on another site and get a report of how many options were added, updated and skipped.
* **Safe by design**: only administrators can use it, and the import writes WooCommerce settings options only. Any other entry in the file is skipped and listed.
* **Upload checks**: .json files only, up to 2 MB.
* **Not included**: general options stored separately, such as the store address, and shipping zones (see the FAQ).

The screen is at **WooCommerce → Settings Transfer**.

**Note:** the export can contain payment gateway keys and other secrets. Keep the file somewhere safe and delete it when you are done.

= More from A-in =

This plugin is complete on its own: no licence key, no locked features. Premium plugins, add-ons and support from the same developer are available at [a-in.co.il](https://a-in.co.il/plugins/).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it. WooCommerce must be active.
3. Go to **WooCommerce → Settings Transfer**.

== Frequently Asked Questions ==

= Which settings are transferred? =

Options named `woocommerce_settings` or `woocommerce_<something>_settings`. These hold the settings of payment gateways, shipping methods, emails and integrations. General options such as the store address are stored as separate `woocommerce_*` options and are not included.

= Are shipping zones transferred? =

No. Shipping zones and the list of methods in each zone live in their own database tables, not in options. The settings of a shipping method instance (for example `woocommerce_flat_rate_3_settings`) are transferred, but they only take effect on a site where a method with the same instance ID exists.

= Can an import change other WordPress options? =

No. Since version 1.3.0 every key that is not a WooCommerce settings option is skipped, and only administrators can import.

= Who can use it? =

Users with the `manage_options` capability (administrators).

== Screenshots ==

1. Export your WooCommerce settings to a JSON file, or import a file from another store.
2. The import report: how many options were added, updated and skipped.
3. Only WooCommerce settings are imported. Any other entry is skipped and listed.

== Changelog ==

= 1.3.0 =
* Security: import now writes only WooCommerce settings options (allowlist), and every other key is skipped and reported.
* Security: export and import need the `manage_options` capability (administrators).
* Security: uploads are checked (upload status, .json extension, 2 MB limit), and values that are not scalars or arrays are rejected.
* Result messages no longer travel in the URL.
* Added readme.txt, the GPL license and the plugin headers, a Donate link, screenshots, and the WooCommerce HPOS compatibility declaration (the plugin never touches orders).

= 1.2 =
* Export/import with a report.

== Upgrade Notice ==

= 1.3.0 =
Security fix: the import is limited to WooCommerce settings and to administrators. Please update.
