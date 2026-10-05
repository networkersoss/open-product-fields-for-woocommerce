<?php
/**
 * Conditional logic evaluator (server-side source of truth).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class Evaluator {

	/**
	 * Placement/conditional subjects evaluated against the selected product
	 * variation instead of a posted field value:
	 *  - `product_var` — selected variation IDs (WAPF `product_var`/`!product_var`).
	 *  - `var_att`     — variation attribute pairs `attribute|value` with `*`
	 *                    wildcard (WAPF `patts`/`!patts`, taxonomy attributes).
	 */
	public const VARIATION_SUBJECTS = [ 'product_var', 'var_att' ];

	/**
	 * Explicit variation context override (tests, non-request flows).
	 *
	 * @var array{variable:bool,id:int,attributes:array<string,string>}|null
	 */
	private static $variation_context = null;

	/**
	 * Product most recently resolved through FieldGroups::for_product().
	 * Variation context derives from it when no explicit override is set.
	 *
	 * @var object|null
	 */
	private static $context_product = null;

	/**
	 * Record the product a field-resolution pass is running against.
	 *
	 * @param object|null $product WC_Product-like object or null to reset.
	 */
	public static function set_context_product( $product ): void {
		self::$context_product = is_object( $product ) ? $product : null;
	}

	/**
	 * Override the variation context used by subject rules.
	 *
	 * @param array{variable:bool,id:int,attributes:array<string,string>}|null $context Context or null to clear.
	 */
	public static function set_variation_context( ?array $context ): void {
		self::$variation_context = $context;
	}

	/**
	 * Should a field be shown, given current values?
	 *
	 * "show" conditionals: field shows if (logic applied over rules) is true.
	 * "hide" conditionals: field hides if it is true. A field with both kinds
	 * shows only when at least one show-conditional passes and no hide passes.
	 *
	 * `action => 'var'` conditionals are generated gates (WAPF
	 * merge_frontend_conditions parity): group-level variation rules merged
	 * into every field at resolution time. They must ALL pass — they never
	 * participate in the show/hide OR semantics.
	 *
	 * @param array<string,mixed>          $field  Normalized field.
	 * @param array<string,string|array>   $values field_id => submitted value.
	 */
	public static function is_visible( array $field, array $values ): bool {
		if ( empty( $field['conditionals'] ) ) {
			return true;
		}

		$has_show  = false;
		$show_pass = false;
		$hide_pass = false;
		$var_ctx   = null;

		foreach ( $field['conditionals'] as $conditional ) {
			if ( null === $var_ctx && self::conditional_has_variation_rules( $conditional ) ) {
				$var_ctx = self::variation_context( $field );
			}
			$passed = self::conditional_passes( $conditional, $values, $var_ctx ?? [] );
			$action = (string) ( $conditional['action'] ?? 'show' );
			if ( 'var' === $action ) {
				if ( ! $passed ) {
					return false;
				}
				continue;
			}
			if ( 'hide' === $action ) {
				$hide_pass = $hide_pass || $passed;
			} else {
				$has_show  = true;
				$show_pass = $show_pass || $passed;
			}
		}

		if ( $hide_pass ) {
			return false;
		}
		return $has_show ? $show_pass : true;
	}

	/**
	 * Does a conditional block reference any variation-scoped subject?
	 *
	 * @param array<string,mixed> $conditional Normalized conditional.
	 */
	private static function conditional_has_variation_rules( array $conditional ): bool {
		foreach ( (array) ( $conditional['rules'] ?? [] ) as $rule ) {
			if ( in_array( (string) ( $rule['subject'] ?? '' ), self::VARIATION_SUBJECTS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate one conditional block.
	 *
	 * @param array<string,mixed>        $conditional Normalized conditional.
	 * @param array<string,string|array> $values      Current values.
	 * @param array<string,mixed>|null   $var_ctx     Variation context (resolved lazily when null and needed).
	 */
	public static function conditional_passes( array $conditional, array $values, ?array $var_ctx = null ): bool {
		$results = [];
		foreach ( $conditional['rules'] as $rule ) {
			if ( in_array( (string) ( $rule['subject'] ?? '' ), self::VARIATION_SUBJECTS, true ) ) {
				$results[] = self::variation_rule_passes( $rule, $var_ctx ?? self::variation_context() );
				continue;
			}
			$results[] = self::rule_passes( $rule, $values[ $rule['field'] ?? '' ] ?? '' );
		}
		if ( empty( $results ) ) {
			return false;
		}
		return 'any' === $conditional['logic'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Evaluate a single rule against a value.
	 *
	 * Quantity maps (`{slug: qty}` assoc arrays, or structured
	 * `{_opf_type, quantities}` product/image-quantity values) get WAPF
	 * qty-selector semantics instead of the generic string comparisons:
	 * zero/negative quantities are invisible, `empty` means "no positive
	 * quantity", `is`/`contains` mean "a positive quantity equals N" (WAPF's
	 * `in_array`/`indexOf` against the submitted quantity list — its admin
	 * forces a number input for these) with a documented OPF superset that
	 * also accepts "choice slug has a positive quantity", and `greater`/`less`
	 * compare the total of positive quantities. Sequential arrays keep their
	 * existing slug-list semantics, so non-qty multi-choice fields are
	 * unaffected.
	 *
	 * @param array<string,mixed> $rule  Normalized rule.
	 * @param string|array        $value Current value.
	 */
	public static function rule_passes( array $rule, $value ): bool {
		$qty_map = self::qty_map( $value );
		if ( null !== $qty_map ) {
			return self::qty_rule_passes( $rule, $qty_map );
		}
		$actual = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		$expect = (string) ( $rule['value'] ?? '' );

		switch ( $rule['operator'] ) {
			case 'is':
				return is_array( $value ) ? in_array( $expect, array_map( 'strval', $value ), true ) : $actual === $expect;
			case 'is_not':
				return ! self::rule_passes( [ 'field' => $rule['field'], 'operator' => 'is', 'value' => $expect ], $value );
			case 'contains':
				return false !== strpos( strtolower( $actual ), strtolower( $expect ) );
			case 'not_contains':
				return false === strpos( strtolower( $actual ), strtolower( $expect ) );
			case 'greater':
				return is_numeric( $actual ) && is_numeric( $expect ) && (float) $actual > (float) $expect;
			case 'less':
				return is_numeric( $actual ) && is_numeric( $expect ) && (float) $actual < (float) $expect;
			case 'empty':
				return '' === trim( $actual );
			case 'not_empty':
				return '' !== trim( $actual );
			default:
				return false;
		}
	}

	/**
	 * Is a submitted value a quantity map? Accepts both the raw posted
	 * `{slug: qty}` assoc array (request space) and the structured
	 * `{_opf_type: 'products'|'image_quantity'|'quantity', quantities: {...}}`
	 * value carried by the client registry and stored cart items.
	 *
	 * Sequential arrays are regular slug lists and return null; empty arrays
	 * are treated as qty maps of nothing (a qty field with all-zero values is
	 * indistinguishable from an untouched one, matching WAPF's zero-filtering).
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string,mixed>|null
	 */
	public static function qty_map( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( isset( $value['quantities'] ) && is_array( $value['quantities'] ) && in_array( (string) ( $value['_opf_type'] ?? '' ), [ 'products', 'image_quantity', 'quantity' ], true ) ) {
			return $value['quantities'];
		}
		if ( [] === $value ) {
			return null;
		}
		$keys = array_keys( $value );
		if ( $keys === range( 0, count( $value ) - 1 ) ) {
			return null; // Sequential slug list, not a qty map.
		}
		foreach ( $value as $slug => $qty ) {
			if ( ! is_scalar( $qty ) ) {
				return null;
			}
		}
		return $value;
	}

	/**
	 * WAPF qty-selector rule semantics over a `{slug: qty}` map.
	 *
	 * @param array<string,mixed>  $rule    Normalized rule.
	 * @param array<string,mixed>  $qty_map slug => submitted quantity.
	 */
	private static function qty_rule_passes( array $rule, array $qty_map ): bool {
		$expect   = (string) ( $rule['value'] ?? '' );
		$positive = [];
		foreach ( $qty_map as $slug => $qty ) {
			if ( is_numeric( $qty ) && (float) $qty > 0 ) {
				$positive[ (string) $slug ] = (float) $qty;
			}
		}
		$total = array_sum( $positive );

		switch ( $rule['operator'] ) {
			case 'empty':
				return [] === $positive;
			case 'not_empty':
				return [] !== $positive;
			case 'is':
			case 'contains':
				// WAPF `==`/`==contains` on qty fields matches a submitted
				// quantity (its rule value is a number input); OPF superset
				// also accepts a choice slug carrying a positive quantity.
				if ( is_numeric( $expect ) && in_array( (float) $expect, $positive, true ) ) {
					return true;
				}
				return '' !== $expect && isset( $positive[ $expect ] );
			case 'is_not':
			case 'not_contains':
				return ! self::qty_rule_passes( [ 'field' => $rule['field'], 'operator' => 'contains', 'value' => $expect ], $qty_map );
			case 'greater':
				return is_numeric( $expect ) && $total > (float) $expect;
			case 'less':
				return is_numeric( $expect ) && $total < (float) $expect;
			default:
				return false;
		}
	}

	/**
	 * Own id of the current product when it is a variation of `$product_id`.
	 *
	 * Reads the ambient context product recorded by
	 * FieldGroups::for_product() — the same source as the variation context —
	 * and only trusts it when it is a variation of the product being resolved,
	 * so a stale context from an earlier pass cannot leak into the match.
	 *
	 * @param int $product_id Parent-resolved id of the product being resolved.
	 * @return string|null Variation id, or null when the current product is not a variation.
	 */
	private static function current_variation_id( int $product_id ): ?string {
		$product = self::$context_product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variation' ) ) {
			return null;
		}
		if ( ! method_exists( $product, 'get_parent_id' ) || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}
		if ( (int) $product->get_parent_id() !== $product_id ) {
			return null;
		}
		return (string) $product->get_id();
	}

	/**
	 * Evaluate a variation-scoped rule against a resolved variation context.
	 *
	 * WAPF 3.1.5 frontend parity (assets/js/frontend.min.js isValidRule):
	 *  - non-variable contexts always pass;
	 *  - no variation selected fails every variation rule;
	 *  - `product_var` matches the selected variation ID against `terms`;
	 *  - `var_att` matches any `attribute|value` term against the selected
	 *    variation's attributes (`attribute_pa_<attr>` first, then the plain
	 *    `attribute_<attr>` key for custom attributes); `*` requires a
	 *    non-empty value;
	 *  - `not_in` negates the match (WAPF `!product_var`/`!patts` at field level).
	 *
	 * @param array<string,mixed>                        $rule Rule with subject/operator/terms.
	 * @param array{variable:bool,id:int,attributes:array<string,string>} $ctx Variation context.
	 */
	public static function variation_rule_passes( array $rule, array $ctx ): bool {
		if ( empty( $ctx['variable'] ) ) {
			return true;
		}
		if ( empty( $ctx['id'] ) ) {
			return false;
		}
		$terms   = array_map( 'strval', (array) ( $rule['terms'] ?? [] ) );
		$subject = (string) ( $rule['subject'] ?? '' );
		if ( 'product_var' === $subject ) {
			$in = in_array( (string) (int) $ctx['id'], $terms, true )
				|| in_array( (string) $ctx['id'], $terms, true );
		} else {
			$in = self::variation_attributes_match( $terms, (array) ( $ctx['attributes'] ?? [] ) );
		}
		return 'not_in' === ( $rule['operator'] ?? 'in' ) ? ! $in : $in;
	}

	/**
	 * Match `attribute|value` terms against `attribute_*`-keyed variation
	 * attributes. WAPF `patts` parity: at least one term must hit.
	 *
	 * @param array<int,string>          $terms      `attr|value` pairs, `*` wildcard.
	 * @param array<string,string>       $attributes `attribute_pa_x`/`attribute_x` keyed values.
	 */
	private static function variation_attributes_match( array $terms, array $attributes ): bool {
		foreach ( $terms as $term ) {
			$parts = explode( '|', $term, 2 );
			if ( 2 !== count( $parts ) || '' === $parts[0] ) {
				continue;
			}
			$actual = $attributes[ 'attribute_pa_' . $parts[0] ] ?? $attributes[ 'attribute_' . $parts[0] ] ?? null;
			if ( null === $actual || '' === $actual ) {
				continue;
			}
			if ( '*' === $parts[1] || (string) $actual === $parts[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the effective variation context: explicit override, per-field
	 * baked context (`_var_ctx`, set by injected group rules), then the product
	 * currently being resolved.
	 *
	 * @param array<string,mixed>|null $field Field being evaluated (may carry `_var_ctx`).
	 * @return array{variable:bool,id:int,attributes:array<string,string>}
	 */
	private static function variation_context( ?array $field = null ): array {
		$ctx = $field['_var_ctx'] ?? self::$variation_context;
		if ( null !== $ctx ) {
			return $ctx;
		}
		return self::product_variation_context( self::$context_product );
	}

	/**
	 * Build the variation context for a product object.
	 *
	 * Variations evaluate strictly against themselves (WAPF strict mode);
	 * variable parents read the submitted `variation_id`/`attribute_*` values
	 * when a request supplies them; every other type is non-variable context
	 * where variation rules pass through.
	 *
	 * @param object|null $product WC_Product-like object.
	 * @return array{variable:bool,id:int,attributes:array<string,string>}
	 */
	public static function product_variation_context( $product ): array {
		$type = is_object( $product ) && method_exists( $product, 'get_type' ) ? (string) $product->get_type() : '';
		// WAPF checks the rendered product type for the `variable`/`variation`
		// substrings, which also covers variable-subscription types.
		if ( false === strpos( $type, 'variable' ) && false === strpos( $type, 'variation' ) ) {
			return [ 'variable' => false, 'id' => 0, 'attributes' => [] ];
		}
		if ( is_object( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			$attributes = method_exists( $product, 'get_variation_attributes' )
				? array_map( 'strval', (array) $product->get_variation_attributes() )
				: [];
			return [ 'variable' => true, 'id' => (int) $product->get_id(), 'attributes' => $attributes ];
		}
		return [
			'variable'   => true,
			'id'         => isset( $_POST['variation_id'] ) ? max( 0, (int) $_POST['variation_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'attributes' => self::posted_variation_attributes(),
		];
	}

	/**
	 * Collect submitted `attribute_*` selections from the current request.
	 * The engine stays pure-PHP testable: sanitizers degrade gracefully when
	 * WordPress helpers are unavailable.
	 *
	 * @return array<string,string>
	 */
	private static function posted_variation_attributes(): array {
		$attributes = [];
		foreach ( (array) $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'attribute_' ) || ! is_scalar( $value ) ) {
				continue;
			}
			$clean_key = function_exists( 'sanitize_key' )
				? sanitize_key( $key )
				: (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			$raw_value = function_exists( 'wp_unslash' ) ? wp_unslash( (string) $value ) : (string) $value;
			$attributes[ $clean_key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw_value ) : trim( $raw_value );
		}
		return $attributes;
	}

	/**
	 * Does a field group's placement rules match a product?
	 *
	 * Empty rule_groups means "everywhere" (explicit OPF semantics — WAPF's
	 * empty-condition fall-through-to-false footgun is deliberately not replicated).
	 *
	 * `product` rules match the parent-resolved `$product_id` plus, when the
	 * product being resolved is one of its variations, that variation's own id.
	 *
	 * @param array<string,mixed> $group           Normalized group data.
	 * @param array<string, array<int|string>> $has_terms subject => term ids the product belongs to, e.g. ['product_cat' => [1,2]]. Subjects besides 'product'/'user_*' may be 'product_cat', 'product_tag', 'product_type' (type slugs), any 'pa_*' attribute taxonomy (term ids), 'product_var' (variation ids in scope) or 'var_att' (`attr|value` pairs the product defines).
	 * @param int                 $product_id      Current product id (parent-resolved for a variation).
	 * @param array<string,mixed> $user_context    Viewer context (logged_in/roles/language).
	 * @param array<int,array<string,mixed>>|null $variation_rules Out: variation rules of the matching rule group (WAPF `valid_rule_group` frontend merge).
	 */
	public static function group_matches( array $group, array $has_terms, int $product_id, array $user_context = [], ?array &$variation_rules = null ): bool {
		$rule_groups = $group['rule_groups'] ?? [];
		if ( empty( $rule_groups ) ) {
			return true;
		}
		foreach ( $rule_groups as $rule_group ) {
			$group_ok = true;
			foreach ( $rule_group['rules'] as $rule ) {
				if ( ! self::placement_rule_passes( $rule, $has_terms, $product_id, $user_context ) ) {
					$group_ok = false;
					break;
				}
			}
			if ( $group_ok ) {
				$variation_rules = self::variation_rules_of( (array) ( $rule_group['rules'] ?? [] ) );
				return true;
			}
		}
		return false;
	}

	/**
	 * Variation-scoped rules inside a placement rule list (WAPF
	 * ConditionRuleGroup::get_variation_rules parity).
	 *
	 * @param array<int,array<string,mixed>> $rules Normalized rules.
	 * @return array<int,array{subject:string,operator:string,terms:array<int,string>}>
	 */
	public static function variation_rules_of( array $rules ): array {
		$out = [];
		foreach ( $rules as $rule ) {
			$subject = (string) ( $rule['subject'] ?? '' );
			if ( ! in_array( $subject, self::VARIATION_SUBJECTS, true ) ) {
				continue;
			}
			$out[] = [
				'subject'  => $subject,
				'operator' => 'not_in' === ( $rule['operator'] ?? 'in' ) ? 'not_in' : 'in',
				'terms'    => array_values( array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ) ),
			];
		}
		return $out;
	}

	/**
	 * Evaluate one placement rule.
	 *
	 * @param array<string,mixed> $rule       Normalized placement rule.
	 * @param array<string,array> $has_terms  subject => term ids.
	 */
	private static function placement_rule_passes( array $rule, array $has_terms, int $product_id, array $user_context ): bool {
		$subject = $rule['subject'];

		if ( 'product' === $subject ) {
			// WAPF 3.1.5 parity (`class-conditions.php:278-287`
			// `is_current_product`): a variation is matched through its parent
			// product id, every other type through its own id. OPF also honours
			// an explicit variation id in the terms, which is what WAPF Free's
			// direct object comparison does and what makes an imported rule that
			// selects one variation match exactly that variation.
			$variation_id = self::current_variation_id( $product_id );
			$in           = in_array( (string) $product_id, $rule['terms'], true )
				|| ( null !== $variation_id && in_array( $variation_id, $rule['terms'], true ) );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( 'product_var' === $subject ) {
			// WAPF 3.1.5 parity quirk (class-conditions.php `check`): the
			// `!product_var` group condition shares the positive branch —
			// the group only renders when a scoped variation is relevant, and
			// the merged per-field `not_in` rule performs the actual exclusion.
			// True negation here would drop the group on the parent page and
			// break the intended "visible except on variation X" flow.
			return ! empty( array_intersect( array_map( 'strval', $rule['terms'] ), array_map( 'strval', (array) ( $has_terms['product_var'] ?? [] ) ) ) );
		}

		if ( 'var_att' === $subject ) {
			// WAPF `patts`/`!patts` group check: the attribute pair must (or
			// must not) exist among the product's configured attributes.
			$in = ! empty( array_intersect( array_map( 'strval', $rule['terms'] ), array_map( 'strval', (array) ( $has_terms['var_att'] ?? [] ) ) ) );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( 'user_auth' === $subject ) {
			$in = ! empty( $user_context['logged_in'] );
			if ( [ 'logged_in' ] === $rule['terms'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
				return 'not_in' === $rule['operator'] ? ! $in : $in;
			}
			if ( [] === $rule['terms'] && in_array( $rule['operator'], [ 'logged_in', 'logged_out' ], true ) ) {
				return 'logged_in' === $rule['operator'] ? $in : ! $in;
			}
			return false;
		}

		if ( 'user_role' === $subject ) {
			$roles = array_map( 'strval', (array) ( $user_context['roles'] ?? [] ) );
			$in    = ! empty( array_intersect( $rule['terms'], $roles ) );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( 'user_language' === $subject ) {
			$language = (string) ( $user_context['language'] ?? 'default' );
			$in       = in_array( $language, $rule['terms'], true );
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( 'product_attribute' === $subject ) {
			// WAPF/legacy builder dialect: composite `pa_x:term_id` terms map
			// onto the per-taxonomy `pa_x` has_terms buckets.
			$in = false;
			foreach ( array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ) as $composite ) {
				$parts = explode( ':', $composite, 2 );
				if ( 2 === count( $parts ) && in_array( $parts[1], array_map( 'strval', (array) ( $has_terms[ $parts[0] ] ?? [] ) ), true ) ) {
					$in = true;
					break;
				}
			}
			return 'not_in' === $rule['operator'] ? ! $in : $in;
		}

		if ( ! in_array( $subject, [ 'product_cat', 'product_tag', 'product_type' ], true ) && 0 !== strpos( $subject, 'pa_' ) ) {
			return false;
		}

		$terms = array_map( 'strval', $has_terms[ $subject ] ?? [] );
		$in    = ! empty( array_intersect( $rule['terms'], $terms ) );
		return 'not_in' === $rule['operator'] ? ! $in : $in;
	}
}
