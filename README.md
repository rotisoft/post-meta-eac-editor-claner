# Post Meta Editor and Cleaner by RotiStudio

Post Meta bulk editor to delete unused data, overwrite values, run search and replace, and clean your database directly from the admin panel.

**Hungarian:** [Magyar nyelvű bővítmény leírás](https://rotistudio.hu/bovitmenyek/post-meta-szerkeszto-es-tisztito/)

| | |
| --- | --- |
| **Requires WordPress** | 5.9+ |
| **Tested up to** | 7.1 |
| **Requires PHP** | 7.4+ |
| **Stable tag** | 1.4.0 |
| **License** | [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) |

**Contributors:** rtomo, rotistudio  
**Tags:** post meta, cleanup, database, optimization, editor  

[**Donate / contact**](https://rotistudio.com/contact/)

---

## Why use this plugin?

Over time, WordPress sites accumulate post meta data from plugins, themes, and page builders. When you switch or remove plugins, their meta often stays in the database - bloating tables, slowing queries, and making backups larger. Post Meta Editor and Cleaner gives you direct control over this data.

## Key benefits

- **Database optimization** - Remove orphaned meta from deleted plugins to reduce database size and improve performance.
- **Source identification** - See which plugin or theme created each meta key. The built-in source map recognizes 50+ popular plugins (WooCommerce, Yoast, Rank Math, Elementor, Jetpack, WPML, and many more).
- **Table search, filters and sorting** - Advanced search across meta key, post type and field content (including serialized fragments); column filters for Meta Key, Source and Post Type; sort by Meta Key, Source, Count or Size.
- **Bulk delete** - Delete more meta keys at once. Adjust process speed to avoid timeouts on large sites.
- **Search and replace** - Update URLs, domain names, or text across all posts. Works with serialized data, including double-serialized values - both array values and array keys can be updated. Values containing PHP objects are skipped for safety.
- **Flexible deletion** - Delete the entire meta key and its values, or clear values only while keeping the key structure.
- **Safe editing** - Overwrite meta values in bulk, or perform targeted search-and-replace. Batches are resumable after connection errors, concurrent operations on the same meta key are blocked server-side, and any database error stops the operation with a clear message. Ideal for migrations, domain changes, or fixing incorrect product data.
- **Large-site friendly** - On catalogs with many products the overview is not scanned automatically. You start with Read data when ready; progress shows percentage and item counts; selected rows can be refreshed without rebuilding the whole index.

## What you can do

- Browse the post meta overview with search, column filters and sortable columns (including Size in MB).
- Clean up after removing plugins or themes.
- Migrate a site and update old URLs in meta fields.
- Fix WooCommerce product attributes, SEO meta, or custom fields in bulk.
- Identify and remove unused or duplicate meta keys.
- Reduce `wp_postmeta` table size for faster backups and queries.

## Important

Before making any changes, create a backup, as modifications and deletions can only be restored from a backup.

## More

Do you have other plugins? Yes, check the plugins website: [rotistudio.com](https://rotistudio.com/plugins/)

Where can we learn more about your work? See the personal site: [rottenbacher.hu](https://rottenbacher.hu/)

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/post-meta-eac-rotistudio`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Dashboard → Tools → Post Meta EAC**.

---

## Frequently asked questions

### How can I use it?

Install and activate the plugin, then open the **Post Meta EAC** menu in your WordPress admin.

### Do I need to create a backup before using this plugin?

Yes - always create a full database backup before deleting any post meta data. Deletions are permanent and cannot be undone without a backup.

### The operation is slow or seems to be stuck - what should I do?

The plugin processes posts in batches. If the operation is slow or appears to hang, reduce the batch size on the **Settings** page. Lower the **Process Speed** value (for example from 50 to 10–20). That reduces how many posts are processed per request and lowers the load on your server.

### Why does the Post Meta table not load automatically?

On large sites the full overview scan can take several minutes. The table page opens immediately and shows a Read data button so you start the scan when you are ready. Use Refresh all data to rebuild the full index, or the bulk Refresh data action for selected rows only. Settings also has Clear index and require re-read to return to that first-launch state.

### Which posts are affected by the operations?

All post types are processed, including revisions. Trashed and auto-draft posts, and orphaned meta rows without an existing post, are excluded from both the table and the operations.

### Who can use the plugin?

By default, users with the `manage_post_meta_cleanup` capability (administrators receive it on plugin activation). Since the plugin exposes and edits post meta of every post type, sites with custom roles can tighten this via the `rspmeac_capability` filter.

---

## Changelog

Check on WordPress.org [Changelog - Post Meta Editor and Cleaner]([https://rotistudio.com/plugins/](https://wordpress.org/plugins/rotistudio-post-meta-editor-cleaner/#developers))

- Initial release.

Full changelog on WordPress.org: [developers section](https://wordpress.org/plugins/rotistudio-post-meta-editor-cleaner/#developers)
