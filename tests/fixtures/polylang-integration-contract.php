<?php
/**
 * In-process WordPress/WooCommerce shims for PolylangIntegrationTest.
 *
 * PolylangIntegrationTest must pass both in the full suite — where sibling test
 * files declare these shims — and in isolation
 * (`vendor/bin/phpunit tests/Unit/PolylangIntegrationTest.php`). Every
 * declaration is guarded, and the file is required from setUp() rather than at
 * load time: PHPUnit loads every test file before running any test, so by the
 * time this runs a sibling file's *unguarded* declaration (for example
 * OPF\Service\apply_filters in WpmlIntegrationTest) already exists and the guard
 * skips ours instead of redeclaring it. Load-time declaration would fatally
 * clash with those unguarded siblings.
 *
 * The registries read here are the same ones the sibling files use, so the
 * isolated and full-suite paths observe identical state.
 */

namespace {
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
	if ( ! function_exists( 'wp_cache_supports' ) ) {
		function wp_cache_supports( string $feature ): bool { return 'flush_group' === $feature; }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) {
			$callback = $GLOBALS['opf_wpml_filters'][ $name ] ?? null;
			return $callback ? $callback( $value, ...$args ) : $value;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void {
			$GLOBALS['opf_woocs_hooks'][ $name ] = [ $callback, $priority, $accepted ];
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void {
			\add_filter( $name, $callback, $priority, $accepted );
		}
	}
}

namespace OPF\Service {
	if ( ! function_exists( __NAMESPACE__ . '\\apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) {
			$callback = $GLOBALS['opf_wpml_filters'][ $name ] ?? null;
			return $callback ? $callback( $value, ...$args ) : $value;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\do_action' ) ) {
		function do_action( $name, ...$args ): void {
			$GLOBALS['opf_wpml_actions'][] = [ $name, $args ];
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
		function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void {
			$GLOBALS['opf_woocs_hooks'][ $name ] = [ $callback, $priority, $accepted ];
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void {
			add_filter( $name, $callback, $priority, $accepted );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\get_posts' ) ) {
		function get_posts( array $args = [] ): array { return $GLOBALS['opf_auth_test_posts'] ?? []; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return (bool) ( $GLOBALS['opf_auth_test_logged_in'] ?? false ); }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wc_get_product_term_ids' ) ) {
		function wc_get_product_term_ids( int $product_id, string $taxonomy ): array { return []; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_get' ) ) {
		function wp_cache_get( $key, string $group = '' ) { return $GLOBALS['opf_auth_test_cache'][ $group ][ $key ] ?? false; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, string $group = '' ): bool { $GLOBALS['opf_auth_test_cache'][ $group ][ $key ] = $value; return true; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_flush_group' ) ) {
		function wp_cache_flush_group( string $group ): bool { $GLOBALS['opf_auth_test_cache'][ $group ] = []; return true; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
		function get_post_meta( int $id, string $key, bool $single ) {
			return $GLOBALS['opf_woocs_meta'][ $id ][ $key ] ?? '';
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\update_post_meta' ) ) {
		function update_post_meta( int $id, string $key, $value ): bool {
			$GLOBALS['opf_woocs_meta'][ $id ][ $key ] = $value;
			return true;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\get_post_field' ) ) {
		function get_post_field( string $field, int $id ) { return ''; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_slash' ) ) {
		function wp_slash( $value ) { return $value; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_wp_error' ) ) {
		function is_wp_error( $value ): bool { return false; }
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_insert_post' ) ) {
		function wp_insert_post( array $args, bool $return_error = false ): int {
			$saved = $GLOBALS['opf_import_saved_posts'] ?? [];
			$id    = 71 + count( $saved );
			$saved[] = $args;
			$GLOBALS['opf_import_saved_posts'] = $saved;
			foreach ( $args['meta_input'] ?? [] as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
			// WordPress persists meta_input before its save_post callbacks fire.
			\OPF\Service\WpmlIntegration::register_post( $id, (object) $args );
			return $id;
		}
	}
}
