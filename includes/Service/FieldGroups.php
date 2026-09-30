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
		add_action( 'set_object_terms', [ __CLASS__, 'flush_product_cache_for_term_change' ], 10, 4 );
		add_action( 'trashed_post', [ __CLASS__, 'flush_if_group_changed' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'flush_if_group_changed' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'flush_if_group_changed' ] );
	}

	/** Flush target-product matches when a product's category, tag, or attribute changes. */
	public static function flush_product_cache_for_term_change( int $object_id, $terms, $term_taxonomy_ids, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) && 0 !== strpos( $taxonomy, 'pa_' ) ) {
			return;
		}

		wp_cache_flush_group( 'opf_groups_for_product' );
	}

	/** Flush product matches when a field group is trashed, restored, or deleted. */
	public static function flush_if_group_changed( int $post_id ): void {
		if ( 'opf_field_group' === get_post_type( $post_id ) ) {
			self::flush_cache();
		}
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
				'lang'                     => '',
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
		$product_id = $product->get_id();
		$cached     = wp_cache_get( $product_id, 'opf_groups_for_product' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? (int) $product->get_parent_id() : $product_id;
		$placement_product = $is_variation ? wc_get_product( $parent_id ) : $product;
		if ( ! $placement_product ) {
			return [];
		}

		$has_terms = [
			'product_cat' => wc_get_product_term_ids( $parent_id, 'product_cat' ),
			'product_tag' => wc_get_product_term_ids( $parent_id, 'product_tag' ),
			'product_attribute' => self::product_attribute_term_keys( $parent_id ),
			'product_type' => [ $placement_product->get_type() ],
			'product_variation' => $is_variation ? [ (string) $product_id ] : [],
		];

		$current_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $parent_id, 'slug' ) : '';

		$matching = [];
		foreach ( self::all() as $entry ) {
			if ( $current_lang && ! empty( $entry['lang'] ) && $entry['lang'] !== $current_lang ) {
				continue;
			}
			if ( Evaluator::group_matches( $entry['group']->data, $has_terms, $parent_id ) ) {
				$matching[] = $entry;
			}
		}

		wp_cache_set( $product_id, $matching, 'opf_groups_for_product' );
		return $matching;
	}

	/**
	 * Build unambiguous placement keys for registered global product attributes.
	 *
	 * @param array<string,array<int|string>> $attributes Taxonomy => term ids.
	 * @return array<int,string> `pa_color:12` keys.
	 */
	public static function attribute_term_keys( array $attributes ): array {
		$keys = [];
		foreach ( $attributes as $taxonomy => $term_ids ) {
			$taxonomy = strtolower( (string) $taxonomy );
			if ( ! preg_match( '/^pa_[a-z0-9_-]+$/', $taxonomy ) || ! is_array( $term_ids ) ) {
				continue;
			}
			foreach ( $term_ids as $term_id ) {
				if ( ! is_scalar( $term_id ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $term_id ) ) {
					continue;
				}
				$keys[] = $taxonomy . ':' . (string) $term_id;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/** Return this product's global attribute term keys for placement matching. */
	private static function product_attribute_term_keys( int $product_id ): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) || ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
			return [];
		}

		$attributes = [];
		foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
			$name = is_object( $attribute ) ? (string) ( $attribute->attribute_name ?? '' ) : '';
			if ( '' === $name ) {
				continue;
			}
			$taxonomy = wc_attribute_taxonomy_name( $name );
			if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$attributes[ $taxonomy ] = wc_get_product_term_ids( $product_id, $taxonomy );
		}

		return self::attribute_term_keys( $attributes );
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
		$existing = $post_id > 0 ? get_post( $post_id ) : null;
		$status   = (string) ( $args['status'] ?? ( is_object( $existing ) ? $existing->post_status : 'publish' ) );

		$fields = [
			'ID'           => $post_id > 0 ? $post_id : 0,
			'post_content' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
			'post_type'    => 'opf_field_group',
			'post_status'  => $status,
			'menu_order'   => (int) ( $args['menu_order'] ?? 0 ),
		];
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
		wp_cache_flush_group( 'opf_groups_for_product' );
	}
}
