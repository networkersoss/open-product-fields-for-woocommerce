<?php
namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class OrderAgainValidationTest extends TestCase {
	protected function setUp(): void {
		eval( 'class WC_Product { public $id; public function __construct($id) { $this->id = $id; } public function get_price($context = "view") { return $this->id === 22 ? "30" : "10"; } }
		class WP_REST_Request { public function get_param($key) { return $GLOBALS["opf_submitted"]; } }
		function wc_get_product($id) { $GLOBALS["opf_product_id"] = $id; return $id === 99 ? false : new WC_Product($id); }
		function apply_filters($name, $value, ...$args) { return $value; }
		function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS["opf_hooks"][$name] = $args; }
		function add_action($name, $callback, $priority = 10, $args = 1) {}
		function wc_add_notice($message, $type) { $GLOBALS["opf_notices"][] = $message; }
		function wp_unslash($value) { return $value; }' );
		eval( 'namespace OPF\\Service;
		class Renderer { public static function visible_to_viewer() { return true; } }
		class FieldGroups { public static function for_product($product) { return $GLOBALS["opf_groups"]; } }' );
		$GLOBALS['opf_groups'] = [ [ 'id' => 'g', 'group' => new FieldGroup( [ 'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'matte', 'label' => 'Matte' ], [ 'slug' => 'retired', 'label' => 'Retired', 'disabled' => true ] ] ],
			[ 'id' => 'style', 'label' => 'Style', 'type' => 'radio', 'required' => true, 'choices' => [ [ 'slug' => 'gloss', 'label' => 'Gloss' ] ] ],
		] ] ) ] ];
		$GLOBALS['opf_notices'] = [];
		$_POST = [];
	}

	private function values(): array {
		return [ 'g' => [ 'finish' => 'matte', 'style' => 'gloss' ] ];
	}

	public function test_registers_six_validation_arguments_and_variation_attachment(): void {
		CartIntegration::init();
		$this->assertSame( 6, $GLOBALS['opf_hooks']['woocommerce_add_to_cart_validation'] );
		$this->assertSame( 3, $GLOBALS['opf_hooks']['woocommerce_add_cart_item_data'] );
	}

	public function test_sixth_argument_restores_required_select_and_radio_without_post(): void {
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 11, 2, 0, [], [ 'opf_fields' => $this->values() ] ) );
		$this->assertSame( [], $GLOBALS['opf_notices'] );
	}

	public function test_variation_product_is_used_for_restored_validation_and_attachment(): void {
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 11, 2, 22, [], [ 'opf_fields' => $this->values() ] ) );
		$this->assertSame( 22, $GLOBALS['opf_product_id'] );
		$data = CartIntegration::attach( [ 'opf_fields' => $this->values(), 'opf_base_price' => 999, 'other' => 'kept' ], 11, 22 );
		$this->assertSame( 22, $GLOBALS['opf_product_id'] );
		$this->assertSame( $this->values(), $data['opf_fields'] );
		$this->assertSame( 30.0, $data['opf_base_price'] );
		$this->assertSame( 'kept', $data['other'] );
	}

	public function test_restored_missing_required_field_still_fails(): void {
		$this->assertFalse( CartIntegration::validate_add_to_cart( true, 11, 1, 0, [], [ 'opf_fields' => [ 'g' => [ 'finish' => 'matte' ] ] ] ) );
		$this->assertSame( [ '"Style" is a required field.' ], $GLOBALS['opf_notices'] );
	}

	public function test_removed_and_disabled_restored_choices_are_rejected(): void {
		foreach ( [ 'removed', 'retired' ] as $choice ) {
			$values = $this->values();
			$values['g']['finish'] = $choice;
			$this->assertFalse( CartIntegration::validate_add_to_cart( true, 11, 1, 0, [], [ 'opf_fields' => $values ] ) );
		}
	}

	public function test_restored_attachment_removes_obsolete_fields_and_uses_current_price(): void {
		$values = $this->values();
		$values['g']['removed'] = 'stale';
		$data = CartIntegration::attach( [ 'opf_fields' => $values, 'opf_base_price' => 999 ], 11 );
		$this->assertSame( $this->values(), $data['opf_fields'] );
		$this->assertSame( 10.0, $data['opf_base_price'] );
		$GLOBALS['opf_groups'] = [];
		$this->assertSame( [ 'other' => 'kept' ], CartIntegration::attach( [ 'opf_fields' => $values, 'opf_base_price' => 999, 'other' => 'kept' ], 11 ) );
	}

	public function test_structured_image_quantities_are_preserved_and_current_limits_checked(): void {
		$GLOBALS['opf_groups'][0]['group'] = new FieldGroup( [ 'fields' => [
			[ 'id' => 'prints', 'type' => 'image_quantity', 'label' => 'Prints', 'min_choices' => 2, 'max_choices' => 3, 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 0, 'max' => 3 ] ] ] ],
		] ] );
		$values = [ 'g' => [ 'prints' => [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 2 ], 'invalid' => [] ] ] ];
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 11, 1, 0, [], [ 'opf_fields' => $values ] ) );
		$this->assertSame( $values, CartIntegration::attach( [ 'opf_fields' => $values ], 11 )['opf_fields'] );
		$values['g']['prints']['quantities']['oak'] = 4;
		$this->assertFalse( CartIntegration::validate_add_to_cart( true, 11, 1, 0, [], [ 'opf_fields' => $values ] ) );
	}

	public function test_fresh_classic_validation_and_attachment_are_preserved(): void {
		$_POST['opf'] = $this->values();
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 11, 1 ) );
		$this->assertSame( $this->values(), CartIntegration::attach( [], 11 )['opf_fields'] );
		$_POST['opf']['g']['style'] = 'unknown';
		$this->assertFalse( CartIntegration::validate_add_to_cart( true, 11, 1 ) );
	}

	public function test_fresh_store_payload_takes_priority_and_is_consumed(): void {
		$GLOBALS['opf_submitted'] = $this->values();
		$data = CartIntegration::capture_store_api( [], new \WP_REST_Request() );
		$data = CartIntegration::attach( $data['cart_item_data'], 11 );
		$this->assertArrayNotHasKey( 'opf_fields_raw', $data );
		$this->assertSame( $this->values(), $data['opf_fields'] );
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 11, 1, 0, [], [ 'opf_fields' => [] ] ) );
		$this->assertFalse( CartIntegration::validate_add_to_cart( true, 11, 1 ) );
	}

	public function test_previous_failure_and_missing_products_preserve_hook_contract(): void {
		$this->assertFalse( CartIntegration::validate_add_to_cart( false, 11, 1, 0, [], [ 'opf_fields' => $this->values() ] ) );
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 99, 1 ) );
		$this->assertSame( [ 'other' => 'kept' ], CartIntegration::attach( [ 'other' => 'kept' ], 99 ) );
	}
}
