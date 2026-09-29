<?php
/**
 * Export the lossless OPF subset accepted by WAPF's JSON importer.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class WapfExporter {

	/** OPF field type => WAPF Free field type, verified against WAPF 1.7.1. */
	private const FIELD_TYPES = [
		'text' => 'text',
		'textarea' => 'textarea',
		'email' => 'email',
		'url' => 'url',
		'number' => 'number',
		'toggle' => 'true-false',
		'select' => 'select',
		'radio' => 'radio',
		'checkbox' => 'checkboxes',
		'paragraph' => 'content',
	];

	/** Convert one normalized OPF group to WAPF's raw JSON import shape. */
	public static function build_payload( array $group, string $title = '' ): array {
		self::reject_unknown_keys( $group, [ 'schema', 'fields', 'rule_groups', 'labels_position', 'mark_required' ], 'group' );
		foreach ( [ 'lookup_tables', 'formula_variables' ] as $resource ) {
			if ( ! empty( $group[ $resource ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve group resource "%s".', $resource ) );
			}
		}

		$fields = [];
		$known_ids = [];
		foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) ) {
				throw new \InvalidArgumentException( 'WAPF JSON export cannot preserve a malformed field.' );
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( ! isset( self::FIELD_TYPES[ $type ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve field type "%s".', $type ) );
			}
			self::reject_unknown_keys(
				$field,
				[ 'id', 'label', 'description', 'type', 'required', 'width', 'css_class', 'placeholder', 'default', 'content', 'choices', 'pricing', 'conditionals' ],
				sprintf( 'field "%s"', (string) ( $field['id'] ?? '?' ) )
			);
			$id = self::safe_id( (string) ( $field['id'] ?? '' ) );
			if ( isset( $known_ids[ $id ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export requires unique field IDs; "%s" is duplicated.', $id ) );
			}
			$known_ids[ $id ] = true;
			$raw = [
				'id' => $id,
				'label' => (string) ( $field['label'] ?? '' ),
				'description' => (string) ( $field['description'] ?? '' ),
				'type' => self::FIELD_TYPES[ $type ],
				'required' => (bool) ( $field['required'] ?? false ),
				'class' => (string) ( $field['css_class'] ?? '' ),
				'width' => (int) ( $field['width'] ?? 100 ),
				'conditionals' => self::conditionals( (array) ( $field['conditionals'] ?? [] ), (string) ( $field['id'] ?? '' ) ),
				'pricing' => self::pricing( (array) ( $field['pricing'] ?? [] ), sprintf( 'field "%s"', $id ) ),
			];
			if ( '' !== (string) ( $field['placeholder'] ?? '' ) ) {
				$raw['placeholder'] = (string) $field['placeholder'];
			}
			if ( array_key_exists( 'default', $field ) ) {
				if ( ! is_scalar( $field['default'] ) ) {
					throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve the non-scalar default for field "%s".', $id ) );
				}
				$raw['default'] = (string) $field['default'];
			}
			if ( 'paragraph' === $type ) {
				$content = (string) ( $field['content'] ?? '' );
				if ( preg_match( '/<[^>]+>/', $content ) ) {
					throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve HTML paragraph content for field "%s".', $id ) );
				}
				$raw['p_content'] = $content;
			}
			if ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
				$raw['choices'] = self::choices( (array) ( $field['choices'] ?? [] ), $id );
			} elseif ( ! empty( $field['choices'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve choices on field "%s".', $id ) );
			}
			$fields[] = $raw;
		}

		return [
			'id' => 'opf-export',
			'type' => 'wapf_product',
			'layout' => [
				'labels_position' => ( 'below' === ( $group['labels_position'] ?? 'above' ) ) ? 'below' : 'above',
				'instructions_position' => 'field',
				'mark_required' => (bool) ( $group['mark_required'] ?? true ),
			],
			'fields' => $fields,
			'conditions' => self::placement_conditions( (array) ( $group['rule_groups'] ?? [] ), $title ),
		];
	}

	/** Map OPF field conditions to the raw WAPF field-condition structure. */
	private static function conditionals( array $conditionals, string $field_id ): array {
		$out = [];
		$operators = [ 'is' => 'is', 'is_not' => 'is_not', 'contains' => 'contains', 'greater' => 'gt', 'less' => 'lt', 'empty' => 'empty', 'not_empty' => 'not_empty' ];
		foreach ( $conditionals as $conditional ) {
			if ( ! is_array( $conditional ) || 'show' !== ( $conditional['action'] ?? 'show' ) || 'all' !== ( $conditional['logic'] ?? 'all' ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve conditional semantics for field "%s".', $field_id ) );
			}
			$rules = [];
			foreach ( (array) ( $conditional['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $operators[ $rule['operator'] ?? '' ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve a conditional operator on field "%s".', $field_id ) );
				}
				$rules[] = [
					'field' => self::safe_id( (string) ( $rule['field'] ?? '' ) ),
					'value' => (string) ( $rule['value'] ?? '' ),
					'condition' => $operators[ $rule['operator'] ],
				];
			}
			if ( $rules ) {
				$out[] = [ 'rules' => $rules ];
			}
		}
		return $out;
	}

	/** Map group placement predicates into WAPF's conditions array. */
	private static function placement_conditions( array $groups, string $title ): array {
		$subjects = [ 'product' => 'products', 'product_cat' => 'product_cats', 'product_tag' => 'p_tags' ];
		$out = [];
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve an empty placement rule group for "%s".', $title ) );
			}
			$rules = [];
			foreach ( $group['rules'] as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $subjects[ $rule['subject'] ?? '' ] ) || ! in_array( $rule['operator'] ?? '', [ 'in', 'not_in' ], true ) ) {
					throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve a placement rule for "%s".', $title ) );
				}
				$terms = array_values( array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ) );
				if ( ! $terms || in_array( '', $terms, true ) ) {
					throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve an empty placement predicate for "%s".', $title ) );
				}
				$subject = $subjects[ $rule['subject'] ];
				$rules[] = [
					'condition' => 'not_in' === $rule['operator'] ? '!' . $subject : $subject,
					'value' => array_map( static fn( string $id ): array => [ 'id' => $id, 'text' => $id ], $terms ),
					'subject' => $rule['subject'],
				];
			}
			$out[] = [ 'rules' => $rules ];
		}
		return $out;
	}

	/** Map OPF price shape to WAPF's field/choice pricing values. */
	private static function pricing( array $pricing, string $context ): array {
		$type = (string) ( $pricing['type'] ?? 'none' );
		if ( ! is_numeric( $pricing['amount'] ?? 0 ) || ! is_finite( (float) ( $pricing['amount'] ?? 0 ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve pricing for %s.', $context ) );
		}
		if ( ! empty( $pricing['formula'] ) || ! empty( $pricing['formula_raw'] ) ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve formula pricing for %s.', $context ) );
		}
		if ( 'percent' === $type && array_key_exists( 'per_unit', $pricing ) && ! $pricing['per_unit'] ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve flat percentage pricing for %s.', $context ) );
		}
		if ( ! in_array( $type, [ 'none', 'fixed', 'percent' ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve pricing type "%s" for %s.', $type, $context ) );
		}
		$wapf_type = 'fixed' === $type && ! empty( $pricing['per_unit'] ) ? 'qt' : $type;
		return [ 'type' => $wapf_type, 'amount' => (float) ( $pricing['amount'] ?? 0 ), 'enabled' => 'none' !== $type ];
	}

	/** Map WAPF choice records, rejecting presentation data its public parser drops. */
	private static function choices( array $choices, string $field_id ): array {
		$out = [];
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve a malformed choice on field "%s".', $field_id ) );
			}
			self::reject_unknown_keys( $choice, [ 'slug', 'label', 'selected', 'disabled', 'pricing' ], sprintf( 'choice on field "%s"', $field_id ) );
			if ( ! empty( $choice['disabled'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve disabled choices on field "%s".', $field_id ) );
			}
			$price = self::pricing( (array) ( $choice['pricing'] ?? [] ), sprintf( 'choice on field "%s"', $field_id ) );
			$out[] = [
				'slug' => self::safe_id( (string) ( $choice['slug'] ?? '' ) ),
				'label' => (string) ( $choice['label'] ?? '' ),
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'pricing_type' => $price['type'],
				'pricing_amount' => $price['amount'],
			];
		}
		return $out;
	}

	private static function reject_unknown_keys( array $value, array $allowed, string $context ): void {
		foreach ( $value as $key => $entry ) {
			if ( in_array( (string) $key, $allowed, true ) || null === $entry || '' === $entry || [] === $entry || false === $entry || 0 === $entry || 0.0 === $entry ) {
				continue;
			}
			throw new \InvalidArgumentException( sprintf( 'WAPF JSON export cannot preserve %s property "%s".', $context, (string) $key ) );
		}
	}

	private static function safe_id( string $id ): string {
		$id = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $id ) );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'WAPF JSON export requires non-empty field and choice IDs.' );
		}
		return $id;
	}
}
