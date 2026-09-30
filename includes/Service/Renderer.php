<?php
/**
 * Renders field groups on the product page.
 *
 * Compat mode emits the legacy WAPF DOM contract 1:1 (classes, ids, data
 * attributes, totals block) so the production theme's CSS and JS behave
 * identically to the legacy plugin. Functional inputs keep OPF names
 * (`opf[gid][fid]`) and the `data-opf-*` hooks for the frontend module.
 *
 * Reference markup: legacy plugin's product-page output for url / textarea /
 * text / text-swatch fields (captured from production, 2026-09).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\Evaluator;
use OPF\Engine\AcfFormula;
use OPF\Engine\Calculator;
use OPF\Engine\DateFormat;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use OPF\Engine\Prefill;
use OPF\Engine\RepeaterField;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Legacy field-type names emitted in compat mode (theme CSS hooks).
	 */
	private const COMPAT_TYPE_NAMES = [
		'text'     => 'text',
		'textarea' => 'textarea',
		'email'    => 'email',
		'url'      => 'url',
		'number'   => 'number',
		'date'     => 'date',
		'upload'   => 'upload',
		'toggle'   => 'toggle',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'swatch'   => 'text-swatch',
		'paragraph' => 'content',
		'html'      => 'content-html',
		'shortcode' => 'shortcode',
		'content_image' => 'content-image',
		'section'   => 'section',
		'calculation' => 'calculation',
	];

	/** @var array<string,string|array<int,string>>|null */
	private static $prefill = null;

	/** @var array<string,array<string,mixed>> */
	private static $cart_edit_values = [];

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render' ], 10, 0 );
	}

	/**
	 * Theme compat mode: emit the legacy wapf-* skeleton.
	 */
	public static function compat(): bool {
		return (bool) apply_filters( 'opf_theme_compat', get_option( 'opf_theme_compat', 'yes' ) === 'yes' );
	}

	/**
	 * Current price-summary presentation mode.
	 *
	 * Existing installs retain the old `opf_show_totals` choice when the new
	 * setting has not been saved. New installs follow WAPF's three-line default.
	 */
	public static function price_summary_mode(): string {
		$mode = get_option( 'opf_price_summary_mode', null );
		if ( null === $mode || '' === $mode ) {
			$legacy = get_option( 'opf_show_totals', null );
			$mode = null === $legacy ? 'three_line' : ( 'yes' === $legacy ? 'three_line' : 'hidden' );
		}
		$mode = is_string( $mode ) && in_array( $mode, [ 'three_line', 'grand_total', 'hidden' ], true ) ? $mode : 'three_line';
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'opf_price_summary_mode', $mode ) : $mode;
	}

	/** Whether the summary wrapper is visible; preserves the legacy filter. */
	public static function show_totals(): bool {
		$visible = 'hidden' !== self::price_summary_mode();
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'opf_show_totals', $visible ) : $visible;
	}

	/** Apply only sanitized customer-selected colors to the group CSS variables. */
	private static function choice_control_color( string $option, string $fallback ): string {
		$value = (string) get_option( $option, $fallback );
		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : $fallback;
	}

	/**
	 * Transition gate: when `opf_admin_only` is "yes", fields render — and the
	 * whole OPF cart layer engages — only for shop admins and E2E traffic.
	 * Customers keep seeing the legacy plugin's fields until cutover.
	 */
	public static function visible_to_viewer(): bool {
		if ( 'yes' !== get_option( 'opf_admin_only', 'no' ) ) {
			return true;
		}
		return self::viewer_bypasses_gate();
	}

	/**
	 * Admins and E2E tooling (matching OPF_E2E_TOKEN) bypass the gate.
	 */
	public static function viewer_bypasses_gate(): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$token = defined( 'OPF_E2E_TOKEN' ) ? (string) OPF_E2E_TOKEN : '';
		if ( '' === $token ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$given = isset( $_GET['opf_e2e'] ) ? (string) $_GET['opf_e2e'] : (string) ( $_SERVER['HTTP_X_OPF_E2E'] ?? '' );
		return '' !== $given && hash_equals( $token, $given );
	}

	/**
	 * Render all matching groups for the current product.
	 */
	public static function render( $groups = null ): void {
		global $product;
		if ( ! self::visible_to_viewer() ) {
			return;
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// Some WooCommerce hook paths pass the previous filter value as the
		// first argument. Only an OPF group collection is meaningful here.
		if ( null !== $groups && ! is_array( $groups ) ) {
			$groups = null;
		}

		$groups = $groups ?? FieldGroups::for_product( $product );
		if ( empty( $groups ) && ! $product->is_type( 'variable' ) ) {
			return;
		}
		$edit_item = CartIntegration::requested_cart_edit_item( $product );
		self::$cart_edit_values = $edit_item && is_array( $edit_item[ CartIntegration::ITEM_KEY ] ?? null ) ? $edit_item[ CartIntegration::ITEM_KEY ] : [];

		Assets::enqueue_frontend( self::registry( $groups, (int) $product->get_id() ) );

		$base_price = (float) $product->get_price( 'edit' );
		$product_fields = [];
		foreach ( $groups as $entry ) {
			$product_fields = array_merge( $product_fields, $entry['group']->data['fields'] );
		}
		$quantity_row_limit = RepeaterField::quantity_row_limit( $product_fields );
		$gids       = [];

		$variation_attrs = $product->is_type( 'variable' )
			? ' data-opf-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-opf-variation-url="' . esc_url( rest_url( 'opf/v1/variation-fields' ) ) . '" data-opf-base-price="' . esc_attr( (string) $base_price ) . '"'
			: '';
		echo '<div class="opf-fields" data-opf-fields="' . esc_attr( (string) count( $groups ) ) . '"' . $variation_attrs . '><div class="opf" id="opf_' . esc_attr( (string) $product->get_id() ) . '"><div class="opf-wrapper">';
		if ( $edit_item && ! empty( $edit_item['key'] ) ) {
			echo '<input type="hidden" name="opf_edit_cart_item" value="' . esc_attr( (string) $edit_item['key'] ) . '" /><input type="hidden" name="opf_edit_cart_nonce" value="' . esc_attr( wp_create_nonce( 'opf_edit_cart_' . (string) $edit_item['key'] ) ) . '" />';
		}

		foreach ( $groups as $entry ) {
			$gids[] = (string) $entry['id'];
			self::render_group( $entry['id'], $entry['title'], $entry['group'], $base_price, $quantity_row_limit, (int) $product->get_id() );
		}

		echo '<input type="hidden" value="' . esc_attr( implode( ',', $gids ) ) . '" name="opf_field_groups"/>';
		echo '</div></div></div>';

		self::render_totals( $product );
	}

	/**
	 * Client registry: gid => fid => {type, conditionals}.
	 *
	 * @param array<int,array{id:int,title:string,lang:string,group:FieldGroup}> $groups Groups.
	 */
	public static function registry( array $groups, int $product_id ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$registry = [ 'fields' => [], 'lookup_tables' => [], 'image_rules' => [], 'image_rule_modes' => [], 'formula_variables' => [], 'acf_variables' => [], 'live_previews' => $product instanceof \WC_Product ? LivePreview::for_product( $product ) : [], 'layered_images' => $product instanceof \WC_Product ? LayeredImages::for_product( $product ) : [] ];
		foreach ( $groups as $entry ) {
			$gid = (string) $entry['id'];
			$registry['lookup_tables'][ $gid ] = FieldGroup::lookup_tables_for_group( $entry['group']->data );
			$registry['image_rules'][ $gid ] = $entry['group']->data['image_rules'] ?? [];
			$registry['image_rule_modes'][ $gid ] = $entry['group']->data['image_rule_mode'] ?? 'rules';
			$registry['formula_variables'][ $gid ] = FieldGroup::formula_variables_for_group( $entry['group']->data );
			$registry['acf_variables'][ $gid ] = AcfFormula::variable_values_for_group( $entry['group']->data, $product_id );
			foreach ( $entry['group']->data['fields'] as $field ) {
				$registry['fields'][ $gid ][ $field['id'] ] = [
					'type'         => $field['type'],
					'repeat'       => $field['repeat'] ?? [],
					'conditionals' => $field['conditionals'],
					'formula'      => (string) ( $field['formula'] ?? '' ),
					'result_text'  => (string) ( $field['result_text'] ?? '{result}' ),
					'calculation_type' => (string) ( $field['calculation_type'] ?? 'informational' ),
					'image_quantities' => ! empty( $field['image_quantities'] ),
					'child_quantity_input' => 'child_products' === $field['type'] && ! empty( $field['quantity_input'] ),
					'change_product_image' => ! empty( $field['change_product_image'] ),
					'choices'      => array_map( static function ( $c ) {
					return [
						'slug'     => $c['slug'],
						'label'    => $c['label'],
						'image'    => (string) ( $c['image'] ?? '' ),
						'pricing'  => [
								'type'       => $c['pricing']['type'],
								'amount'     => (float) $c['pricing']['amount'],
								'formula'    => (string) $c['pricing']['formula'],
								'formula_raw' => (string) ( $c['pricing']['formula_raw'] ?? '' ),
								'per_unit'    => ! empty( $c['pricing']['per_unit'] ),
							],
						];
					}, (array) ( $field['choices'] ?? [] ) ),
					'pricing'      => [
						'type'     => $field['pricing']['type'],
						'amount'   => (float) $field['pricing']['amount'],
						'formula'  => (string) ( $field['pricing']['formula'] ?? '' ),
						'per_unit' => ! empty( $field['pricing']['per_unit'] ),
					],
				];
			}
		}
		return $registry;
	}

	/** Render only the groups added by one selected variation. */
	public static function variation_payload( \WC_Product $variation ): array {
		if ( ! $variation->is_type( 'variation' ) || ! self::visible_to_viewer() ) {
			return [ 'html' => '', 'registry' => [], 'base_price' => (float) $variation->get_price( 'edit' ) ];
		}
		$parent = wc_get_product( (int) $variation->get_parent_id() );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return [ 'html' => '', 'registry' => [], 'base_price' => (float) $variation->get_price( 'edit' ) ];
		}
		$parent_ids = array_fill_keys( array_map( static fn( $entry ): string => (string) $entry['id'], FieldGroups::for_product( $parent ) ), true );
		$groups = array_values( array_filter( FieldGroups::for_product( $variation ), static fn( $entry ): bool => ! isset( $parent_ids[ (string) $entry['id'] ] ) ) );
		ob_start();
		foreach ( $groups as $entry ) {
			self::render_group( $entry['id'], $entry['title'], $entry['group'], (float) $variation->get_price( 'edit' ), null, (int) $variation->get_id() );
		}
		$html = (string) ob_get_clean();
		return [
			'html'       => $html,
			'registry'   => self::registry( $groups, (int) $variation->get_id() ),
			'base_price' => (float) $variation->get_price( 'edit' ),
		];
	}

	/**
	 * Render one group.
	 *
	 * @param int|string $gid        Group post id.
	 * @param string     $title      Group title.
	 * @param FieldGroup $group      Group data.
	 * @param float      $base_price Base unit price.
	 */
	public static function render_group( $gid, string $title, FieldGroup $group, float $base_price, ?int $quantity_row_limit = null, int $product_id = 0 ): void {
		// Seed conditionals with default selections.
		$values = [];
		foreach ( $group->data['fields'] as $field ) {
			$values[ $field['id'] ] = self::default_value( $field );
		}
		if ( isset( self::$cart_edit_values[ (string) $gid ] ) ) {
			$values = array_merge( $values, self::$cart_edit_values[ (string) $gid ] );
		}
		$lookup_tables = FieldGroup::lookup_tables_for_group( $group->data );
		$formula_variables = FieldGroup::resolve_product_formula_variables( $group->data, $values, $product_id );
		$field_prices = Calculator::field_price_map( $group->data['fields'], $values, [ 'price' => $base_price, 'qty' => 1, 'lookup_tables' => $lookup_tables, 'formula_variables' => $formula_variables ] );
		$values = Calculator::resolve_calculation_values(
			$group->data['fields'],
			$values,
			[
				'price' => $base_price,
				'qty' => 1,
				'lookup_tables' => $lookup_tables,
				'formula_variables' => $formula_variables,
				'field_prices' => $field_prices,
			]
		);

		$styled_choice_controls = 'yes' === get_option( 'opf_styled_choice_controls', 'no' );
		$group_class = 'opf-field-group label-' . ( 'above' === $group->data['labels_position'] ? 'above' : 'below' );
		$style_attr = '';
		if ( $styled_choice_controls ) {
			$group_class .= ' opf-field-group--styled-choice-controls';
			$style_attr = ' style="--opf-choice-accent:' . esc_attr( self::choice_control_color( 'opf_choice_accent', '#2271b1' ) ) . ';--opf-choice-border:' . esc_attr( self::choice_control_color( 'opf_choice_border', '#68717c' ) ) . '"';
		}
		echo '<div class="' . esc_attr( $group_class ) . '"' . $style_attr . ' data-group="' . esc_attr( (string) $gid ) . '" data-variables="[]" data-opf-group="' . esc_attr( (string) $gid ) . '" data-opf-product-price="' . esc_attr( (string) $base_price ) . '">';

		foreach ( $group->data['fields'] as $field ) {
			self::render_field( $gid, $field, $values, $base_price, FieldGroup::formula_variables_for_group( $group->data ), $group->data, null, null, $quantity_row_limit, $product_id );
		}

		echo '</div>';
	}

	/**
	 * Render a single field — legacy DOM contract.
	 *
	 * @param string              $gid        Group id.
	 * @param array<string,mixed> $field      Normalized field.
	 * @param array<string,mixed> $values     Seeded values (for conditional state).
	 * @param float               $base_price Base unit price.
	 */
	private static function render_field( string $gid, array $field, array $values, float $base_price, array $group_variables = [], array $group_data = [], ?int $repeat_index = null, ?string $name_override = null, ?int $quantity_row_limit = null, int $product_id = 0 ): void {
		$fid      = $field['id'];
		$name     = $name_override ?? sprintf( 'opf[%s][%s]', $gid, $fid );
		if ( null === $repeat_index && ! empty( $field['repeat']['enabled'] ) ) {
			self::render_repeat_field( $gid, $field, $values, $base_price, $group_variables, $group_data, $quantity_row_limit, $product_id );
			return;
		}
		$hidden   = ! Evaluator::is_visible( $field, $values );
		$compat_t = self::COMPAT_TYPE_NAMES[ $field['type'] ] ?? 'text';

		$classes = [ 'opf-field-container', 'opf-field-' . $compat_t, 'field-' . $fid ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( $field['required'] ) {
			$classes[] = 'opf-required';
		}
		if ( ! empty( $field['switch_control'] ) && in_array( $field['type'], [ 'toggle', 'checkbox' ], true ) ) {
			$classes[] = 'opf-field--switch-control';
		}
		if ( $hidden ) {
			$classes[] = 'opf-hide';
		}

		$field_limit_attrs = '';
		if ( isset( $field['min_selections'] ) ) {
			$field_limit_attrs .= ' data-opf-min-selections="' . esc_attr( (string) $field['min_selections'] ) . '"';
		}
		if ( isset( $field['max_selections'] ) ) {
			$field_limit_attrs .= ' data-opf-max-selections="' . esc_attr( (string) $field['max_selections'] ) . '"';
		}
		if ( isset( $field['min_total_quantity'] ) ) {
			$field_limit_attrs .= ' data-opf-min-total-quantity="' . esc_attr( (string) $field['min_total_quantity'] ) . '"';
		}
		if ( isset( $field['max_total_quantity'] ) ) {
			$field_limit_attrs .= ' data-opf-max-total-quantity="' . esc_attr( (string) $field['max_total_quantity'] ) . '"';
		}
		$field_hook = null === $repeat_index ? ' data-opf-field="' . esc_attr( $fid ) . '"' : ' data-opf-repeat-instance="1"';
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $field_hook . $field_limit_attrs . ' style="width:' . esc_attr( (string) $field['width'] ) . '%;" for="' . esc_attr( $fid ) . '">';

		if ( in_array( $field['type'], [ 'paragraph', 'html', 'shortcode', 'content_image', 'section' ], true ) ) {
			if ( 'section' === $field['type'] ) {
				echo '<section class="opf-field-input opf-section">';
				if ( '' !== $field['heading'] ) {
					echo '<h3>' . esc_html( $field['heading'] ) . '</h3>';
				}
				echo '<div>' . esc_html( $field['content'] ) . '</div></section></div>';
				return;
			}
			if ( 'content_image' === $field['type'] ) {
				if ( '' !== $field['image_url'] ) {
					echo '<div class="opf-field-input opf-content-image"><img src="' . esc_attr( $field['image_url'] ) . '" alt="' . esc_attr( $field['alt'] ) . '" loading="lazy" decoding="async" style="max-width:100%;height:auto;" /></div>';
				}
				echo '</div>';
				return;
			}
			if ( 'shortcode' === $field['type'] ) {
				// WAPF shortcodes intentionally allow integrations such as booking calendars and iframe providers.
				$content = function_exists( 'do_shortcode' ) ? do_shortcode( $field['content'] ) : esc_html( $field['content'] );
				$class   = 'shortcode';
			} else {
				$content = 'html' === $field['type'] && function_exists( 'wp_kses_post' )
					? wp_kses_post( $field['content'] )
					: esc_html( $field['content'] );
				$class   = 'html' === $field['type'] ? 'content-html' : 'paragraph';
			}
			echo '<div class="opf-field-input opf-' . esc_attr( $class ) . '">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- content is escaped or generated by registered shortcodes.
			echo '</div>';
			return;
		}

		echo '<div class="opf-field-label"><label';
		if ( ! in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox', 'child_products', 'calculation' ], true ) ) {
			echo ' for="opf-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		echo '><span' . ( 'radio' === $field['type'] && isset( $field['card_layout'] ) ? ' id="opf-' . esc_attr( $gid . '-' . $fid ) . '-label"' : '' ) . '>' . esc_html( $field['label'] ) . '</span> ';
		if ( $field['required'] ) {
			echo '<abbr class="required" title="' . esc_attr( self::required_title() ) . '">*</abbr>';
		}
		echo '</label>';
		if ( '' !== $field['description'] && 'tooltip' === ( $field['description_presentation'] ?? 'inline' ) ) {
			$instruction_id = 'opf-' . $gid . '-' . $fid . '-instruction';
			echo '<span class="opf-instruction-tooltip"><button type="button" class="opf-instruction-tooltip__trigger" aria-label="' . esc_attr( sprintf( __( 'Instructions for %s', 'open-product-fields-for-woocommerce' ), $field['label'] ) ) . '" aria-describedby="' . esc_attr( $instruction_id ) . '" aria-expanded="false"><span aria-hidden="true">?</span></button><span class="opf-instruction-tooltip__content" role="tooltip" id="' . esc_attr( $instruction_id ) . '">' . esc_html( $field['description'] ) . '</span></span>';
		}
		echo '</div>';
		if ( ( 'calculation' === $field['type'] && 'price' === ( $field['calculation_type'] ?? 'informational' ) ) || ( ! in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox', 'calculation' ], true ) && 'none' !== ( $field['pricing']['type'] ?? 'none' ) ) ) {
			echo '<span class="opf-choice__hint opf-pricing-hint" data-opf-field-hint="1" aria-live="polite"></span>';
		}

		if ( '' !== $field['description'] && 'tooltip' !== ( $field['description_presentation'] ?? 'inline' ) ) {
			echo '<div class="opf-field-description">' . esc_html( $field['description'] ) . '</div>';
		}

		echo '<div class="opf-field-input">';
		if ( 'calculation' === $field['type'] ) {
			$formula_variables = array_replace( FieldGroup::resolve_formula_variables( $group_variables, $values ), AcfFormula::variable_values_for_group( $group_data, $product_id ) );
			$lookup_tables = FieldGroup::lookup_tables_for_group( $group_data );
			$field_prices = Calculator::field_price_map(
				(array) ( $group_data['fields'] ?? [] ),
				$values,
				[ 'price' => $base_price, 'qty' => 1, 'lookup_tables' => $lookup_tables, 'formula_variables' => $formula_variables ]
			);
			$result = Calculator::evaluate_formula( $field['formula'], $base_price, 1, 0.0, '', $values, null, [], $lookup_tables, $formula_variables, $field_prices );
			$result_text = str_replace( '{result}', (string) $result, $field['result_text'] );
			echo '<output class="opf-calculation-output" data-opf-calculation="' . esc_attr( $fid ) . '" data-opf-formula="' . esc_attr( $field['formula'] ) . '" data-opf-calculation-type="' . esc_attr( $field['calculation_type'] ) . '" data-opf-result-text="' . esc_attr( $field['result_text'] ) . '">' . esc_html( $result_text ) . '</output>';
			echo '</div></div>';
			return;
		}

		if ( 'child_products' === $field['type'] ) {
			self::render_child_products( $gid, $name, $field, $product_id );
		} elseif ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			self::render_choices( $gid, $name, $field, $base_price );
		} else {
			self::render_input( $name, $gid, $field );
		}

		echo '</div>';
		echo '</div>';
	}

	/** Render purchasable linked products as child options. */
	private static function render_child_products( string $gid, string $name, array $field, int $parent_id ): void {
		$products = ChildProductCatalog::products( $field, $parent_id );
		if ( ! $products ) {
			echo '<p class="opf-child-products-empty">' . esc_html__( 'No products are currently available.', 'open-product-fields-for-woocommerce' ) . '</p>';
			return;
		}
		$multiple = ! empty( $field['multiple'] );
		$quantity_input = ! empty( $field['quantity_input'] );
		$display = (string) ( $field['product_display'] ?? 'checkboxes' );
		$visual = in_array( $display, [ 'cards', 'images' ], true );
		$container_attrs = '';
		if ( isset( $field['min_selections'] ) ) {
			$container_attrs .= ' data-opf-min-selections="' . esc_attr( (string) $field['min_selections'] ) . '"';
		}
		if ( isset( $field['max_selections'] ) ) {
			$container_attrs .= ' data-opf-max-selections="' . esc_attr( (string) $field['max_selections'] ) . '"';
		}
		$classes = 'opf-child-products opf-child-products--' . sanitize_html_class( $display );
		echo '<div class="' . esc_attr( $classes ) . '"' . $container_attrs . '>';
		$raw_selected = self::prefill_value( $field, $gid );
		$selected = null !== $raw_selected ? $raw_selected : ( $field['default'] ?? [] );
		$selected_ids = is_array( $selected ) ? $selected : ( '' === (string) $selected ? [] : [ $selected ] );
		$quantities = [];
		if ( $quantity_input && is_array( $selected ) ) {
			$quantities = $selected;
			$selected_ids = array_keys( array_filter( $quantities, static fn( $quantity ): bool => (int) $quantity > 0 ) );
		}
		if ( 'select' === $display && ! $quantity_input ) {
			$select_name = $multiple ? $name . '[]' : $name;
			echo '<select class="opf-input opf-child-products__select" name="' . esc_attr( $select_name ) . '"' . ( $multiple ? ' multiple' : '' ) . ( ! empty( $field['required'] ) ? ' required' : '' ) . '>';
			if ( ! $multiple ) {
				echo '<option value="">' . esc_html__( 'Choose an option', 'open-product-fields-for-woocommerce' ) . '</option>';
			}
			foreach ( $products as $product ) {
				$product_id = (int) $product->get_id();
				$selected_option = in_array( (string) $product_id, array_map( 'strval', $selected_ids ), true );
				$label = $product->get_name();
				$price = wp_strip_all_tags( $product->get_price_html() );
				if ( '' !== $price ) {
					$label .= ' — ' . $price;
				}
				echo '<option value="' . esc_attr( (string) $product_id ) . '"' . selected( $selected_option, true, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></div>';
			return;
		}
		foreach ( $products as $product ) {
			$product_id = (int) $product->get_id();
			$product_name = (string) $product->get_name();
			$checked = in_array( (string) $product_id, array_map( 'strval', $selected_ids ), true );
			$type = $multiple ? 'checkbox' : 'radio';
			$input_name = $quantity_input ? $name . '[' . $product_id . ']' : ( $multiple ? $name . '[]' : $name );
			$input_value = $quantity_input ? max( 0, (int) ( $quantities[ $product_id ] ?? 0 ) ) : $product_id;
			$input_type = $quantity_input ? 'number' : $type;
			$input_attrs = 'type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( (string) $input_value ) . '"';
			if ( $quantity_input ) {
				$input_attrs .= ' min="0" step="1" aria-label="' . esc_attr( sprintf( __( 'Quantity of %s', 'open-product-fields-for-woocommerce' ), $product_name ) ) . '"';
			}
			$control_html = $quantity_input
				? '<span class="opf-child-product__quantity"><input ' . $input_attrs . ' /></span>'
				: '<span class="opf-child-product__choice"><input ' . $input_attrs . ( $checked ? ' checked' : '' ) . ' /><span class="opf-child-product__control" aria-hidden="true"></span></span>';
			$image_html = '';
			if ( $visual && $product->get_image_id() ) {
				$image_html = $product->get_image( 'woocommerce_thumbnail', [ 'class' => 'opf-child-product-image', 'alt' => $product_name ] );
				if ( ! empty( $field['image_zoom'] ) ) {
					$image_html = '<span class="opf-child-product-image-zoom" tabindex="0" role="img" aria-label="' . esc_attr( sprintf( __( 'Enlarge image: %s', 'open-product-fields-for-woocommerce' ), $product_name ) ) . '">' . $image_html . '</span>';
				}
			}
			$price_html = $product->get_price_html();
			echo '<label class="opf-child-product">' . $control_html . $image_html . '<span class="opf-child-product__details"><span class="opf-child-product__name">' . esc_html( $product_name ) . '</span>' . ( $price_html ? '<span class="opf-child-product__price">' . wp_kses_post( $price_html ) . '</span>' : '' ) . '</span></label>';
		}
		echo '</div>';
	}

	/** Render one repeated field with ordered input names and browser controls. */
	private static function render_repeat_field( string $gid, array $field, array $values, float $base_price, array $group_variables, array $group_data, ?int $quantity_row_limit = null, int $product_id = 0 ): void {
		$fid = (string) $field['id'];
		$repeat = $field['repeat'];
		$label = (string) $field['label'];
		$hidden = ! Evaluator::is_visible( $field, $values );
		$limits = '';
		foreach ( [ 'min_selections' => 'min-selections', 'max_selections' => 'max-selections' ] as $key => $attribute ) {
			if ( isset( $field[ $key ] ) ) {
				$limits .= ' data-opf-' . $attribute . '="' . esc_attr( (string) $field[ $key ] ) . '"';
			}
		}
		$mode = (string) ( $repeat['mode'] ?? 'button' );
		$repeat_max = 'quantity' === $mode ? min( RepeaterField::MAX_QUANTITY_ROWS, max( 0, $quantity_row_limit ?? RepeaterField::quantity_row_limit( $group_data['fields'] ?? [] ) ) ) : (int) ( $repeat['max'] ?? 5 );
		echo '<div class="opf-field-container opf-field-repeat' . ( $hidden ? ' opf-hide' : '' ) . '" data-opf-field="' . esc_attr( $fid ) . '" data-opf-repeat="1" data-opf-repeat-mode="' . esc_attr( $mode ) . '" data-opf-repeat-max="' . esc_attr( (string) $repeat_max ) . '"' . $limits . ' style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
		echo '<div class="opf-field-label"><span>' . esc_html( $label ) . '</span>' . ( $field['required'] ? '<abbr class="required" title="' . esc_attr( self::required_title() ) . '">*</abbr>' : '' ) . '</div>';
		echo '<div class="opf-repeat-list" data-opf-repeat-list>';
		$defaults = isset( $field['default'] ) && is_array( $field['default'] ) ? array_values( $field['default'] ) : [ $field['default'] ?? null ];
		if ( ! $defaults ) {
			$defaults = [ null ];
		}
		foreach ( array_slice( $defaults, 0, 1 ) as $index => $default ) {
			echo '<div class="opf-repeat-row" data-opf-repeat-row>';
			$instance = $field;
			$instance['id'] = $fid . '-repeat-' . $index;
			unset( $instance['repeat'] );
			if ( null !== $default ) {
				$instance['default'] = $default;
			}
			self::render_field( $gid, $instance, $values, $base_price, $group_variables, $group_data, $index, sprintf( 'opf[%s][%s][%d]', $gid, $fid, $index ), null, $product_id );
			if ( 'button' === $mode ) {
				echo '<button type="button" class="button opf-repeat-remove" data-opf-repeat-remove aria-label="' . esc_attr__( 'Remove this row', 'open-product-fields-for-woocommerce' ) . '">' . esc_html__( 'Remove', 'open-product-fields-for-woocommerce' ) . '</button>';
			}
			echo '</div>';
		}
		echo '</div>';
		if ( 'button' === $mode ) {
			echo '<button type="button" class="button opf-repeat-add" data-opf-repeat-add>' . esc_html__( 'Add another', 'open-product-fields-for-woocommerce' ) . '</button>';
		}
		echo '</div>';
	}

	/**
	 * Choice-based controls (swatch / select / radio / checkbox).
	 *
	 * @param string              $gid        Group id.
	 * @param string              $name       Input base name.
	 * @param array<string,mixed> $field      Field data.
	 * @param float               $base_price Base unit price.
	 */
	private static function render_choices( string $gid, string $name, array $field, float $base_price ): void {
		$fid = $field['id'];

		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( $name ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" class="opf-input input-' . esc_attr( $fid ) . '" autocomplete="off">';
			$prefill = self::prefill_value( $field, $gid );
			foreach ( $field['choices'] as $choice ) {
				$is_selected = null !== $prefill ? (string) $prefill === (string) $choice['slug'] : ( array_key_exists( 'default', $field ) ? (string) $field['default'] === (string) $choice['slug'] : $choice['selected'] );
				$hint_attrs = 'none' !== ( $choice['pricing']['type'] ?? 'none' )
					? ' data-opf-choice-hint="' . esc_attr( $choice['slug'] ) . '" data-opf-base-label="' . esc_attr( $choice['label'] ) . '"'
					: '';
				echo '<option value="' . esc_attr( $choice['slug'] ) . '"' . $hint_attrs . selected( $is_selected, true, false ) . '>'
					. esc_html( $choice['label'] )
					. '</option>';
			}
			echo '</select>';
			return;
		}

		if ( 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) ) {
			$min = max( 1, (int) ( $field['min'] ?? 1 ) );
			$max = isset( $field['max'] ) ? max( $min, (int) $field['max'] ) : null;
			$step = max( 1, (int) ( $field['step'] ?? 1 ) );
			$limit_attrs = '';
			if ( isset( $field['min_selections'] ) ) {
				$limit_attrs .= ' data-opf-min-selections="' . esc_attr( (string) $field['min_selections'] ) . '"';
			}
			if ( isset( $field['max_selections'] ) ) {
				$limit_attrs .= ' data-opf-max-selections="' . esc_attr( (string) $field['max_selections'] ) . '"';
			}
			echo '<div class="opf-image-quantities"' . $limit_attrs . '>';
			$prefill = self::prefill_value( $field, $gid );
			$defaults = is_array( $prefill ) ? $prefill : ( is_array( $field['default'] ?? null ) ? $field['default'] : [] );
			foreach ( $field['choices'] as $choice ) {
				$slug = (string) $choice['slug'];
				$quantity = max( 0, (int) ( $defaults[ $slug ] ?? 0 ) );
				$attrs = 'type="number" autocomplete="off" class="opf-input opf-quantity-input" data-opf-quantity-choice="' . esc_attr( $slug ) . '" data-opf-min-quantity="' . esc_attr( (string) $min ) . '" name="' . esc_attr( $name . '[' . $slug . ']' ) . '" value="' . esc_attr( (string) $quantity ) . '" min="0" step="' . esc_attr( (string) $step ) . '"';
				if ( null !== $max ) {
					$attrs .= ' max="' . esc_attr( (string) $max ) . '"';
				}
				if ( ! empty( $choice['disabled'] ) ) {
					$attrs .= ' disabled';
				}
				$hint = 'none' !== ( $choice['pricing']['type'] ?? 'none' ) ? '<span class="opf-choice__hint" data-opf-choice-hint="' . esc_attr( $slug ) . '" aria-live="polite"></span>' : '';
				$zoom_class = ! empty( $field['image_quantity_zoom'] ) ? ' opf-image-quantity--zoom' : '';
				echo '<div class="opf-image-quantity-choice' . esc_attr( $zoom_class ) . '"><label><img class="opf-swatch-image" src="' . esc_attr( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" /><span>' . esc_html( $choice['label'] ) . '</span> ' . $hint . '<input ' . $attrs . ' /></label></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- attributes escaped above.
			}
			echo '</div>';
			return;
		}

		$multi = 'checkbox' === $field['type'] || ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) );

		$limit_attrs = '';
		if ( isset( $field['min_selections'] ) ) {
			$limit_attrs .= ' data-opf-min-selections="' . esc_attr( (string) $field['min_selections'] ) . '"';
		}
		if ( isset( $field['max_selections'] ) ) {
			$limit_attrs .= ' data-opf-max-selections="' . esc_attr( (string) $field['max_selections'] ) . '"';
		}
		$is_card = 'radio' === $field['type'] && isset( $field['card_layout'] );
		$wrapper_classes = 'opf-swatch-wrapper';
		$wrapper_attrs = $limit_attrs;
		if ( 'checkbox' === $field['type'] && isset( $field['columns'] ) ) {
			$columns = max( 1, min( 12, (int) $field['columns'] ) );
			$wrapper_classes .= ' opf-checkboxes--columns';
			$wrapper_attrs .= sprintf(
				' style="--opf-checkbox-columns:%1$d;--opf-checkbox-columns-tablet:%2$d;--opf-checkbox-columns-mobile:1;"',
				$columns,
				min( $columns, 2 )
			);
		}
		if ( 'checkbox' === $field['type'] && ! empty( $field['switch_control'] ) ) {
			$wrapper_classes .= ' opf-checkboxes--switches';
		}
		if ( $is_card ) {
			$wrapper_classes .= ' opf-cards opf-cards--' . $field['card_layout'];
			$wrapper_attrs .= ' role="radiogroup" aria-labelledby="opf-' . esc_attr( $gid . '-' . $fid ) . '-label"' . ( $field['required'] ? ' aria-required="true"' : '' );
		}
		echo '<div class="' . esc_attr( $wrapper_classes ) . '"' . $wrapper_attrs . '>';
		echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';

		foreach ( $field['choices'] as $choice ) {
			$prefill = self::prefill_value( $field, $gid );
			$selected = null !== $prefill
				? ( is_array( $prefill ) ? in_array( (string) $choice['slug'], array_map( 'strval', $prefill ), true ) : (string) $prefill === (string) $choice['slug'] )
				: ( array_key_exists( 'default', $field ) ? ( is_array( $field['default'] ) ? in_array( (string) $choice['slug'], $field['default'], true ) : (string) $field['default'] === (string) $choice['slug'] ) : $choice['selected'] );
			$swatch_classes = [ 'opf-swatch', 'opf-swatch--text' ];
			$swatch_style = '';
			if ( isset( $choice['color'] ) ) {
				$swatch_classes[] = 'opf-swatch--color';
				$swatch_style = ' style="--opf-swatch-color:' . esc_attr( $choice['color'] ) . ';"';
			}
			if ( ! $multi ) {
				$swatch_classes[] = 'opf-single-select';
			}
			if ( $selected ) {
				$swatch_classes[] = 'opf-checked';
			}
			if ( $is_card ) {
				$swatch_classes[] = 'opf-card';
			}
			if ( 'swatch' === $field['type'] && ! empty( $field['image_zoom'] ) && ! empty( $choice['image'] ) ) {
				$swatch_classes[] = 'opf-swatch--image-zoom';
			}
			if ( 'none' !== $choice['pricing']['type'] ) {
				$swatch_classes[] = 'has-pricing';
			}

			$attrs = sprintf(
				'autocomplete="off" id="opf-%1$s-%2$s-%3$s" name="%4$s" class="opf-input input-%2$s" data-field-id="%2$s" value="%5$s" data-opf-label="%6$s" data-wapf-label="%6$s"%7$s%8$s%9$s%10$s',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $choice['slug'] ),
				esc_attr( $choice['label'] ),
				$field['required'] ? ' required' : '',
				$selected ? ' checked' : '',
				self::pricing_attrs( $choice['pricing'] ),
				$multi && ! empty( $field['switch_control'] ) ? ' role="switch"' : ''
			);

			echo '<div class="' . esc_attr( implode( ' ', $swatch_classes ) ) . '"' . $swatch_style . '>';
			echo '<label>';
			if ( isset( $choice['image'] ) ) {
				$image_class = $is_card ? 'opf-card__image' : 'opf-swatch-image';
				$image_alt = $is_card ? '' : (string) $choice['label'];
				echo '<img class="' . esc_attr( $image_class ) . '" src="' . esc_attr( $choice['image'] ) . '" alt="' . esc_attr( $image_alt ) . '" loading="lazy" decoding="async" />';
			}
			echo '<span class="' . ( $is_card ? 'opf-card__title' : '' ) . '">' . esc_html( $choice['label'] ) . ' </span>';
			if ( $is_card && ! empty( $choice['description'] ) ) {
				echo '<span class="opf-card__description">' . esc_html( $choice['description'] ) . '</span>';
			}
			if ( 'none' !== ( $choice['pricing']['type'] ?? 'none' ) ) {
				echo '<span class="opf-choice__hint" data-opf-choice-hint="' . esc_attr( $choice['slug'] ) . '" aria-live="polite"></span>';
			}
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			echo '</label>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Legacy data attributes for the theme's live-total math, verbatim:
	 *  - percent : data-opf-price = percent amount
	 *  - fixed   : data-opf-price = amount
	 *  - formula : data-opf-price = raw legacy expression (theme evaluates it)
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function pricing_attrs( array $pricing ): string {
		if ( 'none' === $pricing['type'] ) {
			return '';
		}
		$price = 'formula' === $pricing['type']
			? (string) ( $pricing['formula_raw'] ?? $pricing['formula'] )
			: (string) (float) $pricing['amount'];
		$type  = 'formula' === $pricing['type'] ? 'fx' : $pricing['type'];
		if ( 'fixed' === $type && ! empty( $pricing['per_unit'] ) ) {
			$type = 'qt';
		}
		return sprintf( ' data-opf-pricetype="%s" data-opf-price="%s"', esc_attr( $type ), esc_attr( $price ) );
	}

	/**
	 * Text-like inputs.
	 *
	 * @param string              $name  Input name.
	 * @param string              $gid   Group id.
	 * @param array<string,mixed> $field Field data.
	 */
	private static function render_input( string $name, string $gid, array $field ): void {
		$fid = $field['id'];
		$prefill = self::prefill_value( $field, $gid );
		$input_value = null !== $prefill && ! is_array( $prefill ) ? $prefill : ( $field['default'] ?? null );
		$prefilled_attr = null !== $input_value && ! is_array( $input_value ) ? ' value="' . esc_attr( (string) $input_value ) . '"' : '';
		$input_name = $name;
		if ( 'upload' === $field['type'] && (int) ( $field['max_files'] ?? 1 ) !== 1 ) {
			$input_name .= '[]';
		}
		$upload_uses_staged_tokens = 'upload' === $field['type'] && ( 'yes' === get_option( 'opf_modern_uploader', 'yes' ) || ! empty( $field['image_editor_mode'] ) );
		$shared = sprintf(
			'data-field-id="%1$s" id="opf-%2$s-%1$s"%3$s%5$s%6$s name="%7$s" class="opf-input input-%1$s" placeholder="%4$s" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"',
			esc_attr( $fid ),
			esc_attr( $gid ),
			( $field['required'] && ! $upload_uses_staged_tokens ? ' required' : '' ) . self::pricing_attrs( $field['pricing'] ),
			esc_attr( $field['placeholder'] ),
			self::constraint_attrs( $field ),
			$prefilled_attr,
			esc_attr( $input_name )
		);

		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea ' . $shared . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
				break;
			case 'url':
				echo '<input type="url" value="" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'email':
				echo '<input type="email" value="" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				if ( 'yes' === get_option( 'opf_number_buttons', 'no' ) ) {
					$label = trim( wp_strip_all_tags( (string) $field['label'] ) );
					$label = '' !== $label ? $label : esc_html__( 'value', 'open-product-fields-for-woocommerce' );
					echo '<div class="opf-number-stepper" data-opf-number-stepper><button type="button" class="opf-number-stepper__button" data-opf-number-step="down" aria-label="' . esc_attr( sprintf( esc_attr__( 'Decrease %s', 'open-product-fields-for-woocommerce' ), $label ) ) . '">−</button><input type="number" ' . $shared . ' /><button type="button" class="opf-number-stepper__button" data-opf-number-step="up" aria-label="' . esc_attr( sprintf( esc_attr__( 'Increase %s', 'open-product-fields-for-woocommerce' ), $label ) ) . '">+</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					echo '<input type="number" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				break;
			case 'date':
				echo '<input type="date" value="" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'upload':
				$multiple = ( isset( $field['max_files'] ) && (int) $field['max_files'] !== 1 ) ? ' multiple' : '';
				$editor_attributes = '';
				if ( ! empty( $field['image_editor_mode'] ) ) {
					$editor_attributes = ' data-opf-upload-editor="' . esc_attr( (string) $field['image_editor_mode'] ) . '"';
					foreach ( [ 'crop', 'resize', 'rotate', 'flip' ] as $option ) {
						$key = 'image_editor_' . $option;
						$editor_attributes .= ' data-opf-editor-' . $option . '="' . ( ! empty( $field[ $key ] ) ? '1' : '0' ) . '"';
					}
					$editor_attributes .= ' data-opf-editor-aspect="' . esc_attr( (string) ( $field['image_editor_aspect_ratio'] ?? 'free' ) ) . '"';
				}
				$accept   = '';
				if ( ! empty( $field['allowed_types'] ) ) {
					$tokens = [];
					foreach ( (array) $field['allowed_types'] as $type ) {
						$type     = (string) $type;
						$tokens[] = false === strpos( $type, '/' ) ? '.' . ltrim( $type, '.' ) : $type;
					}
					$accept = ' accept="' . esc_attr( implode( ',', $tokens ) ) . '"';
				}
				if ( 'yes' === get_option( 'opf_modern_uploader', 'yes' ) || ! empty( $field['image_editor_mode'] ) ) {
					global $product;
					$product_id = $product instanceof \WC_Product ? $product->get_id() : 0;
				$minimum_files = max( (int) ( $field['min_files'] ?? 0 ), ! empty( $field['required'] ) ? 1 : 0 );
				echo '<div class="opf-upload" data-opf-upload' . $editor_attributes . ' data-opf-upload-url="' . esc_url( rest_url( 'opf/v1/uploads' ) ) . '" data-opf-upload-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-opf-upload-product="' . esc_attr( (string) $product_id ) . '" data-opf-upload-group="' . esc_attr( $gid ) . '" data-opf-upload-field="' . esc_attr( $fid ) . '" data-opf-upload-max="' . esc_attr( (string) ( $field['max_files'] ?? 1 ) ) . '" data-opf-upload-min="' . esc_attr( (string) $minimum_files ) . '">';
					echo '<div class="opf-upload__drop" data-opf-upload-drop><span>' . esc_html__( 'Drop files here or choose files', 'open-product-fields-for-woocommerce' ) . '</span><input type="file" ' . $shared . $multiple . $accept . ' data-opf-upload-input /></div>';
					echo '<div class="opf-upload__status" data-opf-upload-status role="status" aria-live="polite"></div><ul class="opf-upload__list" data-opf-upload-list></ul></div>';
				} else {
					echo '<input type="file" ' . $shared . $multiple . $accept . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				break;
			case 'toggle':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
				$checked = '1' === (string) ( $input_value ?? '0' ) ? ' checked' : '';
				$role = ! empty( $field['switch_control'] ) ? ' role="switch"' : '';
				echo '<input type="checkbox" value="1" ' . $shared . $checked . $role . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				echo '<input type="text" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
		}
	}

	/** Return one signed prefill value for a configured field. */
	private static function prefill_value( array $field, string $gid ) {
		if ( isset( self::$cart_edit_values[ $gid ] ) && array_key_exists( $field['id'], self::$cart_edit_values[ $gid ] ) ) {
			return self::$cart_edit_values[ $gid ][ $field['id'] ];
		}
		$param = $field['prefill_param'] ?? '';
		if ( '' === $param ) {
			return null;
		}
		if ( null === self::$prefill ) {
			self::$prefill = [];
			if ( isset( $_GET['opf_prefill'] ) || isset( $_GET['opf_prefill_sig'] ) ) {
				$payload = isset( $_GET['opf_prefill'] ) && is_string( $_GET['opf_prefill'] ) ? (string) wp_unslash( $_GET['opf_prefill'] ) : '';
				$signature = isset( $_GET['opf_prefill_sig'] ) && is_string( $_GET['opf_prefill_sig'] ) ? (string) wp_unslash( $_GET['opf_prefill_sig'] ) : '';
				$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
				self::$prefill = Prefill::decode( $payload, $signature, $secret );
			}
		}
		if ( array_key_exists( $param, self::$prefill ) ) {
			return self::$prefill[ $param ];
		}
		if ( isset( $_GET['opf_prefill'] ) || isset( $_GET['opf_prefill_sig'] ) || ! isset( $_GET[ $param ] ) ) {
			return null;
		}

		return Prefill::from_query( $field, wp_unslash( $_GET[ $param ] ) );
	}

	/** Render browser hints that mirror server-side constraints. */
	private static function constraint_attrs( array $field ): string {
		$attrs = '';
		if ( 'date' === $field['type'] ) {
			$allow_past = ! array_key_exists( 'allow_past', $field ) || true === $field['allow_past'];
			$allow_future = ! array_key_exists( 'allow_future', $field ) || true === $field['allow_future'];
			$week_start = (int) get_option( 'start_of_week', 0 );
			if ( $week_start < 0 || $week_start > 6 ) {
				$week_start = 0;
			}
			$attrs .= ' data-opf-date-format="' . esc_attr( DateFormat::normalize( get_option( 'opf_date_format', DateFormat::DEFAULT_FORMAT ) ) ) . '"';
			$attrs .= ' data-opf-week-start="' . esc_attr( (string) $week_start ) . '"';
			$attrs .= ' data-opf-allow-past="' . ( $allow_past ? '1' : '0' ) . '"';
			$attrs .= ' data-opf-allow-future="' . ( $allow_future ? '1' : '0' ) . '"';
		}
		foreach ( [ 'min', 'max', 'step', 'maxlength', 'minlength' ] as $key ) {
			if ( array_key_exists( $key, $field ) ) {
				$attrs .= ' ' . $key . '="' . esc_attr( (string) $field[ $key ] ) . '"';
			}
		}
		if ( 'number' === $field['type'] && ! array_key_exists( 'step', $field ) ) {
			$attrs .= ' step="' . ( 'integer' === ( $field['number_mode'] ?? null ) ? '1' : 'any' ) . '"';
		}
		if ( isset( $field['pattern'] ) ) {
			$attrs .= ' pattern="' . esc_attr( (string) $field['pattern'] ) . '"';
		}
		if ( isset( $field['min_date'] ) && null !== ( $min_date = FieldValue::resolve_date_boundary( (string) $field['min_date'] ) ) ) {
			$attrs .= ' min="' . esc_attr( $min_date ) . '"';
		}
		if ( isset( $field['max_date'] ) && null !== ( $max_date = FieldValue::resolve_date_boundary( (string) $field['max_date'] ) ) ) {
			$attrs .= ' max="' . esc_attr( $max_date ) . '"';
		}
		if ( ! empty( $field['disabled_weekdays'] ) ) {
			$attrs .= ' data-opf-disabled-weekdays="' . esc_attr( wp_json_encode( array_values( $field['disabled_weekdays'] ) ) ) . '"';
		}
		if ( ! empty( $field['disabled_dates'] ) ) {
			$attrs .= ' data-opf-disabled-dates="' . esc_attr( wp_json_encode( array_values( $field['disabled_dates'] ) ) ) . '"';
		}
		if ( isset( $field['cutoff_time'] ) || ( array_key_exists( 'allow_past', $field ) && false === $field['allow_past'] ) || ( array_key_exists( 'allow_future', $field ) && false === $field['allow_future'] ) ) {
			$site_now = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			if ( isset( $field['cutoff_time'] ) ) {
				$attrs .= ' data-opf-date-cutoff="' . esc_attr( (string) $field['cutoff_time'] ) . '"';
			}
			$attrs .= ' data-opf-date-site-epoch="' . esc_attr( (string) $site_now->getTimestamp() ) . '"';
			$attrs .= ' data-opf-date-timezone="' . esc_attr( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' ) . '"';
		}
		if ( isset( $field['min_selections'] ) ) {
			$attrs .= ' data-opf-min-selections="' . esc_attr( (string) $field['min_selections'] ) . '"';
		}
		if ( isset( $field['max_selections'] ) ) {
			$attrs .= ' data-opf-max-selections="' . esc_attr( (string) $field['max_selections'] ) . '"';
		}
		return $attrs;
	}

	/**
	 * Localized totals labels for the legacy totals block.
	 *
	 * @return array{product_total:string,options_total:string,grand_total:string,required_title:string}
	 */
	private static function i18n(): array {
		$default = [
			'product_total'  => __( 'Product total', 'open-product-fields-for-woocommerce' ),
			'options_total'  => __( 'Options total', 'open-product-fields-for-woocommerce' ),
			'grand_total'    => __( 'Grand total', 'open-product-fields-for-woocommerce' ),
			'required_title' => __( 'required', 'open-product-fields-for-woocommerce' ),
		];
		$map = get_option( 'opf_compat_i18n', [] );
		if ( ! is_array( $map ) ) {
			return $default;
		}
		$lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
		return isset( $map[ $lang ] ) && is_array( $map[ $lang ] ) ? array_merge( $default, $map[ $lang ] ) : $default;
	}

	/**
	 * Required-marker tooltip text for the current locale.
	 */
	private static function required_title(): string {
		$i18n = self::i18n();
		return '' !== $i18n['required_title'] ? $i18n['required_title'] : __( 'required', 'open-product-fields-for-woocommerce' );
	}

	/**
	 * The legacy totals block the theme's quantity module reads.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function render_totals( \WC_Product $product ): void {
		if ( ! self::compat() ) {
			return;
		}
		$mode = self::price_summary_mode();
		$hidden = self::show_totals() ? '' : ' opf-totals-hidden';
		$i18n = self::i18n();
		echo '<div class="opf-product-totals' . esc_attr( $hidden ) . '" style="' . ( self::show_totals() ? '' : 'display:none;' ) . '" data-opf-summary-mode="' . esc_attr( $mode ) . '" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-opf-tax-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-opf-price-preview-url="' . esc_url( rest_url( 'opf/v1/price-preview' ) ) . '" data-product-type="' . esc_attr( $product->get_type() ) . '" data-product-price="' . esc_attr( (string) $product->get_price() ) . '"><div class="opf--inner">';
		$row_hidden = 'grand_total' === $mode ? ' style="display:none;"' : '';
		echo '<div class="opf-summary-row opf-summary-product"' . $row_hidden . '><span>' . esc_html( $i18n['product_total'] ) . '</span> <span class="opf-total opf-product-total price amount"></span></div>';
		echo '<div class="opf-summary-row opf-summary-options"' . $row_hidden . '><span>' . esc_html( $i18n['options_total'] ) . '</span> <span class="opf-total opf-options-total price amount"></span></div>';
		echo '<div class="opf-summary-row opf-summary-grand-total"><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		echo '<p class="opf-price-preview-status" role="alert" hidden>' . esc_html__( 'The price preview could not be updated. The final price will be calculated by WooCommerce.', 'open-product-fields-for-woocommerce' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Default submitted value for a field (seeds conditional evaluation).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @return string|array
	 */
	private static function default_value( array $field ) {
		if ( array_key_exists( 'default', $field ) ) {
			return $field['default'];
		}
		if ( 'toggle' === $field['type'] ) {
			return '0';
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$selected = [];
			foreach ( $field['choices'] as $choice ) {
				if ( $choice['selected'] && ! $choice['disabled'] ) {
					$selected[] = $choice['slug'];
				}
			}
			return $selected;
		}
		return '';
	}
}
