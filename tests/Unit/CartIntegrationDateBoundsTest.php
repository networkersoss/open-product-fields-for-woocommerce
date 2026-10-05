<?php

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_parent_id(): int { return 0; }
			public function get_id(): int { return (int) ( $GLOBALS['opf_datebounds_product_id'] ?? 42 ); }
			public function get_type(): string { return 'simple'; }
		}
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $_args = [] ): array { return []; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return false; }
	}
	if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
		function wc_get_product_term_ids( $_product_id, $_taxonomy ): array { return []; }
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $_product_id ) { return $GLOBALS['opf_datebounds_product'] ?? null; }
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $_key, $_group = '' ) { return false; }
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $_key, $_data, $_group = '', $_expire = 0 ) { return true; }
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( $_key, $_group = '' ) { return true; }
	}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Field-relative date bounds enforced through the real CartIntegration
 * validation path (not a hand-built FieldValue context): a `[field.x]<period>`
 * minimum must reject the offending submission and name the customer error.
 */
final class CartIntegrationDateBoundsTest extends TestCase {
	protected function setUp(): void {
		FieldGroups::flush_cache();
		unset( $GLOBALS['opf_wpml_filters']['opf_groups_for_product'] );
	}

	protected function tearDown(): void {
		FieldGroups::flush_cache();
		unset(
			$GLOBALS['opf_datebounds_groups'], $GLOBALS['opf_datebounds_product'], $GLOBALS['opf_datebounds_product_id'],
			$GLOBALS['opf_wpml_filters']['opf_groups_for_product']
		);
	}

	private function prime(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'start', 'label' => 'Start date', 'type' => 'date' ],
			[ 'id' => 'end', 'label' => 'End date', 'type' => 'date', 'min_date' => '[field.start]+1d' ],
		] ] );
		$GLOBALS['opf_datebounds_groups'] = [ [ 'id' => 9, 'lang' => '', 'group' => $group ] ];
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = static function () {
			return $GLOBALS['opf_datebounds_groups'];
		};
		$GLOBALS['opf_datebounds_product'] = new \WC_Product();
	}

	private function validate( array $given ): array {
		$method = new ReflectionMethod( CartIntegration::class, 'validate_values' );
		return $method->invoke( null, $GLOBALS['opf_datebounds_product'], [ 9 => $given ], 1 );
	}

	public function test_field_relative_min_bound_rejects_a_violating_submission_through_the_real_path(): void {
		$this->prime();
		$this->assertSame(
			[ '"End date" must be on or after 2026-06-16.' ],
			$this->validate( [ 'start' => '2026-06-15', 'end' => '2026-06-15' ] )
		);
	}

	public function test_field_relative_min_bound_accepts_a_value_inside_the_bound(): void {
		$this->prime();
		$this->assertSame( [], $this->validate( [ 'start' => '2026-06-15', 'end' => '2026-06-16' ] ) );
	}

	public function test_empty_referenced_field_leaves_the_bound_unset_without_erroring(): void {
		$this->prime();
		// A missing referenced sibling leaves the bound unset (WAPF parity).
		$this->assertSame( [], $this->validate( [ 'end' => '2026-06-15' ] ) );
		// An explicitly-empty referenced date is the start field's own error;
		// the end field is not additionally bounded.
		$this->assertSame( [ '"Start date" must be a valid date.' ], $this->validate( [ 'start' => '', 'end' => '2026-06-15' ] ) );
	}
}
}
