<?php
/**
 * Field group repository: CRUD over the opf_field_group CPT.
 *
 * Group data lives as JSON in post_content (schema-versioned by
 * FieldGroup::SCHEMA), keeping the data inspectable, exportable and
 * portable. Placement matching is delegated to the engine evaluator.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class FieldGroups {

	/**
	 * Cache of all published groups for this request.
	 *
	 * @var array<int,array{id:int,title:string,lang:string,group:FieldGroup}>|null
	 */
	private static $all = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'duplicate_row_action' ], 10, 2 );
		add_action( 'admin_post_opf_duplicate_field_group', [ __CLASS__, 'handle_duplicate' ] );
		add_action( 'admin_notices', [ __CLASS__, 'duplicate_notice' ] );
		add_filter( 'pll_get_post_types', [ __CLASS__, 'add_cpt_to_polylang' ], 10, 2 );
	}

	/**
	 * Register the group post type with Polylang, like WAPF does for
	 * `wapf_product`. Groups are always translatable (language column,
	 * editor metabox, translation links and frontend query filtering), but
	 * hidden from the Polylang settings list so merchants cannot untranslate
	 * them. Harmless when Polylang is absent — the filter never fires.
	 *
	 * @param array<string,string> $post_types  Translated post types.
	 * @param bool                 $is_settings Whether the settings screen list is being built.
	 * @return array<string,string>
	 */
	public static function add_cpt_to_polylang( array $post_types, bool $is_settings ): array {
		if ( $is_settings ) {
			unset( $post_types['opf_field_group'] );
		} else {
			$post_types['opf_field_group'] = 'opf_field_group';
		}
		return $post_types;
	}

	/**
	 * Add a nonce-protected duplicate action to published field groups.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param \WP_Post              $post    Current post.
	 * @return array<string,string>
	 */
	public static function duplicate_row_action( array $actions, \WP_Post $post ): array {
		if ( 'opf_field_group' !== $post->post_type || 'publish' !== $post->post_status ) {
			return $actions;
		}
		$post_type = get_post_type_object( 'opf_field_group' );
		if ( ! $post_type || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $post_type->cap->create_posts ) || ! current_user_can( $post_type->cap->publish_posts ) ) {
			return $actions;
		}

		$url = add_query_arg(
			[ 'action' => 'opf_duplicate_field_group', 'post_id' => $post->ID ],
			admin_url( 'admin-post.php' )
		);
		$url = wp_nonce_url( $url, 'opf_duplicate_field_group_' . $post->ID );
		$actions['duplicate'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			/* translators: %s: field group title. */
			esc_attr( sprintf( __( 'Duplicate “%s”', 'open-product-fields-for-woocommerce' ), $post->post_title ) ),
			esc_html__( 'Duplicate', 'open-product-fields-for-woocommerce' )
		);
		return $actions;
	}

	/**
	 * Create a published copy after validating the source and capabilities.
	 */
	public static function handle_duplicate(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid field group.', 'open-product-fields-for-woocommerce' ) );
		}
		check_admin_referer( 'opf_duplicate_field_group_' . $post_id );

		$post = get_post( $post_id );
		$post_type = get_post_type_object( 'opf_field_group' );
		if ( ! $post || 'opf_field_group' !== $post->post_type || 'publish' !== $post->post_status || ! $post_type ) {
			wp_die( esc_html__( 'This field group cannot be duplicated.', 'open-product-fields-for-woocommerce' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $post_type->cap->create_posts ) || ! current_user_can( $post_type->cap->publish_posts ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this field group.', 'open-product-fields-for-woocommerce' ) );
		}

		$group = self::group_from_post( $post );
		if ( ! $group ) {
			wp_die( esc_html__( 'The field group data is invalid.', 'open-product-fields-for-woocommerce' ) );
		}
		try {
			$duplicate = FieldGroup::duplicate( $group->data );
		} catch ( \InvalidArgumentException $error ) {
			wp_die( esc_html__( 'The field group contains duplicate field IDs and cannot be copied safely.', 'open-product-fields-for-woocommerce' ) );
		}

		$new_id = self::save(
			0,
			$duplicate['group'],
			[
				/* translators: %s: original field group title. */
				'title'  => sprintf( __( '%s (Copy)', 'open-product-fields-for-woocommerce' ), $post->post_title ),
				'status' => 'publish',
			]
		);
		if ( ! $new_id ) {
			wp_die( esc_html__( 'The field group copy could not be saved.', 'open-product-fields-for-woocommerce' ) );
		}

		if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_set_post_language' ) ) {
			$language = pll_get_post_language( $post_id, 'slug' );
			if ( is_string( $language ) && '' !== $language ) {
				pll_set_post_language( $new_id, $language );
			}
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=opf_field_group&opf_duplicated=1' ) );
		exit;
	}

	/** Show the success notice after returning to the group list. */
	public static function duplicate_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-opf_field_group' !== $screen->id || empty( $_GET['opf_duplicated'] ) ) {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Field group duplicated.', 'open-product-fields-for-woocommerce' ) );
	}

	/**
	 * Register the CPT.
	 */
	public static function register_cpt(): void {
		register_post_type(
			'opf_field_group',
			[
				'labels'              => [
					'name'          => __( 'Field Groups', 'open-product-fields-for-woocommerce' ),
					'singular_name' => __( 'Field Group', 'open-product-fields-for-woocommerce' ),
					'edit_item'     => __( 'Edit Field Group', 'open-product-fields-for-woocommerce' ),
					'new_item'      => __( 'New Field Group', 'open-product-fields-for-woocommerce' ),
					'search_items'  => __( 'Search Field Groups', 'open-product-fields-for-woocommerce' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'show_in_rest'        => false,
				'capability_type'     => 'product',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => [ 'title' ],
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'delete_with_user'    => false,
			]
		);
	}

	/**
	 * All published groups with at least one field.
	 *
	 * @return array<int,array{id:int,title:string,lang:string,group:FieldGroup}>
	 */
	public static function all(): array {
		if ( null !== self::$all ) {
			return self::$all;
		}

		self::$all = [];

		$posts = get_posts(
			[
				'post_type'                => 'opf_field_group',
				'post_status'              => 'publish',
				'posts_per_page'           => -1,
				'no_found_rows'            => true,
				'update_post_term_cache'   => false,
				'update_post_meta_cache'   => false,
				'order'                    => 'ASC',
				'orderby'                  => 'menu_order title',
				'suppress_filters'         => false,
			]
		);

		foreach ( $posts as $post ) {
			$group = self::group_from_post( $post );
			if ( $group && ! empty( $group->data['fields'] ) ) {
				self::$all[] = [
					'id'    => $post->ID,
					'title' => $post->post_title,
					'lang'  => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'slug' ) : '',
					'group' => $group,
				];
			}
		}

		return self::$all;
	}

	/**
	 * Groups matching a product.
	 *
	 * Locale targeting (Polylang): groups with an assigned language render
	 * only for that language; language-less groups render everywhere. This
	 * mirrors the behaviour the legacy theme integration provided for WAPF.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array{id:int,title:string,lang:string,group:FieldGroup}>
	 */
	public static function for_product( \WC_Product $product ): array {
		// Field-level variation rules evaluate against the product currently
		// being resolved; set it before any early (cached) return.
		Evaluator::set_context_product( $product );

		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$logged_in  = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$user       = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$roles      = $logged_in && is_object( $user ) ? array_map( 'strval', (array) ( $user->roles ?? [] ) ) : [];
		sort( $roles );
		$wpml_language = apply_filters( 'wpml_current_language', null );
		$language   = function_exists( 'pll_current_language' )
			? (string) pll_current_language( 'locale' )
			: ( is_string( $wpml_language ) && '' !== $wpml_language ? $wpml_language : ( defined( 'ICL_LANGUAGE_CODE' ) ? (string) ICL_LANGUAGE_CODE : 'default' ) );
		$current_lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
		$context      = [ 'logged_in' => $logged_in, 'roles' => $roles, 'language' => $language ];
		// Cache per concrete product (variation ids included): variation-scoped
		// `product_var` matching differs between a parent and each of its
		// variations, so they cannot share a placement cache entry.
		$cache_key    = self::cache_key_for_viewer( (int) $product->get_id(), $context, (string) $current_lang );
		// WPML package translations can change without saving an OPF group.
		// Keep source groups cached per request, but do not persist translations.
		// Older WordPress/cache drop-ins cannot invalidate the whole OPF group.
		// Ignore persistent entries there, including stale entries from older code.
		$cache_results = self::can_flush_group_cache() && ( ! is_string( $wpml_language ) || '' === $wpml_language );
		$cached       = $cache_results ? wp_cache_get( $cache_key, 'opf_groups_for_product' ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$type_product = $product->get_parent_id() && function_exists( 'wc_get_product' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$get_type     = function ( $p ) {
			return is_object( $p ) && method_exists( $p, 'get_type' ) ? $p->get_type() : 'simple';
		};
		// WAPF `product_var`: a variation matches its own ID, a variable
		// parent matches when any child variation is targeted. Guarded for
		// partial WC_Product stubs (unit tests, lightweight integrations).
		$variation_scope = [];
		if ( is_object( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			$variation_scope = [ (string) $product->get_id() ];
		} elseif ( is_object( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
			$variation_scope = array_map( 'strval', (array) $product->get_children() );
		}
		$has_terms = [
			'product_cat'  => wc_get_product_term_ids( $product_id, 'product_cat' ),
			'product_tag'  => wc_get_product_term_ids( $product_id, 'product_tag' ),
			'product_type' => [ $get_type( $type_product ) ],
			'product_var'  => $variation_scope,
			// WAPF `patts`: the taxonomy attribute `attr|slug` pairs the
			// product itself defines (`*` wildcard included per attribute).
			'var_att'      => self::variation_attribute_pairs( $type_product ),
		];
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute_taxonomy ) {
				$has_terms[ 'pa_' . $attribute_taxonomy->attribute_name ] = wc_get_product_term_ids( $product_id, 'pa_' . $attribute_taxonomy->attribute_name );
			}
		}

		$matching = [];
		// Runtime-only localization keeps editors, exports and stored JSON intact.
		foreach ( apply_filters( 'opf_groups_for_product', self::all(), $product ) as $entry ) {
			if ( $current_lang && ! empty( $entry['lang'] ) && $entry['lang'] !== $current_lang ) {
				continue;
			}
			$variation_rules = [];
			if ( Evaluator::group_matches( $entry['group']->data, $has_terms, $product_id, $context, $variation_rules ) ) {
				if ( $variation_rules ) {
					// WAPF merge_frontend_conditions parity: group-level
					// variation rules become per-field gates so visibility,
					// validation and pricing follow the selected variation.
					$group = clone $entry['group'];
					$group->inject_variation_rules(
						$variation_rules,
						( is_object( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) )
							? Evaluator::product_variation_context( $product )
							: null
					);
					$entry['group'] = $group;
				}
				$matching[] = $entry;
			}
		}

		if ( $cache_results ) {
			wp_cache_set( $cache_key, $matching, 'opf_groups_for_product' );
		}
		return $matching;
	}

	/**
	 * Taxonomy attribute `attr|slug` pairs a product defines, plus the `*`
	 * wildcard per attribute. WAPF `product_has_attribute_values` non-strict
	 * parity: variation products contribute their parent's attribute terms.
	 *
	 * @param \WC_Product|mixed $product Product to inspect.
	 * @return array<int,string>
	 */
	private static function variation_attribute_pairs( $product ): array {
		$pairs = [];
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return $pairs;
		}
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->is_taxonomy() ) {
				continue;
			}
			$taxonomy = (string) $attribute->get_name();
			if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
				continue;
			}
			$attr    = substr( $taxonomy, 3 );
			$pairs[] = $attr . '|*';
			foreach ( (array) $attribute->get_options() as $term_id ) {
				$term = get_term( (int) $term_id );
				if ( $term instanceof \WP_Term ) {
					$pairs[] = $attr . '|' . $term->slug;
				}
			}
		}
		return $pairs;
	}

	/**
	 * Hydrate a FieldGroup from a post (JSON in post_content).
	 */
	public static function group_from_post( \WP_Post $post ): ?FieldGroup {
		$raw = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		return new FieldGroup( $raw );
	}

	/**
	 * Persist a group's data to a post.
	 *
	 * @param int                  $post_id Post id (0 = create).
	 * @param FieldGroup|array     $group   Group data.
	 * @param array<string,mixed>  $args    title, status, menu_order.
	 */
	public static function save( int $post_id, $group, array $args = [] ): int {
		$data    = $group instanceof FieldGroup ? $group->data : FieldGroup::normalize( $group );
		$title   = (string) ( $args['title'] ?? '' );
		// Re-saving an existing post keeps its current status unless the
		// caller asks for one — a review draft stays a draft on re-import.
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( '' === $status ) {
			$existing = $post_id > 0 ? (string) get_post_field( 'post_status', $post_id ) : '';
			$status   = '' !== $existing ? $existing : 'publish';
		}

		$fields = [
			'ID'           => $post_id > 0 ? $post_id : 0,
			'post_content' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
			'post_type'    => 'opf_field_group',
			'post_status'  => $status,
			'menu_order'   => (int) ( $args['menu_order'] ?? 0 ),
		];
		// Import provenance must exist before save_post callbacks register strings.
		if ( isset( $args['meta_input'] ) && is_array( $args['meta_input'] ) ) {
			$fields['meta_input'] = $args['meta_input'];
		}
		if ( '' !== $title ) {
			$fields['post_title'] = $title;
		}

		$has_title = $post_id > 0 ? (string) get_post_field( 'post_title', $post_id ) : '';
		if ( $post_id > 0 && '' === $title && '' === $has_title ) {
			$fields['post_title'] = __( 'Field Group', 'open-product-fields-for-woocommerce' );
		}

		$id = wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		self::flush_cache();
		return $id;
	}

	/**
	 * Flush request + object caches.
	 */
	public static function flush_cache(): void {
		self::$all = null;
		if ( self::can_flush_group_cache() ) {
			wp_cache_flush_group( 'opf_groups_for_product' );
		}
	}

	/** Persistent result caching requires scoped invalidation from the drop-in. */
	private static function can_flush_group_cache(): bool {
		return function_exists( 'wp_cache_flush_group' )
			&& function_exists( 'wp_cache_supports' )
			&& wp_cache_supports( 'flush_group' );
	}

	/**
	 * Keep cached placement results isolated by viewer and translation context.
	 *
	 * @param array{logged_in?:bool,roles?:array<int,string>,language?:string} $context Viewer context.
	 */
	private static function cache_key_for_viewer( int $product_id, array $context, string $group_language ): string {
		$roles = array_map( 'strval', (array) ( $context['roles'] ?? [] ) );
		sort( $roles );
		$context['roles'] = $roles;
		return $product_id . ':' . hash( 'sha256', serialize( [ $context, $group_language ] ) );
	}
}
