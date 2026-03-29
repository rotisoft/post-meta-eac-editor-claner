<?php
/**
 * Settings page content.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Save settings.
if ( isset( $_POST['submit'], $_POST['rspmeac_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rspmeac_settings_nonce'] ) ), 'rspmeac_settings' ) ) {
	$rspmeac_process_speed  = isset( $_POST['rspmeac_process_speed'] ) ? absint( $_POST['rspmeac_process_speed'] ) : 100;
	$rspmeac_items_per_page = isset( $_POST['rspmeac_items_per_page'] ) ? absint( $_POST['rspmeac_items_per_page'] ) : 40;
	$rspmeac_delete_data    = isset( $_POST['rspmeac_delete_data_on_uninstall'] ) ? 1 : 0;

	// Validation.
	$rspmeac_allowed_speeds = array( 1, 5, 10, 20, 50, 100, 500 );
	if ( ! in_array( $rspmeac_process_speed, $rspmeac_allowed_speeds, true ) ) {
		$rspmeac_process_speed = 100;
	}

	if ( $rspmeac_items_per_page < 10 || $rspmeac_items_per_page > 500 ) {
		$rspmeac_items_per_page = 40;
	}

	// Save.
	update_option( 'rspmeac_process_speed', $rspmeac_process_speed );
	update_option( 'rspmeac_items_per_page', $rspmeac_items_per_page );
	update_option( 'rspmeac_delete_data_on_uninstall', $rspmeac_delete_data );

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'rotistudio-post-meta-editor-cleaner' ) . '</p></div>';
}

// Get current values (with default values).
$rspmeac_process_speed  = get_option( 'rspmeac_process_speed', 100 );
$rspmeac_items_per_page = get_option( 'rspmeac_items_per_page', 40 );
$rspmeac_delete_data    = get_option( 'rspmeac_delete_data_on_uninstall', false );
?>

<form method="post" action="">
	<?php wp_nonce_field( 'rspmeac_settings', 'rspmeac_settings_nonce' ); ?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="rspmeac_process_speed"><?php esc_html_e( 'Process speed', 'rotistudio-post-meta-editor-cleaner' ); ?></label>
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
						<?php esc_html_e( 'If you experience errors or timeouts, decrease this value and try again. Only set to high values if you know what you are doing.', 'rotistudio-post-meta-editor-cleaner' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rspmeac_items_per_page"><?php esc_html_e( 'Items per page', 'rotistudio-post-meta-editor-cleaner' ); ?></label>
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
						<?php esc_html_e( 'Specify how many meta keys to display per page. Not recommended to set too high, only on powerful servers.', 'rotistudio-post-meta-editor-cleaner' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Uninstall', 'rotistudio-post-meta-editor-cleaner' ); ?>
				</th>
				<td>
					<label for="rspmeac_delete_data_on_uninstall">
						<input
							type="checkbox"
							id="rspmeac_delete_data_on_uninstall"
							name="rspmeac_delete_data_on_uninstall"
							value="1"
							<?php checked( $rspmeac_delete_data ); ?>
						/>
						<?php esc_html_e( 'Remove plugin settings and custom data when you delete this plugin from the plugin list.', 'rotistudio-post-meta-editor-cleaner' ); ?>
					</label>
				</td>
			</tr>
		</tbody>
	</table>

	<?php submit_button(); ?>
</form>
