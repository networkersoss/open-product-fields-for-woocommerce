<?php
/** Child-product catalog query tests. */

namespace OPF\Tests\Unit;

use OPF\Service\ChildProductCatalog;
use PHPUnit\Framework\TestCase;

final class ChildProductCatalogTest extends TestCase {
	public function test_specific_products_keep_explicit_order_and_are_not_query_expanded(): void {
		$this->assertSame( [
			'include' => [ 22, 11 ],
			'limit' => 2,
			'orderby' => 'include',
			'status' => 'publish',
			'return' => 'objects',
		], ChildProductCatalog::query_args( [ 'product_source' => 'specific', 'product_ids' => [ 22, 11, 0 ] ] ) );
	}

	public function test_category_query_is_bounded_to_fifty_published_products(): void {
		$this->assertSame( [
			'category' => [ 'gift-boxes', 'seasonal' ],
			'limit' => 50,
			'orderby' => 'title',
			'order' => 'ASC',
			'status' => 'publish',
			'return' => 'objects',
		], ChildProductCatalog::query_args( [ 'product_source' => 'categories' ], [ 'gift-boxes', 'seasonal', 'gift-boxes' ] ) );
		$this->assertSame( [], ChildProductCatalog::query_args( [ 'product_source' => 'categories' ] ) );
	}

	public function test_empty_specific_source_returns_no_query(): void {
		$this->assertSame( [], ChildProductCatalog::query_args( [ 'product_source' => 'specific', 'product_ids' => [ 0, -4 ] ] ) );
	}
}
