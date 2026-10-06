<?php
/**
 * WAPF-COMMERCE-WEIGHT / WAPF-PRICE-FORMULA-WEIGHT unit tests.
 *
 * Covers the schema boundary (FieldGroup), the import boundary (WapfMapper)
 * and the runtime evaluator (Calculator::field_weight) against the WAPF
 * Extended_Controller::maybe_calculate_weight semantics verified on the
 * reference plugin, plus WAPF 3.2's "the weight setting can hold simple
 * formulas":
 *  - weight lives on field `options.weight` (aliased by the builder's
 *    `weight_formula`) and choice `options.weight`;
 *  - [qty] = cart line quantity, [x] = submitted value / choice label,
 *    [field.{id}] = another submitted field's value;
 *  - plain numbers and bare tokens keep their 3.1.5 floatval() results, while
 *    arithmetic is evaluated by the pricing formula parser (no second engine)
 *    and an expression the parser rejects fails closed to 0;
 *  - qty_selector (image_quantity) multiplies the choice weight by the
 *    entered count;
 *  - weight composes with signed field_addon pricing without touching it.
 */

namespace {
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) { return $value; }
	}
	if ( ! function_exists( 'wc_price' ) ) {
		function wc_price( $amount ): string {
			return '<span class="woocommerce-Price-amount amount">$' . number_format( (float) $amount, 2 ) . '</span>';
		}
	}
}

namespace OPF\Tests\Unit {

