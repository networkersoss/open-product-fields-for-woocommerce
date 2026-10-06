<?php
/**
 * WAPF-FIELD-TRUE-FALSE-SWITCH: `switch_control` is the serialized key behind
 * WAPF Pro's "display true/false fields or checkboxes as switches" setting.
 * Both WAPF import documents (the Tools payload and the WXR migration
 * document) must carry it through export→reimport — the export used to fail
 * the whole group closed — while types that cannot render a switch must keep
 * failing the export instead of losing the key.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Engine\WapfParser;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WapfExporterSwitchControlTest extends TestCase {

	/**
	 * WAPF Tools import keeps the flattened payload keys inside the stored
	 * field's `options` bucket; the mapper must read that shape.
	 */
	private function reimport( array $payload ): array {
		$fields = [];
		foreach ( $payload['fields'] as $field ) {
			$field['options'] = $field;
			$fields[] = $field;
		}
		return WapfMapper::map( [ 'fields' => $fields, 'rule_groups' => $payload['conditions'] ] );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
	public static function switch_field_types(): array {
		return [
			'toggle'   => [
				[ 'id' => 'gift-wrap', 'label' => 'Gift wrap', 'type' => 'toggle', 'switch_control' => true ],
				'true-false',
				'toggle',
			],
			'checkbox' => [
				[
					'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'switch_control' => true,
					'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift' ], [ 'slug' => 'card', 'label' => 'Card' ] ],
				],
				'checkboxes',
				'checkbox',
			],
		];
	}

	/** @param array<string,mixed> $field */
	#[DataProvider( 'switch_field_types' )]
	public function test_switch_control_survives_the_tools_export_and_reimport( array $field, string $wapf_type, string $opf_type ): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ $field ] ] ) );
		$this->assertSame( $wapf_type, $payload['fields'][0]['type'] );
		$this->assertTrue( $payload['fields'][0]['switch_control'] );

		$round_trip = $this->reimport( $payload );
		$this->assertFalse( $round_trip['needs_review'], implode( '\n', $round_trip['notes'] ) );
		$round_tripped = $round_trip['group']['fields'][0];
		$this->assertSame( $opf_type, $round_tripped['type'] );
		$this->assertTrue( $round_tripped['switch_control'] );
	}

	/** @param array<string,mixed> $field */
	#[DataProvider( 'switch_field_types' )]
	public function test_switch_control_survives_the_wxr_migration_document( array $field ): void {
		$group = FieldGroup::normalize( [ 'fields' => [ $field ] ] );
		$xml = WapfWxrExporter::build_document(
			[ [ 'id' => 77, 'title' => 'Switch', 'status' => 'publish', 'data' => $group ] ],
			[ 'site_url' => 'https://example.test', 'site_title' => 'Example' ]
		);
		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$wapf = WapfParser::parse( $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent );
		// WAPF reads its stored model, where the setting lives in `options`.
		$this->assertTrue( $wapf['fields'][0]['options']['switch_control'] );

		$round_trip = WapfMapper::map( $wapf );
		$this->assertFalse( $round_trip['needs_review'], implode( '\n', $round_trip['notes'] ) );
		$this->assertTrue( $round_trip['group']['fields'][0]['switch_control'] );
	}

	public function test_switch_control_is_absent_when_the_field_is_not_a_switch(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'gift-wrap', 'label' => 'Gift wrap', 'type' => 'toggle',
		] ] ] ) );
		$this->assertArrayNotHasKey( 'switch_control', $payload['fields'][0] );

		$field = $this->reimport( $payload )['group']['fields'][0];
		$this->assertSame( 'toggle', $field['type'] );
		$this->assertArrayNotHasKey( 'switch_control', $field );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public static function non_switch_field_types(): array {
		return [
			'radio'    => [ [ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen' ] ] ] ],
			'select'   => [ [ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => [ [ 'slug' => 's', 'label' => 'S' ] ] ] ],
			'text'     => [ [ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ] ],
			'textarea' => [ [ 'id' => 'message', 'label' => 'Message', 'type' => 'textarea' ] ],
			'number'   => [ [ 'id' => 'qty', 'label' => 'Quantity', 'type' => 'number' ] ],
		];
	}

	/** @param array<string,mixed> $field */
	#[DataProvider( 'non_switch_field_types' )]
	public function test_switch_control_still_fails_the_export_closed_on_other_types( array $field ): void {
		$field['switch_control'] = true;
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown field data: switch_control' );
		WapfExporter::build_payload( [ 'fields' => [ $field ] ] );
	}

	public function test_mapper_ignores_switch_control_on_non_switch_types(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'note', 'label' => 'Note', 'type' => 'text', 'options' => [ 'switch_control' => true ],
		] ] ] );
		$this->assertSame( 'text', $mapped['group']['fields'][0]['type'] );
		$this->assertArrayNotHasKey( 'switch_control', $mapped['group']['fields'][0] );
	}
}
