=== Post Meta Editor and Cleaner by RotiStudio ===
Contributors: rtomo, rotistudio
Tags: post meta, cleanup, database, optimization, editor
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
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

= 1.2.0 =
* Fix: meta rows stored on post revisions (e.g. Elementor data) could not be deleted or edited - operations now use the low-level metadata API instead of the post meta wrappers, so they target the exact rows shown in the table.
* Fix: "Delete (value only)" could run in an endless loop when the number of affected posts exceeded the batch size.
* Fix: posts holding multiple rows for the same meta key no longer collapse into a single value during search & replace - rows are updated individually by meta ID.
* Fix: batch processing uses deterministic ordering (ORDER BY post ID), so no posts are skipped or processed twice.
* Fix: values containing backslashes are no longer corrupted on save; meta keys and values with HTML or percent-encoded characters are now matched correctly.
* Improvement: the batch continuation offset is now calculated server-side.
* Performance: the meta overview table is cached in a transient (auto-invalidated after every operation) and a "Refresh data" button was added.
* Performance: deterministic sample value query compatible with ONLY_FULL_GROUP_BY SQL mode.
* Improvement: unified process speed default (50), canonical admin URLs under Tools, removed dead code.

= 1.1.0 =
* Minor code fix.
* WordPress 7.0 compatibility check
* PHP 8.5 compatibility check

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Important bug fixes: deleting meta stored on post revisions now works, endless loop in "Delete (value only)" fixed, safer search & replace on multi-value meta keys. Update recommended.

= 1.1.0 =
* Minor code fix.
* WordPress 7.0 compatibility check
* PHP 8.5 compatibility check

= 1.0.0 =
Initial release.
