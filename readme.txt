=== Post Meta Editor and Cleaner by RotiStudio ===
Contributors: rtomo, rotistudio
Tags: post meta, cleanup, database, optimization, editor
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://rotistudio.com/contact/

Post Meta bulk editor to delete unused data, overwrite values, run search and replace, and clean your database directly from the admin panel.

== Description ==

Post Meta bulk editor to delete unused data, overwrite values, run search and replace, and clean your database directly from the admin panel.

Hungarian: [Magyar nyelvű bővítmény leírás](https://rotistudio.hu/bovitmenyek/post-meta-szerkeszto-es-tisztito/)

= Why use this plugin? =

Over time, WordPress sites accumulate post meta data from plugins, themes, and page builders. When you switch or remove plugins, their meta often stays in the database — bloating tables, slowing queries, and making backups larger. Post Meta Editor and Cleaner gives you direct control over this data.

= Key benefits =

* **Database optimization** — Remove orphaned meta from deleted plugins to reduce database size and improve performance.
* **Source identification** — See which plugin or theme created each meta key. The built-in source map recognizes 50+ popular plugins (WooCommerce, Yoast, Rank Math, Elementor, Jetpack, WPML, and many more).
* **Bulk delete** — Delete more meta keys at once. Adjust process speed to avoid timeouts on large sites.
* **Search and replace** — Update URLs, domain names, or text across all posts. Works with serialized data (arrays, objects) — both values and keys can be updated.
* **Flexible deletion** — Delete the entire meta key and its values, or clear values only while keeping the key structure.
* **Safe editing** — Overwrite meta values in bulk, or perform targeted search-and-replace. Ideal for migrations, domain changes, or fixing incorrect product data.

= What you can do =

* Clean up after removing plugins or themes.
* Migrate a site and update old URLs in meta fields.
* Fix WooCommerce product attributes, SEO meta, or custom fields in bulk.
* Identify and remove unused or duplicate meta keys.
* Reduce `wp_postmeta` table size for faster backups and queries.

= Important =

Before making any changes, create a backup, as modifications and deletions can only be restored from a backup.

= More =

Do you have other plugins? Yes, check my plugins website: [rotistudio.com](https://rotistudio.com/plugins/)
Where can we learn more about your work? Check my personal website there: [rottenbacher.hu](https://rottenbacher.hu/)


== Screenshots ==

1. screenshot-1.jpg - Post meta table view
2. screenshot-2.jpg - In WordPress dashboard
3. screenshot-3.jpg - Minimal settings

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/post-meta-eac-rotistudio` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Dashboard / Tools / Post Meta EAC

== Frequently Asked Questions ==

= How can I use it? =

Install and activate the plugin, then navigate to the Post Meta EAC menu in your WordPress admin.

= Do I need to create a backup before using this plugin? =

Yes — always create a full database backup before deleting any post meta data. Deletions are permanent and cannot be undone without a backup.

= The operation is slow or seems to be stuck — what should I do? =

The plugin processes posts in batches. If the operation is slow or appears to hang, reduce the batch size in the Settings page. Lower the "Process Speed" value (e.g. from 50 to 10–20). This reduces the number of posts processed per request, which lowers the load on your server.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
