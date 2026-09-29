<?php
/**
 * Portable OPF group export packaging.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class Exporter {
	/**
	 * Build a versioned package without rewriting the persisted group schema.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups with id/title/status/data metadata.
	 * @param array<string,mixed>             $scope  all, group, or product scope.
	 * @return array<string,mixed>
	 */
	public static function build_package( array $groups, array $scope ): array {
		$exported = [];
		$all_warnings = [];

		foreach ( $groups as $group ) {
			$data = is_array( $group['data'] ?? null ) ? $group['data'] : [];
			$warnings = self::group_warnings( $data );
			$entry = [
				'source_id' => (int) ( $group['id'] ?? 0 ),
				'title' => (string) ( $group['title'] ?? '' ),
				'status' => (string) ( $group['status'] ?? 'draft' ),
				'menu_order' => (int) ( $group['menu_order'] ?? 0 ),
				'language' => (string) ( $group['lang'] ?? '' ),
				'data' => $data,
				'warnings' => $warnings,
			];
			$exported[] = $entry;
			$all_warnings = array_merge( $all_warnings, $warnings );
		}

		return [
			'format' => 'opf-field-groups',
			'format_version' => 1,
			'exported_at' => gmdate( 'c' ),
			'scope' => $scope,
			'groups' => $exported,
			'warnings' => array_values( array_unique( $all_warnings ) ),
		];
	}

	/** Find cross-site references that remain external to the package. */
	private static function group_warnings( array $data ): array {
		$warnings = [];
		$fields = is_array( $data['fields'] ?? null ) ? $data['fields'] : [];
		$formulas = [];
		$has_media = false;

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$has_media = $has_media || self::has_media_reference( $field );
			if ( isset( $field['formula'] ) && is_scalar( $field['formula'] ) ) {
				$formulas[] = (string) $field['formula'];
			}
			foreach ( is_array( $field['choices'] ?? null ) ? $field['choices'] : [] as $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
			}
				$has_media = $has_media || self::has_media_reference( $choice );
				$pricing = is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : [];
				if ( isset( $pricing['formula'] ) && is_scalar( $pricing['formula'] ) ) {
					$formulas[] = (string) $pricing['formula'];
				}
			}
			$pricing = is_array( $field['pricing'] ?? null ) ? $field['pricing'] : [];
			if ( isset( $pricing['formula'] ) && is_scalar( $pricing['formula'] ) ) {
				$formulas[] = (string) $pricing['formula'];
			}
		}

		if ( $has_media ) {
			$warnings[] = 'media_files_not_included';
		}

		if ( self::has_product_target_ids( $data ) ) {
			$warnings[] = 'product_target_ids_may_not_match';
		}

		$local_lookup_tables = array_fill_keys( array_keys( is_array( $data['lookup_tables'] ?? null ) ? $data['lookup_tables'] : [] ), true );
		$local_variables = [];
		foreach ( array_keys( is_array( $data['formula_variables'] ?? null ) ? $data['formula_variables'] : [] ) as $name ) {
			$local_variables[ strtolower( (string) $name ) ] = true;
		}
		$external_lookup = false;
		$external_variables = false;
		$builtins = [ 'price', 'qty', 'addons', 'options_total', 'val' ];
		foreach ( $formulas as $formula ) {
			if ( preg_match_all( '/lookuptable\(\s*([a-zA-Z0-9_]+)/i', $formula, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					if ( ! isset( $local_lookup_tables[ $name ] ) ) {
						$external_lookup = true;
					}
				}
			}
			if ( preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_]*)\]/', $formula, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$name = strtolower( $name );
					if ( in_array( $name, $builtins, true ) || 'field' === $name || isset( $local_variables[ $name ] ) ) {
						continue;
					}
					$external_variables = true;
				}
			}
		}
		if ( $external_lookup ) {
			$warnings[] = 'site_lookup_tables_not_included';
		}
		if ( $external_variables ) {
			$warnings[] = 'site_formula_variables_not_included';
		}

		return $warnings;
	}

	private static function has_media_reference( array $value ): bool {
		foreach ( [ 'image', 'image_url' ] as $key ) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== $value[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	private static function has_product_target_ids( array $data ): bool {
		foreach ( is_array( $data['rule_groups'] ?? null ) ? $data['rule_groups'] : [] as $rule_group ) {
			foreach ( is_array( $rule_group['rules'] ?? null ) ? $rule_group['rules'] : [] as $rule ) {
				if ( is_array( $rule ) && 'product' === ( $rule['subject'] ?? '' ) && ! empty( $rule['terms'] ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
