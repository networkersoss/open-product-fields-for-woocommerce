<?php
/**
 * Supported PHP extension API for third-party plugins.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF;

use OPF\Engine\Calculator;
use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use OPF\Service\Renderer;

defined( 'ABSPATH' ) || exit;

/** Stable entry point for supported developer extensions. */
final class API {

	/** Return whether a named OPF setting exists. */
	public static function has_setting( string $name = '' ): bool {
		$option = self::setting_option( $name );
		if ( '' === $option ) {
			return false;
		}
		$missing = new \stdClass();
		return $missing !== get_option( $option, $missing );
	}

	/** Read a setting and apply the matching `opf/setting/{name}` filter. */
	public static function get_setting( string $name, $default = null ) {
		$option = self::setting_option( $name );
		$value = '' === $option ? null : get_option( $option, null );
		if ( null === $value ) {
			$value = $default;
		}
		return apply_filters( 'opf/setting/' . sanitize_key( $name ), $value );
	}

	/** Get one published field group by post ID, or null when it is unavailable. */
	public static function get_field_group_by_id( $id ): ?array {
		$id = self::positive_id( $id );
		if ( ! $id ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'opf_field_group' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		try {
			$group = FieldGroups::group_from_post( $post );
		} catch ( \InvalidArgumentException $error ) {
			return null;
		}
		return $group ? self::group_entry( $post, $group ) : null;
	}

	/**
	 * Get published groups by ID in the same order as the supplied IDs.
	 *
	 * @param array<int,int|string> $ids Group post IDs.
	 * @return array<int,array{id:int,title:string,lang:string,group:FieldGroup}>
	 */
	public static function get_field_groups_by_ids( array $ids = [] ): array {
		$groups = [];
		foreach ( $ids as $id ) {
			$group = self::get_field_group_by_id( $id );
			if ( $group ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/** Get groups whose placement rules match a product or product ID. */
	public static function get_field_groups_of_product( $product ): array {
		$product = self::product( $product );
		return $product ? FieldGroups::for_product( $product ) : [];
	}

	/** Return whether at least one field group matches a product. */
	public static function product_has_options( $product ): bool {
		return ! empty( self::get_field_groups_of_product( $product ) );
	}

	/**
	 * Return storefront markup for all groups matching a product.
	 *
	 * @return string Empty when product or matching groups are unavailable.
	 */
	public static function display_field_groups_for_product( $product ): string {
		$product = self::product( $product );
		if ( ! $product || ! Renderer::visible_to_viewer() ) {
			return '';
		}
		$exists = array_key_exists( 'product', $GLOBALS );
		$previous_product = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $product;
		ob_start();
		try {
			Renderer::render();
			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			throw $error;
		} finally {
			if ( $exists ) {
				$GLOBALS['product'] = $previous_product;
			} else {
				unset( $GLOBALS['product'] );
			}
		}
	}

	/**
	 * Return canonical field values from current cart items.
	 *
	 * Values use OPF's stored value shape, so choice fields contain choice
	 * slugs rather than display labels.
	 *
	 * @return array<int,array{cart_item_key:string,product_id:int,fields:array<int,array{id:string,label:string,value:mixed,type:string}>>>
	 */
	public static function get_custom_fields_in_cart(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return [];
		}
		$out = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			$values = $item[ CartIntegration::ITEM_KEY ] ?? null;
			if ( ! $product instanceof \WC_Product || ! is_array( $values ) || ! $values ) {
				continue;
			}
			$fields = self::field_definitions( FieldGroups::for_product( $product ) );
			$selected = self::selected_fields( $values, $fields );
			if ( $selected ) {
				$out[] = [ 'cart_item_key' => (string) $key, 'product_id' => (int) $product->get_id(), 'fields' => $selected ];
			}
		}
		return $out;
	}

	/**
	 * Build the immutable field label/type/value snapshot stored on new orders.
	 *
	 * Each record additionally carries a `values` list — the per-value
	 * breakdown WAPF persists on its cart fields (`label`, `slug` and the
	 * already-formatted `pricing_hint`, plus `row` for repeated fields).
	 * `pricing_hint` is '' when the field suppresses hints or the value is
	 * unpriced. Pass the cart item when it is available so hints are priced
	 * against the stored line base/quantity exactly like the order meta.
	 *
	 * @param \WC_Product           $product   Product.
	 * @param array<string,mixed>   $values    gid => fid => value map.
	 * @param array<string,mixed>|null $cart_item Cart line for hint pricing.
	 * @return array<int,array{group_id:string,id:string,label:string,value:mixed,type:string,values:array<int,array{label:string,slug:string,pricing_hint:string,row?:int}>}>
	 */
	public static function field_snapshot_for_product( \WC_Product $product, array $values, ?array $cart_item = null ): array {
		$fields    = self::field_definitions( FieldGroups::for_product( $product ) );
		$breakdown = CartIntegration::selection_value_breakdown( $product, $values, $cart_item );
		$snapshot  = [];
		foreach ( $values as $group_id => $group_values ) {
			if ( ! is_array( $group_values ) ) {
				continue;
			}
			foreach ( $group_values as $field_id => $value ) {
				$field = $fields[ (string) $group_id ][ (string) $field_id ] ?? null;
				if ( ! is_array( $field ) ) {
					continue;
				}
				$snapshot[] = [
					'group_id' => (string) $group_id,
					'id'       => (string) $field['id'],
					'label'    => (string) $field['label'],
					'value'    => $value,
					'type'     => (string) $field['type'],
					'values'   => $breakdown[ (string) $group_id ][ (string) $field_id ] ?? [],
				];
			}
		}
		return $snapshot;
	}

	/**
	 * Read canonical field values from order line items.
	 *
	 * @param mixed $order WC_Order or an order ID.
	 * @return array<int,array{product_id:int|false,item_id:int,quantity:int,options:array<int,array{field_id:string,label:string,value:mixed,type:string}>>>
	 */
	public static function get_options_from_order( $order ): array {
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order_id = self::positive_id( $order );
			$order = $order_id ? wc_get_order( $order_id ) : null;
		}
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return [];
		}
		$out = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$stored = $item->get_meta( '_opf_fields', true );
			$values = is_array( $stored ) ? $stored : ( is_string( $stored ) ? json_decode( $stored, true ) : null );
			if ( ! is_array( $values ) || ! $values ) {
				continue;
			}
			$snapshot = $item->get_meta( '_opf_fields_snapshot', true );
			$snapshot = is_array( $snapshot ) ? $snapshot : ( is_string( $snapshot ) ? json_decode( $snapshot, true ) : null );
			$product = $item->get_product();
			if ( is_array( $snapshot ) ) {
				$options = array_map(
					static function ( array $field ): array {
						return [
							'id' => (string) ( $field['id'] ?? '' ),
							'label' => (string) ( $field['label'] ?? $field['id'] ?? '' ),
							'value' => $field['value'] ?? null,
							'type' => (string) ( $field['type'] ?? '' ),
						];
					},
					array_filter( $snapshot, 'is_array' )
				);
			} else {
				$groups = $product instanceof \WC_Product ? FieldGroups::for_product( $product ) : [];
				$options = self::selected_fields( $values, self::field_definitions( $groups ), true );
			}
			$records = [];
			foreach ( $options as $option ) {
				$records[] = [ 'field_id' => $option['id'], 'label' => $option['label'], 'value' => $option['value'], 'type' => $option['type'] ];
			}
			$out[] = [
				'product_id' => $product instanceof \WC_Product ? (int) $product->get_id() : false,
				'item_id'    => (int) $item->get_id(),
				'quantity'   => (int) $item->get_quantity(),
				'options'    => $records,
			];
		}
		return $out;
	}

	/** Return a field group's normalized array representation. */
	public static function field_group_to_array( FieldGroup $group ): array {
		return $group->data;
	}

	/** Create an OPF field group from untrusted array data using schema normalization. */
	public static function array_to_field_group( array $data ): FieldGroup {
		return new FieldGroup( $data );
	}

	/**
	 * Register a formula function used by OPF's server-side pricing engine.
	 *
	 * Callbacks receive the semicolon-separated argument strings and a context
	 * array with `price`, `qty`, `addons`, `value`, `field_values`, and the
	 * optional `product_id`. They must
	 * return a finite numeric value; invalid results fail closed to zero.
	 *
	 * @param string   $function  Function name used in formula expressions.
	 * @param callable $callback  Callback with signature (array $args, array $context): int|float|string.
	 * @throws \InvalidArgumentException When the name is invalid, reserved, or callback is not callable.
	 */
	public static function add_formula_function( string $function, callable $callback ): void {
		$function = strtolower( trim( $function ) );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $function ) || in_array( $function, [ 'min', 'max', 'len', 'lookuptable', 'round', 'abs', 'floor', 'ceil', 'sqrt', 'cos', 'sin', 'tan', 'pow', 'sumqty', 'checked', 'files', 'if', 'or', 'and', 'today', 'datediff', 'dow', 'month' ], true ) ) {
			throw new \InvalidArgumentException( 'Formula function name is invalid or reserved.' );
		}
		Calculator::register_formula_function( $function, $callback );
	}

	/** Map a setting name to an OPF-owned option key. */
	private static function setting_option( string $name ): string {
		$name = sanitize_key( $name );
		return '' === $name ? '' : ( 0 === strpos( $name, 'opf_' ) ? $name : 'opf_' . $name );
	}

	/** @return \WC_Product|null */
	private static function product( $product ) {
		if ( is_object( $product ) && is_a( $product, 'WC_Product' ) ) {
			return $product;
		}
		$product_id = self::positive_id( $product );
		return $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
	}

	/** Accept only positive integer IDs at public API boundaries. */
	private static function positive_id( $id ): int {
		if ( is_int( $id ) && $id > 0 ) {
			return $id;
		}
		if ( is_string( $id ) && ctype_digit( $id ) && (int) $id > 0 ) {
			return (int) $id;
		}
		return 0;
	}

	/** @return array{id:int,title:string,lang:string,group:FieldGroup} */
	private static function group_entry( \WP_Post $post, FieldGroup $group ): array {
		return [
			'id'    => (int) $post->ID,
			'title' => (string) $post->post_title,
			'lang'  => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'slug' ) : '',
			'group' => $group,
		];
	}

	/** @param array<int,array{id:int,title:string,lang:string,group:FieldGroup}> $groups */
	private static function field_definitions( array $groups ): array {
		$fields = [];
		foreach ( $groups as $entry ) {
			foreach ( $entry['group']->data['fields'] as $field ) {
				$fields[ (string) $entry['id'] ][ (string) $field['id'] ] = $field;
			}
		}
		return $fields;
	}

	/**
	 * @param array<string,mixed> $values Stored gid => fid => value map.
	 * @param array<string,array<string,array<string,mixed>>> $fields Definitions.
	 * @return array<int,array{id:string,label:string,value:mixed,type:string}>
	 */
	private static function selected_fields( array $values, array $fields, bool $include_empty = false ): array {
		$selected = [];
		foreach ( $values as $group_id => $group_values ) {
			if ( ! is_array( $group_values ) || empty( $fields[ (string) $group_id ] ) ) {
				continue;
			}
			foreach ( $group_values as $field_id => $value ) {
				$field = $fields[ (string) $group_id ][ (string) $field_id ] ?? null;
				if ( ! is_array( $field ) || ( ! $include_empty && ( '' === $value || [] === $value ) ) ) {
					continue;
				}
				$selected[] = [
					'id'    => (string) $field['id'],
					'label' => (string) $field['label'],
					'value' => $value,
					'type'  => (string) $field['type'],
				];
			}
		}
		return $selected;
	}
}
