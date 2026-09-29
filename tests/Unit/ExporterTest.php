<?php

namespace OPF\Tests\Unit {
	use OPF\Service\Exporter;
	use PHPUnit\Framework\TestCase;

	final class ExporterTest extends TestCase {
		public function test_package_preserves_group_data_and_reports_portability_warnings(): void {
			$data = [
				'schema' => 1,
				'fields' => [
					[
						'id' => 'finish',
						'type' => 'swatch',
						'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'image' => 'https://source.example/red.png' ] ],
					],
					[ 'id' => 'total', 'type' => 'calculation', 'formula' => 'lookuptable(prices; finish)' ],
				],
				'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '42' ] ] ] ] ],
				'labels_position' => 'below',
			];
			$group = [
				'id' => 7,
				'title' => 'Custom finish',
				'status' => 'draft',
				'menu_order' => 3,
				'lang' => 'en',
				'data' => $data,
			];

			$package = Exporter::build_package( [ $group ], [ 'type' => 'group', 'id' => 7 ] );

			$this->assertSame( 'opf-field-groups', $package['format'] );
			$this->assertSame( 1, $package['format_version'] );
			$this->assertSame( [ 'type' => 'group', 'id' => 7 ], $package['scope'] );
			$this->assertSame( $data, $package['groups'][0]['data'] );
			$this->assertSame( 'draft', $package['groups'][0]['status'] );
			$this->assertContains( 'media_files_not_included', $package['groups'][0]['warnings'] );
			$this->assertContains( 'product_target_ids_may_not_match', $package['groups'][0]['warnings'] );
			$this->assertContains( 'site_lookup_tables_not_included', $package['groups'][0]['warnings'] );
			$this->assertContains( 'product_target_ids_may_not_match', $package['warnings'] );
		}

		public function test_local_formula_resources_do_not_get_reported_as_external(): void {
			$data = [
				'schema' => 1,
				'fields' => [ [ 'id' => 'price', 'type' => 'calculation', 'formula' => 'lookuptable(local_prices; size) + [local_fee]' ] ],
				'lookup_tables' => [ 'local_prices' => [ [ 'small', 3 ] ] ],
				'formula_variables' => [ 'local_fee' => [ 'default' => 2 ] ],
			];

			$package = Exporter::build_package(
				[ [ 'id' => 9, 'title' => 'Local resources', 'status' => 'publish', 'data' => $data ] ],
				[ 'type' => 'all' ]
			);

			$this->assertNotContains( 'site_lookup_tables_not_included', $package['groups'][0]['warnings'] );
			$this->assertNotContains( 'site_formula_variables_not_included', $package['groups'][0]['warnings'] );
		}

		public function test_content_image_urls_are_reported_without_being_rewritten(): void {
			$data = [ 'schema' => 1, 'fields' => [ [ 'id' => 'guide', 'type' => 'content_image', 'image_url' => '/wp-content/uploads/guide.jpg' ] ] ];
			$package = Exporter::build_package(
				[ [ 'id' => 10, 'title' => 'Guide', 'status' => 'publish', 'data' => $data ] ],
				[ 'type' => 'group', 'id' => 10 ]
			);

			$this->assertSame( '/wp-content/uploads/guide.jpg', $package['groups'][0]['data']['fields'][0]['image_url'] );
			$this->assertContains( 'media_files_not_included', $package['groups'][0]['warnings'] );
		}

		public function test_product_scope_reports_an_empty_match_without_dropping_package_metadata(): void {
			$package = Exporter::build_package( [], [ 'type' => 'product', 'id' => 123 ] );

			$this->assertSame( [ 'type' => 'product', 'id' => 123 ], $package['scope'] );
			$this->assertSame( [], $package['groups'] );
			$this->assertSame( [], $package['warnings'] );
		}
	}
}
