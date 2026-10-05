<?php
/** Normalized product-local configuration for choice-driven product imagery. */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class LayeredImageConfig {
	/** @return array{enabled:bool,target:string,target_image_id:int,base_image_id:int,delay:bool,auto_scroll:bool,layers:array<int,array{group_id:int,field_id:string,choice:string,image_id:int}>} */
	public static function normalize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$target = in_array( $raw['target'] ?? '', [ 'main', 'gallery' ], true ) ? $raw['target'] : 'main';
		$target_image_id = 'gallery' === $target ? self::positive_id( $raw['target_image_id'] ?? 0 ) : 0;
		$base_image_id = self::positive_id( $raw['base_image_id'] ?? 0 );
		$layers = [];
		$seen = [];
		foreach ( array_slice( is_array( $raw['layers'] ?? null ) ? $raw['layers'] : [], 0, 200 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$group_id = self::positive_id( $row['group_id'] ?? 0 );
			$field_id = self::identifier( $row['field_id'] ?? '' );
			$choice = self::identifier( $row['choice'] ?? '' );
			$image_id = self::positive_id( $row['image_id'] ?? 0 );
			if ( ! $group_id || '' === $field_id || '' === $choice || ! $image_id ) {
				continue;
			}
			$key = $group_id . ':' . $field_id . ':' . $choice;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$layers[] = [ 'group_id' => $group_id, 'field_id' => $field_id, 'choice' => $choice, 'image_id' => $image_id ];
		}
		$enabled = ! empty( $raw['enabled'] ) && $base_image_id > 0 && ( 'main' === $target || $target_image_id > 0 );
		return [
			'enabled'         => $enabled,
			'target'          => $target,
			'target_image_id' => $target_image_id,
			'base_image_id'   => $base_image_id,
			'delay'           => ! empty( $raw['delay'] ),
			'auto_scroll'     => ! empty( $raw['auto_scroll'] ),
			'layers'          => $layers,
		];
	}

	private static function positive_id( $value ): int {
		return is_scalar( $value ) && preg_match( '/^[1-9][0-9]{0,9}$/D', (string) $value ) ? (int) $value : 0;
	}

	private static function identifier( $value ): string {
		return is_string( $value ) && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $value ) ? $value : '';
	}
}
