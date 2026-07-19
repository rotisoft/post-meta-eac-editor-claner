<?php
/**
 * Post Meta table page content.
 *
 * @package PostMetaEAC_RotiStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The overview index (keys, counts, sizes, sources) is built only on an
// explicit user action. Cold page views stay fast and show a Read data CTA.
$rspmeac_overview = rspmeac_get_meta_overview_cache();

if ( false === $rspmeac_overview ) {
	$rspmeac_build_url = wp_nonce_url( add_query_arg( 'rspmeac_build', '1' ), 'rspmeac_build' );
	?>
	<div class="rspmeac-notice-error">
		<p><strong><?php esc_html_e( 'Always make a backup before modifying or deleting data!', 'post-meta-eac-rotistudio' ); ?></strong></p>
	</div>

	<div class="rspmeac-overview-empty">
		<p>
			<strong><?php esc_html_e( 'Post meta data is not loaded yet.', 'post-meta-eac-rotistudio' ); ?></strong>
		</p>
		<p>
			<?php esc_html_e( 'On large sites (for example WooCommerce catalogs with many products) reading the index can take several minutes. Start when you are ready - the table and tools appear after the scan finishes.', 'post-meta-eac-rotistudio' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( $rspmeac_build_url ); ?>" class="button button-primary button-hero rspmeac-overview-empty__button">
				<span class="dashicons dashicons-database" aria-hidden="true"></span>
				<?php esc_html_e( 'Read data', 'post-meta-eac-rotistudio' ); ?>
			</a>
		</p>
	</div>
	<?php
	return;
}

$rspmeac_meta_keys   = $rspmeac_overview['meta_keys'];
$rspmeac_post_types  = $rspmeac_overview['post_types'];
$rspmeac_post_counts = $rspmeac_overview['post_counts'];
$rspmeac_sizes       = $rspmeac_overview['sizes'];
$rspmeac_sources     = $rspmeac_overview['sources'];

// Filter option lists are built from the full (unfiltered) overview so choices stay stable while filtering.
$rspmeac_source_options     = rspmeac_get_filter_source_options( $rspmeac_meta_keys, $rspmeac_sources );
$rspmeac_post_type_options  = rspmeac_get_filter_post_type_options( $rspmeac_post_types );
$rspmeac_search_key_options = rspmeac_get_search_meta_key_options( $rspmeac_meta_keys );
$rspmeac_advanced_search    = rspmeac_get_advanced_search();
$rspmeac_filters            = rspmeac_get_table_filters();

$rspmeac_meta_keys = rspmeac_apply_advanced_search( $rspmeac_meta_keys, $rspmeac_post_types, $rspmeac_advanced_search, $rspmeac_sources );
$rspmeac_meta_keys = rspmeac_filter_meta_keys( $rspmeac_meta_keys, $rspmeac_post_types, $rspmeac_filters, $rspmeac_sources );

list( $rspmeac_orderby, $rspmeac_order ) = rspmeac_get_table_order();
$rspmeac_meta_keys                       = rspmeac_sort_meta_keys( $rspmeac_meta_keys, $rspmeac_post_counts, $rspmeac_orderby, $rspmeac_order, $rspmeac_sizes, $rspmeac_sources );

// Pagination settings - slice the filtered index; do not load per-row details yet.
$per_page              = max( 1, intval( get_option( 'rspmeac_items_per_page', 40 ) ) );
$rspmeac_current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination, no state change.
$rspmeac_total_items   = count( $rspmeac_meta_keys );
$rspmeac_total_pages   = max( 1, (int) ceil( $rspmeac_total_items / $per_page ) );
$rspmeac_offset        = ( $rspmeac_current_page - 1 ) * $per_page;

// Keep the current page in range after a sort or items-per-page change.
if ( $rspmeac_current_page > $rspmeac_total_pages ) {
	$rspmeac_current_page = $rspmeac_total_pages;
	$rspmeac_offset       = ( $rspmeac_current_page - 1 ) * $per_page;
}

// Current page keys only - one short sample per key (LIMIT 1, not GROUP BY).
$rspmeac_paged_meta_keys = array_slice( $rspmeac_meta_keys, $rspmeac_offset, $per_page );
$rspmeac_sample_values   = array();
if ( ! empty( $rspmeac_paged_meta_keys ) ) {
	$rspmeac_sample_values = rspmeac_get_sample_values_for_keys( $rspmeac_paged_meta_keys );
}

?>

<div class="rspmeac-notice-error">
	<p><strong><?php esc_html_e( 'Always make a backup before modifying or deleting data!', 'post-meta-eac-rotistudio' ); ?></strong></p>
</div>

	<div class="tablenav top">
		<div class="alignleft actions bulkactions">
			<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'post-meta-eac-rotistudio' ); ?></label>
			<select name="action" id="bulk-action-selector-top" form="rspmeac-meta-form">
				<option value="-1"><?php esc_html_e( 'Bulk actions', 'post-meta-eac-rotistudio' ); ?></option>
				<option value="refresh"><?php esc_html_e( 'Refresh data', 'post-meta-eac-rotistudio' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete (key + value)', 'post-meta-eac-rotistudio' ); ?></option>
				<option value="delete_value"><?php esc_html_e( 'Delete (value only)', 'post-meta-eac-rotistudio' ); ?></option>
			</select>
			<button type="button" id="doaction" class="button action"><?php esc_html_e( 'Apply', 'post-meta-eac-rotistudio' ); ?></button>
		</div>
		<div class="alignleft actions">
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'rspmeac_refresh', '1' ), 'rspmeac_refresh' ) ); ?>" class="button">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<?php esc_html_e( 'Refresh all data', 'post-meta-eac-rotistudio' ); ?>
			</a>
			<button
				type="button"
				id="rspmeac-toggle-advanced-search"
				class="button<?php echo $rspmeac_advanced_search['active'] ? ' is-active' : ''; ?>"
				aria-expanded="<?php echo $rspmeac_advanced_search['active'] ? 'true' : 'false'; ?>"
				aria-controls="rspmeac-advanced-search-panel"
			>
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<?php esc_html_e( 'Search', 'post-meta-eac-rotistudio' ); ?>
			</button>
		</div>
		<?php rspmeac_render_pagination( $rspmeac_current_page, $rspmeac_total_pages, $rspmeac_total_items, 'top' ); ?>
		<br class="clear" />
	</div>

	<div
		id="rspmeac-advanced-search-panel"
		class="rspmeac-advanced-search-panel"
		<?php echo $rspmeac_advanced_search['active'] ? '' : 'hidden'; ?>
	>
		<?php rspmeac_render_advanced_search_form( $rspmeac_advanced_search, $rspmeac_search_key_options, $rspmeac_source_options ); ?>
	</div>

<form method="post" id="rspmeac-meta-form">

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<td id="cb" class="manage-column column-cb check-column">
				<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'post-meta-eac-rotistudio' ); ?></label>
				<input id="cb-select-all-1" type="checkbox" />
			</td>
			<?php
			rspmeac_render_table_column_header(
				array(
					'column'              => 'meta_key',
					'label'               => __( 'Meta Key', 'post-meta-eac-rotistudio' ),
					'current_orderby'     => $rspmeac_orderby,
					'current_order'       => $rspmeac_order,
					'sortable'            => true,
					'filter_search'       => true,
					'filter_search_name'  => 'filter_meta_key',
					'filter_search_value' => $rspmeac_filters['meta_key_search'],
				)
			);
			rspmeac_render_table_column_header(
				array(
					'column'                => 'source',
					'label'                 => __( 'Source', 'post-meta-eac-rotistudio' ),
					'current_orderby'       => $rspmeac_orderby,
					'current_order'         => $rspmeac_order,
					'sortable'              => true,
					'filter_select'         => true,
					'filter_select_name'    => 'filter_source',
					'filter_select_value'   => $rspmeac_filters['source'],
					'filter_select_options' => $rspmeac_source_options,
				)
			);
			rspmeac_render_table_column_header(
				array(
					'column'                => 'post_type',
					'label'                 => __( 'Post Type', 'post-meta-eac-rotistudio' ),
					'current_orderby'       => $rspmeac_orderby,
					'current_order'         => $rspmeac_order,
					'sortable'              => false,
					'filter_select'         => true,
					'filter_select_name'    => 'filter_post_type',
					'filter_select_value'   => $rspmeac_filters['post_type'],
					'filter_select_options' => $rspmeac_post_type_options,
				)
			);
			rspmeac_render_table_column_header(
				array(
					'column'          => 'count',
					'label'           => __( 'Count', 'post-meta-eac-rotistudio' ),
					'current_orderby' => $rspmeac_orderby,
					'current_order'   => $rspmeac_order,
					'sortable'        => true,
				)
			);
			rspmeac_render_table_column_header(
				array(
					'column'          => 'size',
					'label'           => __( 'Size', 'post-meta-eac-rotistudio' ),
					'current_orderby' => $rspmeac_orderby,
					'current_order'   => $rspmeac_order,
					'sortable'        => true,
				)
			);
			?>
			<th scope="col" class="manage-column" style="max-width: 30%;"><?php esc_html_e( 'Field content', 'post-meta-eac-rotistudio' ); ?></th>
			<th scope="col" class="manage-column"><?php esc_html_e( 'Actions', 'post-meta-eac-rotistudio' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $rspmeac_paged_meta_keys ) ) : ?>
			<tr>
				<td colspan="8"><?php esc_html_e( 'No post meta data found.', 'post-meta-eac-rotistudio' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $rspmeac_paged_meta_keys as $rspmeac_key ) : ?>
				<?php
				$rspmeac_sample         = isset( $rspmeac_sample_values[ $rspmeac_key ] ) ? $rspmeac_sample_values[ $rspmeac_key ] : null;
				$rspmeac_sample_display = rspmeac_truncate_sample_value( $rspmeac_sample );

				// Post types, count and size summary.
				$rspmeac_types_display = implode( ', ', $rspmeac_post_types[ $rspmeac_key ] );
				$rspmeac_total_count   = array_sum( $rspmeac_post_counts[ $rspmeac_key ] );
				$rspmeac_size_bytes    = isset( $rspmeac_sizes[ $rspmeac_key ] ) ? (int) $rspmeac_sizes[ $rspmeac_key ] : 0;
				$rspmeac_edit_id       = 'rspmeac-edit-actions-' . md5( $rspmeac_key );
				$rspmeac_delete_id     = 'rspmeac-delete-actions-' . md5( $rspmeac_key );
				?>
				<tr>
					<th scope="row" class="check-column">
					<label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $rspmeac_key ); ?>">
						<?php esc_html_e( 'Bulk select', 'post-meta-eac-rotistudio' ); ?>
					</label>
						<input id="cb-select-<?php echo esc_attr( $rspmeac_key ); ?>" type="checkbox" name="meta_keys[]" value="<?php echo esc_attr( $rspmeac_key ); ?>" />
					</th>
				<td data-label="<?php esc_attr_e( 'Meta Key', 'post-meta-eac-rotistudio' ); ?>"><code><?php echo esc_html( $rspmeac_key ); ?></code></td>
				<td data-label="<?php esc_attr_e( 'Source', 'post-meta-eac-rotistudio' ); ?>"><?php echo esc_html( isset( $rspmeac_sources[ $rspmeac_key ] ) ? $rspmeac_sources[ $rspmeac_key ] : rspmeac_get_source_for_key( $rspmeac_key ) ); ?></td>
				<td data-label="<?php esc_attr_e( 'Post Type', 'post-meta-eac-rotistudio' ); ?>"><?php echo esc_html( $rspmeac_types_display ); ?></td>
				<td data-label="<?php esc_attr_e( 'Count', 'post-meta-eac-rotistudio' ); ?>">
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
				<td data-label="<?php esc_attr_e( 'Size', 'post-meta-eac-rotistudio' ); ?>">
					<?php echo esc_html( rspmeac_format_size_mb( $rspmeac_size_bytes ) ); ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Field content', 'post-meta-eac-rotistudio' ); ?>" style="max-width: 30%; word-break: break-word;">
					<?php echo esc_html( $rspmeac_sample_display ); ?>
				</td>
				<td data-label="<?php esc_attr_e( 'Actions', 'post-meta-eac-rotistudio' ); ?>">
					<div class="rspmeac-row-actions">
						<button
							type="button"
							class="button-link rspmeac-action-toggle"
							data-rspmeac-action-panel="edit"
							aria-expanded="false"
							aria-controls="<?php echo esc_attr( $rspmeac_edit_id ); ?>"
						>
							<span class="dashicons dashicons-edit" aria-hidden="true"></span>
							<span class="screen-reader-text rspmeac-action-toggle__label"><?php esc_html_e( 'Edit', 'post-meta-eac-rotistudio' ); ?></span>
						</button>
						<button
							type="button"
							class="button-link rspmeac-action-toggle"
							data-rspmeac-action-panel="delete"
							aria-expanded="false"
							aria-controls="<?php echo esc_attr( $rspmeac_delete_id ); ?>"
						>
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<span class="screen-reader-text rspmeac-action-toggle__label"><?php esc_html_e( 'Delete', 'post-meta-eac-rotistudio' ); ?></span>
						</button>
						<div id="<?php echo esc_attr( $rspmeac_edit_id ); ?>" class="rspmeac-action-panel rspmeac-action-panel--edit" hidden>
							<label class="screen-reader-text" for="<?php echo esc_attr( $rspmeac_edit_id ); ?>-select">
								<?php esc_html_e( 'Edit actions', 'post-meta-eac-rotistudio' ); ?>
							</label>
							<select
								id="<?php echo esc_attr( $rspmeac_edit_id ); ?>-select"
								class="rspmeac-edit-actions-select"
								data-key="<?php echo esc_attr( $rspmeac_key ); ?>"
							>
								<option value=""><?php esc_html_e( 'Choose…', 'post-meta-eac-rotistudio' ); ?></option>
								<option value="overwrite"><?php esc_html_e( 'Overwrite', 'post-meta-eac-rotistudio' ); ?></option>
								<option value="search_replace_value"><?php esc_html_e( 'Search and replace (only value)', 'post-meta-eac-rotistudio' ); ?></option>
								<option value="search_replace_value_and_key"><?php esc_html_e( 'Search and replace (name and value)', 'post-meta-eac-rotistudio' ); ?></option>
							</select>
						</div>
						<div id="<?php echo esc_attr( $rspmeac_delete_id ); ?>" class="rspmeac-action-panel rspmeac-action-panel--delete" hidden>
							<label class="screen-reader-text" for="<?php echo esc_attr( $rspmeac_delete_id ); ?>-select">
								<?php esc_html_e( 'Delete actions', 'post-meta-eac-rotistudio' ); ?>
							</label>
							<select
								id="<?php echo esc_attr( $rspmeac_delete_id ); ?>-select"
								class="rspmeac-delete-actions-select"
								data-key="<?php echo esc_attr( $rspmeac_key ); ?>"
							>
								<option value=""><?php esc_html_e( 'Choose…', 'post-meta-eac-rotistudio' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete (key + value)', 'post-meta-eac-rotistudio' ); ?></option>
								<option value="delete_value"><?php esc_html_e( 'Delete (value only)', 'post-meta-eac-rotistudio' ); ?></option>
							</select>
							<span class="rspmeac-meta-status rspmeac-meta-status-delete"></span>
						</div>
					</div>
				</td>
				</tr>
				<tr class="rspmeac-inline-edit-row" style="display:none;" data-key="<?php echo esc_attr( $rspmeac_key ); ?>">
					<td colspan="8">
						<div class="rspmeac-inline-edit-overwrite" style="display:none;">
							<label><?php esc_html_e( 'New value:', 'post-meta-eac-rotistudio' ); ?>
								<input type="text" class="rspmeac-input-new-value regular-text" />
							</label>
							<button type="button" class="button button-primary rspmeac-apply-overwrite"><?php esc_html_e( 'Apply', 'post-meta-eac-rotistudio' ); ?></button>
							<button type="button" class="button rspmeac-cancel-inline-edit"><?php esc_html_e( 'Cancel', 'post-meta-eac-rotistudio' ); ?></button>
							<span class="rspmeac-meta-status-edit"></span>
						</div>
						<div class="rspmeac-inline-edit-search-replace" style="display:none;">
							<label><?php esc_html_e( 'Search:', 'post-meta-eac-rotistudio' ); ?>
								<input type="text" class="rspmeac-input-search regular-text" />
							</label>
							<label><?php esc_html_e( 'Replace with:', 'post-meta-eac-rotistudio' ); ?>
								<input type="text" class="rspmeac-input-replace regular-text" />
							</label>
							<button type="button" class="button button-primary rspmeac-apply-search-replace"><?php esc_html_e( 'Apply', 'post-meta-eac-rotistudio' ); ?></button>
							<button type="button" class="button rspmeac-cancel-inline-edit"><?php esc_html_e( 'Cancel', 'post-meta-eac-rotistudio' ); ?></button>
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
		<label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'post-meta-eac-rotistudio' ); ?></label>
		<select name="action2" id="bulk-action-selector-bottom">
			<option value="-1"><?php esc_html_e( 'Bulk actions', 'post-meta-eac-rotistudio' ); ?></option>
			<option value="refresh"><?php esc_html_e( 'Refresh data', 'post-meta-eac-rotistudio' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete (key + value)', 'post-meta-eac-rotistudio' ); ?></option>
			<option value="delete_value"><?php esc_html_e( 'Delete (value only)', 'post-meta-eac-rotistudio' ); ?></option>
		</select>
		<button type="button" id="doaction2" class="button action"><?php esc_html_e( 'Apply', 'post-meta-eac-rotistudio' ); ?></button>
	</div>
	<?php rspmeac_render_pagination( $rspmeac_current_page, $rspmeac_total_pages, $rspmeac_total_items, 'bottom' ); ?>
	<br class="clear" />
</div>

</form>
