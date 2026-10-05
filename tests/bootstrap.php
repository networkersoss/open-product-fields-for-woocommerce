<?php
/**
 * PHPUnit bootstrap. Defines the minimal WordPress constants the engine
 * files expect and registers the autoloader. The engine is pure PHP, so no
 * WordPress installation is needed for unit tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'OPF_DIR' ) ) {
	define( 'OPF_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'OPF_URL' ) ) {
	define( 'OPF_URL', 'http://example.test/wp-content/plugins/open-product-fields-for-woocommerce/' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	define( 'OPF_VERSION', '0.1.0' );
}
if ( ! defined( 'OPF_FILE' ) ) {
	define( 'OPF_FILE', OPF_DIR . 'open-product-fields-for-woocommerce.php' );
}

require_once OPF_DIR . 'includes/Autoloader.php';

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ): string { return '/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ): string { return 'test-nonce'; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ): string { return (string) $text; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $GLOBALS['opf_test_options'][ $name ] ?? $default; }
}
if ( ! function_exists( 'get_field' ) ) {
	function get_field( $selector, $post_id = false, $format_value = true ) {
		$source = is_string( $post_id ) && in_array( $post_id, [ 'option', 'options' ], true ) ? 'option' : 'product';
		$key = $source . ':' . ( 'product' === $source ? (string) $post_id : '' ) . ':' . (string) $selector;
		return $GLOBALS['opf_test_acf_fields'][ $key ] ?? null;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ): string { return strip_tags( (string) $content, '<a><br><em><p><strong><ul><ol><li>' ); }
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( array $list, string $field ): array {
		return array_values( array_map( static fn( $item ) => is_array( $item ) ? ( $item[ $field ] ?? null ) : null, $list ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, $echo = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ): string {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
}

require_once __DIR__ . '/fixtures/engine-gettext.php';
