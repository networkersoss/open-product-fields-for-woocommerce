<?php

namespace {
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $html ): string { return (string) $html; }
	}
	if ( ! function_exists( 'wp_trim_words' ) ) {
		function wp_trim_words( $text, $num_words = 55, $more = null ): string { return (string) $text; }
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $text ): string { return strip_tags( (string) $text ); }
	}
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
		function wp_get_attachment_image_url( $id, $size ) { return 'https://example.test/full-' . $id . '.jpg'; }
	}
	if ( ! function_exists( 'wc_get_product_attachment_props' ) ) {
		function wc_get_product_attachment_props( $id ) {
			return [
				'src'       => 'https://example.test/gallery-' . $id . '.jpg',
				'full_src'  => 'https://example.test/gallery-' . $id . '-full.jpg',
				'thumb_src' => 'https://example.test/gallery-' . $id . '-thumb.jpg',
				'srcset'    => '',
				'sizes'     => '',
				'alt'       => 'Gallery ' . $id,
				'title'     => 'Gallery ' . $id,
				'caption'   => '',
			];
		}
	}
	if ( ! function_exists( 'esc_attr__' ) ) {
		function esc_attr__( $text, $domain = null ): string { return (string) $text; }
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ): string { return (string) $url; }
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string { return (string) $text; }
	}
	if ( ! function_exists( 'selected' ) ) {
		function selected( $selected, $current = true, $echo = true ): string {
			$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
			if ( $echo ) { echo $result; }
			return $result;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) { return $value; }
	}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Renderer markup for the cards + image-zoom cluster: `data-zoom-url` zoom
 * surfaces for image-quantity/image-swatch choices and the WAPF group-level
 * gallery-image rule payload. Product-card markup is proven in the guarded
 * browser fixture (needs live WC_Product objects).
 */
final class CardsZoomMarkupTest extends TestCase {

	public function test_image_quantity_large_image_emits_zoom_url_and_wrapper(): void {
		$group = new FieldGroup( [ 'fields' => [ [
			'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity', 'large_image' => true,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image_id' => 55, 'quantity' => [ 'default' => 0 ] ] ],
		] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Prints', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'opf-image-quantity__img', $html );
		$this->assertStringContainsString( 'wapf-tt-wrap', $html );
		$this->assertStringContainsString( 'data-zoom-url="https://example.test/full-55.jpg"', $html );
		$this->assertStringContainsString( 'class="opf-swatch-zoom-preview" src="https://example.test/full-55.jpg"', $html );
		$this->assertStringContainsString( 'class="opf-input opf-image-quantity__input', $html );
		$this->assertStringContainsString( 'data-choice-slug="oak"', $html );
	}

	public function test_image_quantity_without_zoom_has_no_zoom_url(): void {
		$group = new FieldGroup( [ 'fields' => [ [
			'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity',
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image_id' => 55, 'quantity' => [ 'default' => 0 ] ] ],
		] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Prints', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-zoom-url', $html );
	}

	public function test_image_swatch_zoom_emits_zoom_url_on_wrapper(): void {
		$group = new FieldGroup( [ 'fields' => [ [
			'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch', 'swatch_style' => 'image',
			'image_zoom' => true,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image_id' => 77 ] ],
		] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Finish', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-zoom-url="https://example.test/full-77.jpg"', $html );
		$this->assertStringContainsString( 'opf-swatch--image-zoom', $html );
	}

	public function test_group_gallery_rules_emit_wapf_and_opf_data_attributes(): void {
		$group = new FieldGroup( [
			'fields' => [
				[ 'id' => 'color', 'label' => 'Color', 'type' => 'select', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ],
			],
			'layout' => [
				'enable_gallery_images' => true,
				'swap_type'             => 'last',
				'gallery_images'        => [ [
					'id'     => 501,
					'source' => 'upload',
					'values' => [ [ 'field' => 'color', 'value' => 'red' ] ],
				] ],
			],
		] );
		ob_start();
		Renderer::render_group( '17', 'Gallery', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-wapf-st="last"', $html );
		$this->assertStringContainsString( 'data-opf-st="last"', $html );
		$this->assertSame( 2, substr_count( $html, 'data-wapf-gi=' ) + substr_count( $html, 'data-opf-gi=' ) );

		preg_match( '/data-wapf-gi="([^"]+)"/', $html, $match );
		$payload = json_decode( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), true );
		$this->assertSame( '501', $payload['rules'][0]['image'] );
		$this->assertSame( 'color', $payload['rules'][0]['values'][0]['field'] );
		$this->assertSame( '501', $payload['images'][0]['image_id'] );
		$this->assertSame( 'https://example.test/gallery-501.jpg', $payload['images'][0]['src'] );
	}

	public function test_group_without_gallery_config_has_no_gallery_attributes(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'color', 'label' => 'Color', 'type' => 'select', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Gallery', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-wapf-gi', $html );
		$this->assertStringNotContainsString( 'data-opf-gi', $html );
	}
}
}
