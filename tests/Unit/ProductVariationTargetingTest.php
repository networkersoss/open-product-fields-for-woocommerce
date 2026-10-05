<?php
/**
 * `product` placement rules that name a variation, and the WAPF import/export
 * round trip that has to keep that targeting unchanged.
 *
 * Reference: WAPF Extended 3.1.5 includes/classes/class-conditions.php:278-287
 * (`is_current_product`: parent membership) and :255-274
 * (`is_product_variation`: own id / parent children) for the `product_var`
 * subject, which stays untouched.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class ProductVariationTargetingTest extends TestCase {

	private const PARENT_ID    = '7001';
	private const VARIATION_ID = '7002';
	private const SIBLING_ID   = '7003';

	protected function tearDown(): void {
		Evaluator::set_context_product( null );
		Evaluator::set_variation_context( null );
	}

	public function test_wapf_import_export_round_trip_preserves_parent_and_variation_targets(): void {
		$imported = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $this->wapf_conditions( 'products', [
			[ 'id' => self::PARENT_ID, 'text' => 'Variable parent' ],
			[ 'id' => self::VARIATION_ID, 'text' => 'Blue variation' ],
		] ) ] );
		$this->assertFalse( $imported['needs_review'], implode( "\n", $imported['notes'] ) );
		$group = FieldGroup::normalize( $imported['group'] );
		$this->assertSame( [ self::PARENT_ID, self::VARIATION_ID ], $group['rule_groups'][0]['rules'][0]['terms'] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'product', $payload['conditions'][0]['rules'][0]['subject'] );
		$this->assertSame( 'products', $payload['conditions'][0]['rules'][0]['condition'] );
		$this->assertSame( [ self::PARENT_ID, self::VARIATION_ID ], array_column( $payload['conditions'][0]['rules'][0]['value'], 'id' ) );

		$reimported = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$round_tripped = FieldGroup::normalize( $reimported['group'] );
		$this->assertSame( $group['rule_groups'], $round_tripped['rule_groups'] );

		// Targeting is unchanged end to end: the parent and the named variation
		// still match, an unrelated parent still does not.
		Evaluator::set_context_product( $this->variation( self::VARIATION_ID, self::PARENT_ID ) );
		$this->assertTrue( Evaluator::group_matches( $round_tripped, [], (int) self::PARENT_ID ) );
		Evaluator::set_context_product( null );
		$this->assertTrue( Evaluator::group_matches( $round_tripped, [], (int) self::PARENT_ID ) );
		$this->assertFalse( Evaluator::group_matches( $round_tripped, [], 7100 ) );
	}

	public function test_wapf_import_export_round_trip_preserves_negated_variation_targets(): void {
		$imported = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $this->wapf_conditions( '!products', [
			[ 'id' => self::VARIATION_ID, 'text' => 'Blue variation' ],
		] ) ] );
		$group = FieldGroup::normalize( $imported['group'] );
		$this->assertSame( 'not_in', $group['rule_groups'][0]['rules'][0]['operator'] );
		$this->assertSame( [ self::VARIATION_ID ], $group['rule_groups'][0]['rules'][0]['terms'] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( '!products', $payload['conditions'][0]['rules'][0]['condition'] );
		$this->assertSame( [ self::VARIATION_ID ], array_column( $payload['conditions'][0]['rules'][0]['value'], 'id' ) );

		$reimported = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$round_tripped = FieldGroup::normalize( $reimported['group'] );
		$this->assertSame( $group['rule_groups'], $round_tripped['rule_groups'] );

		Evaluator::set_context_product( $this->variation( self::VARIATION_ID, self::PARENT_ID ) );
		$this->assertFalse( Evaluator::group_matches( $round_tripped, [], (int) self::PARENT_ID ) );
		Evaluator::set_context_product( $this->variation( self::SIBLING_ID, self::PARENT_ID ) );
		$this->assertTrue( Evaluator::group_matches( $round_tripped, [], (int) self::PARENT_ID ) );
	}

	public function test_product_var_subject_keeps_its_variation_semantics(): void {
		// Placement subject: terms are matched against the variation ids in
		// scope (a variation's own id, or a variable parent's children) — the
		// `product` change must not alter this.
		$group = [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ self::VARIATION_ID ] ],
		] ] ] ];
		Evaluator::set_context_product( $this->variation( self::VARIATION_ID, self::PARENT_ID ) );
		$this->assertTrue( Evaluator::group_matches( $group, [ 'product_var' => [ self::VARIATION_ID ] ], (int) self::PARENT_ID ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_var' => [ self::SIBLING_ID ] ], (int) self::PARENT_ID ) );
		$this->assertFalse( Evaluator::group_matches( $group, [ 'product_var' => [] ], (int) self::PARENT_ID ) );

		// Field-level gate: the selected variation id, never the parent id.
		$ctx = [ 'variable' => true, 'id' => (int) self::VARIATION_ID, 'attributes' => [] ];
		$this->assertTrue( Evaluator::variation_rule_passes( [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ self::VARIATION_ID ] ], $ctx ) );
		$this->assertFalse( Evaluator::variation_rule_passes( [ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ self::PARENT_ID ] ], $ctx ) );
	}

	/**
	 * @param array<int,array{id:string,text:string}> $value
	 * @return array<int,array{rules:array<int,array<string,mixed>>}>
	 */
	private function wapf_conditions( string $condition, array $value ): array {
		return [ [ 'rules' => [ [
			'subject'   => 'product',
			'condition' => $condition,
			'value'     => $value,
		] ] ] ];
	}

	private function variation( string $id, string $parent_id ): object {
		return new class( $id, $parent_id ) {
			/** @var string */
			private $id;

			/** @var string */
			private $parent_id;

			public function __construct( string $id, string $parent_id ) {
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
				return (int) $this->id;
			}

			public function get_parent_id(): int {
				return (int) $this->parent_id;
			}
		};
	}
}
