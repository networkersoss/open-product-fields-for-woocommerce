<?php
/** Bounded schema for product-local live image previews. */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class ProductPreview {
	private const MAX_ITEMS = 20;
	private const DYNAMIC_PROPERTIES = [ 'color', 'font_family', 'font_size', 'alignment' ];

	/** Normalize a product's preview configuration; malformed items are discarded. */
	public static function normalize( $raw_items ): array {
		if ( ! is_array( $raw_items ) ) {
			return [];
		}

		$items = [];
		foreach ( array_slice( array_values( $raw_items ), 0, self::MAX_ITEMS ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$id = self::identifier( $raw['id'] ?? '' );
			$group_id = self::positive_id( $raw['group_id'] ?? 0 );
			$field_id = self::identifier( $raw['field_id'] ?? '' );
			$source = in_array( $raw['source'] ?? '', [ 'text', 'upload' ], true ) ? $raw['source'] : '';
			$target = in_array( $raw['target'] ?? '', [ 'main', 'gallery' ], true ) ? $raw['target'] : '';
			$box = self::normalize_box( $raw['box'] ?? null );
			if ( '' === $id || ! $group_id || '' === $field_id || '' === $source || '' === $target || null === $box ) {
				continue;
			}

			$image_id = 'gallery' === $target ? self::positive_id( $raw['image_id'] ?? 0 ) : 0;
			if ( 'gallery' === $target && ! $image_id ) {
				continue;
			}
			$item = [
				'id'       => $id,
				'group_id' => $group_id,
				'field_id' => $field_id,
				'source'   => $source,
				'target'   => $target,
				'image_id' => $image_id,
				'box'      => $box,
			];
			if ( 'text' === $source ) {
				$item['text'] = self::normalize_text_style( $raw['text'] ?? [] );
				$item['dynamic'] = self::normalize_dynamic( $raw['dynamic'] ?? [] );
			} else {
				$item['image'] = self::normalize_image_style( $raw['image'] ?? [] );
			}
			$items[] = $item;
		}
		return $items;
	}

	/** @return array{x:float,y:float,width:float,height:float}|null */
	private static function normalize_box( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$box = [];
		foreach ( [ 'x', 'y', 'width', 'height' ] as $key ) {
			$value = $raw[ $key ] ?? null;
			if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
				return null;
			}
			$box[ $key ] = round( (float) $value, 3 );
		}
		if ( $box['x'] < 0 || $box['y'] < 0 || $box['width'] <= 0 || $box['height'] <= 0 || $box['x'] + $box['width'] > 100 || $box['y'] + $box['height'] > 100 ) {
			return null;
		}
		return $box;
	}

	/** @return array<string,mixed> */
	private static function normalize_text_style( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$color = self::color( $raw['color'] ?? '' );
		$weight = $raw['font_weight'] ?? 'normal';
		if ( ! in_array( $weight, [ 'normal', 'bold', 100, 200, 300, 400, 500, 600, 700, 800, 900, '100', '200', '300', '400', '500', '600', '700', '800', '900' ], true ) ) {
			$weight = 'normal';
		}
		$style = in_array( $raw['font_style'] ?? '', [ 'normal', 'italic' ], true ) ? $raw['font_style'] : 'normal';
		return [
			'color'             => $color ?: '#000000',
			'font_family'       => self::font_family( $raw['font_family'] ?? '' ) ?: 'Arial, sans-serif',
			'font_size'         => self::bounded_int( $raw['font_size'] ?? 24, 1, 256, 24 ),
			'mobile_font_size'  => self::bounded_int( $raw['mobile_font_size'] ?? 18, 1, 256, 18 ),
			'font_weight'       => is_numeric( $weight ) ? (int) $weight : $weight,
			'font_style'        => $style,
			'alignment'         => self::alignment( $raw['alignment'] ?? '' ),
			'mobile_alignment'  => self::alignment( $raw['mobile_alignment'] ?? '' ),
		];
	}

	/** @return array<string,array{field_id:string,values:array<string,mixed>,default:mixed}> */
	private static function normalize_dynamic( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$dynamic = [];
		foreach ( self::DYNAMIC_PROPERTIES as $property ) {
			$config = $raw[ $property ] ?? null;
			if ( ! is_array( $config ) ) {
				continue;
			}
			$field_id = self::identifier( $config['field_id'] ?? '' );
			if ( '' === $field_id ) {
				continue;
			}
			$values = [];
			if ( is_array( $config['values'] ?? null ) ) {
				foreach ( array_slice( $config['values'], 0, 50, true ) as $choice => $value ) {
					if ( ! is_scalar( $choice ) ) {
						continue;
					}
					$normalized = self::dynamic_value( $property, $value );
					if ( null !== $normalized ) {
						$values[ substr( (string) $choice, 0, 80 ) ] = $normalized;
					}
				}
			}
			$dynamic[ $property ] = [
				'field_id' => $field_id,
				'values'   => $values,
				'default'  => self::dynamic_value( $property, $config['default'] ?? null ),
			];
		}
		return $dynamic;
	}

	private static function dynamic_value( string $property, $value ) {
		if ( 'color' === $property ) {
			return self::color( $value ) ?: null;
		}
		if ( 'font_size' === $property ) {
			return is_numeric( $value ) && (float) $value >= 1 && (float) $value <= 256 ? (int) $value : null;
		}
		if ( 'alignment' === $property ) {
			return in_array( $value, [ 'left', 'center', 'right' ], true ) ? $value : null;
		}
		return self::font_family( $value ) ?: null;
	}

	/** @return array{shape:string,fit:string} */
	private static function normalize_image_style( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'shape' => in_array( $raw['shape'] ?? '', [ 'rectangle', 'oval' ], true ) ? $raw['shape'] : 'rectangle',
			'fit'   => in_array( $raw['fit'] ?? '', [ 'fill', 'contain' ], true ) ? $raw['fit'] : 'fill',
		];
	}

	private static function identifier( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $value ) ) {
			return '';
		}
		return $value;
	}

	private static function positive_id( $value ): int {
		return is_scalar( $value ) && preg_match( '/^[1-9][0-9]{0,9}$/D', (string) $value ) ? (int) $value : 0;
	}

	private static function color( $value ): string {
		return is_string( $value ) && preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $value ) ? strtolower( $value ) : '';
	}

	private static function font_family( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( preg_replace( '/[;{}<>\x00-\x1f]/', '', $value ) );
		return '' !== $value && strlen( $value ) <= 120 ? $value : '';
	}

	private static function bounded_int( $value, int $minimum, int $maximum, int $fallback ): int {
		return is_numeric( $value ) && (float) $value >= $minimum && (float) $value <= $maximum ? (int) $value : $fallback;
	}

	private static function alignment( $value ): string {
		return in_array( $value, [ 'left', 'center', 'right' ], true ) ? $value : 'center';
	}
}
