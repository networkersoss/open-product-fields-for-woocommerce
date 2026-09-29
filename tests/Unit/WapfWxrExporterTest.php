<?php

namespace OPF\Tests\Unit;

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

	public function test_rejects_any_group_that_wapf_json_export_would_lose(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'field type "swatch"' );
		WapfWxrExporter::build_document( [ [
			'id' => 5,
			'title' => 'Unsupported group',
			'data' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'color', 'type' => 'swatch' ] ] ],
		] ], [ 'site_url' => 'https://example.test', 'site_title' => 'Example Store' ] );
	}
}
