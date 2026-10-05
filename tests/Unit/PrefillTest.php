<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\Prefill;
use PHPUnit\Framework\TestCase;

final class PrefillTest extends TestCase {
	public function test_signed_payload_is_bounded_and_tamper_evident(): void {
		$json = json_encode( [ 'engraving' => 'Ada', 'extras' => [ 'gift', 'card' ] ] );
		$payload = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$signature = hash_hmac( 'sha256', $payload, 'secret' );
		$this->assertSame( [ 'Ada' ], [ Prefill::decode( $payload, $signature, 'secret' )['engraving'] ] );
		$this->assertSame( [ 'gift', 'card' ], Prefill::decode( $payload, $signature, 'secret' )['extras'] );
		$this->assertSame( [], Prefill::decode( $payload . 'x', $signature, 'secret' ) );
	}

	public function test_prefill_parameter_is_allow_listed_in_schema(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'engraving', 'type' => 'text', 'prefill_param' => 'engraving' ] );
		$this->assertSame( 'engraving', $field['prefill_param'] );
		$invalid = FieldGroup::normalize_field( [ 'id' => 'x', 'type' => 'text', 'prefill_param' => 'bad.param' ] );
		$this->assertArrayNotHasKey( 'prefill_param', $invalid );
	}

	public function test_configured_raw_query_key_prefills_one_scalar_value(): void {
		$field = [ 'type' => 'text', 'prefill_param' => 'engraving' ];
		$this->assertSame( 'Ada + Grace', Prefill::from_query( $field, 'Ada + Grace' ) );
	}

	public function test_configured_multi_choice_query_key_splits_comma_values(): void {
		$field = [ 'type' => 'checkbox', 'prefill_param' => 'colors' ];
		$this->assertSame( [ 'red', 'blue' ], Prefill::from_query( $field, 'red,blue,red' ) );
	}

	public function test_single_choice_query_value_keeps_commas_literal(): void {
		$field = [ 'type' => 'radio', 'prefill_param' => 'option' ];
		$this->assertSame( 'red,blue', Prefill::from_query( $field, 'red,blue' ) );
	}

	public function test_raw_query_prefill_rejects_unconfigured_malformed_and_oversized_values(): void {
		$this->assertNull( Prefill::from_query( [ 'type' => 'text' ], 'Ada' ) );
		$this->assertNull( Prefill::from_query( [ 'type' => 'text', 'prefill_param' => 'x' ], [ 'Ada' ] ) );
		$this->assertNull( Prefill::from_query( [ 'type' => 'text', 'prefill_param' => 'x' ], str_repeat( 'x', 2049 ) ) );
	}
}
