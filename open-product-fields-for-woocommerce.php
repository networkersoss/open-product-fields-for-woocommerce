<?php
/**
 * Plugin Name: Open Product Fields for WooCommerce
 * Plugin URI: https://github.com/networkersoss/open-product-fields-for-woocommerce
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

/**
 * Minimum platform requirements, mirroring the plugin headers above
 * (`Requires at least`, `Requires PHP`, `WC requires at least`). The headers
 * let WordPress block activation on unsupported installs; the runtime checks
 * below keep the plugin inert — with an admin notice — when a site somehow
 * runs it anyway, instead of fatalling on missing APIs.
 */
define( 'OPF_MIN_WP', '6.5' );
define( 'OPF_MIN_WC', '9.0' );
define( 'OPF_MIN_PHP', '7.4' );

if ( version_compare( PHP_VERSION, OPF_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'opf_php_version_notice' );
	return;
}

// $GLOBALS lookup works whether this file is included in global scope
// (wp-settings.php) or inside activate_plugin()'s function scope.
$opf_wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0.0';
if ( version_compare( $opf_wp_version, OPF_MIN_WP, '<' ) ) {
	add_action( 'admin_notices', 'opf_wp_version_notice' );
	return;
}

require_once OPF_DIR . 'includes/Autoloader.php';

use OPF\Service\Admin\Builder;
use OPF\Service\Admin\CouponSettings;
use OPF\Service\Admin\ImportPage;
use OPF\Service\Admin\Settings;
use OPF\Compat\WapfHooks;
use OPF\Service\Assets;
use OPF\Service\AeliaIntegration;
use OPF\Service\CartIntegration;
use OPF\Service\Cli;
use OPF\Service\FieldGroups;
use OPF\Service\Importer;
use OPF\Service\LinkedProducts;
use OPF\Service\MetaPrettifier;
use OPF\Service\ProductPriceDisplay;
use OPF\Service\QuickView;
use OPF\Service\Renderer;
use OPF\Service\Rest;
use OPF\Service\SubscriptionIntegration;
use OPF\Service\Uploads;
use OPF\Service\WoocsIntegration;
use OPF\Service\WpmlIntegration;

/**
 * Wire the plugin up on plugins_loaded (priority 20 — after WooCommerce has
 * booted at 10). Registering hooks on the plugin file's own load order is a
 * trap: "open-product-fields…" sorts before "woocommerce", so class_exists()
 * would always be false there.
 */
add_action( 'plugins_loaded', 'opf_boot', 20 );
register_activation_hook( __FILE__, 'opf_activate' );

/**
 * Activation defaults.
 *
 * Refuses activation with a readable message when the platform is
 * unsupported; WordPress core already enforces the header minimums, this is
 * the second line of defence for edge cases (forced activation, WP-CLI,
 * outdated WooCommerce).
 */
function opf_activate(): void {
	if ( version_compare( PHP_VERSION, OPF_MIN_PHP, '<' ) ) {
		wp_die( esc_html( sprintf( 'Open Product Fields for WooCommerce requires PHP %s or newer (running: %s). The plugin was not activated.', OPF_MIN_PHP, PHP_VERSION ) ) );
	}
	if ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], OPF_MIN_WP, '<' ) ) {
		wp_die( esc_html( sprintf( 'Open Product Fields for WooCommerce requires WordPress %s or newer (running: %s). The plugin was not activated.', OPF_MIN_WP, (string) $GLOBALS['wp_version'] ) ) );
	}
	if ( defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, OPF_MIN_WC, '<' ) ) {
		wp_die( esc_html( sprintf( 'Open Product Fields for WooCommerce requires WooCommerce %s or newer (running: %s). The plugin was not activated.', OPF_MIN_WC, (string) WC_VERSION ) ) );
	}
	add_option( 'opf_version', OPF_VERSION );
	add_option( 'opf_theme_compat', 'yes' );
}

function opf_boot(): void {
	// Without this call WordPress only looks in WP_LANG_DIR/plugins for
	// translations; catalogs bundled under languages/ would never load.
	load_plugin_textdomain( 'open-product-fields-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	FieldGroups::init();

	// Keep the stored version in sync (upgrade path for future migrations).
	if ( get_option( 'opf_version' ) !== OPF_VERSION ) {
		update_option( 'opf_version', OPF_VERSION );
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'opf_wc_missing_notice' );
		return;
	}

	if ( ! defined( 'WC_VERSION' ) || version_compare( (string) WC_VERSION, OPF_MIN_WC, '<' ) ) {
		add_action( 'admin_notices', 'opf_wc_version_notice' );
		return;
	}

	Renderer::init();
	ProductPriceDisplay::init();
	CartIntegration::init();
	LinkedProducts::init();
	Assets::init();
	QuickView::init();
	WoocsIntegration::init();
	WpmlIntegration::init();
	AeliaIntegration::init();
	SubscriptionIntegration::init();
	Rest::init();
	Uploads::init();
	Importer::init();
	Builder::init();
	ImportPage::init();
	Settings::init();
	CouponSettings::init();
	MetaPrettifier::init();
	// Backward-compatible `wapf/…` aliases for migrated third-party integrations.
	WapfHooks::init();

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

/**
 * WooCommerce version notice — the plugin stays loaded but inert.
 */
function opf_wc_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required WooCommerce version, 2: running WooCommerce version. */
				__( 'Open Product Fields requires WooCommerce %1$s or newer; this site is running WooCommerce %2$s. The plugin is inactive until WooCommerce is updated.', 'open-product-fields-for-woocommerce' ),
				OPF_MIN_WC,
				defined( 'WC_VERSION' ) ? WC_VERSION : '?'
			)
		)
	);
}

/**
 * PHP version notice. Rendered before the autoloader is even required, so it
 * must not touch plugin classes; plain English because the text domain is
 * not loaded on this path.
 */
function opf_php_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( sprintf( 'Open Product Fields for WooCommerce requires PHP %s or newer; this site is running PHP %s. The plugin was not loaded.', OPF_MIN_PHP, PHP_VERSION ) )
	);
}

/**
 * WordPress version notice. Same constraints as opf_php_version_notice().
 */
function opf_wp_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( sprintf( 'Open Product Fields for WooCommerce requires WordPress %s or newer; this site is running WordPress %s. The plugin was not loaded.', OPF_MIN_WP, isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '?' ) )
	);
}
