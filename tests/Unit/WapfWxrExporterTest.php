<?php

namespace OPF\Tests\Unit;

use OPF\Engine\WapfMapper;
use OPF\Service\WapfWxrExporter;
use PHPUnit\Framework\TestCase;

final class WapfWxrExporterTest extends TestCase {

	public function test_exports_all_groups_as_wapf_registered_posts_with_serialized_fieldgroups(): void {
		$groups = [
			[
				'id' => 91,
				'title' => 'Custom finish & sizing',
				'status' => 'publish',
				'menu_order' => 3,
				'data' => [
					'schema' => 1,
					'labels_position' => 'below',
					'fields' => [
						[
							'id' => 'finish', 'label' => 'Finish ]]> & more', 'type' => 'select', 'required' => false,
							'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'selected' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ] ],
							'pricing' => [ 'type' => 'none', 'amount' => 0 ],
							'conditionals' => [],
						],
					],
					'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '44' ] ] ] ] ],
				],
			],
			[
				'id' => 92,
				'title' => 'Second group',
				'status' => 'draft',
				'menu_order' => 4,
				'data' => [ 'schema' => 1, 'fields' => [], 'rule_groups' => [] ],
			],
		];

		$xml = WapfWxrExporter::build_document( $groups, [ 'site_url' => 'https://example.test', 'site_title' => 'Example & Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.2/' );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$this->assertSame( 2, $xpath->query( '/rss/channel/item' )->length );
		$this->assertSame( 'Example & Store', $xpath->query( '/rss/channel/title' )->item( 0 )->textContent );
		$this->assertSame( 'wapf_product', $xpath->query( '/rss/channel/item[1]/wp:post_type' )->item( 0 )->textContent );
		$this->assertSame( 'publish', $xpath->query( '/rss/channel/item[1]/wp:status' )->item( 0 )->textContent );
		$this->assertSame( 'draft', $xpath->query( '/rss/channel/item[2]/wp:status' )->item( 0 )->textContent );

		$content = $xpath->query( '/rss/channel/item[1]/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );
		$this->assertIsArray( $group );
		$this->assertSame( 'wapf_product', $group['type'] );
		$this->assertSame( 'below', $group['layout']['labels_position'] );
		$this->assertSame( 'Finish ]]> & more', $group['fields'][0]['label'] );
		$this->assertSame( 'fixed', $group['fields'][0]['options']['choices'][0]['pricing_type'] );
		$this->assertSame( 2.0, $group['fields'][0]['options']['choices'][0]['pricing_amount'] );
		$this->assertSame( [ [ 'id' => '44', 'text' => '44' ] ], $group['rule_groups'][0]['rules'][0]['value'] );
	}

	public function test_wxr_serializes_date_options_into_the_wapf_options_bucket(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 5,
			'title' => 'Date group',
			'data' => [
				'schema' => 1,
				'fields' => [ [
					'id' => 'when', 'label' => 'When', 'type' => 'date',
					'allow_past' => false,
					'allow_future' => true,
					'min_date' => '2027-02-10',
					'max_date' => '1y',
					'disabled_weekdays' => [ 0, 6 ],
					'disabled_dates' => [ '2027-02-10 2027-02-12', '12-25' ],
					'cutoff_time' => '12:00',
				] ],
				'rule_groups' => [],
			],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );

		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$group = unserialize( $xpath->query( '/rss/channel/item[1]/content:encoded' )->item( 0 )->textContent, [ 'allowed_classes' => false ] );
		$options = $group['fields'][0]['options'];
		$this->assertTrue( $options['disable_past'] );
		$this->assertFalse( $options['disable_future'] );
		$this->assertSame( '02-10-2027', $options['min_date'] );
		$this->assertSame( '1y', $options['max_date'] );
		$this->assertSame( '0,6', $options['disabled_days'] );
		$this->assertSame( '02-10-2027 02-12-2027,12-25', $options['disabled_dates'] );
		$this->assertSame( '12:00', $options['disable_today_after'] );
	}

	public function test_wxr_preserves_image_swatch_type_and_choice_media_references(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 93,
			'title' => 'Image finishes',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'finish',
				'label' => 'Finish',
				'type' => 'swatch',
				'swatch_style' => 'image',
				'image_zoom' => true,
				'label_pos' => 'tooltip',
				'grid_layout' => 'flexible',
				'item_width' => 96,
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 481 ] ],
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'image-swatch', $group['fields'][0]['type'] );
		$this->assertTrue( $group['fields'][0]['options']['large_image'] );
		$this->assertSame( 'tooltip', $group['fields'][0]['options']['label_pos'] );
		$this->assertSame( 'flexible', $group['fields'][0]['options']['grid_layout'] );
		$this->assertSame( 96, $group['fields'][0]['options']['item_width'] );
		$this->assertSame( 'https://example.test/oak.jpg', $group['fields'][0]['options']['choices'][0]['image'] );
		$this->assertSame( 481, $group['fields'][0]['options']['choices'][0]['attachment'] );
	}

	public function test_wxr_preserves_multi_color_swatch_options_and_choices(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 94,
			'title' => 'Color choices',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'palette', 'label' => 'Palette', 'type' => 'swatch', 'swatch_style' => 'color',
				'multiple' => true, 'min_choices' => 1, 'max_choices' => 2,
				'color_layout' => 'rounded', 'color_size' => 36, 'color_label_pos' => 'default',
				'choices' => [ [ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456' ] ],
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'multi-color-swatch', $group['fields'][0]['type'] );
		$this->assertSame( 1, $group['fields'][0]['options']['min_choices'] );
		$this->assertSame( 2, $group['fields'][0]['options']['max_choices'] );
		$this->assertSame( 'rounded', $group['fields'][0]['options']['layout'] );
		$this->assertSame( 36, $group['fields'][0]['options']['size'] );
		$this->assertSame( '#123456', $group['fields'][0]['options']['choices'][0]['color'] );
	}

	public function test_wxr_serializes_checkbox_columns_as_a_wapf_field_option(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 96,
			'title' => 'Checkbox columns',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'columns' => 3,
				'choices' => [ [ 'slug' => 'wrap', 'label' => 'Gift wrap' ] ],
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'checkboxes', $group['fields'][0]['type'] );
		$this->assertSame( 3, $group['fields'][0]['options']['columns'] );
		$reimported = WapfMapper::map( $group );
		$this->assertSame( 3, $reimported['group']['fields'][0]['columns'] );
	}

	public function test_wxr_preserves_extended_p_content_markup_and_type(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 95,
			'title' => 'Rich content',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'offer', 'label' => 'Offer', 'type' => 'paragraph',
				'content_format' => 'html', 'process_shortcodes' => true,
				'content' => '<strong>Special</strong> [site_name]',
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'p', $group['fields'][0]['type'] );
		$this->assertSame( '<strong>Special</strong> [site_name]', $group['fields'][0]['options']['p_content'] );
	}

	public function test_wxr_preserves_informative_image_type_url_and_attachment(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 96, 'title' => 'Informative image',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'fabric-guide', 'label' => 'Fabric guide', 'type' => 'content_image',
				'image_url' => 'https://example.test/fabric.jpg', 'image_id' => 481,
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'img', $group['fields'][0]['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $group['fields'][0]['options']['image'] );
		$this->assertSame( 481, $group['fields'][0]['options']['attachment'] );
	}

	public function test_wxr_preserves_section_and_sectionend_markers(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 97, 'title' => 'Section layout',
			'data' => [ 'schema' => 1, 'fields' => [
				[ 'id' => 'details', 'type' => 'section' ],
				[ 'id' => 'note', 'type' => 'paragraph', 'content' => 'Inside' ],
				[ 'id' => 'details-end', 'type' => 'section_end' ],
			], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( [ 'section', 'content', 'sectionend' ], array_column( $group['fields'], 'type' ) );
	}

	public function test_wxr_preserves_formula_pricing_amount_expression(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 98, 'title' => 'Formula pricing',
			'data' => [ 'schema' => 1, 'fields' => [
				[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number' ],
				[ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text',
					'pricing' => [ 'type' => 'formula', 'formula' => '([price] + [field.weight]) * [qty]', 'per_unit' => false ] ],
			], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'fx', $group['fields'][1]['pricing']['type'] );
		$this->assertTrue( $group['fields'][1]['pricing']['enabled'] );
		$this->assertSame( '([price] + [field.weight]) * [qty]', $group['fields'][1]['pricing']['amount'] );
	}

	public function test_wxr_preserves_products_field_options_subtype_and_choices(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 99,
			'title' => 'Linked products',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'linked', 'label' => 'Linked', 'type' => 'products', 'subtype' => 'card-qty',
				'product_selection' => 'manual', 'display' => 'plus_min',
				'min_choices' => 1, 'max_choices' => 9,
				'items_per_row' => 3, 'incl_img' => true, 'incl_desc' => false,
				'slot_1' => 'price', 'slot_2' => 'stock',
				'hide_cart' => true,
				'choices' => [
					[ 'slug' => 'a', 'label' => '', 'product_id' => 51, 'pricing_type' => 'fixed',
						'quantity' => [ 'default' => 2, 'min' => 1, 'max' => 8 ] ],
					[ 'slug' => 'b', 'label' => '', 'product_id' => 77, 'pricing_type' => 'none' ],
				],
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$field = $group['fields'][0];
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'card-qty', $field['subtype'] );
		$this->assertSame( 'manual', $field['options']['product_selection'] );
		$this->assertSame( 'plus_min', $field['options']['display'] );
		$this->assertSame( 1, $field['options']['min_choices'] );
		$this->assertSame( 9, $field['options']['max_choices'] );
		$this->assertSame( 3, $field['options']['items_per_row'] );
		$this->assertSame( 'price', $field['options']['slot_1'] );
		$this->assertSame( 'stock', $field['options']['slot_2'] );
		$this->assertFalse( $field['options']['incl_desc'] );
		$this->assertSame( 'true', $field['options']['hide_cart'] );
		$choice = $field['options']['choices'][0];
		$this->assertSame( 51, $choice['id'] );
		$this->assertSame( 'a', $choice['slug'] );
		$this->assertSame( 'fixed', $choice['pricing_type'] );
		$this->assertSame( [ 'min' => 1, 'max' => 8, 'default' => 2 ], $choice['options'] );
		$this->assertSame( 'none', $field['options']['choices'][1]['pricing_type'] );
	}

	public function test_wxr_preserves_category_products_query_and_file_options(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 100,
			'title' => 'Query and upload',
			'data' => [ 'schema' => 1, 'fields' => [
				[
					'id' => 'related', 'label' => 'Related', 'type' => 'products', 'subtype' => 'vcard',
					'product_selection' => 'category',
					'product_query' => [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ],
					'img_fit' => 'contain',
				],
				[
					'id' => 'art', 'label' => 'Artwork', 'type' => 'upload',
					'multiple' => true, 'max_size' => 2.5, 'accepted_types' => [ 'png', 'pdf' ],
				],
			], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$products_field = $group['fields'][0];
		$this->assertSame( 'category', $products_field['options']['product_selection'] );
		$this->assertSame( 44, $products_field['options']['product_query']['query_id'] );
		$this->assertSame( 'name_asc', $products_field['options']['product_query']['sort'] );
		$this->assertSame( 'none', $products_field['options']['product_query']['pricing_type'] );
		$this->assertSame( 'contain', $products_field['options']['img_fit'] );

		$file_field = $group['fields'][1];
		$this->assertSame( 'file', $file_field['type'] );
		$this->assertTrue( $file_field['options']['multiple'] );
		$this->assertSame( 2.5, $file_field['options']['maxsize'] );
		$this->assertSame( 'png,pdf', $file_field['options']['accept'] );
	}

	public function test_wxr_preserves_variables_and_placement(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 101,
			'title' => 'Variables',
			'data' => [ 'schema' => 1,
				'fields' => [ [ 'id' => 'width', 'label' => 'Width', 'type' => 'number' ] ],
				'variables' => [ [
					'name' => 'fee', 'default' => '[field.width] * 2',
					'rules' => [ [ 'type' => 'field', 'field' => 'width', 'condition' => '==', 'value' => 'x', 'variable' => 'lookuptable(t;width;2)' ] ],
				] ],
				'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '44' ] ] ] ] ],
			],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$this->assertSame( 'fee', $group['variables'][0]['name'] );
		$this->assertSame( '[field.width] * 2', $group['variables'][0]['default'] );
		$this->assertSame( 'lookuptable(t;width;2)', $group['variables'][0]['rules'][0]['variable'] );
		$this->assertSame( [ [ 'id' => '44', 'text' => '44' ] ], $group['rule_groups'][0]['rules'][0]['value'] );

		// The serialized payload must survive the OPF import path too.
		$mapped = \OPF\Engine\WapfMapper::map( $group );
		$this->assertSame( 'fee', $mapped['group']['variables'][0]['name'] );
	}

	public function test_wxr_preserves_number_mode_bounds_and_step(): void {
		$xml = WapfWxrExporter::build_document( [ [
			'id' => 102,
			'title' => 'Number constraints',
			'data' => [ 'schema' => 1, 'fields' => [ [
				'id' => 'length', 'label' => 'Length', 'type' => 'number',
				'number_mode' => 'decimal', 'min' => 1, 'max' => 3, 'step' => 0.25, 'default' => '2',
			] ], 'rule_groups' => [] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$content = $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent;
		$group = unserialize( $content, [ 'allowed_classes' => false ] );

		$field = $group['fields'][0];
		// WAPF stores the mode and its custom step inside `number_type`.
		$this->assertSame( '0.25', $field['options']['number_type'] );
		$this->assertSame( 1.0, $field['options']['minimum'] );
		$this->assertSame( 3.0, $field['options']['maximum'] );
		$this->assertSame( '2', $field['options']['default'] );
		$this->assertArrayNotHasKey( 'step', $field['options'] );

		// The serialized options must survive the OPF import path too.
		$mapped = \OPF\Engine\WapfMapper::map( $group );
		$this->assertFalse( $mapped['needs_review'] );
		$number = $mapped['group']['fields'][0];
		$this->assertSame( [ 'decimal', 0.25 ], [ $number['number_mode'], $number['step'] ] );
		$this->assertSame( [ 1.0, 3.0, '2' ], [ $number['min'], $number['max'], $number['default'] ] );
	}

	public function test_requires_valid_site_url_and_source_group_identity(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'source site URL' );
		WapfWxrExporter::build_document( [], [ 'site_url' => '', 'site_title' => 'Example Store' ] );
	}
}
