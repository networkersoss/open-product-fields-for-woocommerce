<?php
/**
 * Plugin Name: Open Product Fields for WooCommerce
 * Plugin URI: https://github.com/netwokersllc/open-product-fields-for-woocommerce
 * Description: Build custom product fields and add-ons for WooCommerce — conditional logic, server-side pricing, and first-class block checkout support. Free and open source.
 * Version: 0.1.1
 * Author: ssthormess
 * Author URI: https://github.com/ssthormess
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: open-product-fields-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.1
 *
 * Copyright (C) 2026 ssthormess
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'OPF_VERSION', '0.1.1' );
define( 'OPF_FILE', __FILE__ );
define( 'OPF_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPF_URL', plugin_dir_url( __FILE__ ) );

require_once OPF_DIR . 'includes/Autoloader.php';

use OPF\Engine\UploadService;
use OPF\Service\Admin\Builder;
use OPF\Service\Admin\FormulaVariables;
use OPF\Service\Admin\ImportPage;
use OPF\Service\Admin\Settings;
use OPF\Service\Assets;
use OPF\Service\CartIntegration;
use OPF\Service\Cli;
use OPF\Service\FieldGroups;
use OPF\Service\Importer;
use OPF\Service\MetaPrettifier;
use OPF\Service\ProductPriceDisplay;
use OPF\Service\LivePreview;
use OPF\Service\LayeredImages;
use OPF\Service\QuantityPrefill;
use OPF\Service\Renderer;
use OPF\Service\Rest;

/**
 * Wire the plugin up on plugins_loaded (priority 20 — after WooCommerce has
 * booted at 10). Registering hooks on the plugin file's own load order is a
 * trap: "open-product-fields…" sorts before "woocommerce", so class_exists()
 * would always be false there.
 */
add_action( 'plugins_loaded', 'opf_boot', 20 );
register_activation_hook( __FILE__, 'opf_activate' );
register_deactivation_hook( __FILE__, 'opf_deactivate' );

/**
 * Activation defaults.
 */
function opf_activate(): void {
	add_option( 'opf_version', OPF_VERSION );
	add_option( 'opf_theme_compat', 'yes' );
	if ( ! wp_next_scheduled( 'opf_cleanup_uploads' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'opf_cleanup_uploads' );
	}
}

/**
 * Remove scheduled work.
 */
function opf_deactivate(): void {
	wp_clear_scheduled_hook( 'opf_cleanup_uploads' );
}

function opf_boot(): void {
	FieldGroups::init();

	add_action(
		'opf_cleanup_uploads',
		static function () {
			UploadService::cleanup_orphans();
		}
	);

	// Keep the stored version in sync (upgrade path for future migrations).
	if ( get_option( 'opf_version' ) !== OPF_VERSION ) {
		update_option( 'opf_version', OPF_VERSION );
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'opf_wc_missing_notice' );
		return;
	}

	Renderer::init();
	ProductPriceDisplay::init();
	LivePreview::init();
	LayeredImages::init();
	CartIntegration::init();
	Assets::init();
	Rest::init();
	Importer::init();
	Builder::init();
	ImportPage::init();
	FormulaVariables::init();
	Settings::init();
	MetaPrettifier::init();
	QuantityPrefill::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		Cli::init();
	}

	// Declare compatibility with WooCommerce feature sets.
	add_action(
		'before_woocommerce_init',
		static function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			}
		}
	);
}

/**
 * WooCommerce missing notice.
 */
function opf_wc_missing_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Open Product Fields requires WooCommerce to be installed and active.', 'open-product-fields-for-woocommerce' );
	echo '</p></div>';
}
