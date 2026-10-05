<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\Evaluator;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class ContentImageTest extends TestCase {
	public function test_content_image_is_safe_non_submittable_content(): void {
		$group = new FieldGroup(
			[
				'fields' => [
					[
						'id'        => 'fabric-guide',
						'type'      => 'content_image',
						'image_url' => '/uploads/fabric-guide.jpg',
						'alt'       => 'Fabric choices',
						'required'  => true,
						'pricing'   => [ 'type' => 'fixed', 'amount' => 99 ],
					],
				],
			]
		);

		$field = $group->data['fields'][0];
		$this->assertSame( 'content_image', $field['type'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], $field['pricing'] );
		$this->assertSame( '/uploads/fabric-guide.jpg', $field['image_url'] );
		$this->assertSame( 'Fabric choices', $field['alt'] );

		ob_start();
		Renderer::render_group( '17', 'Fabric', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'src="/uploads/fabric-guide.jpg"', $html );
		$this->assertStringContainsString( 'alt="Fabric choices"', $html );
		$this->assertStringNotContainsString( 'name="opf[17][fabric-guide]"', $html );
		$this->assertStringNotContainsString( 'data-opf-price', $html );
	}

	public function test_content_image_rejects_unsafe_and_oversized_urls(): void {
		$unsafe = FieldGroup::normalize_field(
			[ 'id' => 'bad-image', 'type' => 'content_image', 'image_url' => 'javascript:alert(1)', 'alt' => '<script>bad</script>' ]
		);
		$this->assertSame( '', $unsafe['image_url'] );
		$this->assertSame( 'bad', $unsafe['alt'] );

		$oversized = FieldGroup::normalize_field(
			[ 'id' => 'long-image', 'type' => 'content_image', 'image_url' => '/' . str_repeat( 'a', 2048 ) ]
		);
		$this->assertSame( '', $oversized['image_url'] );

		$non_scalar = FieldGroup::normalize_field(
			[ 'id' => 'array-image', 'type' => 'content_image', 'image_url' => [ '/unexpected.png' ], 'alt' => [ 'unexpected' ] ]
		);
		$this->assertSame( '', $non_scalar['image_url'] );
		$this->assertSame( '', $non_scalar['alt'] );
	}

	public function test_content_image_can_follow_field_conditionals(): void {
		$group = new FieldGroup(
			[
				'fields' => [
					[
						'id' => 'finish', 'type' => 'select', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ],
					],
					[
						'id' => 'red-fabric', 'type' => 'content_image', 'image_url' => '/uploads/red-fabric.jpg',
						'conditionals' => [
							[ 'logic' => 'all', 'action' => 'show', 'rules' => [ [ 'field' => 'finish', 'operator' => 'is', 'value' => 'red' ] ] ],
						],
					],
				],
			]
		);

		$image_field = $group->data['fields'][1];
		$this->assertFalse( Evaluator::is_visible( $image_field, [ 'finish' => 'blue' ] ) );
		$this->assertTrue( Evaluator::is_visible( $image_field, [ 'finish' => 'red' ] ) );
	}
}
