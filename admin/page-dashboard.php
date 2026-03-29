<?php
/**
 * Post Meta table page content.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin page, single grouped query.
$rspmeac_type_rows = $wpdb->get_results(
	"SELECT pm.meta_key, p.post_type,
		SUM(CASE WHEN pm.meta_value != '' THEN 1 ELSE 0 END) AS cnt
	FROM {$wpdb->postmeta} pm
	INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	WHERE p.post_status NOT IN ('trash', 'auto-draft')
	GROUP BY pm.meta_key, p.post_type
	ORDER BY pm.meta_key ASC, p.post_type ASC"
);

$rspmeac_meta_keys   = array();
$rspmeac_post_types  = array();
$rspmeac_post_counts = array();

foreach ( $rspmeac_type_rows as $rspmeac_row ) {
	if ( ! isset( $rspmeac_post_types[ $rspmeac_row->meta_key ] ) ) {
		$rspmeac_meta_keys[]                              = $rspmeac_row->meta_key;
		$rspmeac_post_types[ $rspmeac_row->meta_key ]  = array();
		$rspmeac_post_counts[ $rspmeac_row->meta_key ] = array();
	}
	$rspmeac_post_types[ $rspmeac_row->meta_key ][]   = $rspmeac_row->post_type;
	$rspmeac_post_counts[ $rspmeac_row->meta_key ][ $rspmeac_row->post_type ] = intval( $rspmeac_row->cnt );
}

// Pagination settings.
$per_page              = get_option( 'rspmeac_items_per_page', 40 );
$rspmeac_current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination, no state change.
$rspmeac_total_items   = count( $rspmeac_meta_keys );
$rspmeac_total_pages   = ceil( $rspmeac_total_items / $per_page );
$rspmeac_offset        = ( $rspmeac_current_page - 1 ) * $per_page;

// Select meta keys for current page.
$rspmeac_paged_meta_keys = array_slice( $rspmeac_meta_keys, $rspmeac_offset, $per_page );

?>

<div class="rspmeac-notice-error">
	<p><strong><?php esc_html_e( 'Always make a backup before modifying or deleting data!', 'rotistudio-post-meta-editor-cleaner' ); ?></strong></p>
</div>

<form method="post" id="rspmeac-meta-form">
	<?php wp_nonce_field( 'rspmeac_bulk_action', 'rspmeac_bulk_nonce' ); ?>
	<input type="hidden" name="bulk_action" value="1" />

	<div class="tablenav top">
		<div class="alignleft actions bulkactions">
			<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'rotistudio-post-meta-editor-cleaner' ); ?></label>
			<select name="action" id="bulk-action-selector-top">
				<option value="-1"><?php esc_html_e( 'Bulk actions', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete (key + value)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
				<option value="delete_value"><?php esc_html_e( 'Delete (value only)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
			</select>
			<button type="button" id="doaction" class="button action"><?php esc_html_e( 'Apply', 'rotistudio-post-meta-editor-cleaner' ); ?></button>
		</div>
		<?php if ( $rspmeac_total_pages > 1 ) : ?>
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: %s: number of items */
					esc_html( _n( '%s item', '%s items', $rspmeac_total_items, 'rotistudio-post-meta-editor-cleaner' ) ),
					'<span class="total-items">' . esc_html( number_format_i18n( $rspmeac_total_items ) ) . '</span>'
				);
				?>
			</span>
			<?php
			$rspmeac_pagination_links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '<span class="screen-reader-text">' . __( 'Previous page', 'rotistudio-post-meta-editor-cleaner' ) . '</span><span aria-hidden="true">‹</span>',
					'next_text' => '<span class="screen-reader-text">' . __( 'Next page', 'rotistudio-post-meta-editor-cleaner' ) . '</span><span aria-hidden="true">›</span>',
					'current'   => $rspmeac_current_page,
					'total'     => $rspmeac_total_pages,
					'type'      => 'array',
					'show_all'  => false,
					'end_size'  => 1,
					'mid_size'  => 1,
				)
			);

			if ( $rspmeac_pagination_links ) {
				echo '<span class="pagination-links">';

				// First/Previous.
				if ( $rspmeac_current_page > 1 ) {
					printf(
						'<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
						esc_url( add_query_arg( 'paged', 1 ) ),
						esc_html__( 'First page', 'rotistudio-post-meta-editor-cleaner' ),
						'«'
					);
					printf(
						'<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
						esc_url( add_query_arg( 'paged', max( 1, $rspmeac_current_page - 1 ) ) ),
						esc_html__( 'Previous page', 'rotistudio-post-meta-editor-cleaner' ),
						'‹'
					);
				} else {
					echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
					echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
				}

				// Current page info.
				echo '<span class="screen-reader-text">' . esc_html__( 'Current Page', 'rotistudio-post-meta-editor-cleaner' ) . '</span>';
				echo '<span id="table-paging" class="paging-input">';
				$rspmeac_paging_text = sprintf(
					/* translators: 1: current page number, 2: total pages. */
					__( '%1$s of %2$s', 'rotistudio-post-meta-editor-cleaner' ),
					number_format_i18n( $rspmeac_current_page ),
					number_format_i18n( $rspmeac_total_pages )
				);
				echo '<span class="tablenav-paging-text">' . esc_html( $rspmeac_paging_text ) . '</span>';
				echo '</span>';

				// Next/Last.
				if ( $rspmeac_current_page < $rspmeac_total_pages ) {
					printf(
						'<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
						esc_url( add_query_arg( 'paged', min( $rspmeac_total_pages, $rspmeac_current_page + 1 ) ) ),
						esc_html__( 'Next page', 'rotistudio-post-meta-editor-cleaner' ),
						'›'
					);
					printf(
						'<a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
						esc_url( add_query_arg( 'paged', $rspmeac_total_pages ) ),
						esc_html__( 'Last page', 'rotistudio-post-meta-editor-cleaner' ),
						'»'
					);
				} else {
					echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
					echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
				}

				echo '</span>';
			}
			?>
		</div>
		<?php endif; ?>
		<br class="clear" />
	</div>

