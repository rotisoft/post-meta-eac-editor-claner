<?php
/**
 * Settings page content.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get current values (with default values).
$rspmeac_process_speed  = get_option( 'rspmeac_process_speed', 50 );
$rspmeac_items_per_page = get_option( 'rspmeac_items_per_page', 40 );
$rspmeac_delete_data    = get_option( 'rspmeac_delete_data_on_uninstall', false );
?>

<form method="post" action="options.php">
	<?php
	settings_fields( 'rspmeac_settings_group' );
	do_settings_sections( 'rspmeac_settings_group' );
	?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="rspmeac_process_speed"><?php esc_html_e( 'Process speed', 'post-meta-eac-rotistudio' ); ?></label>
				</th>
				<td>
					<select name="rspmeac_process_speed" id="rspmeac_process_speed">
						<option value="1" <?php selected( $rspmeac_process_speed, 1 ); ?>>1</option>
						<option value="5" <?php selected( $rspmeac_process_speed, 5 ); ?>>5</option>
						<option value="10" <?php selected( $rspmeac_process_speed, 10 ); ?>>10</option>
						<option value="20" <?php selected( $rspmeac_process_speed, 20 ); ?>>20</option>
						<option value="50" <?php selected( $rspmeac_process_speed, 50 ); ?>>50</option>
						<option value="100" <?php selected( $rspmeac_process_speed, 100 ); ?>>100</option>
						<option value="500" <?php selected( $rspmeac_process_speed, 500 ); ?>>500</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'If you experience errors or timeouts, decrease this value and try again. Only set to high values if you know what you are doing.', 'post-meta-eac-rotistudio' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rspmeac_items_per_page"><?php esc_html_e( 'Items per page', 'post-meta-eac-rotistudio' ); ?></label>
				</th>
				<td>
					<input
						name="rspmeac_items_per_page"
						type="number"
						id="rspmeac_items_per_page"
						value="<?php echo esc_attr( $rspmeac_items_per_page ); ?>"
						min="10"
						max="500"
						class="small-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Specify how many meta keys to display per page. Not recommended to set too high, only on powerful servers.', 'post-meta-eac-rotistudio' ); ?>
					</p>
				</td>
			</tr>
			<tr class="rspmeac-uninstall-row">
				<th scope="row">
					<?php esc_html_e( 'Uninstall', 'post-meta-eac-rotistudio' ); ?>
				</th>
				<td>
					<div class="rspmeac-uninstall-warning">
						<label for="rspmeac_delete_data_on_uninstall">
							<input type="hidden" name="rspmeac_delete_data_on_uninstall" value="0" />
							<input
								type="checkbox"
								id="rspmeac_delete_data_on_uninstall"
								name="rspmeac_delete_data_on_uninstall"
								value="1"
								<?php checked( $rspmeac_delete_data, 1 ); ?>
							/>
							<?php esc_html_e( 'Delete plugin data when this plugin is removed from the Plugins list.', 'post-meta-eac-rotistudio' ); ?>
						</label>
						<p class="description">
							<strong><?php esc_html_e( 'Warning:', 'post-meta-eac-rotistudio' ); ?></strong>
							<?php esc_html_e( 'This only applies when you delete the plugin (not when you deactivate it). Checked: removes plugin settings from the options table, the cached post meta overview index (transients), and leftover operation lock records. It does not delete post meta rows from the database (wp_postmeta).', 'post-meta-eac-rotistudio' ); ?>
						</p>
					</div>
				</td>
			</tr>
		</tbody>
	</table>

	<?php submit_button(); ?>
</form>

<hr />

<h2><?php esc_html_e( 'Post meta table index', 'post-meta-eac-rotistudio' ); ?></h2>

<p class="description">
	<?php esc_html_e( 'Clears the cached overview so the Post Meta table page returns to the first-launch state. You will need to click Read data again before the table and tools appear. On large sites that scan can take several minutes.', 'post-meta-eac-rotistudio' ); ?>
</p>

<form
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	class="rspmeac-reset-overview-form"
	onsubmit="return window.confirm( <?php echo wp_json_encode( __( 'Clear the cached post meta index and return to the Read data screen?', 'post-meta-eac-rotistudio' ) ); ?> );"
>
	<input type="hidden" name="action" value="rspmeac_reset_overview" />
	<?php wp_nonce_field( 'rspmeac_reset_overview' ); ?>
	<?php
	submit_button(
		__( 'Clear index and require re-read', 'post-meta-eac-rotistudio' ),
		'secondary',
		'submit',
		false
	);
	?>
</form>
