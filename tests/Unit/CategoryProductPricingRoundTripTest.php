<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Engine\WapfParser;
use OPF\Service\ArchiveImporter;
use OPF\Service\Exporter;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryProductPricingRoundTripTest extends TestCase {

	public static function pricing_cases(): array {
		$cases = [];
		foreach ( [ 'fixed', 'none' ] as $price ) {
			foreach ( [ 'checkbox', 'radio', 'dropdown', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty' ] as $subtype ) {
				$cases[ "$price/$subtype" ] = [ $price, $subtype ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'pricing_cases' )]
	public function test_category_price_survives_tools_wxr_and_archive( string $price, string $subtype ): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'products',
			'subtype' => $subtype, 'product_selection' => 'category',
			'product_query' => [ 'query_id' => 3, 'query_label' => 'Extras', 'limit' => 50, 'sort' => 'name_asc', 'pricing_type' => $price ],
			'qty_method' => 'parent',
		] ] ] );
		$field = $group['fields'][0];
		$tools = WapfExporter::build_payload( $group );
		$this->assertSame( $field['product_query'], $tools['fields'][0]['product_query'] );
		$source = $tools['fields'][0];
		$source['options'] = $source;
		$mapped = WapfMapper::map( [ 'fields' => [ $source ] ] );
		$this->assertFalse( $mapped['needs_review'], implode( '\n', $mapped['notes'] ) );
		$this->assertSame( $field['product_query'], $mapped['group']['fields'][0]['product_query'] );
		$this->assertSame( $subtype, $mapped['group']['fields'][0]['subtype'] );

		$entries = [ [ 'id' => 77, 'title' => 'Category pricing', 'status' => 'publish', 'data' => $group ] ];
		$xml = WapfWxrExporter::build_document( $entries, [ 'site_url' => 'https://example.test', 'site_title' => 'Example' ] );
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$wxr = WapfParser::parse( $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent );
		$this->assertSame( $field['product_query'], $wxr['fields'][0]['options']['product_query'] );
		$mapped = WapfMapper::map( $wxr );
		$this->assertFalse( $mapped['needs_review'], implode( '\n', $mapped['notes'] ) );
		$this->assertSame( $field['product_query'], $mapped['group']['fields'][0]['product_query'] );

		$archive = Exporter::build_package( $entries, [ 'site' => 'https://example.test' ] );
		$decoded = ArchiveImporter::decode( json_encode( $archive, JSON_THROW_ON_ERROR ) );
		$this->assertSame( $group, $decoded['groups'][0]['data'] );
	}
}
