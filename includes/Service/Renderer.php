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

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use OPF\Engine\WapfDesign;

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
		'toggle'   => 'toggle',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'swatch'   => 'text-swatch',
		'image_quantity' => 'image-swatch-qty',
		'products' => 'products',
		'upload' => 'file',
		'paragraph' => 'content',
		'html'      => 'content-html',
		'shortcode' => 'shortcode',
		'content_image' => 'content-image',
		'section'   => 'section',
		'child_products' => 'child-products',
		'calc' => 'calc',
		'calculation' => 'calculation',
	];

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render' ], 10 );
	}

	/**
	 * Theme compat mode: emit the legacy wapf-* skeleton.
	 */
	public static function compat(): bool {
		$compat = get_option( 'opf_theme_compat', 'yes' ) === 'yes';
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'opf_theme_compat', $compat ) : $compat;
	}

	/**
	 * Totals block: hidden by default (the legacy setup also hid it). The
	 * data attributes stay in the DOM for the theme's currency converter;
	 * flip `opf_show_totals` to display the visible totals rows.
	 */
	public static function show_totals(): bool {
		$show = 'hidden' !== self::price_summary_mode();
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'opf_show_totals', $show ) : $show;
	}

	/**
	 * Summary mode: 'three' (3-line), 'grand' (grand total only), 'hidden'.
	 * New installs default to the documented 3-line mode; when the new option
	 * has never been saved, a previously saved legacy `opf_show_totals`
	 * ('yes'/'no') choice is mapped onto it.
	 */
	public static function summary_mode(): string {
		$mode = get_option( 'opf_price_summary_mode', null );
		if ( null === $mode || '' === $mode || false === $mode ) {
			$legacy = get_option( 'opf_show_totals', false );
			if ( false !== $legacy && '' !== $legacy ) {
				return 'yes' === $legacy ? 'three' : 'hidden';
			}
			return 'three';
		}
		return in_array( $mode, [ 'three', 'grand', 'hidden' ], true ) ? $mode : 'three';
	}

	/**
	 * WAPF-spelling price-summary mode (`three_line` | `grand_total` |
	 * `hidden`). The stored option keeps both vocabularies working: new
	 * spellings win, legacy 'three'/'grand' values and the boolean
	 * `opf_show_totals` choice map forward.
	 */
	public static function price_summary_mode(): string {
		$mode = get_option( 'opf_price_summary_mode', null );
		if ( is_string( $mode ) && in_array( $mode, [ 'three_line', 'grand_total', 'hidden' ], true ) ) {
			$resolved = $mode;
		} elseif ( 'three' === $mode ) {
			$resolved = 'three_line';
		} elseif ( 'grand' === $mode ) {
			$resolved = 'grand_total';
		} elseif ( null === $mode || '' === $mode || false === $mode ) {
			$legacy = get_option( 'opf_show_totals', null );
			$resolved = null === $legacy ? 'three_line' : ( 'yes' === $legacy ? 'three_line' : 'hidden' );
		} else {
			$resolved = 'three_line';
		}
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'opf_price_summary_mode', $resolved ) : $resolved;
	}

	/**
	 * Sanitize a stored hex color, falling back when the value is malformed.
	 */
	private static function choice_control_color( string $option, string $fallback ): string {
		$value = (string) get_option( $option, $fallback );
		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : $fallback;
	}

	/** Per-option price hints toggle. */
	public static function show_price_hints(): bool {
		$show = get_option( 'opf_show_price_hints', 'yes' ) === 'yes';
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'opf_show_price_hints', $show ) : $show;
	}

	/**
	 * Signed price hint HTML for a pricing block, e.g. "+ $5.00".
	 *
	 * WAPF 3.1.5 Helper::format_pricing_hint parity: percent hints stay a
	 * percent-derived figure (WAPF adjust_addon_price never tax-adjusts
	 * percent types), while fixed and formula amounts are converted through
	 * the store's shop tax display rules — wc_get_price_to_display for a
	 * positive, taxable product price. Negative and empty amounts render
	 * verbatim exactly like WAPF's maybe_add_tax early return.
	 *
	 * @param array<string,mixed> $pricing    Normalized pricing.
	 * @param float               $base_price Product base unit price.
	 * @param \WC_Product|null    $product    Product (tax class + customer tax context).
	 */
	public static function pricing_hint_html( array $pricing, float $base_price, ?\WC_Product $product = null ): string {
		if ( ! self::show_price_hints() ) {
			return '';
		}
		if ( empty( $pricing ) || 'none' === ( $pricing['type'] ?? 'none' ) ) {
			return '';
		}
		$type   = (string) $pricing['type'];
		$amount = 0.0;
		switch ( $type ) {
			case 'fixed':
				$amount = (float) $pricing['amount'];
				break;
			case 'percent':
				$amount = $base_price * ( (float) $pricing['amount'] / 100 );
				break;
			case 'formula':
				$amount = Calculator::evaluate_formula( (string) ( $pricing['formula'] ?? '0' ), $base_price, 1, 0.0 );
				break;
			default:
				return '';
		}
		// WAPF `wapf/html/pricing_hint/amount` parity: currency converters can
		// rewrite the raw amount before tax/display adjustment.
		$amount = function_exists( 'apply_filters' ) ? (float) apply_filters( 'opf_pricing_hint_amount', $amount, $product, $type, 'product' ) : $amount;
		if ( 'percent' !== $type ) {
			$amount = self::hint_price_with_tax( $product, $amount );
		}
		$sign = $amount < 0 ? '-' : '+';
		$price_html = function_exists( 'wc_price' ) ? wc_price( abs( $amount ) ) : esc_html( (string) abs( $amount ) );
		return ' <span class="opf-pricing-hint">' . $sign . ' ' . $price_html . '</span>';
	}

	/**
	 * WAPF 3.1.5 Helper::maybe_add_tax($product, $price, 'shop') verbatim:
	 * empty or negative amounts and a missing tax context render untouched;
	 * otherwise the amount is run through wc_get_price_to_display so the hint
	 * follows woocommerce_tax_display_shop + prices-entered-with-tax.
	 *
	 * @param \WC_Product|null $product Product.
	 * @param float            $amount  Amount to convert.
	 */
	private static function hint_price_with_tax( ?\WC_Product $product, float $amount ): float {
		$with_tax = $amount;
		if ( empty( $amount ) || $amount < 0 || ! $product instanceof \WC_Product ) {
			return function_exists( 'apply_filters' ) ? (float) apply_filters( 'opf_pricing_price_with_tax', $with_tax, $amount, $product, 'shop' ) : $with_tax;
		}
		if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && function_exists( 'wc_get_price_to_display' ) ) {
			$with_tax = (float) wc_get_price_to_display( $product, [ 'qty' => 1, 'price' => $amount ] );
		}
		return function_exists( 'apply_filters' ) ? (float) apply_filters( 'opf_pricing_price_with_tax', $with_tax, $amount, $product, 'shop' ) : $with_tax;
	}

	/**
	 * WAPF 3.1.5 Helper::get_tax_multiplier verbatim: the real rate applied to
	 * the product (1 + summed WC_Tax::calc_tax on $1), 1 for non-taxable
	 * products and VAT-exempt customers. Missing Woo context degrades to 1.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function tax_multiplier( \WC_Product $product ): float {
		$multiplier = 1.0;
		if ( $product->is_taxable() ) {
			$customer = function_exists( 'WC' ) && WC() && ! empty( WC()->customer ) ? WC()->customer : null;
			if ( $customer && $customer->get_is_vat_exempt() ) {
				return 1.0;
			}
			if ( class_exists( '\WC_Tax' ) ) {
				$tax_rates  = \WC_Tax::get_rates( $product->get_tax_class() );
				$taxes      = \WC_Tax::calc_tax( 1, $tax_rates );
				$multiplier = 1 + array_sum( $taxes );
			}
		}
		return (float) $multiplier;
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
	public static function render(): void {
		global $product;
		if ( ! self::visible_to_viewer() ) {
			return;
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$groups = FieldGroups::for_product( $product );
		if ( empty( $groups ) ) {
			return;
		}

		Assets::enqueue_frontend( self::registry( $groups, $product ) );

		// WAPF alias bridge: wapf/pricing/product.
		$base_price = (float) \OPF\Compat\WapfHooks::pricing_product( (float) $product->get_price( 'edit' ), $product );
		$gids       = [];

		// WAPF alias bridge: legacy wapf_before_wrapper action.
		\OPF\Compat\WapfHooks::before_wrapper( $product );
		// Cart-edit prefill (WAPF-INTERACTION-CART-EDIT): stored values for the
		// line being edited feed field defaults + conditional seeds. Null on a
		// plain product page — renders identically to before.
		$edit_context = class_exists( CartEdit::class ) ? CartEdit::for_product( $product ) : null;
		$edit_values  = $edit_context ? $edit_context['values'] : null;
		$edit_qty     = $edit_context ? max( 1, (int) ( $edit_context['item']['quantity'] ?? 1 ) ) : 1;

		echo '<div class="opf-fields" data-opf-fields="' . esc_attr( (string) count( $groups ) ) . '"><div class="opf" id="opf_' . esc_attr( (string) $product->get_id() ) . '"><div class="opf-wrapper">';

		foreach ( $groups as $entry ) {
			$gids[] = (string) $entry['id'];
			self::render_group( $entry['id'], $entry['title'], $entry['group'], $base_price, $product, $edit_values[ (string) $entry['id'] ] ?? null, $edit_qty );
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
	private static function registry( array $groups, ?\WC_Product $product = null ): array {
		$registry = [];
		foreach ( $groups as $entry ) {
			$gid = (string) $entry['id'];
			// WAPF field-group carries its custom variables + field defs so the
			// browser can expand [var_name] (and evaluate variables' own rules)
			// while the shopper edits the form. The keys are prefixed to avoid
			// colliding with field ids (`__opf_variables`, `__opf_fields`).
			$variables = is_array( $entry['group']->data['variables'] ?? null ) ? $entry['group']->data['variables'] : [];
			if ( $variables ) {
				$registry[ $gid ]['__opf_variables'] = $variables;
			}
			$field_defs = [];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( ! in_array( $field['type'], [ 'section', 'section_end' ], true ) ) {
					$field_defs[] = [ 'id' => $field['id'], 'type' => $field['type'] ];
				}
			}
			if ( $field_defs ) {
				$registry[ $gid ]['__opf_formula_fields'] = $field_defs;
			}
			foreach ( $entry['group']->data['fields'] as $field ) {
				$choices = 'products' === $field['type']
					? LinkedProducts::product_choices( $field, $product )
					: (array) ( $field['choices'] ?? [] );
				$registry[ $gid ][ $field['id'] ] = [
					'type'         => $field['type'],
					'subtype'      => $field['subtype'] ?? null,
					'qty_selector' => 'products' === $field['type'] && LinkedProducts::is_qty_subtype( $field ),
					'repeat'       => $field['repeat'] ?? null,
					'multiple'     => ! empty( $field['multiple'] ) || ( 'products' === $field['type'] && in_array( $field['subtype'] ?? '', [ 'checkbox', 'image', 'card', 'vcard' ], true ) ),
					'min_choices'  => $field['min_choices'] ?? null,
					'max_choices'  => $field['max_choices'] ?? null,
					'conditionals' => $field['conditionals'],
					'choices'      => array_map( static function ( $c ) {
						if ( isset( $c['product'] ) ) {
							// Child prices belong to their own cart lines. Expose only
							// catalog pricing for the preview, never the product object.
							return [
								'slug'             => (string) $c['slug'],
								'label'            => (string) $c['label'],
								'disabled'         => ! empty( $c['disabled'] ),
								'child_price_type' => (string) $c['pricing_type'],
								'child_price'      => (float) $c['pricing_amount'],
							];
						}
					return [
						'slug'     => $c['slug'],
						'label'    => $c['label'],
						'disabled' => ! empty( $c['disabled'] ),
						'quantity' => $c['quantity'] ?? null,
						'pricing'  => [
								'type'       => $c['pricing']['type'],
								'amount'     => (float) $c['pricing']['amount'],
								'formula'    => (string) $c['pricing']['formula'],
								'formula_raw' => (string) ( $c['pricing']['formula_raw'] ?? '' ),
								'per_unit'   => ! empty( $c['pricing']['per_unit'] ),
							],
						];
					}, $choices ),
					'pricing'      => [
						'type'    => $field['pricing']['type'],
						'amount'  => (float) $field['pricing']['amount'],
						'formula' => (string) ( $field['pricing']['formula'] ?? '' ),
						'formula_raw' => (string) ( $field['pricing']['formula_raw'] ?? '' ),
						'per_unit' => ! empty( $field['pricing']['per_unit'] ),
					],
				];
				if ( 'calc' === $field['type'] ) {
					$registry[ $gid ][ $field['id'] ]['calc_type']     = $field['calc_type'] ?? 'default';
					$registry[ $gid ][ $field['id'] ]['formula']       = (string) ( $field['formula'] ?? '' );
					$registry[ $gid ][ $field['id'] ]['result_format'] = $field['result_format'] ?? 'number';
					$registry[ $gid ][ $field['id'] ]['result_text']   = (string) ( $field['result_text'] ?? '{result}' );
				}
			}
		}
		return $registry;
	}

	/**
	 * Render one group.
	 *
	 * @param int|string $gid        Group post id.
	 * @param string     $title      Group title.
	 * @param FieldGroup $group      Group data.
	 * @param float      $base_price Base unit price.
	 * @param \WC_Product|null $product  Product.
	 * @param array|null $prefill    Cart-edit stored values `fid => value` (null = normal render).
	 * @param int        $edit_qty   Edited cart line quantity (quantity-mode row padding).
	 */
	/** Settings hash of the WAPF design CSS already printed this request. */
	private static $design_css_hash = null;

	/**
	 * Print the migrated WAPF `wapf_design_settings` stylesheet once per
	 * distinct settings revision. Emitting `:root` + `.wapf-custom` rules lets
	 * theme/add-on CSS written for WAPF (`--apf-*`, `.wapf-custom`) apply to
	 * OPF's native controls.
	 */
	private static function emit_wapf_design_css(): void {
		$css = WapfDesign::css();
		if ( '' === $css ) {
			return;
		}
		$hash = md5( $css );
		if ( $hash === self::$design_css_hash ) {
			return;
		}
		self::$design_css_hash = $hash;
		echo '<style id="opf-wapf-design-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- generated from sanitized WAPF design keys.
	}

	public static function render_group( $gid, string $title, FieldGroup $group, float $base_price, ?\WC_Product $product = null, ?array $prefill = null, int $edit_qty = 1 ): void {
		$group_fields = $group->data['fields'];
		// fid => repeat mode for fields nested inside a repeating section
		// (mirrors CartIntegration::section_repeat_context semantics).
		$section_repeat_fids = self::section_repeat_fids( $group_fields );

		// Seed conditionals with the stored edit values when editing; a
		// repeated field's seed is its first row so Evaluator keeps its
		// scalar/list contract.
		$values = [];
		foreach ( $group_fields as $field ) {
			$repeated = ! empty( $field['repeat']['enabled'] ) || isset( $section_repeat_fids[ $field['id'] ] );
			$values[ $field['id'] ] = self::seed_value( $field, $prefill[ $field['id'] ] ?? null, $repeated );
		}

		// WAPF parity: the group exposes its custom variables on the wrapper so
		// the browser formula evaluator can expand [var_name]. OPF additionally
		// ships them via the client registry; the attribute keeps integrations
		// (and WAPF-shaped selectors) working unchanged.
		$variables = is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [];
		$variables_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $variables ) : json_encode( $variables );
		$group_attrs = ' data-variables="' . esc_attr( (string) $variables_json ) . '"';
		// WAPF gallery-image bridge: group-level `layout.enable_gallery_images`
		// emits data-wapf-st (swap type) + data-wapf-gi ({images,rules}) — the
		// same attributes WAPF 3.1.5 renders on .wapf-field-group. OPF aliases
		// ride alongside so integrations can pick either spelling.
		$gallery_rules = self::gallery_image_rules( $group->data['layout'] ?? null );
		if ( null !== $gallery_rules ) {
			$gallery_json = self::attr_json( $gallery_rules['payload'] );
			$group_attrs .= ' data-wapf-st="' . esc_attr( $gallery_rules['swap_type'] ) . '" data-wapf-gi="' . $gallery_json . '"'
				. ' data-opf-st="' . esc_attr( $gallery_rules['swap_type'] ) . '" data-opf-gi="' . $gallery_json . '"';
		}

		// WAPF design layer: emit the migrated `wapf_design_settings` `:root`
		// variables + `.wapf-custom` skin once per settings revision so theme
		// CSS written for WAPF keeps working.
		self::emit_wapf_design_css();

		// Styled native controls opt-in (opf_styled_choice_controls): the group
		// carries the accent/border CSS variables so themed ::check colors apply.
		$group_class = 'opf-field-group label-' . ( 'above' === $group->data['labels_position'] ? 'above' : 'below' );
		if ( 'yes' === get_option( 'opf_styled_choice_controls', 'no' ) ) {
			$group_class .= ' opf-field-group--styled-choice-controls';
			$group_attrs .= ' style="--opf-choice-accent:' . esc_attr( self::choice_control_color( 'opf_choice_accent', '#2271b1' ) ) . ';--opf-choice-border:' . esc_attr( self::choice_control_color( 'opf_choice_border', '#68717c' ) ) . '"';
		}
		// data-opf-product-price seeds the frontend pricing module's base price
		// (WAPF data-product-price parity).
		$group_attrs .= ' data-opf-product-price="' . esc_attr( (string) $base_price ) . '"';
		echo '<div class="' . esc_attr( $group_class ) . '" data-group="' . esc_attr( (string) $gid ) . '"' . $group_attrs . ' data-opf-group="' . esc_attr( (string) $gid ) . '">';

		$section_stack = [];
		$section_repeat_index = null;
		$section_repeat_mode = null;
		$mark_required = ! isset( $group->data['mark_required'] ) || ! empty( $group->data['mark_required'] );
		foreach ( $group_fields as $index => $field ) {
			// Group layout flag (WAPF mark_required): when off, required
			// fields render without the asterisk but stay validated.
			$field['_opf_mark_required'] = $mark_required;
			if ( 'section' === $field['type'] ) {
				$previous_repeat_index = $section_repeat_index;
				$previous_repeat_mode = $section_repeat_mode;
				$repeat = $field['repeat'] ?? [];
				$repeat_mode = (string) ( $repeat['mode'] ?? '' );
				$repeats_section = ! empty( $repeat['enabled'] ) && in_array( $repeat_mode, [ 'button', 'quantity' ], true );
				if ( $repeats_section ) {
					$classes = [ 'opf-field-container', 'opf-field-repeat', 'opf-section-repeat', 'field-' . $field['id'] ];
					if ( '' !== $field['css_class'] ) {
						$classes[] = $field['css_class'];
					}
					// Cart-edit: section clones beyond the server-rendered
					// first instance are created + filled by frontend JS
					// (WAPF `data-edit-cart` parity).
					$edit_rows = null !== $prefill
						? self::section_edit_rows( $group_fields, $index, $prefill, 'quantity' === $repeat_mode ? $edit_qty : 0 )
						: [];
					$edit_rows_attr = $edit_rows ? ' data-opf-edit-rows="' . esc_attr( (string) wp_json_encode( $edit_rows ) ) . '"' : '';
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $field['id'] ) . '" data-opf-repeat="' . esc_attr( $repeat_mode ) . '" data-opf-section-repeat="1" data-opf-repeat-max="' . esc_attr( (string) ( $repeat['max'] ?? 10000 ) ) . '"' . $edit_rows_attr . '>';
					echo '<div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1">';
					if ( '' !== $field['label'] ) {
						echo '<div class="opf-section-repeat__label"><span>' . esc_html( $field['label'] ) . '</span></div>';
					}
					self::render_section( $field, $values, false );
					$section_repeat_index = 0;
					$section_repeat_mode = $repeat_mode;
				} else {
					self::render_section( $field, $values );
				}
				$section_stack[] = [
					'repeats' => $repeats_section,
					'mode' => $repeat_mode,
					'options' => $repeat,
					'previous_repeat_index' => $previous_repeat_index,
					'previous_repeat_mode' => $previous_repeat_mode,
				];
				continue;
			}
			if ( 'section_end' === $field['type'] ) {
				if ( $section_stack ) {
					$section_context = array_pop( $section_stack );
					echo '</div>';
					if ( $section_context['repeats'] ) {
						echo '</div></div>';
						if ( 'button' === $section_context['mode'] ) {
							$add_label = (string) ( $section_context['options']['add'] ?? __( 'Add another', 'open-product-fields-for-woocommerce' ) );
							echo '<button type="button" class="opf-field-repeat__add">' . esc_html( $add_label ) . '</button>';
						}
						echo '<span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
					}
					$section_repeat_index = $section_context['previous_repeat_index'];
					$section_repeat_mode = $section_context['previous_repeat_mode'];
				}
				continue;
			}
			if ( ! empty( $field['repeat']['enabled'] ) ) {
				$repeat_rows = self::edit_rows_for( $prefill[ $field['id'] ] ?? null, 'quantity' === ( $field['repeat']['mode'] ?? '' ) ? $edit_qty : 0 );
				self::render_repeated_field( $gid, $field, $values, $base_price, $section_repeat_index, $product, $repeat_rows );
			} else {
				// Inside a repeating section the stored value is row-indexed;
				// instance 0 renders with row 0's value (WAPF `$value` parity).
				$stored = null !== $section_repeat_index
					? self::row_at( $prefill[ $field['id'] ] ?? null, 0 )
					: ( $prefill[ $field['id'] ] ?? null );
				$render_field = null === $stored ? $field : self::with_prefill( $field, $stored );
				self::render_field( $gid, $render_field, $values, $base_price, false, $section_repeat_index, 'quantity' === $section_repeat_mode, $product );
			}
		}
		while ( $section_stack ) {
			$section_context = array_pop( $section_stack );
			echo '</div>';
			if ( $section_context['repeats'] ) {
				echo '</div></div><span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
			}
			$section_repeat_index = $section_context['previous_repeat_index'];
			$section_repeat_mode = $section_context['previous_repeat_mode'];
		}

		echo '</div>';
	}

	/**
	 * Build the WAPF `data-wapf-gi` payload for a group layout, or null when
	 * gallery images are disabled/unusable. Mirrors
	 * FieldGroup::get_gallery_image_rules() (installed Extended 3.1.5):
	 * rules keep {values:[{field,value}],image:id}; `images` collects
	 * wc_get_product_attachment_props() rows keyed by attachment id.
	 *
	 * @param array<string,mixed>|null $layout Normalized group layout.
	 * @return array<string,mixed>|null
	 */
	private static function gallery_image_rules( ?array $layout ): ?array {
		if ( empty( $layout['enable_gallery_images'] ) || empty( $layout['gallery_images'] ) || ! is_array( $layout['gallery_images'] ) ) {
			return null;
		}
		$images = [];
		$rules  = [];
		foreach ( $layout['gallery_images'] as $gallery_image ) {
			if ( ! is_array( $gallery_image ) || empty( $gallery_image['id'] ) || empty( $gallery_image['values'] ) ) {
				continue;
			}
			$image_id = (string) $gallery_image['id'];
			$rules[]  = [
				'values' => array_map( static function ( $value ) {
					return [ 'field' => (string) ( $value['field'] ?? '' ), 'value' => (string) ( $value['value'] ?? '*' ) ];
				}, (array) $gallery_image['values'] ),
				'image'  => $image_id,
			];
			if ( ! isset( $images[ $image_id ] ) && function_exists( 'wc_get_product_attachment_props' ) ) {
				$props = wc_get_product_attachment_props( (int) $image_id );
				if ( is_array( $props ) && ! empty( $props['src'] ) ) {
					$images[ $image_id ] = array_merge( $props, [ 'image_id' => $image_id ] );
				}
			}
		}
		if ( ! $rules || ! $images ) {
			return null;
		}
		return [
			// WAPF keeps {images,rules} in data-wapf-gi and the swap type in
			// data-wapf-st; `payload` preserves that exact attribute shape.
			'payload'   => [
				'images' => array_values( $images ),
				'rules'  => $rules,
			],
			'swap_type' => in_array( $layout['swap_type'] ?? 'rules', [ 'rules', 'last' ], true ) ? $layout['swap_type'] : 'rules',
		];
	}

	/**
	 * JSON-encode a payload for a data attribute (WAPF
	 * `Util::to_html_attribute_string` parity: compact JSON, attribute-escaped).
	 *
	 * @param array<string,mixed> $data Payload.
	 */
	private static function attr_json( array $data ): string {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return esc_attr( (string) $json );
	}

	/**
	 * sanitize_html_class() with a test-env fallback (the unit bootstrap does
	 * not load formatting.php).
	 */
	private static function html_class( string $class ): string {
		if ( function_exists( 'sanitize_html_class' ) ) {
			return sanitize_html_class( $class );
		}
		$clean = (string) preg_replace( '/[^A-Za-z0-9_-]/', '-', $class );
		return '' === $clean ? 'value' : $clean;
	}

	/** Render the opening wrapper for a WAPF-compatible section marker. */
	private static function render_section( array $field, array $values, bool $include_field_attribute = true ): void {
		$classes = [ 'opf-section', 'wapf-section', 'field-' . $field['id'] ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( ! empty( $field['conditionals'] ) ) {
			$classes[] = 'has-conditions';
		}
		if ( ! Evaluator::is_visible( $field, $values ) ) {
			$classes[] = 'opf-hide';
		}
		// WAPF alias bridge: wapf/html/section_container_classes.
		$classes = \OPF\Compat\WapfHooks::section_container_classes( $classes, $field );
		$field_attribute = $include_field_attribute ? ' data-opf-field="' . esc_attr( $field['id'] ) . '"' : '';
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $field_attribute . ' style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
		// WAPF section fields carry a heading plus descriptive content
		// (options.heading / options.p_content in the legacy schema).
		$heading = (string) ( $field['heading'] ?? '' );
		if ( '' !== $heading ) {
			echo '<h3>' . esc_html( $heading ) . '</h3>';
		}
		$content = (string) ( $field['content'] ?? '' );
		if ( '' !== $content ) {
			echo '<div class="opf-section-content">' . esc_html( $content ) . '</div>';
		}
	}

	/**
	 * Render a single field — legacy DOM contract.
	 *
	 * @param string              $gid        Group id.
	 * @param array<string,mixed> $field      Normalized field.
	 * @param array<string,mixed> $values     Seeded values (for conditional state).
	 * @param float               $base_price Base unit price.
	 */
	private static function render_repeated_field( string $gid, array $field, array $values, float $base_price, ?int $section_repeat_index = null, ?\WC_Product $product = null, ?array $edit_rows = null ): void {
		$fid = (string) $field['id'];
		$repeat = $field['repeat'];
		$mode = (string) ( $repeat['mode'] ?? '' );
		if ( ! in_array( $mode, [ 'button', 'quantity' ], true ) || ! in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
			echo '<div class="opf-field-container opf-field-repeat opf-field-repeat--unsupported" data-opf-field="' . esc_attr( $fid ) . '">';
			echo '<div class="opf-field-label"><span>' . esc_html( $field['label'] ) . '</span></div>';
			$message = 'button' === ( $repeat['mode'] ?? '' )
				? __( 'This repeated field type is not available yet.', 'open-product-fields-for-woocommerce' )
				: __( 'This quantity-based repeated field is not available yet.', 'open-product-fields-for-woocommerce' );
			echo '<p class="opf-field-repeat__notice">' . esc_html( $message ) . '</p></div>';
			return;
		}
		$hidden = ! Evaluator::is_visible( $field, $values );
		$classes = [ 'opf-field-container', 'opf-field-repeat', 'field-' . $fid ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( $field['required'] ) {
			$classes[] = 'opf-required';
		}
		if ( $hidden ) {
			$classes[] = 'opf-hide';
		}
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $fid ) . '" data-opf-repeat="' . esc_attr( $mode ) . '" data-opf-repeat-max="' . esc_attr( (string) ( $repeat['max'] ?? 10000 ) ) . '" style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
		echo '<div class="opf-field-repeat__rows">';
		// Cart-edit: every stored row renders as a real instance (null = the
		// standard single default instance). `update()`/`syncQuantity` in the
		// frontend re-index names, labels and remove buttons on init.
		$rows = null === $edit_rows || ! $edit_rows ? [ null ] : array_values( $edit_rows );
		foreach ( $rows as $row_index => $row_value ) {
			$instance = null === $row_value ? $field : self::with_prefill( $field, $row_value );
			$instance['_opf_source_id'] = $fid;
			$instance['_opf_repeat_index'] = $row_index;
			$instance['id'] = $fid . '-repeat-' . $row_index;
			self::render_field( $gid, $instance, $values, $base_price, true, $section_repeat_index, 'quantity' === $mode, $product );
		}
		if ( 'button' === ( $repeat['mode'] ?? 'button' ) ) {
			$add_label = (string) ( $repeat['add'] ?? __( 'Add another', 'open-product-fields-for-woocommerce' ) );
			echo '</div><button type="button" class="opf-field-repeat__add">' . esc_html( $add_label ) . '</button>';
		} else {
			echo '</div>';
		}
		echo '<span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
	}

	private static function render_field( string $gid, array $field, array $values, float $base_price, bool $repeat_instance = false, ?int $section_repeat_index = null, bool $qty_based = false, ?\WC_Product $product = null ): void {
		$fid      = $field['id'];
		$source_fid = (string) ( $field['_opf_source_id'] ?? $fid );
		$name     = sprintf( 'opf[%s][%s]', $gid, $source_fid );
		if ( null !== $section_repeat_index ) {
			$name .= '[' . $section_repeat_index . ']';
		}
		if ( isset( $field['_opf_repeat_index'] ) ) {
			$name .= '[' . (int) $field['_opf_repeat_index'] . ']';
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

		// WAPF alias bridge: wapf/html/field_container_classes.
		$classes = \OPF\Compat\WapfHooks::field_container_classes( $classes, $field );

		$repeat_attr = $repeat_instance ? ' data-opf-repeat-instance="1"' : ' data-opf-field="' . esc_attr( $fid ) . '"';
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $repeat_attr . ' style="width:' . esc_attr( (string) $field['width'] ) . '%;" for="' . esc_attr( $fid ) . '">';
		if ( 'content_image' === $field['type'] ) {
			$rendered_image = false;
			$attachment_id = (int) ( $field['image_id'] ?? 0 );
			$image_alt = (string) ( $field['alt'] ?? '' );
			if ( '' === trim( $image_alt ) ) {
				$image_alt = (string) ( $field['label'] ?? '' );
			}
			if ( $attachment_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
				$image = wp_get_attachment_image( $attachment_id, 'full', false, [ 'alt' => $image_alt, 'loading' => 'lazy', 'decoding' => 'async' ] );
				if ( is_string( $image ) && '' !== $image ) {
					echo '<div class="opf-field-content-image">' . $image . '</div>';
					$rendered_image = true;
				}
			}
			$src = (string) ( $field['image_url'] ?? '' );
			if ( ! $rendered_image && '' !== $src ) {
				echo '<div class="opf-field-content-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $image_alt ) . '" loading="lazy" decoding="async" style="max-width:100%;height:auto;" /></div>';
			}
			echo '</div>';
			return;
		}
		// WAPF standalone content types: sanitized html and registered shortcodes.
		if ( in_array( $field['type'], [ 'html', 'shortcode' ], true ) ) {
			if ( 'shortcode' === $field['type'] ) {
				// WAPF shortcodes intentionally allow integrations such as booking calendars and iframe providers.
				$content = function_exists( 'do_shortcode' ) ? do_shortcode( (string) ( $field['content'] ?? '' ) ) : esc_html( (string) ( $field['content'] ?? '' ) );
				$class   = 'shortcode';
			} else {
				$content = function_exists( 'wp_kses_post' ) ? wp_kses_post( (string) ( $field['content'] ?? '' ) ) : esc_html( (string) ( $field['content'] ?? '' ) );
				$class   = 'content-html';
			}
			echo '<div class="opf-field-input opf-' . esc_attr( $class ) . '">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content is escaped or generated by registered shortcodes.
			echo '</div>';
			return;
		}
		if ( 'paragraph' === $field['type'] ) {
			$content = esc_html( $field['content'] );
			if ( 'html' === ( $field['content_format'] ?? 'plain' ) && function_exists( 'wp_kses' ) ) {
				$allowed_html = [
					'br' => [],
					'hr' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'a' => [ 'href' => [], 'target' => [], 'class' => [], 'style' => [], 'id' => [] ],
					'i' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'em' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'strong' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'b' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'span' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'div' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h1' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h2' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h3' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h4' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h5' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h6' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'ul' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'ol' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'li' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'table' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'tr' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'td' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'th' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'thead' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'tbody' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'img' => [ 'src' => [], 'target' => [], 'class' => [], 'alt' => [], 'style' => [], 'id' => [] ],
				];
				$content = wp_kses( $field['content'], $allowed_html );
				if ( ! empty( $field['process_shortcodes'] ) && function_exists( 'do_shortcode' ) ) {
					$content = do_shortcode( $content );
				}
			}
			echo '<div class="opf-field-content">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain content is escaped; HTML content is allow-list sanitized before optional registered shortcodes.
			echo '</div>';
			return;
		}

		// WAPF alias bridge: wapf/html/field_label (filtered content, escaped default).
		$label_content = \OPF\Compat\WapfHooks::field_label( esc_html( $field['label'] ), $field, $product );

		echo '<div class="opf-field-label"><label';
		$is_card_radio = 'radio' === $field['type'] && isset( $field['card_layout'] );
		if ( 'radio' === $field['type'] && ! $is_card_radio ) {
			echo ' id="opf-label-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		if ( ! in_array( $field['type'], [ 'swatch', 'image_quantity', 'radio', 'checkbox', 'products', 'child_products', 'calculation' ], true ) ) {
			echo ' for="opf-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		echo '><span' . ( $is_card_radio ? ' id="opf-' . esc_attr( $gid . '-' . $fid ) . '-label"' : '' ) . '>' . $label_content . '</span>' . self::pricing_hint_html( $field['pricing'] ?? [], $base_price, $product ) . ' ';
		if ( $field['required'] && ( $field['_opf_mark_required'] ?? true ) ) {
			echo '<abbr class="required" title="' . esc_attr( self::required_title() ) . '">*</abbr>';
		}
		echo '</label>';
		// WAPF alias bridge: wapf/html/field_description. Unfiltered values keep
		// the original escape path so output stays byte-identical without listeners.
		if ( '' === $field['description'] ) {
			$description      = '';
			$description_html = '';
		} else {
			$description      = \OPF\Compat\WapfHooks::field_description( $field['description'], $field );
			$description_html = ( $description === $field['description'] )
				? esc_html( $field['description'] )
				: ( function_exists( 'wp_kses_post' ) ? wp_kses_post( $description ) : esc_html( $description ) );
		}
		// Tooltip instructions sit inside the label container, next to the
		// label, mirroring WAPF instructions_position=tooltip (the icon is
		// emitted inside .wapf-field-label).
		if ( '' !== $description && 'tooltip' === ( $field['description_presentation'] ?? 'inline' ) ) {
			$tid = 'opf-tt-' . esc_attr( $gid . '-' . $fid );
			echo '<button type="button" class="opf-tooltip-trigger" aria-describedby="' . $tid . '" aria-expanded="false"><span aria-hidden="true">?</span><span class="screen-reader-text">' . esc_html( $field['label'] ) . ' help</span></button>';
			echo '<span role="tooltip" id="' . $tid . '" class="opf-tooltip">' . $description_html . '</span>';
			// WAPF instructions_position=tooltip parity: the legacy markup used
			// opf-{gid}-{fid}-instruction ids wrapped in .opf-instruction-tooltip.
			// Kept as a hidden alias node so WAPF-shaped selectors and styles
			// keep resolving while the OPF tooltip remains the visible control.
			$instruction_id = 'opf-' . esc_attr( $gid . '-' . $fid ) . '-instruction';
			echo '<span class="opf-instruction-tooltip" hidden><button type="button" class="opf-instruction-tooltip__trigger" tabindex="-1" aria-label="' . esc_attr( sprintf( __( 'Instructions for %s', 'open-product-fields-for-woocommerce' ), $field['label'] ) ) . '" aria-describedby="' . $instruction_id . '" aria-expanded="false"><span aria-hidden="true">?</span></button><span class="opf-instruction-tooltip__content" role="tooltip" id="' . $instruction_id . '">' . esc_html( (string) $field['description'] ) . '</span></span>';
		}
		echo '</div>';

		// Live price-hint target (WAPF parity): non-choice fields with pricing
		// and price-mode calculation fields get an updateable hint node.
		if ( ( 'calculation' === $field['type'] && 'price' === ( $field['calculation_type'] ?? 'informational' ) )
			|| ( ! in_array( $field['type'], [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox', 'calculation', 'products', 'child_products' ], true ) && 'none' !== ( $field['pricing']['type'] ?? 'none' ) ) ) {
			echo '<span class="opf-choice__hint' . ( self::show_price_hints() ? ' opf-pricing-hint' : '' ) . '" data-opf-field-hint="1" aria-live="polite"></span>';
		}

		if ( '' !== $description && 'tooltip' !== ( $field['description_presentation'] ?? 'inline' ) ) {
			echo '<div class="opf-field-description">' . $description_html . '</div>';
		}

		echo '<div class="opf-field-input">';

		if ( 'calculation' === $field['type'] ) {
			// WAPF `calculation` display field: server-seeded result plus the
			// formula attributes the frontend evaluator re-computes live.
			$calc_result   = Calculator::evaluate_formula( (string) ( $field['formula'] ?? '' ), $base_price, 1, 0.0, '', null, $values, $product instanceof \WC_Product ? $product->get_id() : 0 );
			$calc_text_tpl = (string) ( $field['result_text'] ?? '{result}' );
			$calc_display  = str_replace( '{result}', (string) $calc_result, $calc_text_tpl );
			echo '<output class="opf-calculation-output" data-opf-calculation="' . esc_attr( $fid ) . '" data-opf-formula="' . esc_attr( (string) ( $field['formula'] ?? '' ) ) . '" data-opf-calculation-type="' . esc_attr( (string) ( $field['calculation_type'] ?? 'informational' ) ) . '" data-opf-result-text="' . esc_attr( $calc_text_tpl ) . '">' . esc_html( $calc_display ) . '</output>';
		} elseif ( 'child_products' === $field['type'] ) {
			self::render_child_products( $gid, $name, $field, $product );
		} elseif ( 'products' === $field['type'] ) {
			self::render_products_field( $gid, $name, $field, $product );
		} elseif ( in_array( $field['type'], [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox' ], true ) ) {
			self::render_choices( $gid, $name, $field, $base_price, $qty_based, $product );
		} else {
			self::render_input( $name, $gid, $field, $qty_based );
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * WAPF child-products field: purchasable linked products rendered as
	 * checkboxes, radios, a select, or card/image tiles with optional
	 * per-product quantity inputs.
	 *
	 * @param string           $gid        Group id.
	 * @param string           $name       Input base name.
	 * @param array            $field      Normalized field.
	 * @param \WC_Product|null $product    Parent product.
	 */
	private static function render_child_products( string $gid, string $name, array $field, ?\WC_Product $product = null ): void {
		if ( null === $product ) {
			$product = $GLOBALS['product'] ?? null;
		}
		$parent_id = $product instanceof \WC_Product ? $product->get_id() : 0;
		$products  = ChildProductCatalog::products( $field, $parent_id );
		if ( ! $products ) {
			echo '<p class="opf-child-products-empty">' . esc_html__( 'No products are currently available.', 'open-product-fields-for-woocommerce' ) . '</p>';
			return;
		}
		$multiple       = ! empty( $field['multiple'] );
		$quantity_input = ! empty( $field['quantity_input'] );
		$display        = (string) ( $field['product_display'] ?? 'checkboxes' );
		$visual         = in_array( $display, [ 'cards', 'images' ], true );
		$container_attrs = '';
		foreach ( [ 'min_selections' => 'min-selections', 'max_selections' => 'max-selections' ] as $key => $attribute ) {
			if ( isset( $field[ $key ] ) ) {
				$container_attrs .= ' data-opf-' . $attribute . '="' . esc_attr( (string) $field[ $key ] ) . '"';
			}
		}
		$classes = 'opf-child-products opf-child-products--' . self::html_class( $display );
		echo '<div class="' . esc_attr( $classes ) . '"' . $container_attrs . '>';
		$raw_selected  = $field['_opf_prefill'] ?? ( $field['default'] ?? [] );
		$quantities    = $quantity_input && is_array( $raw_selected ) ? $raw_selected : [];
		$selected_ids  = $quantity_input && is_array( $raw_selected )
			? array_keys( array_filter( $quantities, static function ( $quantity ): bool { return (int) $quantity > 0; } ) )
			: ( is_array( $raw_selected ) ? $raw_selected : ( '' === (string) $raw_selected ? [] : [ $raw_selected ] ) );

		if ( 'select' === $display && ! $quantity_input ) {
			$select_name = $multiple ? $name . '[]' : $name;
			echo '<select class="opf-input opf-child-products__select" name="' . esc_attr( $select_name ) . '"' . ( $multiple ? ' multiple' : '' ) . ( ! empty( $field['required'] ) ? ' required' : '' ) . '>';
			if ( ! $multiple ) {
				echo '<option value="">' . esc_html__( 'Choose an option', 'open-product-fields-for-woocommerce' ) . '</option>';
			}
			foreach ( $products as $child ) {
				$child_id        = (int) $child->get_id();
				$selected_option = in_array( (string) $child_id, array_map( 'strval', $selected_ids ), true );
				$label           = $child->get_name();
				$price           = wp_strip_all_tags( $child->get_price_html() );
				if ( '' !== $price ) {
					$label .= ' — ' . $price;
				}
				echo '<option value="' . esc_attr( (string) $child_id ) . '"' . selected( $selected_option, true, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></div>';
			return;
		}
		foreach ( $products as $child ) {
			$child_id    = (int) $child->get_id();
			$child_name  = (string) $child->get_name();
			$checked     = in_array( (string) $child_id, array_map( 'strval', $selected_ids ), true );
			$input_type  = $quantity_input ? 'number' : ( $multiple ? 'checkbox' : 'radio' );
			$input_name  = $quantity_input ? $name . '[' . $child_id . ']' : ( $multiple ? $name . '[]' : $name );
			$input_value = $quantity_input ? max( 0, (int) ( $quantities[ $child_id ] ?? 0 ) ) : $child_id;
			$input_attrs = 'type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( (string) $input_value ) . '"';
			if ( $quantity_input ) {
				$input_attrs .= ' min="0" step="1" aria-label="' . esc_attr( sprintf( /* translators: %s: child product name. */ __( 'Quantity of %s', 'open-product-fields-for-woocommerce' ), $child_name ) ) . '"';
			}
			$control_html = $quantity_input
				? '<span class="opf-child-product__quantity"><input ' . $input_attrs . ' /></span>'
				: '<span class="opf-child-product__choice"><input ' . $input_attrs . ( $checked ? ' checked' : '' ) . ' /><span class="opf-child-product__control" aria-hidden="true"></span></span>';
			$image_html = '';
			if ( $visual && $child->get_image_id() ) {
				$image_html = $child->get_image( 'woocommerce_thumbnail', [ 'class' => 'opf-child-product-image', 'alt' => $child_name ] );
				if ( ! empty( $field['image_zoom'] ) ) {
					$image_html = '<span class="opf-child-product-image-zoom" tabindex="0" role="img" aria-label="' . esc_attr( sprintf( /* translators: %s: child product name. */ __( 'Enlarge image: %s', 'open-product-fields-for-woocommerce' ), $child_name ) ) . '">' . $image_html . '</span>';
				}
			}
			$price_html = $child->get_price_html();
			echo '<label class="opf-child-product">' . $control_html . $image_html . '<span class="opf-child-product__details"><span class="opf-child-product__name">' . esc_html( $child_name ) . '</span>' . ( $price_html ? '<span class="opf-child-product__price">' . wp_kses_post( $price_html ) . '</span>' : '' ) . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sub-elements are escaped or generated by WordPress.
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
	private static function render_choices( string $gid, string $name, array $field, float $base_price, bool $qty_based = false, ?\WC_Product $product = null ): void {
		$fid = $field['id'];

		// WAPF `image_quantities` swatch parity: per-choice quantity inputs in
		// the legacy opf-image-quantities/opf-image-quantity-choice markup.
		if ( 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) ) {
			$min  = max( 1, (int) ( $field['min'] ?? 1 ) );
			$max  = isset( $field['max'] ) ? max( $min, (int) $field['max'] ) : null;
			$step = max( 1, (int) ( $field['step'] ?? 1 ) );
			$limit_attrs = '';
			foreach ( [ 'min_selections' => 'min-selections', 'max_selections' => 'max-selections' ] as $key => $attribute ) {
				if ( isset( $field[ $key ] ) ) {
					$limit_attrs .= ' data-opf-' . $attribute . '="' . esc_attr( (string) $field[ $key ] ) . '"';
				}
			}
			echo '<div class="opf-image-quantities"' . $limit_attrs . '>';
			$prefill  = $field['_opf_prefill'] ?? null;
			$defaults = is_array( $prefill ) ? $prefill : ( is_array( $field['default'] ?? null ) ? $field['default'] : [] );
			foreach ( $field['choices'] as $choice ) {
				$slug     = (string) $choice['slug'];
				$quantity = max( 0, (int) ( $defaults[ $slug ] ?? ( $choice['quantity']['default'] ?? 0 ) ) );
				$attrs    = 'type="number" autocomplete="off" class="opf-input opf-quantity-input" data-opf-quantity-choice="' . esc_attr( $slug ) . '" data-opf-min-quantity="' . esc_attr( (string) $min ) . '" name="' . esc_attr( $name . '[' . $slug . ']' ) . '" value="' . esc_attr( (string) $quantity ) . '" min="0" step="' . esc_attr( (string) $step ) . '"';
				if ( null !== $max ) {
					$attrs .= ' max="' . esc_attr( (string) $max ) . '"';
				}
				if ( ! empty( $choice['disabled'] ) ) {
					$attrs .= ' disabled';
				}
				$hint = 'none' !== ( $choice['pricing']['type'] ?? 'none' )
					? '<span class="opf-choice__hint" data-opf-choice-hint="' . esc_attr( $slug ) . '" aria-live="polite"></span>'
					: '';
				$src = '';
				if ( ! empty( $choice['image'] ) ) {
					$src = (string) $choice['image'];
				} elseif ( ! empty( $choice['image_id'] ) && function_exists( 'wp_get_attachment_image_url' ) ) {
					$src = (string) wp_get_attachment_image_url( (int) $choice['image_id'], 'medium' );
				}
				$zoom_class = ! empty( $field['image_quantity_zoom'] ) ? ' opf-image-quantity--zoom' : '';
				echo '<div class="opf-image-quantity-choice' . esc_attr( $zoom_class ) . '"><label><img class="opf-swatch-image" src="' . esc_url( $src ) . '" alt="' . esc_attr( $choice['label'] ) . '" /><span>' . esc_html( $choice['label'] ) . '</span> ' . $hint . '<input ' . $attrs . ' /></label></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- attributes escaped above.
			}
			echo '</div>';
			return;
		}

		if ( 'image_quantity' === $field['type'] ) {
			echo '<div class="opf-image-quantity">';
			foreach ( $field['choices'] as $choice ) {
				$q = $choice['quantity'];
				$label = esc_html( $choice['label'] );
				// WAPF large_image parity: the image wrapper carries
				// wapf-tt-wrap + data-zoom-url (full attachment src) and OPF's
				// CSS zoom preview provides the hover/focus enlargement.
				$zoom_url = '';
				if ( ! empty( $field['image_zoom'] ) ) {
					$zoom_url = ! empty( $choice['image_id'] ) && function_exists( 'wp_get_attachment_image_url' )
						? (string) wp_get_attachment_image_url( (int) $choice['image_id'], 'full' )
						: (string) ( $choice['image'] ?? '' );
				}
				$img_html = '';
				if ( ! empty( $choice['image'] ) || ! empty( $choice['image_id'] ) ) {
					$src = ! empty( $choice['image'] )
						? $choice['image']
						: ( function_exists( 'wp_get_attachment_image_url' ) ? (string) wp_get_attachment_image_url( (int) $choice['image_id'], 'medium' ) : '' );
					if ( '' !== $src ) {
						$img_html = '<img class="opf-swatch-image" src="' . esc_url( $src ) . '" alt="' . $label . '" loading="lazy" decoding="async" />';
					}
				}
				echo '<label class="opf-image-quantity__choice">';
				if ( '' !== $img_html ) {
					$img_wrap_classes = 'opf-image-quantity__img';
					$img_wrap_attrs = '';
					if ( '' !== $zoom_url ) {
						$img_wrap_classes .= ' opf-swatch--image-zoom wapf-tt-wrap';
						$img_wrap_attrs    = ' data-zoom-url="' . esc_url( $zoom_url ) . '"';
					}
					echo '<span class="' . esc_attr( $img_wrap_classes ) . '"' . $img_wrap_attrs . '>' . $img_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					if ( '' !== $zoom_url ) {
						echo '<img class="opf-swatch-zoom-preview" src="' . esc_url( $zoom_url ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
					}
					echo '</span>';
				}
				echo '<span>' . $label . self::pricing_hint_html( $choice['pricing'] ?? [], $base_price, $product ) . '</span><input type="number" class="opf-input opf-image-quantity__input is-qty input-' . esc_attr( $fid ) . ' input-' . esc_attr( $fid ) . '_' . esc_attr( $choice['slug'] ) . '" name="' . esc_attr( $name . '[' . $choice['slug'] . ']' ) . '" value="' . esc_attr( (string) $q['default'] ) . '" min="' . esc_attr( (string) $q['min'] ) . '" max="' . esc_attr( (string) $q['max'] ) . '" step="1" data-field-id="' . esc_attr( $fid ) . '" data-choice-slug="' . esc_attr( $choice['slug'] ) . '"' . ( ! empty( $choice['disabled'] ) ? ' disabled' : '' ) . ' /></label>';
			}
			echo '</div>';
			return;
		}

		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( $name ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" class="opf-input input-' . esc_attr( $fid ) . '" autocomplete="off"' . ( $field['required'] ? ' required' : '' ) . '>';
			$has_default = false;
			foreach ( $field['choices'] as $choice ) {
				$has_default = $has_default || ( $choice['selected'] && ! $choice['disabled'] );
			}
			if ( ! $field['required'] || ! $has_default ) {
				echo '<option value="">' . esc_html( __( 'Choose an option', 'open-product-fields-for-woocommerce' ) ) . '</option>';
			}
			foreach ( $field['choices'] as $choice ) {
				$choice_selected = $choice['selected'] && ! $choice['disabled'];
				$hint_attrs = 'none' !== ( $choice['pricing']['type'] ?? 'none' )
					? ' data-opf-choice-hint="' . esc_attr( $choice['slug'] ) . '" data-opf-base-label="' . esc_attr( $choice['label'] ) . '"'
					: '';
				echo '<option value="' . esc_attr( $choice['slug'] ) . '"' . $hint_attrs . selected( $choice_selected, true, false ) . self::pricing_attrs( $choice['pricing'], $qty_based ) . ( ! empty( $choice['disabled'] ) ? ' disabled' : '' ) . '>'
					. esc_html( $choice['label'] )
					. strip_tags( self::pricing_hint_html( $choice['pricing'], $base_price, $product ) )
					. '</option>';
			}
			echo '</select>';
			return;
		}

		$multi = 'checkbox' === $field['type'] || ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) );
		$image_swatch = 'swatch' === $field['type'] && 'image' === ( $field['swatch_style'] ?? '' );
		$color_swatch = 'swatch' === $field['type'] && 'color' === ( $field['swatch_style'] ?? '' );

		$is_card = 'radio' === $field['type'] && isset( $field['card_layout'] ) && '' !== (string) $field['card_layout'];
		// WAPF styled checkbox/radio contract: WAPF wraps each native control
		// with `wapf-checkbox`/`wapf-radio` + a `.wapf-custom` skin span after
		// the input (views/frontend/fields/checkboxes.php, radio.php). Cards
		// and switch controls keep their own markup.
		$wapf_native = 'checkbox' === $field['type'] && empty( $field['switch_control'] )
			? 'checkbox'
			: ( 'radio' === $field['type'] && ! $is_card ? 'radio' : '' );

		$wrapper_class = $image_swatch ? 'opf-swatch-wrapper opf-image-swatch-wrapper' : 'opf-swatch-wrapper';
		$checkbox_columns = 'checkbox' === $field['type'] ? (int) ( $field['columns'] ?? 1 ) : 1;
		if ( $checkbox_columns > 1 ) {
			$wrapper_class .= ' opf-checkboxes--columns';
		}
		if ( 'checkbox' === $field['type'] && ! empty( $field['switch_control'] ) ) {
			$wrapper_class .= ' opf-checkboxes--switches';
		}
		if ( $color_swatch ) {
			$wrapper_class .= ' opf-color-swatch-wrapper';
		}
		// WAPF container classes so theme CSS written for WAPF's choice
		// wrappers applies to OPF's native checkbox/radio groups.
		if ( 'checkbox' === $wapf_native ) {
			$wrapper_class .= ' wapf-checkboxes';
		} elseif ( 'radio' === $wapf_native ) {
			$wrapper_class .= ' wapf-radios';
		}
		if ( $is_card ) {
			$wrapper_class .= ' opf-cards opf-cards--' . self::html_class( (string) $field['card_layout'] );
		}
		$wrapper_attrs = '';
		if ( 'radio' === $field['type'] ) {
			// Card radios label the group via the field-label span
			// (opf-{gid}-{fid}-label); plain radios use the label element id.
			$wrapper_attrs = $is_card
				? ' role="radiogroup" aria-labelledby="opf-' . esc_attr( $gid . '-' . $fid ) . '-label"' . ( $field['required'] ? ' aria-required="true"' : '' )
				: ' role="radiogroup" aria-labelledby="opf-label-' . esc_attr( $gid . '-' . $fid ) . '"' . ( $field['required'] ? ' aria-required="true"' : '' );
		}
		if ( $checkbox_columns > 1 ) {
			$wrapper_attrs .= sprintf(
				' style="--opf-checkbox-columns:%1$d;--opf-checkbox-columns-tablet:%2$d;--opf-checkbox-columns-mobile:1;"',
				max( 1, min( 12, $checkbox_columns ) ),
				max( 1, min( 12, $checkbox_columns, 2 ) )
			);
		}
		if ( $image_swatch ) {
			$wrapper_attrs = ' data-grid-layout="' . esc_attr( $field['grid_layout'] ) . '" data-label-position="' . esc_attr( $field['label_pos'] ) . '"';
			if ( 'flexible' === $field['grid_layout'] ) {
				$wrapper_attrs .= ' style="--opf-image-swatch-cols:' . esc_attr( (string) $field['items_per_row'] ) . ';--opf-image-swatch-cols-tablet:' . esc_attr( (string) $field['items_per_row_tablet'] ) . ';--opf-image-swatch-cols-mobile:' . esc_attr( (string) $field['items_per_row_mobile'] ) . ';"';
			} else {
				$wrapper_attrs .= ' style="--opf-image-swatch-width:' . esc_attr( (string) $field['item_width'] ) . 'px;"';
			}
		}
		if ( $color_swatch ) {
			$wrapper_attrs .= ' data-color-layout="' . esc_attr( $field['color_layout'] ) . '"';
		}
		if ( $multi && in_array( $field['type'], [ 'swatch', 'checkbox' ], true ) ) {
			if ( isset( $field['min_choices'] ) ) {
				$wrapper_attrs .= ' data-min-choices="' . esc_attr( (string) $field['min_choices'] ) . '"';
			}
			if ( isset( $field['max_choices'] ) ) {
				$wrapper_attrs .= ' data-max-choices="' . esc_attr( (string) $field['max_choices'] ) . '"';
			}
		}
		// WAPF spelling for selection bounds (min_selections/max_selections
		// normalize onto min_choices/max_choices in the schema).
		$min_selections = $field['min_selections'] ?? $field['min_choices'] ?? null;
		$max_selections = $field['max_selections'] ?? $field['max_choices'] ?? null;
		if ( null !== $min_selections ) {
			$wrapper_attrs .= ' data-opf-min-selections="' . esc_attr( (string) $min_selections ) . '"';
		}
		if ( null !== $max_selections ) {
			$wrapper_attrs .= ' data-opf-max-selections="' . esc_attr( (string) $max_selections ) . '"';
		}
		echo '<div class="' . esc_attr( $wrapper_class ) . '"' . $wrapper_attrs . '>';
		if ( ! ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) ) {
			echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
		}

		foreach ( $field['choices'] as $choice ) {
			$choice_selected  = $choice['selected'] && ! $choice['disabled'];
			$choice_has_image = ! empty( $choice['image'] ) || ! empty( $choice['image_id'] );
			$swatch_classes   = [ 'opf-swatch', $image_swatch ? 'opf-swatch--image' : ( $color_swatch ? 'opf-swatch--color' : 'opf-swatch--text' ) ];
			if ( '' !== $wapf_native ) {
				$swatch_classes[] = 'wapf-' . $wapf_native;
			}
			$choice_zoom_url  = '';
			// WAPF parity: zoom flags scope to choices that actually carry an
			// image — text swatches get the class only (CSS hover), image
			// swatches keep the wapf-tt-wrap/data-zoom-url preview contract.
			$choice_zoom = 'swatch' === $field['type'] && ! empty( $field['image_zoom'] ) && $choice_has_image;
			if ( $choice_zoom && $image_swatch ) {
				$choice_zoom_url = ! empty( $choice['image_id'] ) && function_exists( 'wp_get_attachment_image_url' )
					? (string) wp_get_attachment_image_url( (int) $choice['image_id'], 'full' )
					: (string) ( $choice['image'] ?? '' );
			}
			if ( $image_swatch ) {
				$swatch_classes[] = 'opf-image-swatch-label--' . $field['label_pos'];
			}
			if ( ! $multi ) {
				$swatch_classes[] = 'opf-single-select';
			}
			if ( $choice_selected ) {
				$swatch_classes[] = 'opf-checked';
			}
			if ( $is_card ) {
				$swatch_classes[] = 'opf-card';
			}
			if ( $choice_zoom ) {
				$swatch_classes[] = 'opf-swatch--image-zoom';
				if ( $image_swatch ) {
					$swatch_classes[] = 'wapf-tt-wrap';
				}
			}
			if ( 'none' !== $choice['pricing']['type'] ) {
				$swatch_classes[] = 'has-pricing';
			}

			// WAPF alias bridge: wapf/html/option_wrapper_classes.
			$swatch_classes = \OPF\Compat\WapfHooks::option_wrapper_classes( $swatch_classes, $field, $GLOBALS['product'] ?? null, $choice );

			$attrs = sprintf(
				'autocomplete="off" id="opf-%1$s-%2$s-%3$s" name="%4$s" class="opf-input input-%2$s" data-field-id="%2$s" value="%5$s" data-opf-label="%6$s" data-wapf-label="%6$s"%7$s%8$s%9$s%10$s',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $choice['slug'] ),
				esc_attr( $choice['label'] ),
				$field['required'] && ( ! $multi || ! isset( $field['_opf_repeat_index'] ) ) ? ' required' : '',
				$choice_selected ? ' checked' : '',
				self::pricing_attrs( $choice['pricing'], $qty_based ) . ( ! empty( $choice['disabled'] ) ? ' disabled' : '' ),
				$multi && ! empty( $field['switch_control'] ) ? ' role="switch"' : ''
			);

			$choice_label_attr = $image_swatch ? ' data-opf-swatch-label="' . esc_attr( $choice['label'] ) . '"' : '';
			if ( '' !== $choice_zoom_url ) {
				$choice_label_attr .= ' data-zoom-url="' . esc_url( $choice_zoom_url ) . '"';
			}
			if ( $color_swatch ) {
				$choice_label_attr .= ' data-opf-swatch-label="' . esc_attr( $choice['label'] ) . '" data-color-label-position="' . esc_attr( $field['color_label_pos'] ) . '"';
			}
			echo '<div class="' . esc_attr( implode( ' ', $swatch_classes ) ) . '"' . $choice_label_attr . '>';
			echo '<label' . ( '' !== $wapf_native ? ' class="wapf-input-label"' : '' ) . '>';
			if ( $color_swatch && ! empty( $choice['color'] ) ) {
				echo '<span class="opf-color-swatch" aria-hidden="true" style="--opf-swatch-color:' . esc_attr( $choice['color'] ) . ';--opf-swatch-size:' . esc_attr( (string) $field['color_size'] ) . 'px"></span>';
			}
			$image_html = '';
			if ( $image_swatch && ! empty( $choice['image_id'] ) && function_exists( 'wp_get_attachment_image' ) ) {
				// WAPF alias bridge: wapf/html/image_swatch_size.
				$image_size = \OPF\Compat\WapfHooks::image_swatch_size( 'medium', $field, $GLOBALS['product'] ?? null, $choice );
				$image_html = (string) wp_get_attachment_image(
					(int) $choice['image_id'],
					$image_size,
					false,
					[ 'class' => 'opf-swatch-image', 'alt' => (string) $choice['label'], 'loading' => 'lazy', 'decoding' => 'async' ]
				);
			}
			if ( $image_swatch && '' === $image_html && ! empty( $choice['image'] ) ) {
				$image_html = '<img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
			}
			// WAPF parity: text swatches and card radios render choice images
			// too (WAPF has no swatch_style gate); card images are decorative.
			if ( '' === $image_html && $choice_has_image && ! empty( $choice['image'] ) && ( $is_card || ( 'swatch' === $field['type'] && ! $image_swatch && ! $color_swatch ) ) ) {
				$image_html = $is_card
					? '<img class="opf-card__image" src="' . esc_url( $choice['image'] ) . '" alt="" loading="lazy" decoding="async" />'
					: '<img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
			}
			$zoom_html = '';
			if ( '' !== $choice_zoom_url ) {
				$zoom_html = '<img class="opf-swatch-zoom-preview" src="' . esc_url( $choice_zoom_url ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
			}
			$image_frame = $image_swatch && 'out' !== $field['label_pos'] && ( '' !== $image_html || '' !== $zoom_html );
			if ( $image_frame ) {
				echo '<span class="opf-image-swatch-frame">';
			}
			if ( '' !== $image_html ) {
				echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput -- attachment markup is generated by WordPress or escaped above.
			}
			if ( '' !== $zoom_html ) {
				echo $zoom_html; // phpcs:ignore WordPress.Security.EscapeOutput -- URL is escaped and attributes are fixed.
			}
			if ( $image_frame ) {
				echo '</span>';
			}
			if ( $is_card ) {
				echo '<span class="opf-card__title">' . esc_html( $choice['label'] ) . ' </span>';
				if ( ! empty( $choice['description'] ) ) {
					echo '<span class="opf-card__description">' . esc_html( $choice['description'] ) . '</span>';
				}
			} else {
				$label_class = $image_swatch ? ' class="opf-image-swatch-label"' : ( '' !== $wapf_native ? ' class="wapf-label-text"' : '' );
				echo '<span' . $label_class . '>' . esc_html( $choice['label'] ) . self::pricing_hint_html( $choice['pricing'], $base_price, $product ) . ' </span>';
			}
			if ( 'none' !== $choice['pricing']['type'] ) {
				echo '<span class="opf-choice__hint" data-opf-choice-hint="' . esc_attr( $choice['slug'] ) . '" aria-live="polite"></span>';
			}
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			if ( '' !== $wapf_native ) {
				// Skin target for WAPF's global design layer; native semantics
				// (focus/checked/keyboard) stay on the real input.
				echo '<span class="wapf-custom" aria-hidden="true"></span>';
			}
			echo '</label>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Linked-products field (WAPF `products-*` parity). Children are real
	 * products; the rendered choice inputs submit slugs (or per-choice
	 * quantities for the *-qty subtypes) which CartIntegration + LinkedProducts
	 * turn into native cart lines.
	 *
	 * @param string              $gid     Group id.
	 * @param string              $name    Input base name.
	 * @param array<string,mixed> $field   Field data.
	 * @param \WC_Product|null    $product Parent product (for query exclusion).
	 */
	private static function render_products_field( string $gid, string $name, array $field, ?\WC_Product $product = null ): void {
		$fid      = $field['id'];
		$subtype  = (string) ( $field['subtype'] ?? 'checkbox' );
		$is_qty   = LinkedProducts::is_qty_subtype( $field );
		$multi    = in_array( $subtype, [ 'checkbox', 'image', 'card', 'vcard' ], true );
		$required = ! empty( $field['required'] );
		$large_image = 'image' === $subtype && ! empty( $field['large_image'] );

		if ( null === $product ) {
			$product = $GLOBALS['product'] ?? null;
		}
		$choices = LinkedProducts::product_choices( $field, $product instanceof \WC_Product ? $product : null );
		if ( ! $choices ) {
			echo '<p class="opf-products-empty">' . esc_html__( 'No products are available for this option.', 'open-product-fields-for-woocommerce' ) . '</p>';
			return;
		}

		// Cart-edit: restore the line's stored selection onto the live choices.
		$prefill_products = $field['_opf_prefill_products'] ?? null;
		if ( null !== $prefill_products ) {
			if ( $is_qty ) {
				$quantities = is_array( $prefill_products ) ? ( $prefill_products['quantities'] ?? $prefill_products ) : [];
				foreach ( $choices as $i => $choice ) {
					$slug = (string) ( $choice['slug'] ?? '' );
					$choices[ $i ]['quantity'] = array_merge( [ 'default' => 0, 'min' => 0, 'max' => 999999 ], (array) ( $choice['quantity'] ?? [] ) );
					$choices[ $i ]['quantity']['default'] = (int) ( $quantities[ $slug ] ?? 0 );
				}
			} else {
				$slugs = array_map( 'strval', (array) $prefill_products );
				foreach ( $choices as $i => $choice ) {
					$choices[ $i ]['selected'] = in_array( (string) ( $choice['slug'] ?? '' ), $slugs, true );
				}
			}
		}

		// Shared per-choice input attributes (WAPF get_option_classes_and_attributes parity).
		$input_attrs = static function ( array $choice, string $input_name, bool $checked ) use ( $gid, $fid, $field, $required ): string {
			$attrs = sprintf(
				'id="opf-%1$s-%2$s-%3$s" name="%4$s" class="opf-input opf-product-input input-%2$s" data-field-id="%2$s" value="%3$s" data-opf-label="%5$s" data-wapf-label="%5$s" data-opf-product-id="%6$s"',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $input_name ),
				esc_attr( $choice['label'] ),
				esc_attr( (string) ( $choice['product_id'] ?? '' ) )
			);
			if ( $required ) {
				$attrs .= ' required';
			}
			if ( $checked ) {
				$attrs .= ' checked';
			}
			if ( 'none' !== ( $choice['pricing_type'] ?? 'none' ) ) {
				$attrs .= sprintf( ' data-opf-pricetype="%s" data-opf-price="%s" data-wapf-pricetype="%s" data-wapf-price="%s"',
					esc_attr( $choice['pricing_type'] ),
					esc_attr( (string) (float) ( $choice['pricing_amount'] ?? 0 ) ),
					esc_attr( $choice['pricing_type'] ),
					esc_attr( (string) (float) ( $choice['pricing_amount'] ?? 0 ) ) );
			}
			if ( ! empty( $choice['disabled'] ) ) {
				$attrs .= ' disabled data-disabled="1"';
			}
			// image_zoom: hover preview + gallery swap URL (WAPF data-zoom-url parity).
			if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
				$attrs .= ' data-opf-swap-image="' . esc_attr( $choice['zoom_url'] ) . '"';
			}
			return $attrs;
		};

		$choice_classes = static function ( array $choice, array $base = [] ): array {
			if ( ! empty( $choice['selected'] ) ) {
				$base[] = 'opf-checked wapf-checked';
			}
			if ( 'none' !== ( $choice['pricing_type'] ?? 'none' ) ) {
				$base[] = 'has-pricing';
			}
			if ( ! empty( $choice['disabled'] ) || ( isset( $choice['product'] ) && ! $choice['product']->is_in_stock() ) ) {
				$base[] = 'opf-disabled wapf-disabled';
			}
			return $base;
		};

		$wrapper_attrs = static function ( array $choice ) use ( $field, $large_image ): string {
			$attrs = '';
			if ( ( $large_image || ! empty( $field['image_zoom'] ) ) && ! empty( $choice['zoom_url'] ) ) {
				$attrs .= ' data-zoom-url="' . esc_attr( $choice['zoom_url'] ) . '"';
			}
			return $attrs;
		};

		$hint = static function ( array $choice ): string {
			if ( 'none' === ( $choice['pricing_type'] ?? 'none' ) || empty( $choice['pricing_amount'] ) || ! function_exists( 'wc_price' ) ) {
				return '';
			}
			return ' <span class="opf-pricing-hint">(' . wc_price( (float) $choice['pricing_amount'] ) . ')</span>';
		};

		switch ( $subtype ) {
			case 'dropdown':
				echo '<select name="' . esc_attr( $name ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" class="opf-input input-' . esc_attr( $fid ) . '" autocomplete="off"' . ( $required ? ' required' : '' ) . '>';
				$has_default = (bool) array_filter( $choices, static fn ( $c ) => ! empty( $c['selected'] ) && empty( $c['disabled'] ) );
				if ( ! $required || ! $has_default ) {
					echo '<option value="">' . esc_html__( 'Choose an option', 'open-product-fields-for-woocommerce' ) . '</option>';
				}
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					echo '<option value="' . esc_attr( $choice['slug'] ) . '"'
						. selected( ! empty( $choice['selected'] ) && ! $disabled, true, false )
						. ' data-opf-label="' . esc_attr( $choice['label'] ) . '"'
						. ( 'none' !== ( $choice['pricing_type'] ?? 'none' ) ? ' data-opf-pricetype="' . esc_attr( $choice['pricing_type'] ) . '" data-opf-price="' . esc_attr( (string) (float) $choice['pricing_amount'] ) . '"' : '' )
						. ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ? ' data-opf-swap-image="' . esc_attr( $choice['zoom_url'] ) . '"' : '' )
						. ( $disabled ? ' disabled' : '' )
						. '>' . esc_html( $choice['label'] ) . '</option>';
				}
				echo '</select>';
				return;

			case 'image':
				echo '<div class="opf-swatch-wrapper opf-image-swatch-wrapper opf-products opf-products--image" data-label-position="' . esc_attr( $field['label_pos'] ?? 'tooltip' ) . '" style="--opf-image-swatch-width:' . esc_attr( (string) ( $field['item_width'] ?? 60 ) ) . 'px;">';
				echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					if ( $disabled ) {
						$choice['disabled'] = true;
					}
					$checked  = ! empty( $choice['selected'] ) && ! $disabled;
					$classes  = $choice_classes( $choice, [ 'opf-swatch', 'opf-swatch--image', 'opf-product-choice', 'opf-image-swatch-label--' . ( $field['label_pos'] ?? 'tooltip' ) ] );
					if ( $large_image || ! empty( $field['image_zoom'] ) ) {
						$classes[] = 'opf-swatch--image-zoom wapf-tt-wrap';
					}
					if ( ! $multi ) {
						$classes[] = 'opf-single-select';
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $wrapper_attrs( $choice ) . ' data-opf-swatch-label="' . esc_attr( $choice['label'] ) . '">';
					echo '<label>';
					if ( 'out' !== ( $field['label_pos'] ?? 'tooltip' ) ) {
						echo '<span class="opf-image-swatch-frame">';
						echo '<img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
						if ( ( $large_image || ! empty( $field['image_zoom'] ) ) && ! empty( $choice['zoom_url'] ) ) {
							echo '<img class="opf-swatch-zoom-preview" src="' . esc_url( $choice['zoom_url'] ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
						}
						echo '</span>';
					}
					echo '<span class="opf-image-swatch-label">' . esc_html( $choice['label'] ) . $hint( $choice ) . '</span>';
					echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $input_attrs( $choice, $name . ( $multi ? '[]' : '' ), $checked ) . ' />';
					echo '</label>';
					echo '</div>';
				}
				echo '</div>';
				return;

			case 'card':
			case 'vcard':
			case 'card-qty':
			case 'vcard-qty':
				$vertical = in_array( $subtype, [ 'vcard', 'vcard-qty' ], true );
				echo '<style>.field-' . esc_html( $fid ) . ' .opf-card-wrap{--opf-cols:' . esc_html( (string) ( $field['items_per_row'] ?? 2 ) ) . ';--opf-cols-t:' . esc_html( (string) ( $field['items_per_row_tablet'] ?? 1 ) ) . ';--opf-cols-m:' . esc_html( (string) ( $field['items_per_row_mobile'] ?? 1 ) ) . ';}</style>';
				echo '<div class="opf-card-wrap opf-products opf-products--' . esc_attr( $subtype ) . '">';
				if ( ! $is_qty ) {
					echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
				}
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					if ( $disabled ) {
						$choice['disabled'] = true;
					}
					$checked = ! empty( $choice['selected'] ) && ! $disabled;
					$classes = $choice_classes( $choice, [ 'opf-card', 'opf-product-choice' ] );
					if ( $is_qty ) {
						$classes[] = 'is-qty-select';
					}
					if ( $vertical ) {
						$classes[] = 'opf-card--vertical';
						$classes[] = 'opf-card--fit-' . ( $field['img_fit'] ?? 'cover' );
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $wrapper_attrs( $choice ) . '>';
					echo '<div class="opf-card-inner">';
					if ( ! $is_qty ) {
						echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $input_attrs( $choice, $name . ( $multi ? '[]' : '' ), $checked ) . ' />';
					}
					if ( ! empty( $field['incl_img'] ) ) {
						$card_img_classes = 'opf-card-img';
						if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
							// Hover/focus enlargement (documented WAPF 3.2.1
							// linked-product zoom parity via OPF's CSS preview).
							$card_img_classes .= ' opf-swatch--image-zoom wapf-tt-wrap';
						}
						echo '<div class="' . esc_attr( $card_img_classes ) . '"><img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
						if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
							echo '<img class="opf-swatch-zoom-preview" src="' . esc_url( $choice['zoom_url'] ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
						}
						echo '</div>';
					}
					echo '<div class="opf-card-body">';
					echo '<div class="opf-card-row"><div class="opf-card-title"><span>' . esc_html( $choice['label'] ) . '</span></div>';
					echo self::product_slot_info( $field, $choice, 'slot_1' );
					echo '</div>';
					if ( ! empty( $field['incl_desc'] ) && '' !== (string) $choice['desc'] ) {
						echo '<div class="opf-card-row"><div class="opf-card-desc">' . esc_html( wp_trim_words( wp_strip_all_tags( $choice['desc'] ), 20 ) ) . '</div></div>';
					}
					if ( $is_qty ) {
						$q       = $choice['quantity'] ?? [ 'default' => 0, 'min' => 0, 'max' => 999999 ];
						$default = $disabled ? 0 : (int) $q['default'];
						$max     = isset( $field['max_choices'] ) ? min( (int) $q['max'], (int) $field['max_choices'] ) : (int) $q['max'];
						echo '<div class="opf-card-row"><div class="opf-card-qty opf-qty' . ( 'plus_min' === ( $field['display'] ?? '' ) ? ' apf-plusmin' : '' ) . '">';
						if ( 'plus_min' === ( $field['display'] ?? '' ) ) {
							echo '<button type="button" tabindex="-1" aria-label="' . esc_attr__( 'Reduce', 'open-product-fields-for-woocommerce' ) . '" class="button apf-minus opf-qty-minus">−</button>';
						}
						echo '<input type="number" step="1" value="' . esc_attr( (string) $default ) . '" min="' . esc_attr( (string) (int) $q['min'] ) . '" max="' . esc_attr( (string) $max ) . '" name="' . esc_attr( $name . '[' . $choice['slug'] . ']' ) . '" class="opf-input opf-qty is-qty input-' . esc_attr( $fid ) . ' input-' . esc_attr( $fid ) . '_' . esc_attr( $choice['slug'] ) . '" data-field-id="' . esc_attr( $fid ) . '" data-choice-slug="' . esc_attr( $choice['slug'] ) . '" data-no-zero="1"' . ( $disabled ? ' disabled data-disabled="1"' : '' ) . ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ? ' data-opf-swap-image="' . esc_attr( $choice['zoom_url'] ) . '"' : '' ) . ' />';
						if ( 'plus_min' === ( $field['display'] ?? '' ) ) {
							echo '<button type="button" tabindex="-1" aria-label="' . esc_attr__( 'Increase', 'open-product-fields-for-woocommerce' ) . '" class="button apf-plus opf-qty-plus">+</button>';
						}
						echo '</div></div>';
					}
					$slot_2 = self::product_slot_info( $field, $choice, 'slot_2' );
					$slot_3 = self::product_slot_info( $field, $choice, 'slot_3' );
					if ( '' !== $slot_2 || '' !== $slot_3 ) {
						echo '<div class="opf-card-row">' . $slot_2 . $slot_3 . '</div>';
					}
					echo '</div></div></div>';
				}
				echo '</div>';
				return;

			default: // checkbox + radio
				echo '<div class="opf-checkboxes opf-products opf-products--' . esc_attr( $subtype ) . ' wapf-checkboxes">';
				echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					if ( $disabled ) {
						$choice['disabled'] = true;
					}
					$checked = ! empty( $choice['selected'] ) && ! $disabled;
					$classes = $choice_classes( $choice, [ 'opf-swatch', 'opf-product-choice', 'wapf-input-label-wrap' ] );
					if ( ! $multi ) {
						$classes[] = 'opf-single-select';
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $wrapper_attrs( $choice ) . '>';
					echo '<label class="opf-input-label wapf-input-label">';
					echo '<input type="' . ( 'checkbox' === $subtype ? 'checkbox' : 'radio' ) . '" ' . $input_attrs( $choice, $name . ( 'checkbox' === $subtype ? '[]' : '' ), $checked ) . ' />';
					echo '<span class="opf-label-text wapf-label-text">' . esc_html( $choice['label'] ) . $hint( $choice ) . '</span>';
					echo '</label>';
					echo '</div>';
				}
				echo '</div>';
		}
	}

	/**
	 * Card slot content (price/stock/link) — WAPF Html::get_cart_info parity.
	 */
	private static function product_slot_info( array $field, array $choice, string $key ): string {
		$piece = (string) ( $field[ $key ] ?? 'none' );
		if ( 'none' === $piece || empty( $choice[ $piece ] ) ) {
			return '';
		}
		$html = '<div class="opf-card-info wapf-card-info opf-card-' . esc_attr( $piece ) . '">';
		if ( 'link' === $piece ) {
			$html .= '<a href="' . esc_url( $choice['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Details', 'open-product-fields-for-woocommerce' ) . '</a>';
		} else {
			$html .= wp_kses_post( (string) $choice[ $piece ] );
		}
		return $html . '</div>';
	}

	/**
	 * Legacy data attributes for the theme's live-total math. The theme
	 * interprets `data-opf-pricetype` with WAPF semantics (its evaluator
	 * treats fx results as per-unit after stripping an outermost *[qty]
	 * factor, qt as a per-unit amount, fixed as a flat per-line fee), so
	 * each OPF pricing block is encoded into the equivalent WAPF shape:
	 *  - percent per-unit → "percent" amount
	 *  - percent flat     → "fx" expression ([price]*a/100)/[qty]
	 *  - fixed flat       → "fixed" amount
	 *  - fixed per-unit   → "qt" amount
	 *  - formula per-unit → "fx" (expr) — the theme evaluates per unit
	 *  - formula flat     → "fx" (expr)/[qty] — per-unit share, flat line
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function pricing_attrs( array $pricing, bool $qty_based = false ): string {
		if ( 'none' === $pricing['type'] ) {
			return '';
		}
		$per_unit = ! empty( $pricing['per_unit'] );
		if ( 'formula' === $pricing['type'] ) {
			$expr  = '' !== trim( (string) $pricing['formula'] ) ? (string) $pricing['formula'] : '0';
			$type  = 'fx';
			$price = $qty_based
				? ( $per_unit ? '(' . $expr . ') * [qty]' : '(' . $expr . ')' )
				: ( $per_unit ? '(' . $expr . ')' : '(' . $expr . ') / [qty]' );
		} elseif ( 'percent' === $pricing['type'] && $qty_based && $per_unit ) {
			$type  = 'fx';
			$price = '([price] * ' . ( (float) $pricing['amount'] / 100 ) . ') * [qty]';
		} elseif ( 'percent' === $pricing['type'] && ! $per_unit ) {
			if ( $qty_based ) {
				$type  = 'percent';
				$price = (string) (float) $pricing['amount'];
			} else {
				$type  = 'fx';
				$price = '([price] * ' . ( (float) $pricing['amount'] / 100 ) . ') / [qty]';
			}
		} else {
			if ( $qty_based ) {
				$type = 'fixed' === $pricing['type'] && $per_unit ? 'fx' : 'qt';
				$price = 'fixed' === $pricing['type'] && $per_unit
					? '(' . (string) (float) $pricing['amount'] . ') * [qty]'
					: (string) (float) $pricing['amount'];
			} else {
				$type  = 'fixed' === $pricing['type'] && $per_unit ? 'qt' : $pricing['type'];
				$price = (string) (float) $pricing['amount'];
			}
		}
		return sprintf( ' data-opf-pricetype="%s" data-opf-price="%s"', esc_attr( $type ), esc_attr( $price ) );
	}

	/**
	 * WAPF text-length/regex native constraints (class-html.php:748-756).
	 * Rendered only; WAPF does not enforce these server-side.
	 *
	 * @param array<string,mixed> $field Field data.
	 */
	private static function text_validation_attrs( array $field ): string {
		$attrs = '';
		foreach ( [ 'minlength', 'maxlength' ] as $key ) {
			if ( isset( $field[ $key ] ) ) {
				$attrs .= ' ' . $key . '="' . esc_attr( (string) $field[ $key ] ) . '"';
			}
		}
		if ( ! empty( $field['pattern'] ) ) {
			$attrs .= ' pattern="' . esc_attr( (string) $field['pattern'] ) . '"';
		}
		return $attrs;
	}

	/**
	 * Text-like inputs.
	 *
	 * @param string              $name  Input name.
	 * @param string              $gid   Group id.
	 * @param array<string,mixed> $field Field data.
	 */
	private static function render_input( string $name, string $gid, array $field, bool $qty_based = false ): void {
		$fid = $field['id'];
		$shared = sprintf(
			'data-field-id="%1$s" id="opf-%2$s-%1$s"%3$s name="%5$s" class="opf-input input-%1$s" placeholder="%4$s" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"',
			esc_attr( $fid ),
			esc_attr( $gid ),
			$field['required'] ? ' required' : '',
			esc_attr( $field['placeholder'] ),
			esc_attr( $name )
		);
		$shared .= self::pricing_attrs( $field['pricing'] ?? [], $qty_based );
		$shared .= self::text_validation_attrs( $field );

		switch ( $field['type'] ) {
			case 'calc':
				$calc_type     = (string) ( $field['calc_type'] ?? 'default' );
				$calc_formula  = (string) ( $field['formula'] ?? '' );
				$calc_format   = (string) ( $field['result_format'] ?? 'number' );
				$calc_text     = (string) ( $field['result_text'] ?? '{result}' );
				$seed          = isset( $field['_opf_prefill'] ) && is_scalar( $field['_opf_prefill'] ) ? (string) $field['_opf_prefill'] : '';
				echo '<div class="opf-calc" data-opf-calc="1" data-opf-calc-type="' . esc_attr( $calc_type ) . '" data-opf-calc-format="' . esc_attr( $calc_format ) . '" data-opf-calc-text="' . esc_attr( $calc_text ) . '" data-opf-calc-formula="' . esc_attr( $calc_formula ) . '">';
				echo '<span class="opf-calc-text" aria-live="polite"></span>';
				echo '<input type="hidden" value="' . esc_attr( $seed ) . '" data-field-id="' . esc_attr( $fid ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" name="' . esc_attr( $name ) . '" class="opf-input opf-calc-raw input-' . esc_attr( $fid ) . '" data-opf-calc-raw="1" />';
				echo '</div>';
				break;
			case 'upload':
				$modern = Uploads::modern();
				// Cart-edit: the line's session-owned tokens come back as
				// hidden inputs + file rows; validate_tokens re-verifies
				// owner/product/group/field on resubmit (fail closed).
				$existing = array_values( array_filter( (array) ( $field['_opf_prefill_tokens'] ?? [] ), static function ( $t ) {
					return is_string( $t ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $t );
				} ) );
				// WAPF parity: raw configured bounds ride data-opf-upload-max/min
				// (-1 stays -1 = unlimited); data-opf-upload-limit keeps the
				// resolved PHP cap the uploader enforces client-side.
				$max_files_raw = isset( $field['max_files'] ) ? (int) $field['max_files'] : 1;
				$min_files     = max( (int) ( $field['min_files'] ?? 0 ), ( $field['required'] && ! $existing ) ? 1 : 0 );
				$editor_attrs  = '';
				if ( ! empty( $field['image_editor_mode'] ) ) {
					$editor_attrs = ' data-opf-upload-editor="' . esc_attr( (string) $field['image_editor_mode'] ) . '"';
					foreach ( [ 'crop', 'resize', 'rotate', 'flip' ] as $editor_option ) {
						$editor_attrs .= ' data-opf-editor-' . $editor_option . '="' . ( ! empty( $field[ 'image_editor_' . $editor_option ] ) ? '1' : '0' ) . '"';
					}
					$editor_attrs .= ' data-opf-editor-aspect="' . esc_attr( (string) ( $field['image_editor_aspect_ratio'] ?? 'free' ) ) . '"';
				}
				echo '<div class="opf-upload" data-opf-upload="' . ( $modern ? 'modern' : 'native' ) . '"' . $editor_attrs . ' data-opf-upload-name="' . esc_attr( $name ) . '" data-opf-upload-group="' . esc_attr( $gid ) . '" data-opf-upload-field="' . esc_attr( $field['id'] ) . '" data-opf-upload-limit="' . esc_attr( (string) Uploads::max_files( $field ) ) . '" data-opf-upload-max="' . esc_attr( (string) $max_files_raw ) . '" data-opf-upload-min="' . esc_attr( (string) $min_files ) . '" data-opf-upload-url="' . esc_url( rest_url( 'opf/v1/uploads' ) ) . '">';
				echo '<input type="hidden" name="opf_upload_native_nonce" value="' . esc_attr( wp_create_nonce( 'opf_upload_native' ) ) . '" />';
				// Staged uploads validate via session tokens (data-opf-upload-min
				// + setCustomValidity), so the file input never carries a native
				// required/empty-file constraint — it is always empty pre-upload.
				echo '<input type="file" class="opf-upload__input" id="opf-' . esc_attr( $gid . '-' . $field['id'] ) . '" name="opf_upload[' . esc_attr( $gid ) . '][' . esc_attr( $field['id'] ) . '][]"' . ( $field['accepted_types'] ? ' accept="' . esc_attr( '.' . implode( ',.', $field['accepted_types'] ) ) . '"' : '' ) . ( $field['multiple'] ? ' multiple' : '' ) . ' />';
				echo '<div class="opf-upload__files">';
				foreach ( $existing as $token ) {
					$record = Uploads::record( (string) $token );
					$fname  = is_array( $record ) && '' !== (string) ( $record['name'] ?? '' ) ? (string) $record['name'] : __( 'Uploaded file', 'open-product-fields-for-woocommerce' );
					echo '<div class="opf-upload__file opf-upload__file--existing"><input type="hidden" name="' . esc_attr( $name . '[]' ) . '" value="' . esc_attr( (string) $token ) . '" data-opf-upload-token="1" /><span>' . esc_html( $fname ) . '</span><button type="button" class="opf-upload__remove" data-opf-upload-remove="1" aria-label="' . esc_attr( sprintf( /* translators: %s: file name. */ __( 'Remove %s', 'open-product-fields-for-woocommerce' ), $fname ) ) . '">' . esc_html__( 'Remove', 'open-product-fields-for-woocommerce' ) . '</button></div>';
				}
				echo '</div><div class="opf-upload__status" role="status" aria-live="polite"></div></div>';
				break;
			case 'textarea':
				echo '<textarea ' . $shared . '>' . esc_html( (string) ( $field['default'] ?? '' ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
				break;
			case 'url':
				echo '<input type="url" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'email':
				echo '<input type="email" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				// WAPF number parity: min/max constraints ride the input, step
				// comes from the explicit step or the integer|decimal mode.
				$num_attrs = '';
				if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
					$num_attrs .= ' min="' . esc_attr( (string) (float) $field['min'] ) . '"';
				}
				if ( isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
					$num_attrs .= ' max="' . esc_attr( (string) (float) $field['max'] ) . '"';
				}
				if ( isset( $field['step'] ) && is_numeric( $field['step'] ) && (float) $field['step'] > 0 ) {
					$num_attrs .= ' step="' . esc_attr( (string) (float) $field['step'] ) . '"';
				} else {
					$num_attrs .= ' step="' . ( 'decimal' === ( $field['number_mode'] ?? 'integer' ) ? 'any' : '1' ) . '"';
				}
				// WAPF stores the stepper PER FIELD (`display: plus_min`,
				// class-config.php:614 and views/frontend/fields/number.php:3).
				// A field that sets the value overrides the global OPF default;
				// an absent value keeps the global Product fields setting working.
				$per_field_display = $field['display'] ?? null;
				$use_stepper = null !== $per_field_display
					? 'plus_min' === $per_field_display
					: 'yes' === get_option( 'opf_number_buttons', 'no' );
				if ( $use_stepper ) {
					$stepper_label = trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) );
					$stepper_label = '' !== $stepper_label ? $stepper_label : __( 'value', 'open-product-fields-for-woocommerce' );
					// WAPF-compatible wrapper/button classes (`apf-plusmin`,
					// `button apf-minus`/`apf-plus`, tabindex -1) so theme CSS
					// written for WAPF keeps working; OPF's own hook classes and
					// descriptive labels ride along.
					echo '<div class="opf-number-stepper apf-plusmin" data-opf-number-stepper><button type="button" tabindex="-1" class="opf-number-stepper__button button apf-minus" data-opf-number-step="down" aria-label="' . esc_attr( sprintf( /* translators: %s: field label. */ __( 'Decrease %s', 'open-product-fields-for-woocommerce' ), $stepper_label ) ) . '">−</button><input type="number" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . $num_attrs . ' /><button type="button" tabindex="-1" class="opf-number-stepper__button button apf-plus" data-opf-number-step="up" aria-label="' . esc_attr( sprintf( /* translators: %s: field label. */ __( 'Increase %s', 'open-product-fields-for-woocommerce' ), $stepper_label ) ) . '">+</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
				} else {
					echo '<input type="number" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . $num_attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				break;
			case 'date':
				$date_attrs = ' data-opf-date-format="' . esc_attr( \OPF\Engine\DateFormat::configured() ) . '"';
				$week_start = (int) get_option( 'start_of_week', 0 );
				if ( $week_start < 0 || $week_start > 6 ) {
					$week_start = 0; // WordPress week start is 0-6; anything else falls back to Sunday.
				}
				$date_attrs .= ' data-opf-week-start="' . esc_attr( (string) $week_start ) . '"';
				$current = function_exists( 'current_datetime' ) ? \current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
				$site_today = $current->format( 'Y-m-d' );
				$date_min = isset( $field['min_date'] ) ? FieldValue::resolve_date_boundary( (string) $field['min_date'] ) : null;
				$date_max = isset( $field['max_date'] ) ? FieldValue::resolve_date_boundary( (string) $field['max_date'] ) : null;
				if ( false === ( $field['allow_past'] ?? true ) && ( null === $date_min || $date_min < $site_today ) ) {
					$date_min = $site_today;
				}
				if ( false === ( $field['allow_future'] ?? true ) && ( null === $date_max || $date_max > $site_today ) ) {
					$date_max = $site_today;
				}
				foreach ( [ 'min_date' => 'min', 'max_date' => 'max' ] as $key => $attribute ) {
					$date = 'min_date' === $key ? $date_min : $date_max;
					if ( null !== $date ) {
						$date_attrs .= ' ' . $attribute . '="' . esc_attr( $date ) . '"';
					}
				}
				// WAPF field-relative bounds (`[field.x]<period>`) resolve against
				// another date field's runtime value; hand the raw expression to
				// the picker so it can re-resolve as the sibling changes.
				$field_relative_bound = false;
				foreach ( [ 'min_date' => 'min', 'max_date' => 'max' ] as $key => $attribute ) {
					if ( ! isset( $field[ $key ] ) || null === FieldValue::date_boundary_reference( (string) $field[ $key ] ) ) {
						continue;
					}
					$field_relative_bound = true;
					$date_attrs .= ' data-opf-date-' . $attribute . '-expression="' . esc_attr( (string) $field[ $key ] ) . '"';
				}
				if ( ! empty( $field['disable_today'] ) ) {
					$date_attrs .= ' data-opf-disable-today="1"';
				}
				if ( ! empty( $field['disabled_weekdays'] ) ) {
					$date_attrs .= ' data-opf-disabled-weekdays="' . esc_attr( wp_json_encode( array_values( $field['disabled_weekdays'] ) ) ) . '"';
				}
				if ( ! empty( $field['disabled_dates'] ) ) {
					$date_attrs .= ' data-opf-disabled-dates="' . esc_attr( wp_json_encode( array_values( $field['disabled_dates'] ) ) ) . '"';
				}
				// The site clock backs the cutoff, disable_today, and any
				// field-relative bound so server and browser resolve identically.
				if ( isset( $field['cutoff_time'] ) || ! empty( $field['disable_today'] ) || $field_relative_bound ) {
					$current = function_exists( 'current_datetime' ) ? \current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
					$date_attrs .= ' data-opf-date-site-epoch="' . esc_attr( (string) $current->getTimestamp() ) . '"';
					$date_attrs .= ' data-opf-date-timezone="' . esc_attr( function_exists( 'wp_timezone_string' ) ? \wp_timezone_string() : 'UTC' ) . '"';
				}
				if ( isset( $field['cutoff_time'] ) ) {
					$date_attrs .= ' data-opf-date-cutoff="' . esc_attr( $field['cutoff_time'] ) . '"';
				}
				// Cart-edit prefills via `_opf_prefill`; authored defaults are
				// handled by the date picker bootstrap.
				echo '<input type="date" value="' . esc_attr( (string) ( $field['_opf_prefill'] ?? '' ) ) . '" ' . $shared . $date_attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'toggle':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
				$switch_role = ! empty( $field['switch_control'] ) ? ' role="switch"' : '';
				echo '<input type="checkbox" value="1" ' . $shared . ( '1' === ( $field['default'] ?? '0' ) ? ' checked' : '' ) . $switch_role . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( '' !== ( $field['message'] ?? '' ) ) {
					echo '<label for="opf-' . esc_attr( $gid . '-' . $fid ) . '">' . esc_html( $field['message'] ) . '</label>';
				}
				break;
			default:
				echo '<input type="text" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
		}
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
		$lang = function_exists( 'pll_current_language' ) ? \pll_current_language( 'slug' ) : '';
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
		// WAPF alias bridge: legacy wapf_before_product_totals action.
		\OPF\Compat\WapfHooks::before_product_totals( $product );
		$mode = self::summary_mode();
		$hidden = 'hidden' === $mode ? ' opf-totals-hidden' : '';
		$i18n = self::i18n();
		// WAPF parity (class-html.php product totals): data-tax carries the REAL
		// product tax multiplier — the theme/legacy consumers multiply raw prices
		// by it. 1 for non-taxable, VAT-exempt, or missing tax context.
		// data-opf-tax-factor carries the customer-facing display factor (exempt/
		// location aware) so the frontend preview matches wc_get_price_to_display.
		$data_tax = self::tax_multiplier( $product );
		$tax_factor = self::tax_display_factor( $product );
		echo '<div class="opf-product-totals' . esc_attr( $hidden ) . '" style="' . ( 'hidden' === $mode ? 'display:none;' : '' ) . '" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-product-type="' . esc_attr( $product->get_type() ) . '" data-product-price="' . esc_attr( (string) $product->get_price() ) . '" data-tax="' . esc_attr( (string) $data_tax ) . '" data-opf-tax-factor="' . esc_attr( (string) $tax_factor ) . '"><div class="opf--inner">';
		if ( 'three' === $mode ) {
			echo '<div><span>' . esc_html( $i18n['product_total'] ) . '</span> <span class="opf-total opf-product-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['options_total'] ) . '</span> <span class="opf-total opf-options-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		} elseif ( 'grand' === $mode ) {
			echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		} else {
			echo '<div><span>' . esc_html( $i18n['product_total'] ) . '</span> <span class="opf-total opf-product-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['options_total'] ) . '</span> <span class="opf-total opf-options-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		}
		echo '</div></div>';
		// WAPF alias bridge: legacy wapf_after_product_totals action.
		\OPF\Compat\WapfHooks::after_product_totals( $product );
	}

	/**
	 * Shop-context display multiplier: the amount `wc_get_price_to_display()`
	 * returns for a unit price of 1. Handles tax-exclusive and tax-inclusive
	 * catalogs as well as the customer's location / VAT-exempt session. The
	 * frontend multiplies its raw option totals by this so the on-page preview
	 * matches the cart/order display instead of showing the untaxed amount.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function tax_display_factor( \WC_Product $product ): float {
		if ( ! function_exists( 'wc_get_price_to_display' ) ) {
			return 1.0;
		}
		$factor = (float) wc_get_price_to_display( $product, [ 'qty' => 1, 'price' => 1, 'display_context' => 'shop' ] );
		return $factor > 0 ? $factor : 1.0;
	}

	/* ------------------------------------------------------------------
	 * Cart-edit prefill helpers (WAPF-INTERACTION-CART-EDIT).
	 * Stored cart values (`opf_fields`) reuse the sanitized submit shape, so
	 * overlaying them onto a field definition lets the unchanged render
	 * functions emit `value=`/`selected`/`checked` exactly like a normal
	 * authored default.
	 * ------------------------------------------------------------------ */

	/**
	 * Conditional seed for one field: the stored edit value when present
	 * (first row for repeated fields), else the authored default. Wrapper
	 * types flatten to their slug/quantity map so Evaluator keeps its
	 * scalar-or-flat-list contract.
	 *
	 * @param array<string,mixed> $field    Field data.
	 * @param mixed               $stored   Stored edit value or null.
	 * @param bool                $repeated Field repeats or lives in a repeating section.
	 * @return string|array
	 */
	private static function seed_value( array $field, $stored, bool $repeated ) {
		if ( null === $stored ) {
			return self::default_value( $field );
		}
		if ( $repeated ) {
			$stored = self::row_at( $stored, 0 );
			if ( null === $stored ) {
				return self::default_value( $field );
			}
		}
		if ( is_array( $stored ) && isset( $stored['_opf_type'] ) && is_array( $stored['quantities'] ?? null ) ) {
			return $stored['quantities']; // image_quantity / products qty selector.
		}
		return is_array( $stored ) || is_scalar( $stored ) ? $stored : '';
	}

	/** Row `$index` of a row-indexed stored value, or the value itself. */
	private static function row_at( $stored, int $index ) {
		if ( is_array( $stored ) && array_key_exists( $index, $stored ) ) {
			return $stored[ $index ];
		}
		return null === $stored ? null : ( 0 === $index && ! is_array( $stored ) ? $stored : null );
	}

	/**
	 * Stored rows for a repeated field: list form, padded to `$qty` with the
	 * last row for quantity mode (split cart lines store one row per merged
	 * unit group — identical units legitimately share that row).
	 *
	 * @return array<int,mixed>|null Null when nothing stored.
	 */
	private static function edit_rows_for( $stored, int $qty ): ?array {
		if ( null === $stored ) {
			return null;
		}
		$rows = is_array( $stored ) ? array_values( $stored ) : [ $stored ];
		if ( $qty > count( $rows ) && $rows ) {
			$last = end( $rows );
			while ( count( $rows ) < $qty ) {
				$rows[] = $last;
			}
		}
		return $rows;
	}

	/**
	 * fid => repeat mode for fields nested inside a repeating section.
	 * Mirrors CartIntegration::section_repeat_context() (kept private: the
	 * render path needs the mode, not the config).
	 *
	 * @param array<int,array<string,mixed>> $fields Normalized group fields.
	 * @return array<string,string>
	 */
	private static function section_repeat_fids( array $fields ): array {
		$context = [];
		$stack   = [];
		$active  = [];
		foreach ( $fields as $field ) {
			if ( 'section_end' === ( $field['type'] ?? '' ) ) {
				array_pop( $stack );
				$active = $stack ? end( $stack ) : [];
				continue;
			}
			if ( 'section' === ( $field['type'] ?? '' ) ) {
				$repeat = ! empty( $field['repeat']['enabled'] ) ? $field['repeat'] : $active;
				$stack[] = $repeat;
				$active  = $repeat;
				continue;
			}
			if ( $active ) {
				$context[ (string) $field['id'] ] = (string) ( $active['mode'] ?? 'button' );
			}
		}
		return $context;
	}

	/**
	 * Field ids directly inside the section that starts at `$section_index`
	 * (depth-aware; nested section bodies are skipped by level tracking but
	 * their ids still belong to the outer row payload when not repeatable).
	 *
	 * @param array<int,array<string,mixed>> $fields        Group fields.
	 * @param int                            $section_index Index of the opening `section` field.
	 * @return array<int,string>
	 */
	private static function section_inner_fids( array $fields, int $section_index ): array {
		$fids  = [];
		$depth = 0;
		for ( $i = $section_index + 1; $i < count( $fields ); $i++ ) {
			$type = (string) ( $fields[ $i ]['type'] ?? '' );
			if ( 'section' === $type ) {
				$depth++;
				continue;
			}
			if ( 'section_end' === $type ) {
				if ( 0 === $depth ) {
					break;
				}
				$depth--;
				continue;
			}
			$fids[] = (string) ( $fields[ $i ]['id'] ?? '' );
		}
		return array_values( array_filter( $fids, 'strlen' ) );
	}

	/**
	 * `data-opf-edit-rows` payload for a repeating section: rows 1..N as
	 * ordered `fid => value` maps (row 0 is server-rendered). For
	 * quantity-mode sections rows pad to the cart line quantity.
	 *
	 * @param array<int,array<string,mixed>> $fields        Group fields.
	 * @param int                            $section_index Opening section index.
	 * @param array<string,mixed>            $prefill       Stored values fid => value.
	 * @param int                            $qty           Quantity-mode row target (0 = button mode).
	 * @return array<int,array<string,mixed>>
	 */
	private static function section_edit_rows( array $fields, int $section_index, array $prefill, int $qty ): array {
		$fids = self::section_inner_fids( $fields, $section_index );
		if ( ! $fids ) {
			return [];
		}
		$row_count = $qty > 0 ? $qty : 1;
		$by_fid    = [];
		foreach ( $fids as $fid ) {
			$stored = $prefill[ $fid ] ?? null;
			if ( null === $stored ) {
				$by_fid[ $fid ] = [];
				continue;
			}
			$rows = is_array( $stored ) ? array_values( $stored ) : [ $stored ];
			$row_count = max( $row_count, count( $rows ) );
			$by_fid[ $fid ] = $rows;
		}
		if ( $row_count < 2 ) {
			return [];
		}
		$out = [];
		for ( $i = 1; $i < $row_count; $i++ ) {
			$row = [];
			foreach ( $by_fid as $fid => $rows ) {
				if ( array_key_exists( $i, $rows ) ) {
					$row[ $fid ] = $rows[ $i ];
				} elseif ( $rows ) {
					$row[ $fid ] = end( $rows ); // quantity-mode padding mirrors edit_rows_for().
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Overlay one stored edit value onto a field definition so the existing
	 * render functions emit it as the field's current value.
	 *
	 * @param array<string,mixed> $field Field data.
	 * @param mixed               $value Stored value (scalar, slug list, quantities wrapper, tokens).
	 */
	private static function with_prefill( array $field, $value ): array {
		switch ( $field['type'] ) {
			case 'text':
			case 'url':
			case 'email':
			case 'number':
			case 'textarea':
				$field['default'] = is_scalar( $value ) ? (string) $value : '';
				break;
			case 'calc':
				// Cart-edit prefill: the previously computed raw value seeds the
				// hidden input; the client recomputes it on load.
				$field['_opf_prefill'] = is_scalar( $value ) ? (string) $value : '';
				break;
			case 'date':
				// render_input emits `_opf_prefill` for date (the authored
				// default stays the field's real default elsewhere).
				$field['_opf_prefill'] = is_scalar( $value ) ? (string) $value : '';
				break;
			case 'toggle':
				$field['default'] = '1' === (string) $value ? '1' : '0';
				break;
			case 'select':
			case 'radio':
			case 'checkbox':
			case 'swatch':
				$slugs = array_map( 'strval', (array) $value );
				foreach ( $field['choices'] as $i => $choice ) {
					$field['choices'][ $i ]['selected'] = in_array( (string) ( $choice['slug'] ?? '' ), $slugs, true );
				}
				break;
			case 'image_quantity':
				$quantities = is_array( $value ) ? ( $value['quantities'] ?? $value ) : [];
				foreach ( $field['choices'] as $i => $choice ) {
					$slug = (string) ( $choice['slug'] ?? '' );
					if ( isset( $field['choices'][ $i ]['quantity'] ) ) {
						$field['choices'][ $i ]['quantity']['default'] = (int) ( $quantities[ $slug ] ?? 0 );
					}
				}
				break;
			case 'products':
				// Stash for render_products_field: choices resolve at render
				// time (manual ids AND category-mode live queries), so the
				// overlay applies to the expanded list, not authored data.
				$field['_opf_prefill_products'] = $value;
				break;
			case 'upload':
				// Session-owned private tokens ride back through hidden
				// inputs; validate_tokens re-checks owner/scope on resubmit.
				$field['_opf_prefill_tokens'] = Uploads::tokens( $value );
				break;
		}
		return $field;
	}

	/**
	 * Default submitted value for a field (seeds conditional evaluation).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @return string|array
	 */
	private static function default_value( array $field ) {
		// Quantity-selector fields submit a quantity map, not selectable slugs;
		// seed conditionals with the same `{_opf_type, quantities}` shape the
		// client registry and request space use so `empty`/`!empty` evaluate
		// "no/any positive quantity" identically on the server and in the DOM.
		if ( ( 'products' === $field['type'] && LinkedProducts::is_qty_subtype( $field ) ) || 'image_quantity' === $field['type'] ) {
			$quantities = [];
			foreach ( $field['choices'] as $choice ) {
				$quantities[ (string) $choice['slug'] ] = (int) ( $choice['quantity']['default'] ?? 0 );
			}
			return [
				'_opf_type'  => 'image_quantity' === $field['type'] ? 'image_quantity' : 'products',
				'quantities' => $quantities,
			];
		}
		if ( in_array( $field['type'], [ 'text', 'url' ], true ) ) {
			return (string) ( $field['default'] ?? '' );
		}
		if ( 'toggle' === $field['type'] ) {
			return (string) ( $field['default'] ?? '0' );
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox', 'products' ], true ) ) {
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