<table class="widefat fixed striped">
	<thead>
		<tr>
			<td id="cb" class="manage-column column-cb check-column">
				<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'rotistudio-post-meta-editor-cleaner' ); ?></label>
				<input id="cb-select-all-1" type="checkbox" />
			</td>
			<th><?php esc_html_e( 'Meta Key', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Source', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Post Type', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Count', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
			<th style="max-width: 30%;"><?php esc_html_e( 'Field content', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Edit actions', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
			<th><?php esc_html_e( 'Delete actions', 'rotistudio-post-meta-editor-cleaner' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $rspmeac_paged_meta_keys ) ) : ?>
			<tr>
				<td colspan="8"><?php esc_html_e( 'No post meta data found.', 'rotistudio-post-meta-editor-cleaner' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $rspmeac_paged_meta_keys as $rspmeac_key ) : ?>
				<?php
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin page, sample value query.
				$rspmeac_sample = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
						$rspmeac_key
					)
				);
				$rspmeac_sample_display = ( null !== $rspmeac_sample ) ? mb_strimwidth( $rspmeac_sample, 0, 100, '…' ) : '';

				// Post types and count summary.
				$rspmeac_types_display = implode( ', ', $rspmeac_post_types[ $rspmeac_key ] );
				$rspmeac_total_count   = array_sum( $rspmeac_post_counts[ $rspmeac_key ] );
				?>
				<tr>
					<th scope="row" class="check-column">
					<label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $rspmeac_key ); ?>">
						<?php esc_html_e( 'Bulk select', 'rotistudio-post-meta-editor-cleaner' ); ?>
					</label>
						<input id="cb-select-<?php echo esc_attr( $rspmeac_key ); ?>" type="checkbox" name="meta_keys[]" value="<?php echo esc_attr( $rspmeac_key ); ?>" />
					</th>
				<td data-label="<?php esc_attr_e( 'Meta Key', 'rotistudio-post-meta-editor-cleaner' ); ?>"><code><?php echo esc_html( $rspmeac_key ); ?></code></td>
				<td data-label="<?php esc_attr_e( 'Source', 'rotistudio-post-meta-editor-cleaner' ); ?>"><?php echo esc_html( rspmeac_get_source_for_key( $rspmeac_key ) ); ?></td>
				<td data-label="<?php esc_attr_e( 'Post Type', 'rotistudio-post-meta-editor-cleaner' ); ?>"><?php echo esc_html( $rspmeac_types_display ); ?></td>
				<td data-label="<?php esc_attr_e( 'Count', 'rotistudio-post-meta-editor-cleaner' ); ?>">
					<strong><?php echo esc_html( number_format_i18n( $rspmeac_total_count ) ); ?></strong>
					<?php if ( count( $rspmeac_post_types[ $rspmeac_key ] ) > 1 ) : ?>
						<br><small>
							<?php
							$rspmeac_count_details = array();
							foreach ( $rspmeac_post_types[ $rspmeac_key ] as $post_type ) {
								$rspmeac_c               = isset( $rspmeac_post_counts[ $rspmeac_key ][ $post_type ] ) ? $rspmeac_post_counts[ $rspmeac_key ][ $post_type ] : 0;
								$rspmeac_count_details[] = $post_type . ': ' . number_format_i18n( $rspmeac_c );
							}
							echo esc_html( implode( ', ', $rspmeac_count_details ) );
							?>
						</small>
					<?php endif; ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Field content', 'rotistudio-post-meta-editor-cleaner' ); ?>" style="max-width: 30%; word-break: break-word;">
					<?php echo esc_html( $rspmeac_sample_display ); ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Edit actions', 'rotistudio-post-meta-editor-cleaner' ); ?>">
						<select class="rspmeac-edit-actions-select" data-key="<?php echo esc_attr( $rspmeac_key ); ?>">
							<option value=""><?php esc_html_e( 'Choose…', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
							<option value="overwrite"><?php esc_html_e( 'Overwrite', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
							<option value="search_replace_value"><?php esc_html_e( 'Search and replace (only value)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
							<option value="search_replace_value_and_key"><?php esc_html_e( 'Search and replace (name and value)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
						</select>
					</td>
				<td data-label="<?php esc_attr_e( 'Delete actions', 'rotistudio-post-meta-editor-cleaner' ); ?>">
						<select class="rspmeac-delete-actions-select" data-key="<?php echo esc_attr( $rspmeac_key ); ?>">
							<option value=""><?php esc_html_e( 'Choose…', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete (key + value)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
							<option value="delete_value"><?php esc_html_e( 'Delete (value only)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
						</select>
						<span class="rspmeac-meta-status rspmeac-meta-status-delete"></span>
					</td>
				</tr>
				<tr class="rspmeac-inline-edit-row" style="display:none;" data-key="<?php echo esc_attr( $rspmeac_key ); ?>">
					<td colspan="8">
						<div class="rspmeac-inline-edit-overwrite" style="display:none;">
							<label><?php esc_html_e( 'New value:', 'rotistudio-post-meta-editor-cleaner' ); ?>
								<input type="text" class="rspmeac-input-new-value regular-text" />
							</label>
							<button type="button" class="button button-primary rspmeac-apply-overwrite"><?php esc_html_e( 'Apply', 'rotistudio-post-meta-editor-cleaner' ); ?></button>
							<button type="button" class="button rspmeac-cancel-inline-edit"><?php esc_html_e( 'Cancel', 'rotistudio-post-meta-editor-cleaner' ); ?></button>
							<span class="rspmeac-meta-status-edit"></span>
						</div>
						<div class="rspmeac-inline-edit-search-replace" style="display:none;">
							<label><?php esc_html_e( 'Search:', 'rotistudio-post-meta-editor-cleaner' ); ?>
								<input type="text" class="rspmeac-input-search regular-text" />
							</label>
							<label><?php esc_html_e( 'Replace with:', 'rotistudio-post-meta-editor-cleaner' ); ?>
								<input type="text" class="rspmeac-input-replace regular-text" />
							</label>
							<button type="button" class="button button-primary rspmeac-apply-search-replace"><?php esc_html_e( 'Apply', 'rotistudio-post-meta-editor-cleaner' ); ?></button>
							<button type="button" class="button rspmeac-cancel-inline-edit"><?php esc_html_e( 'Cancel', 'rotistudio-post-meta-editor-cleaner' ); ?></button>
							<span class="rspmeac-meta-status-edit"></span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>

<div class="tablenav bottom">
	<div class="alignleft actions bulkactions">
		<label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'rotistudio-post-meta-editor-cleaner' ); ?></label>
		<select name="action2" id="bulk-action-selector-bottom">
			<option value="-1"><?php esc_html_e( 'Bulk actions', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete (key + value)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
			<option value="delete_value"><?php esc_html_e( 'Delete (value only)', 'rotistudio-post-meta-editor-cleaner' ); ?></option>
		</select>
		<button type="button" id="doaction2" class="button action"><?php esc_html_e( 'Apply', 'rotistudio-post-meta-editor-cleaner' ); ?></button>
	</div>
	<?php if ( $rspmeac_total_pages > 1 ) : ?>
	<div class="tablenav-pages">
		<span class="displaying-num">
			<?php
			printf(
				/* translators: %s: number of items */
				esc_html( _n( '%s item', '%s items', $rspmeac_total_items, 'rotistudio-post-meta-editor-cleaner' ) ),
				'<span class="total-items">' . esc_html( number_format_i18n( $rspmeac_total_items ) ) . '</span>'
			);
			?>
		</span>
		<span class="pagination-links">
			<?php
			// First/Previous.
			if ( $rspmeac_current_page > 1 ) {
				printf(
					'<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', 1 ) ),
					esc_html__( 'First page', 'rotistudio-post-meta-editor-cleaner' ),
					'«'
				);
				printf(
					'<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', max( 1, $rspmeac_current_page - 1 ) ) ),
					esc_html__( 'Previous page', 'rotistudio-post-meta-editor-cleaner' ),
					'‹'
				);
			} else {
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
			}

			// Current page info.
			echo '<span class="screen-reader-text">' . esc_html__( 'Current Page', 'rotistudio-post-meta-editor-cleaner' ) . '</span>';
			echo '<span id="table-paging" class="paging-input">';
			$rspmeac_paging_text = sprintf(
				/* translators: 1: current page number, 2: total pages. */
				__( '%1$s of %2$s', 'rotistudio-post-meta-editor-cleaner' ),
				number_format_i18n( $rspmeac_current_page ),
				number_format_i18n( $rspmeac_total_pages )
			);
			echo '<span class="tablenav-paging-text">' . esc_html( $rspmeac_paging_text ) . '</span>';
			echo '</span>';

			// Next/Last.
			if ( $rspmeac_current_page < $rspmeac_total_pages ) {
				printf(
					'<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', min( $rspmeac_total_pages, $rspmeac_current_page + 1 ) ) ),
					esc_html__( 'Next page', 'rotistudio-post-meta-editor-cleaner' ),
					'›'
				);
				printf(
					'<a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
					esc_url( add_query_arg( 'paged', $rspmeac_total_pages ) ),
					esc_html__( 'Last page', 'rotistudio-post-meta-editor-cleaner' ),
					'»'
				);
			} else {
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
				echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
			}
			?>
		</span>
	</div>
	<?php endif; ?>
	<br class="clear" />
</div>

</form>
