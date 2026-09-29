<?php

namespace OPF\Tests\Unit;

use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class WapfExporterTest extends TestCase {

	public function test_emits_wapf_import_shape_and_round_trips_supported_fields_and_rules(): void {
		$opf = [
			'schema' => 1,
			'labels_position' => 'below',
			'mark_required' => false,
			'fields' => [
				[
					'id' => 'material', 'label' => 'Material', 'description' => 'Choose a finish', 'type' => 'radio',
					'required' => true, 'width' => 75, 'css_class' => 'finish-field', 'placeholder' => '',
					'choices' => [
						[ 'slug' => 'linen', 'label' => 'Linen', 'selected' => true, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
						[ 'slug' => 'wool', 'label' => 'Wool', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ],
					],
					'pricing' => [ 'type' => 'none', 'amount' => 0 ],
					'conditionals' => [],
				],
				[
					'id' => 'engraving', 'label' => 'Engraving', 'description' => '', 'type' => 'text',
					'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => 'Name', 'default' => 'Ava',
					'choices' => [], 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ],
					'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'material', 'operator' => 'is', 'value' => 'wool' ] ] ] ],
				],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'subject' => 'product', 'operator' => 'not_in', 'terms' => [ '42', '43' ] ] ] ],
				[ 'rules' => [
					[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ '7' ] ],
					[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '11' ] ],
				] ],
			],
		];

		$payload = WapfExporter::build_payload( $opf, 'Custom finish' );

		$this->assertSame( 'wapf_product', $payload['type'] );
		$this->assertSame( [ 'labels_position' => 'below', 'instructions_position' => 'field', 'mark_required' => false ], $payload['layout'] );
		$this->assertSame( 'radio', $payload['fields'][0]['type'] );
		$this->assertSame( 'fixed', $payload['fields'][0]['choices'][1]['pricing_type'] );
		$this->assertSame( 4.0, $payload['fields'][0]['choices'][1]['pricing_amount'] );
		$this->assertSame( 'qt', $payload['fields'][1]['pricing']['type'] );
		$this->assertSame( 'is', $payload['fields'][1]['conditionals'][0]['rules'][0]['condition'] );
		$this->assertSame( '!products', $payload['conditions'][0]['rules'][0]['condition'] );
		$this->assertSame( [ [ 'id' => '42', 'text' => '42' ], [ 'id' => '43', 'text' => '43' ] ], $payload['conditions'][0]['rules'][0]['value'] );
		$this->assertSame( [ 'product_cats', 'p_tags' ], array_column( $payload['conditions'][1]['rules'], 'condition' ) );

		$round_trip = WapfMapper::map( [
			'id' => $payload['id'],
			'type' => $payload['type'],
			'layout' => $payload['layout'],
			'fields' => $payload['fields'],
			'rule_groups' => array_map(
				static function ( array $group ): array {
					return [ 'rules' => array_map(
						static function ( array $rule ): array {
							return [ 'subject' => $rule['subject'], 'condition' => $rule['condition'], 'value' => $rule['value'] ];
						},
						$group['rules']
					) ];
				},
				$payload['conditions']
			),
		] );

		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( [ 'radio', 'text' ], array_column( $round_trip['group']['fields'], 'type' ) );
		$this->assertSame( 'wool', $round_trip['group']['fields'][1]['conditionals'][0]['rules'][0]['value'] );
		$this->assertTrue( $round_trip['group']['fields'][1]['pricing']['per_unit'] );
		$this->assertSame( [ '42', '43' ], $round_trip['group']['rule_groups'][0]['rules'][0]['terms'] );
		$this->assertSame( [ 'product_cat', 'product_tag' ], array_column( $round_trip['group']['rule_groups'][1]['rules'], 'subject' ) );
	}

	public function test_rejects_values_that_wapf_importer_cannot_preserve(): void {
		$opf = [
			'schema' => 1,
			'fields' => [ [
				'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch', 'choices' => [
					[ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/linen.jpg', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
				],
			] ],
		];

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'field type "swatch"' );
		WapfExporter::build_payload( $opf, 'Unsupported swatch' );
	}

	public function test_rejects_any_hide_conditionals_instead_of_changing_their_meaning(): void {
		$opf = [
			'schema' => 1,
			'fields' => [ [
				'id' => 'details', 'label' => 'Details', 'type' => 'text',
				'conditionals' => [ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [ [ 'field' => 'size', 'operator' => 'is', 'value' => 'large' ] ] ] ],
			] ],
		];

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'conditional semantics' );
		WapfExporter::build_payload( $opf, 'Conditional group' );
	}
}
