<?php
/**
 * Help page content.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="rspmeac-faq">

	<h2 class="rspmeac-faq-title"><?php esc_html_e( 'Frequently Asked Questions', 'post-meta-eac-rotistudio' ); ?></h2>

	<div class="rspmeac-faq-item rspmeac-faq-item--warning">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'Do I need to create a backup before using this plugin?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p><?php esc_html_e( 'Yes — always create a full database backup before deleting any post meta data. Deletions are permanent and cannot be undone without a backup.', 'post-meta-eac-rotistudio' ); ?></p>
			<p><?php esc_html_e( 'You can create a backup using a backup plugin (e.g. UpdraftPlus, All-in-One WP Migration) or directly from phpMyAdmin by exporting your database.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-undo"></span>
			<?php esc_html_e( 'Can a deletion be undone?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p><?php esc_html_e( 'No. Deletions are irreversible and can only be restored from a database backup.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-controls-pause"></span>
			<?php esc_html_e( 'The operation is very slow or seems to be stuck — what should I do?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p><?php esc_html_e( 'The plugin processes posts in batches. If the operation is slow or appears to hang, reduce the batch size in the Settings page.', 'post-meta-eac-rotistudio' ); ?></p>
			<p><?php esc_html_e( 'Lower the "Process Speed" value (e.g. from 50 to 10–20). This reduces the number of posts processed per request, which lowers the load on your server.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-info-outline"></span>
			<?php esc_html_e( 'What is the difference between "Delete (key + value)" and "Delete (value only)"?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p>
				<strong><?php esc_html_e( 'Delete (key + value):', 'post-meta-eac-rotistudio' ); ?></strong>
				<br>
				<?php esc_html_e( 'Permanently removes the meta key and all its values from every post. The key will no longer appear in the table.', 'post-meta-eac-rotistudio' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Delete (value only):', 'post-meta-eac-rotistudio' ); ?></strong>
				<br>
				<?php esc_html_e( 'Clears the stored value but keeps the meta key in the database. Each existing meta row is cleared individually, so posts storing multiple values under the same key keep their row count. The key remains visible in the table with a count of 0.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-database"></span>
			<?php esc_html_e( 'What does the "Count" column show?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p><?php esc_html_e( 'The Count column shows the number of posts that have a non-empty value stored for the given meta key. Posts that have the key but an empty value are not counted.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-admin-post"></span>
			<?php esc_html_e( 'Which posts are affected by the operations?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p><?php esc_html_e( 'All post types are processed, including revisions. Trashed and auto-draft posts, and orphaned meta rows without an existing post, are excluded from both the table and the operations.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-edit"></span>
			<?php esc_html_e( 'What are the Edit actions (Overwrite, Search and replace)?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p>
				<strong><?php echo esc_html( __( 'Overwrite', 'post-meta-eac-rotistudio' ) ) . ':'; ?></strong>
				<br>
				<?php esc_html_e( 'Replaces the full post meta field content for all posts with the new value you enter. Use this when you want to set the same value everywhere.', 'post-meta-eac-rotistudio' ); ?>
			</p>
			<p>
				<strong><?php echo esc_html( __( 'Search and replace (only value)', 'post-meta-eac-rotistudio' ) ) . ':'; ?></strong>
				<br>
				<?php esc_html_e( 'Finds and replaces text only in the stored values. Array keys (names) in serialized data remain unchanged. Works on both plain text and serialized arrays.', 'post-meta-eac-rotistudio' ); ?>
			</p>
			<p>
				<strong><?php echo esc_html( __( 'Search and replace (name and value)', 'post-meta-eac-rotistudio' ) ) . ':'; ?></strong>
				<br>
				<?php esc_html_e( 'Finds and replaces text in both values and array keys within serialized data. Use this when you need to rename keys (e.g. pa_color to pa_color2) in addition to values.', 'post-meta-eac-rotistudio' ); ?>
			</p>
			<p><?php esc_html_e( 'For safety, some rows are skipped and left untouched: values containing PHP objects, serialized data that cannot be read, and rows where a key rename would overwrite another existing key. The status message shows how many rows were skipped.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

	<div class="rspmeac-faq-item">
		<h3 class="rspmeac-faq-question">
			<span class="dashicons dashicons-admin-users"></span>
			<?php esc_html_e( 'Who can use the plugin?', 'post-meta-eac-rotistudio' ); ?>
		</h3>
		<div class="rspmeac-faq-answer">
			<p><?php esc_html_e( 'By default, users with the manage_post_meta_cleanup capability (administrators receive it on plugin activation). Since the plugin exposes and edits post meta of every post type, sites with custom roles can tighten this via the rspmeac_capability filter.', 'post-meta-eac-rotistudio' ); ?></p>
		</div>
	</div>

</div>
