<?php
/**
 * Isolated WordPress/WooCommerce contract for locale-resolution tests.
 *
 * Deliberately defines NO Polylang functions: these tests prove the fallback
 * chain FieldGroups::for_product() uses when Polylang is absent. Loaded only
 * inside child PHPUnit processes (`#[RunTestsInSeparateProcesses]`, the
 * AeliaIntegrationTest fixture pattern), never in the main suite process.
 */

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_title;
		public string $post_content;

		public function __construct( int $id, string $title, string $content ) {
			$this->ID           = $id;
			$this->post_title   = $title;
			$this->post_content = $content;
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function get_id(): int { return 42; }
		public function get_parent_id(): int { return 0; }
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array { return $GLOBALS['opf_fallback_posts'] ?? []; }
}
if ( ! function_exists( 'wp_cache_supports' ) ) {
	function wp_cache_supports( string $feature ): bool { return 'flush_group' === $feature; }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, string $group = '' ) { return $GLOBALS['opf_fallback_cache'][ $group ][ $key ] ?? false; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, string $group = '' ): bool { $GLOBALS['opf_fallback_cache'][ $group ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'wp_cache_flush_group' ) ) {
	function wp_cache_flush_group( string $group ): bool { $GLOBALS['opf_fallback_cache'][ $group ] = []; return true; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return (bool) ( $GLOBALS['opf_fallback_logged_in'] ?? false ); }
}
if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( int $product_id, string $taxonomy ): array { return []; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $name, $value, ...$args ) {
		$callback = $GLOBALS['opf_fallback_filters'][ $name ] ?? null;
		return $callback ? $callback( $value, ...$args ) : $value;
	}
}
