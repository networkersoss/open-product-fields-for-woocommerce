<?php
/** Child-product option submission tests. */

namespace OPF\Tests\Unit;

use OPF\Engine\ChildProductSelection;
use PHPUnit\Framework\TestCase;

final class ChildProductSelectionTest extends TestCase {
	public function test_validates_selected_products_against_currently_eligible_ids(): void {
		$field = [ 'label' => 'Bundle items', 'required' => true, 'multiple' => true, 'min_selections' => 1, 'max_selections' => 2 ];
		$this->assertSame( [ 12 => 2 ], ChildProductSelection::quantities( $field, [ '12' ], [ 12, 13 ], 2 )['selection'] );
		$this->assertSame( [ '"Bundle items" contains an unavailable product.' ], ChildProductSelection::quantities( $field, [ '12', '99' ], [ 12, 13 ], 2 )['errors'] );
	}

	public function test_enforces_selection_bounds_and_required_state(): void {
		$field = [ 'label' => 'Bundle items', 'required' => false, 'multiple' => true, 'min_selections' => 2, 'max_selections' => 2 ];
		$this->assertSame( [ '"Bundle items" requires at least 2 selection(s).' ], ChildProductSelection::quantities( $field, [ '12' ], [ 12, 13 ], 1 )['errors'] );
		$this->assertSame( [ '"Bundle items" allows at most 2 selection(s).' ], ChildProductSelection::quantities( $field, [ '12', '13', '14' ], [ 12, 13, 14 ], 1 )['errors'] );
		$this->assertSame( [ '"Bundle items" is a required field.' ], ChildProductSelection::quantities( [ 'label' => 'Bundle items', 'required' => true ], [], [ 12 ], 1 )['errors'] );
	}

	public function test_single_selection_mode_rejects_multiple_product_ids(): void {
		$this->assertSame( [ '"Bundle items" allows one selection.' ], ChildProductSelection::quantities( [ 'label' => 'Bundle items', 'multiple' => false ], [ '12', '13' ], [ 12, 13 ], 1 )['errors'] );
	}

	public function test_child_quantities_follow_parent_mode_or_explicit_independent_quantities(): void {
		$default = [ 'label' => 'Extras', 'required' => false, 'quantity_input' => false, 'quantity_mode' => 'per_parent' ];
		$this->assertSame( [ 12 => 3 ], ChildProductSelection::quantities( $default, [ '12' ], [ 12 ], 3 )['selection'] );

		$input = [ 'label' => 'Extras', 'required' => false, 'quantity_input' => true, 'quantity_mode' => 'multiply_parent' ];
		$this->assertSame( [ 12 => 6 ], ChildProductSelection::quantities( $input, [ '12' => '2' ], [ 12 ], 3 )['selection'] );
		$input['quantity_mode'] = 'per_parent';
		$this->assertSame( [ 12 => 2 ], ChildProductSelection::quantities( $input, [ '12' => '2' ], [ 12 ], 3 )['selection'] );
	}

	public function test_malformed_ids_and_quantities_are_rejected_atomically(): void {
		$field = [ 'label' => 'Extras', 'quantity_input' => true, 'required' => false ];
		$result = ChildProductSelection::quantities( $field, [ '12' => '2.5', '13' => '1' ], [ 12, 13 ], 1 );
		$this->assertSame( [], $result['selection'] );
		$this->assertSame( [ '"Extras" has an invalid product quantity.' ], $result['errors'] );
	}
}
