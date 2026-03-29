<?php
/**
 * Meta key => Plugin source definitions.
 *
 * Two sections:
 *  - 'exact'  : Exact meta key matches. Plugin name => list of meta keys.
 *  - 'prefix' : Prefix-based matches.   Plugin name => list of prefixes.
 *               E.g. 'attribute_*' matches all WooCommerce product variation attributes.
 *
 * The reverse lookup map is built from this in admin-core.php.
 *
 * @package PostMetaEAC_RotiStudio
 */

return array(
    'prefix' => array(
        'All in One SEO'                                    => array(
            '_aioseo_',
        ),
        'Árukereső for Woocommerce (Bitron)'                => array(
            'arukereso_',
            'arukereso-',
            'waruk_',
        ),
        'Astra Theme'                                       => array(
            'ast-',
            'astra-',
        ),
        'Billingo Plus for WooCommerce'                     => array(
            '_wc_billingo_plus',
            'item_data__wc_billingo_plus_',
            'wc_billingo_plus_',
        ),
        'Booster for WooCommerce'                           => array(
            '_wcj_',
        ),
        'CookieYes'                                         => array(
            '_cli_',
        ),
        'Csomagpontok és Címkék WooCommerce-hez'             => array(
            '_vp_woo_pont',
        ),
        'Custom Product Tabs for WooCommerce'               => array(
            'custom_tab_',
        ),
        'Elementor'                                         => array(
            '_elementor_',
            '__elementor_',
        ),
        'Facebook for WooCommerce'                          => array(
            '_wc_facebook_',
            'fb_',
        ),
        'Generate Press, Generate Blocks'                   => array(
            '_generate_block_',
            '_generate_',
            '_generate-',
        ),
        'iThemes Security, SolidWP'                         => array(
            '_itsec_',
        ),
        'Jetpack'                                           => array(
            '_jetpack_',
            '_wpas_',
            '_publicize_',
        ),
        'Pixel Manager for WooCommerce'                     => array(
            '_wooptpm_',
        ),
        'PixelYourSite'                                     => array(
            '_pys_',
        ),
        'PixelYourSite Product Catalog Feed for WooCommerce' => array(
            'wpfoof-',
        ),
        'Popup Maker'                                       => array(
            '_aoc_',
            '_pum_',
        ),
        'Product Feed Manager For Woo (RexThemes)'          => array(
            'rex_feed_',
        ),
        'Product Feed Pro for WooCommerce'                  => array(
            '_woosea_',
        ),
        'Product GTIN (EAN, UPC, ISBN) for WooCommerce'     => array(
            '_wpm_',
        ),
        'Rank Math SEO'                                     => array(
            'rank_math_',
            '_rank_math_',
        ),
        'Recover Abandoned Cart'                            => array(
            'rac_',
        ),
        'SalesAutopilot'                                    => array(
            'salesautopilot_',
        ),
        'SEOPress'                                          => array(
            '_seopress_',
        ),
        'Smush Image Optimization'                          => array(
            'smush-',
        ),
        'Számlázz.hu integráció WooCommerce-hez'            => array(
            '_wc_szamlazz',
        ),
        'Ultimate Gift Cards for WooCommerce'               => array(
            '_wznd_',
        ),
        'WooCommerce'                                       => array(
            'attribute_',
            'pa_',
            '_billing_',
            '_cart_',
            '_customer_',
            '_shipping_',
        ),
        'WooCommerce Waitlist'                              => array(
            'wcwl_',
            'woocommerce_waitlist',
        ),
        'WordPress core'                                    => array(
            '_menu_item_',
        ),
        'WP Mailster'                                       => array(
            '_mailster_',
        ),
        'WP Meta SEO'                                       => array(
            '_metaseo_',
        ),
        'WPBakery Page Builder'                             => array(
            '_vc',
            '_wpb_',
            'vcv',
        ),
        'WPML'                                              => array(
            '_wpml_',
        ),
        'XML Feed for Google Merchant Center'               => array(
            '_xfgmc_',
            'xfgmc_',
        ),
        'YayMail Email Customizer'                          => array(
            '_yaymail_',
        ),
        'YITH Google Product Feed'                          => array(
            'yith_wcgpf_',
        ),
        'YITH Woo Product Slider Carousel'                  => array(
            'yith_wcps_',
        ),
        'YITH WooCommerce Gift Cards'                       => array(
            '_yith_wcgc_',
        ),
        'Yoast SEO'                                         => array(
            '_yoast_wpseo_',
        ),
    ),
    'exact'  => array(
        'Astra Theme'                             => array(
            'header_view',
            'layout',
            'site-content-style',
            'site-post-title',
            'site-sidebar-layout',
            'site-sidebar-style',
            'stick-header-meta',
            'theme-transparent-header-meta',
        ),
        'Barion'                                  => array(
            '_barion',
        ),
        'Classic Editor'                          => array(
            'classic-editor-remember',
        ),
        'Contact Form 7'                          => array(
            '_additional_settings',
            '_form',
            '_hash',
            '_locale',
            '_mail',
            '_mail_2',
            '_messages',
        ),
        'Jetpack'                                 => array(
            '_last_editor_used_jetpack',
            '_publicize_pending',
            '_publicize_twitter_user',
            '_wpas_done_all',
            '_jetpack_memberships_contains_paid_content',
            '_jetpack_newsletter_access',
            '_jetpack_newsletter_tier_id',
        ),
        'Max Mega Menu'                           => array(
            '_megamenu',
        ),
        'Variation Swatches for WooCommerce'      => array(
            '_coloredvariables',
            '_shop_swatches',
            '_shop_swatches_attribute',
            'swatch_options',
        ),
        'WooCommerce'                             => array(
            '__first_variation_id',
            '__is_newly_created_product',
            '_backorders',
            '_button_text',
            '_completed_date',
            '_coupon_title',
            '_crosssell_ids',
            '_default_attributes',
            '_download_expiry',
            '_download_limit',
            '_download_permissions',
            '_download_permissions_granted',
            '_downloadable',
            '_downloadable_files',
            '_featured',
            '_height',
            '_length',
            '_low_stock_amount',
            '_manage_stock',
            '_max_price_variation_id',
            '_max_regular_price_variation_id',
            '_max_sale_price_variation_id',
            '_max_variation_price',
            '_max_variation_regular_price',
            '_max_variation_sale_price',
            '_min_price_variation_id',
            '_min_regular_price_variation_id',
            '_min_sale_price_variation_id',
            '_min_variation_price',
            '_min_variation_regular_price',
            '_min_variation_sale_price',
            '_price',
            '_product_attributes',
            '_product_image_gallery',
            '_product_url',
            '_product_version',
            '_purchase_note',
            '_regular_price',
            '_sale_price',
            '_sale_price_dates_from',
            '_sale_price_dates_to',
            '_sku',
            '_sold_individually',
            '_stock',
            '_stock_status',
            '_tax_class',
            '_tax_status',
            '_upsell_ids',
            '_variation_description',
            '_virtual',
            '_wc_attachment_source',
            '_wc_average_rating',
            '_wc_rating_count',
            '_wc_review_count',
            '_weight',
            '_width',
            '_wpcom_is_markdown',
            'Gyártó',
            'manufacturer',
            'product_image_on_hover',
            'shipping_cost',
            'shipping_time',
            'total_sales',
        ),
        'WooCommerce Dynamic Price'               => array(
            '_pricing_rules',
        ),
        'WooCommerce Google Product Feed'         => array(
            '_woocommerce_gpf_data',
        ),
        'WooCommerce Waitlist'                    => array(
            '_woocommerce_waitlist_count',
        ),
        'WordPress core'                          => array(
            '_children',
            '_edit_last',
            '_edit_lock',
            '_encloseme',
            '_file_paths',
            '_global_unique_id',
            '_menu_item_classes',
            '_menu_item_menu_item_parent',
            '_menu_item_object',
            '_menu_item_object_id',
            '_menu_item_target',
            '_menu_item_type',
            '_menu_item_url',
            '_menu_item_xfn',
            '_pingme',
            '_thumbnail_id',
            '_wp_attachment_context',
            '_wp_attachment_image_alt',
            '_wp_attachment_metadata',
            '_wp_attached_file',
            '_wp_desired_post_slug',
            '_wp_old_date',
            '_wp_old_slug',
            '_wp_page_template',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
            'inline_featured_image',
            'origin',
        ),
        'WP Meta SEO'                             => array(
            'wp_metaseo_seoscore',
        ),
        'WPML'                                    => array(
            '_woo_ml_product_tracked',
            '_wpml_media_duplicate',
            '_wpml_media_featured',
            '_wpml_media_has_media',
            '_wpml_word_count',
        ),
        'YITH WooCommerce Ajax Product Filter'    => array(
            '_filters',
        ),
        'Review Reminder for WooCommerce'    => array(
            '_ivole_review_reminder',
        ),
    ),
);