	use OPF\Engine\Calculator;
	use OPF\Engine\FieldGroup;
	use OPF\Engine\WapfMapper;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class CommerceWeightTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['opf_test_options'] = [];
		}

		// --- FieldGroup schema boundary ----------------------------------------

		public function test_field_level_weight_survives_normalization(): void {
			// WAPF native storage shape: options.weight.
			$wapf = FieldGroup::normalize_field( [
				'id' => 'numw', 'type' => 'number', 'label' => 'Units',
				'options' => [ 'weight' => '[x]' ],
			] );
			$this->assertSame( '[x]', $wapf['weight'] );

			// Manually-constructed fields may pass the normalized key directly.
			$direct = FieldGroup::normalize_field( [ 'id' => 'w', 'type' => 'text', 'weight' => '0.25' ] );
			$this->assertSame( '0.25', $direct['weight'] );

			// Top-level wins over options.weight like every other schema key.
			$both = FieldGroup::normalize_field( [ 'id' => 'w', 'type' => 'text', 'weight' => '1', 'options' => [ 'weight' => '2' ] ] );
			$this->assertSame( '1', $both['weight'] );
		}

		public function test_field_weight_expressions_kept_verbatim(): void {
			foreach ( [ '[qty]', '[x]', '-10', '[x]*0.5', ' 0.5 ' ] as $expr ) {
				$field = FieldGroup::normalize_field( [ 'id' => 'w', 'type' => 'text', 'weight' => $expr ] );
				$this->assertSame( trim( $expr ), $field['weight'], 'expression ' . $expr );
			}
		}

		public function test_empty_and_nonscalar_weights_are_dropped(): void {
			foreach ( [ '', '   ', null, false, [ 'x' ], new \stdClass() ] as $bad ) {
				$field = FieldGroup::normalize_field( [ 'id' => 'w', 'type' => 'text', 'weight' => $bad, 'options' => [ 'weight' => $bad ] ] );
				$this->assertArrayNotHasKey( 'weight', $field );
			}
		}

		public function test_choice_level_weight_survives_normalization(): void {
			$field = FieldGroup::normalize_field( [
				'id' => 'selw', 'type' => 'select', 'label' => 'Packaging',
				'choices' => [
					[ 'slug' => 'light', 'label' => 'Light', 'options' => [ 'weight' => '0.5' ] ],
					[ 'slug' => 'heavy', 'label' => 'Heavy', 'options' => [ 'weight' => '[qty]' ] ],
					[ 'slug' => 'plain', 'label' => 'Plain' ],
				],
			] );
			$this->assertSame( '0.5', $field['choices'][0]['weight'] );
			$this->assertSame( '[qty]', $field['choices'][1]['weight'] );
			$this->assertArrayNotHasKey( 'weight', $field['choices'][2] );
		}

		public function test_weight_round_trips_through_full_group_normalization(): void {
			$group = new FieldGroup( [
				'fields' => [
					[
						'id' => 'numw', 'type' => 'number', 'label' => 'Units',
						'options' => [ 'weight' => '[x]' ],
					],
					[
						'id' => 'selw', 'type' => 'select', 'label' => 'Packaging',
						'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'options' => [ 'weight' => '0.5' ] ] ],
					],
				],
			] );
			$this->assertSame( '[x]', $group->data['fields'][0]['weight'] );
			$this->assertSame( '0.5', $group->data['fields'][1]['choices'][0]['weight'] );
		}

		// --- WapfMapper import boundary -----------------------------------------

		public function test_mapper_preserves_field_and_choice_weight_verbatim(): void {
			$mapped = WapfMapper::map( [
				'fields' => [
					[
						'id' => 'selw', 'label' => 'Packaging', 'type' => 'select',
						'options' => [
							'choices' => [
								[ 'slug' => 'light', 'label' => 'Light', 'options' => [ 'weight' => '0.5' ] ],
								[ 'slug' => 'heavy', 'label' => 'Heavy', 'options' => [ 'weight' => '[qty]' ] ],
								[ 'slug' => 'neg', 'label' => 'Negative', 'options' => [ 'weight' => '-10' ] ],
							],
						],
						'clone' => [ 'enabled' => false ],
						'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
					],
					[
						'id' => 'numw', 'label' => 'Units of cable', 'type' => 'number',
						'options' => [ 'weight' => '[x]' ],
						'clone' => [ 'enabled' => false ],
						'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
					],
				],
			] );

			$fields = $mapped['group']['fields'];
			$sel    = $fields[0];
			$num    = $fields[1];
			$this->assertSame( '0.5', $sel['choices'][0]['weight'] );
			$this->assertSame( '[qty]', $sel['choices'][1]['weight'] );
			$this->assertSame( '-10', $sel['choices'][2]['weight'] );
			$this->assertSame( '[x]', $num['weight'] );

			// The stale "weight not preserved" review note is gone.
			foreach ( $mapped['notes'] as $note ) {
				$this->assertStringNotContainsString( 'weight', $note );
			}
		}

		public function test_mapper_qty_selector_choice_weight_kept_for_multiply(): void {
			$mapped = WapfMapper::map( [
				'fields' => [ [
					'id' => 'iq', 'label' => 'Packs', 'type' => 'image-swatch-qty',
					'options' => [
						'choices' => [
							[ 'slug' => 'oak', 'label' => 'Oak', 'options' => [ 'weight' => '0.25', 'min' => 0, 'max' => 9 ] ],
						],
					],
					'clone' => [ 'enabled' => false ],
					'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
				] ],
			] );
			$field = $mapped['group']['fields'][0];
			$this->assertSame( 'image_quantity', $field['type'] );
			$this->assertSame( '0.25', $field['choices'][0]['weight'] );
			foreach ( $mapped['notes'] as $note ) {
				$this->assertStringNotContainsString( 'weight', $note );
			}
		}

		// --- Calculator::field_weight --------------------------------------------

		public function test_choice_weight_scalar_qty_and_label_x(): void {
			$field = [
				'id' => 'selw', 'type' => 'select',
				'choices' => [
					[ 'slug' => 'light', 'label' => 'Light', 'disabled' => false, 'weight' => '0.5' ],
					[ 'slug' => 'heavy', 'label' => 'Heavy', 'disabled' => false, 'weight' => '[qty]' ],
					[ 'slug' => 'bylabel', 'label' => '4', 'disabled' => false, 'weight' => '[x]' ],
				],
			];
			$this->assertSame( 0.5, Calculator::field_weight( $field, 'light', 3 ) );
			$this->assertSame( 3.0, Calculator::field_weight( $field, 'heavy', 3 ) );
			// WAPF substitutes [x] with the selected choice's LABEL for slugged values.
			$this->assertSame( 4.0, Calculator::field_weight( $field, 'bylabel', 3 ) );
		}

		public function test_field_level_weight_scalar_x_and_empty(): void {
			$field = [ 'id' => 'numw', 'type' => 'number', 'weight' => '[x]' ];
			$this->assertSame( 4.0, Calculator::field_weight( $field, '4', 1 ) );
			$this->assertSame( 4.5, Calculator::field_weight( $field, '4.5', 2 ) );
			// WAPF skips fields whose raw submission is empty.
			$this->assertSame( 0.0, Calculator::field_weight( $field, '', 1 ) );
			$this->assertSame( 0.0, Calculator::field_weight( $field, null, 1 ) );
			// Non-numeric values floatval to 0.
			$this->assertSame( 0.0, Calculator::field_weight( $field, 'abc', 1 ) );
		}

		public function test_weight_is_evaluated_as_a_simple_formula(): void {
			// WAPF 3.2: the weight setting may hold a simple formula. [x]*0.5 at
			// x=4 is 2, not the 3.1.5 floatval() result of 4.
			$field = [ 'id' => 'w', 'type' => 'number', 'weight' => '[x]*0.5' ];
			$this->assertSame( 2.0, Calculator::field_weight( $field, '4', 1 ) );
			$plus = [ 'id' => 'w', 'type' => 'number', 'weight' => '[x]+1' ];
			$this->assertSame( 5.0, Calculator::field_weight( $plus, '4', 1 ) );
		}

		public function test_qty_token_is_evaluated_arithmetically(): void {
			$field = [ 'id' => 'w', 'type' => 'number', 'weight' => '[qty] * 0.25' ];
			$this->assertSame( 1.0, Calculator::field_weight( $field, '4', 4 ) );
			// A bare [qty] keeps its 3.1.5 result.
			$bare = [ 'id' => 'w', 'type' => 'number', 'weight' => '[qty]' ];
			$this->assertSame( 3.0, Calculator::field_weight( $bare, '4', 3 ) );
		}

		public function test_numeric_field_reference_is_resolved(): void {
			$field = [ 'id' => 'w', 'type' => 'number', 'weight' => '[field.extra] * 2' ];
			$this->assertSame( 6.0, Calculator::field_weight( $field, '4', 1, [ 'extra' => '3' ] ) );
			// A non-numeric referenced value contributes 0 without failing the
			// rest of the arithmetic (pricing-formula retry semantics).
			$mixed = [ 'id' => 'w', 'type' => 'number', 'weight' => '[field.extra] * 2 + 1' ];
			$this->assertSame( 1.0, Calculator::field_weight( $mixed, '4', 1, [ 'extra' => 'abc' ] ) );
			// Without the referenced value the field contributes nothing.
			$this->assertSame( 0.0, Calculator::field_weight( $field, '4', 1 ) );
		}

		public function test_builder_weight_formula_key_is_evaluated(): void {
			// The builder has no separate `weight` input: it writes `weight_formula`.
			// That key must never be inert.
			$field = [ 'id' => 'w', 'type' => 'number', 'weight_formula' => '[field.extra] * 2' ];
			$this->assertSame( 6.0, Calculator::field_weight( $field, '3', 1, [ 'extra' => '3' ] ) );
			$scalar = [ 'id' => 'w', 'type' => 'number', 'weight_formula' => '[x] * 1' ];
			$this->assertSame( 1.75, Calculator::field_weight( $scalar, '1.75', 1 ) );
		}

		public function test_weight_key_wins_over_weight_formula_alias(): void {
			$field = [ 'id' => 'w', 'type' => 'number', 'weight' => '0.5', 'weight_formula' => '[x] * 9' ];
			$this->assertSame( 0.5, Calculator::field_weight( $field, '4', 1 ) );
		}

		public function test_invalid_formula_fails_closed_to_zero(): void {
			foreach ( [ '[x] *', '[x] * * 2', '[unknown_function(2)', '([x]' ] as $broken ) {
				$field = [ 'id' => 'w', 'type' => 'number', 'weight' => $broken ];
				$this->assertSame( 0.0, Calculator::field_weight( $field, '4', 1 ), 'expression ' . $broken );
			}
		}

		public function test_checkbox_weights_sum_per_selected_choice(): void {
			$field = [
				'id' => 'opts', 'type' => 'checkbox',
				'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'disabled' => false, 'weight' => '0.5' ],
					[ 'slug' => 'b', 'label' => 'B', 'disabled' => false, 'weight' => '0.25' ],
					[ 'slug' => 'c', 'label' => 'C', 'disabled' => false, 'weight' => '9' ],
				],
			];
			$this->assertSame( 0.75, Calculator::field_weight( $field, [ 'a', 'b' ], 1 ) );
			// Disabled choices contribute nothing even when submitted.
			$field['choices'][2]['disabled'] = true;
			$this->assertSame( 0.75, Calculator::field_weight( $field, [ 'a', 'b', 'c' ], 1 ) );
		}

		public function test_image_quantity_multiplies_choice_weight_by_count(): void {
			$field = [
				'id' => 'iq', 'type' => 'image_quantity',
				'choices' => [
					[ 'slug' => 'oak', 'label' => 'Oak', 'disabled' => false, 'weight' => '0.5' ],
					[ 'slug' => 'ash', 'label' => 'Ash', 'disabled' => false, 'weight' => '1' ],
				],
			];
			$value = [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 3, 'ash' => 2 ] ];
			// WAPF qty_selector: floatval(weight) * intval(entered count).
			$this->assertSame( ( 0.5 * 3 ) + ( 1.0 * 2 ), Calculator::field_weight( $field, $value, 1 ) );
			// A count of zero contributes nothing.
			$this->assertSame( 0.0, Calculator::field_weight( $field, [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 0, 'ash' => 0 ] ], 1 ) );
		}

		public function test_weight_composes_with_signed_field_addon_pricing(): void {
			$field = [
				'id' => 'selw', 'type' => 'select',
				'choices' => [
					[ 'slug' => 'light', 'label' => 'Light', 'disabled' => false, 'weight' => '0.5',
						'pricing' => [ 'type' => 'fixed', 'amount' => -5.0, 'per_unit' => true ] ],
				],
			];
			// Signed addon is untouched by the weight path.
			$this->assertSame( -5.0, Calculator::field_addon( $field, 'light', [ 'price' => 10, 'qty' => 2 ] ) );
			$this->assertSame( 0.5, Calculator::field_weight( $field, 'light', 2 ) );
		}

		public function test_negative_and_zero_weight_expressions(): void {
			$field = [
				'id' => 's', 'type' => 'select',
				'choices' => [
					[ 'slug' => 'neg', 'label' => 'N', 'disabled' => false, 'weight' => '-10' ],
					[ 'slug' => 'zero', 'label' => 'Z', 'disabled' => false, 'weight' => '0' ],
				],
			];
			$this->assertSame( -10.0, Calculator::field_weight( $field, 'neg', 1 ) );
			$this->assertSame( 0.0, Calculator::field_weight( $field, 'zero', 1 ) );
		}

		public function test_fields_without_weight_contribute_nothing(): void {
			$field = [ 'id' => 't', 'type' => 'text' ];
			$this->assertSame( 0.0, Calculator::field_weight( $field, 'abc', 1 ) );
			$choice_field = [
				'id' => 's', 'type' => 'radio',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'disabled' => false ] ],
			];
			$this->assertSame( 0.0, Calculator::field_weight( $choice_field, 'a', 1 ) );
			// Static/content types never carry a submission.
			$static = [ 'id' => 'p', 'type' => 'paragraph', 'weight' => '5' ];
			$this->assertSame( 0.0, Calculator::field_weight( $static, 'x', 1 ) );
		}

		public function test_toggle_weight_only_when_checked(): void {
			$field = [ 'id' => 'tg', 'type' => 'toggle', 'weight' => '0.75' ];
			$this->assertSame( 0.0, Calculator::field_weight( $field, '0', 1 ) );
			$this->assertSame( 0.75, Calculator::field_weight( $field, '1', 1 ) );
		}

		public function test_upload_array_weight_applied_once_like_wapf(): void {
			// WAPF joins file names into one slug-less cart value, so a constant
			// field weight applies exactly once for the whole field.
			$field = [ 'id' => 'files', 'type' => 'upload', 'weight' => '0.5' ];
			$this->assertSame( 0.5, Calculator::field_weight( $field, [ 'a.pdf', 'b.pdf', 'c.pdf' ], 1 ) );
			// [x] binds to the comma-joined raw, which floatvals to 0.
			$expr = [ 'id' => 'files2', 'type' => 'upload', 'weight' => '[x]' ];
			$this->assertSame( 0.0, Calculator::field_weight( $expr, [ 'a.pdf', 'b.pdf' ], 1 ) );
			// An empty upload contributes nothing.
			$this->assertSame( 0.0, Calculator::field_weight( $field, [], 1 ) );
		}

		public function test_repeat_instances_sum_each_row(): void {
			$field = [
				'id' => 'numw', 'type' => 'number', 'weight' => '[x]',
				'repeat' => [ 'enabled' => true, 'mode' => 'button' ],
			];
			$this->assertSame( 3.0, Calculator::field_weight( $field, [ '1', '2' ], 1 ) );
		}

		// --- Renderer hint contract (no WC stubs required) ------------------------

		public function test_percent_hint_stays_percent_derived_and_signed_fixed_untaxed_without_product(): void {
			// Percent hints stay a percent-derived figure — never tax-converted
			// (WAPF adjust_addon_price early-returns percent types).
			$hint = Renderer::pricing_hint_html( [ 'type' => 'percent', 'amount' => 50 ], 10.0 );
			$this->assertMatchesRegularExpression( '/\+\s*<[^>]+>\$5(\.\d+)?/', $hint );

			// Without a product there is no tax context: amounts render verbatim,
			// preserving the sign contract.
			$fixed = Renderer::pricing_hint_html( [ 'type' => 'fixed', 'amount' => -10 ], 100.0 );
			$this->assertStringContainsString( '-', $fixed );
			$this->assertMatchesRegularExpression( '/\$10(\.\d+)?/', $fixed );
		}
	}
}
