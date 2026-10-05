<?php
namespace OPF\Tests\Unit;

use OPF\Service\CartIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class OrderAgainBasePriceTest extends TestCase {
	protected function setUp(): void {
		eval( 'namespace OPF\\Service; class FieldGroups { public static function for_product($product) {
			return [["id" => "g", "group" => new \\OPF\\Engine\\FieldGroup(["fields" => [["id" => "choice", "type" => "select", "choices" => [["slug" => "available", "label" => "Available"]]]]])]];
		} }' );
		eval( 'class WC_Product { public $price = "10"; public function get_price($context = "view") { return $this->price; } }
		class WC_Order {}
		class WC_Order_Item_Product {
			public function get_meta($key, $single = true) { return "{\\"g\\":{\\"choice\\":\\"available\\"}}"; }
			public function get_quantity() { return 1; }
			public function get_product() { return new WC_Product(); }
		}' );
	}

	public function test_order_again_restores_catalog_base_for_choice_pricing(): void {
		$data = CartIntegration::restore_order_again( [ 'other_plugin' => 'retained' ], new \WC_Order_Item_Product(), new \WC_Order() );
		$this->assertSame( [ 'g' => [ 'choice' => 'available' ] ], $data['opf_fields'] );
		$this->assertSame( 'retained', $data['other_plugin'] );
		$this->assertArrayHasKey( 'opf_base_price', $data );
		$this->assertSame( 10.0, $data['opf_base_price'] );
	}

	public function test_free_catalog_base_is_restored(): void {
		$item = new class extends \WC_Order_Item_Product {
			public function get_product() { $product = new \WC_Product(); $product->price = '0'; return $product; }
		};
		$this->assertSame( 0.0, CartIntegration::restore_order_again( [], $item, new \WC_Order() )['opf_base_price'] );
	}

	public function test_missing_product_still_preserves_selection_values(): void {
		$item = new class extends \WC_Order_Item_Product { public function get_product() { return false; } };
		$this->assertSame( [ 'opf_fields' => [ 'g' => [ 'choice' => 'available' ] ] ], CartIntegration::restore_order_again( [], $item, new \WC_Order() ) );
	}

	public function test_invalid_metadata_preserves_incoming_data(): void {
		$item = new class extends \WC_Order_Item_Product { public function get_meta($key, $single = true) { return '{invalid'; } };
		$this->assertSame( [ 'other_plugin' => 'retained' ], CartIntegration::restore_order_again( [ 'other_plugin' => 'retained' ], $item, new \WC_Order() ) );
	}
}
