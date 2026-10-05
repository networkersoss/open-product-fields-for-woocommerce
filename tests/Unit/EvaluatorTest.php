<?php
/**
 * Evaluator unit tests — field conditionals and placement rules.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Evaluator;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase {

	protected function tearDown(): void {
		Evaluator::set_context_product( null );
	}

	private function field( array $conditionals ): array {
		return [ 'id' => 'f', 'type' => 'text', 'conditionals' => $conditionals ];
	}

	public function test_no_conditionals_is_visible(): void {
		$this->assertTrue( Evaluator::is_visible( $this->field( [] ), [] ) );
	}

	public function test_show_conditional_logic_all(): void {
		$field = $this->field(
			[ [ 'action' => 'show', 'logic' => 'all', 'rules' => [
				[ 'field' => 'a', 'operator' => 'is', 'value' => 'yes' ],
				[ 'field' => 'b', 'operator' => 'is', 'value' => 'yes' ],
			] ] ]
		);
		$this->assertFalse( Evaluator::is_visible( $field, [ 'a' => 'yes', 'b' => 'no' ] ) );
		$this->assertTrue( Evaluator::is_visible( $field, [ 'a' => 'yes', 'b' => 'yes' ] ) );
	}

	public function test_show_conditional_logic_any(): void {
		$field = $this->field(
			[ [ 'action' => 'show', 'logic' => 'any', 'rules' => [
				[ 'field' => 'a', 'operator' => 'is', 'value' => 'yes' ],
				[ 'field' => 'b', 'operator' => 'is', 'value' => 'yes' ],
			] ] ]
		);
		$this->assertTrue( Evaluator::is_visible( $field, [ 'a' => 'yes', 'b' => 'no' ] ) );
		$this->assertFalse( Evaluator::is_visible( $field, [ 'a' => 'no', 'b' => 'no' ] ) );
	}

	public function test_hide_conditional_wins(): void {
		$field = $this->field(
			[ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [
				[ 'field' => 'a', 'operator' => 'is', 'value' => 'hide_me' ],
			] ] ]
		);
		$this->assertFalse( Evaluator::is_visible( $field, [ 'a' => 'hide_me' ] ) );
		$this->assertTrue( Evaluator::is_visible( $field, [ 'a' => 'keep' ] ) );
	}

	public function test_operators(): void {
		$check = fn ( string $operator, $value, string $expect = '5' ) => Evaluator::rule_passes(
			[ 'field' => 'f', 'operator' => $operator, 'value' => $expect ],
			$value
		);
		$this->assertTrue( $check( 'is', '5' ) );
		$this->assertTrue( $check( 'is', [ '4', '5' ] ) );
		$this->assertTrue( $check( 'is_not', '6' ) );
		$this->assertTrue( $check( 'contains', 'hello 5 world', '5' ) );
		$this->assertTrue( $check( 'not_contains', 'hello world', '5' ) );
		$this->assertFalse( $check( 'not_contains', 'hello 5 world', '5' ) );
		$this->assertTrue( $check( 'greater', '7' ) );
		$this->assertFalse( $check( 'greater', '3' ) );
		$this->assertTrue( $check( 'less', '3' ) );
		$this->assertTrue( $check( 'empty', '' ) );
		$this->assertTrue( $check( 'not_empty', 'x' ) );
		$this->assertTrue( $check( 'not_empty', [ 'a', 'b' ] ) );
	}

	public function test_placement_empty_rules_match_everything(): void {
		$this->assertTrue( Evaluator::group_matches( [ 'rule_groups' => [] ], [], 42 ) );
	}

	public function test_placement_product_in(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '42' ] ] ] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 43 ) );
	}

	/**
	 * `product` placement on a variation: 3.1.5 parent membership
	 * (class-conditions.php:278-287 `is_current_product` resolves a variation to
	 * its parent id) plus the variation's own id, which is what WAPF Free
	 * compares and what an imported rule selecting one variation needs.
	 */
	public function test_placement_product_targets_a_variation_through_its_parent(): void {
		Evaluator::set_context_product( $this->variation( 7002, 7001 ) );
		$this->assertTrue( Evaluator::group_matches( $this->product_rule( 'in', [ '7001' ] ), [], 7001 ) );
		$this->assertFalse( Evaluator::group_matches( $this->product_rule( 'not_in', [ '7001' ] ), [], 7001 ) );
	}

	public function test_placement_product_matches_an_explicit_variation_id(): void {
		Evaluator::set_context_product( $this->variation( 7002, 7001 ) );
		$this->assertTrue( Evaluator::group_matches( $this->product_rule( 'in', [ '7002' ] ), [], 7001 ) );
		$this->assertFalse( Evaluator::group_matches( $this->product_rule( 'not_in', [ '7002' ] ), [], 7001 ) );
		// A sibling variation of the same parent is a different target.
		$this->assertFalse( Evaluator::group_matches( $this->product_rule( 'in', [ '7003' ] ), [], 7001 ) );
	}

	public function test_placement_product_lists_parent_and_variation_ids(): void {
		$group = $this->product_rule( 'in', [ '7001', '7002' ] );

		Evaluator::set_context_product( $this->variation( 7002, 7001 ) );
		$this->assertTrue( Evaluator::group_matches( $group, [], 7001 ) );
		// The parent id keeps 3.1.5 parent membership, so unlisted sibling
		// variations of a listed parent still match.
		Evaluator::set_context_product( $this->variation( 7003, 7001 ) );
		$this->assertTrue( Evaluator::group_matches( $group, [], 7001 ) );
		// A variation from an unlisted parent still does not.
		Evaluator::set_context_product( $this->variation( 7102, 7100 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 7100 ) );
	}

	public function test_placement_product_on_simple_and_variable_products(): void {
		// Simple product: its own id only.
		$this->assertTrue( Evaluator::group_matches( $this->product_rule( 'in', [ '7200' ] ), [], 7200 ) );
		$this->assertFalse( Evaluator::group_matches( $this->product_rule( 'in', [ '7002' ] ), [], 7200 ) );
		// Variable parent: its own id, never a child variation id — per-variation
		// matching stays with the `product_var` subject (class-conditions.php:255-274).
		Evaluator::set_context_product( $this->variable( 7001, [ 7002, 7003 ] ) );
		$this->assertTrue( Evaluator::group_matches( $this->product_rule( 'in', [ '7001' ] ), [], 7001 ) );
		$this->assertFalse( Evaluator::group_matches( $this->product_rule( 'in', [ '7002' ] ), [], 7001 ) );
	}

	public function test_placement_product_ignored_explicit_variation_ids_before_the_fix(): void {
		// Pre-fix predicate, kept verbatim as the comparison the fix replaced:
		// every `product` rule resolved the variation to its parent id.
		$pre_fix_match = in_array( (string) 7001, [ '7002' ], true );
		$this->assertFalse( $pre_fix_match, 'the pre-fix parent-only predicate never matched the selected variation' );

		Evaluator::set_context_product( $this->variation( 7002, 7001 ) );
		$this->assertTrue( Evaluator::group_matches( $this->product_rule( 'in', [ '7002' ] ), [], 7001 ) );
	}

	private function product_rule( string $operator, array $terms ): array {
		return [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => $operator, 'terms' => $terms ] ] ] ] ];
	}

	private function variation( int $id, int $parent_id ): object {
		return new class( $id, $parent_id ) {
			/** @var int */
			private $id;

			/** @var int */
			private $parent_id;

			public function __construct( int $id, int $parent_id ) {
				$this->id        = $id;
				$this->parent_id = $parent_id;
			}
			public function get_type(): string {
				return 'variation';
			}
			public function is_type( string $type ): bool {
				return 'variation' === $type;
			}
			public function get_id(): int {
				return $this->id;
			}
			public function get_parent_id(): int {
				return $this->parent_id;
			}
		};
	}

	private function variable( int $id, array $children ): object {
		return new class( $id, $children ) {
			/** @var int */
			private $id;

			/** @var int[] */
			private $children;

			public function __construct( int $id, array $children ) {
				$this->id       = $id;
				$this->children = $children;
			}
			public function get_type(): string {
				return 'variable';
			}
			public function is_type( string $type ): bool {
				return 'variable' === $type;
			}
			public function get_id(): int {
				return $this->id;
			}
			public function get_parent_id(): int {
				return 0;
			}
			public function get_children(): array {
				return $this->children;
			}
		};
	}

	public function test_placement_tag_not_in(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_tag', 'operator' => 'not_in', 'terms' => [ '9' ] ] ] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_tag' => [ 1, 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_tag' => [ 9 ] ], 42 ) );
	}

	public function test_placement_rules_within_group_are_and(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ '1' ] ],
			[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '2' ] ],
		] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_cat' => [ 1 ], 'product_tag' => [ 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_cat' => [ 1 ], 'product_tag' => [ 3 ] ], 42 ) );
	}

	public function test_placement_product_type_uses_exact_terms_and_exclusions(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'variable' ] ] ] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_type' => [ 'variable' ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_type' => [ 'simple' ] ], 42 ) );
		$excluded = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'not_in', 'terms' => [ 'variable' ] ] ] ] ] ];
		$this->assertFalse( Evaluator::group_matches( $excluded, [ 'product_type' => [ 'variable' ] ], 42 ) );
	}

	public function test_placement_variation_subject_matches_the_exact_variation_id(): void {
		// Master dialect: WAPF's variation subject is `product_var` and term
		// ids live under the `product_var` has_terms key (WAPF 3.1.5 parity —
		// the group only renders while a scoped variation is relevant).
		$group = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '99' ] ] ] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_var' => [ '99' ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_var' => [ '100' ] ], 42 ) );
	}

	public function test_placement_matches_product_attribute_terms_and_exclusions(): void {
		// Master dialect: attribute placement uses per-taxonomy `pa_*`
		// subjects keyed to plain term ids rather than the legacy
		// `product_attribute` subject with `pa_x:id` composite keys.
		$has_terms = [ 'pa_color' => [ 8 ], 'pa_material' => [ 3 ] ];
		$included  = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'pa_color', 'operator' => 'in', 'terms' => [ '8' ] ] ] ] ] ];
		$excluded  = [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'pa_color', 'operator' => 'not_in', 'terms' => [ '9' ] ] ] ] ] ];

		$this->assertTrue( Evaluator::group_matches( $included, $has_terms, 42 ) );
		$this->assertTrue( Evaluator::group_matches( $excluded, $has_terms, 42 ) );
	}

	public function test_placement_rule_groups_are_or(): void {
		$group = [ 'rule_groups' => [
			[ 'rules' => [ [ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ '1' ] ] ] ],
			[ 'rules' => [ [ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '2' ] ] ] ],
		] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_cat' => [ 9 ], 'product_tag' => [ 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_cat' => [ 9 ], 'product_tag' => [ 3 ] ], 42 ) );
	}

	public function test_placement_product_type_and_attribute_subjects(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'simple' ] ],
			[ 'subject' => 'pa_color', 'operator' => 'in', 'terms' => [ '7' ] ],
		] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_type' => [ 'simple' ], 'pa_color' => [ 7, 9 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_type' => [ 'variable' ], 'pa_color' => [ 7, 9 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_type' => [ 'simple' ], 'pa_color' => [ 8 ] ], 42 ) );
		$excluded = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'pa_size', 'operator' => 'not_in', 'terms' => [ '3' ] ],
			[ 'subject' => 'product_type', 'operator' => 'not_in', 'terms' => [ 'external' ] ],
		] ] ] ];
		$this->assertTrue( Evaluator::group_matches( $excluded, [ 'product_type' => [ 'simple' ], 'pa_size' => [ 1, 2 ] ], 42 ) );
		$this->assertFalse( Evaluator::group_matches( $excluded, [ 'product_type' => [ 'simple' ], 'pa_size' => [ 3 ] ], 42 ) );
	}

	public function test_user_auth_role_and_language_placement_rules(): void {
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'user_auth', 'operator' => 'in', 'terms' => [ 'logged_in' ] ],
			[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'wholesale' ] ],
			[ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'suspended' ] ],
			[ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'nl_NL' ] ],
		] ] ] ];
		$buyer = [ 'logged_in' => true, 'roles' => [ 'customer', 'wholesale' ], 'language' => 'nl_NL' ];

		$this->assertTrue( Evaluator::group_matches( $group, [], 42, $buyer ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 42, [ 'logged_in' => false, 'roles' => [], 'language' => 'nl_NL' ] ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 42, [ 'logged_in' => true, 'roles' => [ 'customer' ], 'language' => 'nl_NL' ] ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 42, [ 'logged_in' => true, 'roles' => [ 'wholesale', 'suspended' ], 'language' => 'nl_NL' ] ) );
		$this->assertFalse( Evaluator::group_matches( $group, [], 42, [ 'logged_in' => true, 'roles' => [ 'wholesale' ], 'language' => 'en_US' ] ) );
	}
}
