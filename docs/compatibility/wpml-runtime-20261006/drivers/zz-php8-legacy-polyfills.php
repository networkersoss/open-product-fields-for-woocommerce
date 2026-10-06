<?php
/**
 * /tmp clone only: PHP 8 removed get_magic_quotes_gpc(), which WPML 4.2.8-era
 * String Translation still calls during boot. Polyfill, not behaviour change.
 */
if ( ! function_exists( 'get_magic_quotes_gpc' ) ) {
	function get_magic_quotes_gpc() {
		return false;
	}
}
