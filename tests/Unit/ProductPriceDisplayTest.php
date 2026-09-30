<?php

namespace OPF\Tests\Unit;

use OPF\Service\ProductPriceDisplay;
use PHPUnit\Framework\TestCase;

final class ProductPriceDisplayTest extends TestCase {
	public function test_default_price_html_is_unchanged(): void {
		$this->assertSame( '<span class="price">$10</span>', ProductPriceDisplay::format( '<span class="price">$10</span>', 'default', '' ) );
	}

	public function test_hide_mode_removes_base_price_html(): void {
		$this->assertSame( '', ProductPriceDisplay::format( '<span class="price">$10</span>', 'hide', '' ) );
	}

	public function test_replace_mode_shows_only_the_configured_text(): void {
		$this->assertSame( '<span class="opf-product-price-label">Custom quote</span>', ProductPriceDisplay::format( '<span class="price">$10</span>', 'replace', 'Custom quote' ) );
	}

	public function test_label_can_precede_or_follow_price_and_is_escaped(): void {
		$html = '<span class="price">$10</span>';
		$this->assertSame( '<span class="opf-product-price-label">From &lt;</span> <span class="price">$10</span>', ProductPriceDisplay::format( $html, 'before', 'From <' ) );
		$this->assertSame( '<span class="price">$10</span> <span class="opf-product-price-label">each</span>', ProductPriceDisplay::format( $html, 'after', 'each' ) );
	}

	public function test_invalid_mode_and_empty_label_preserve_native_price(): void {
		$html = '<span class="price">$10</span>';
		$this->assertSame( $html, ProductPriceDisplay::format( $html, 'unexpected', 'label' ) );
		$this->assertSame( $html, ProductPriceDisplay::format( $html, 'before', ' ' ) );
		$this->assertSame( $html, ProductPriceDisplay::format( $html, 'replace', '' ) );
	}
}
