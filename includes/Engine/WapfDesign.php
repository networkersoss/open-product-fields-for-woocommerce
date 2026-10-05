<?php
/**
 * Reproduces WAPF Extended's global design layer for OPF markup.
 *
 * WAPF stores flat, sanitized design keys in the `wapf_design_settings`
 * option and turns them into a `:root` CSS-variable block plus control skins
 * (Design_Helper::design_settings_to_variables_css, class-design-helper.php).
 * OPF consumes the same option so theme/add-on CSS written against `--apf-*`
 * variables and the `.wapf-custom` checkbox/radio skin keeps working after a
 * migration. Scope: the generic `--apf-*` variables and the checkbox/radio
 * `.wapf-custom` skins the styled-choice row covers; WAPF's datepicker /
 * select-arrow / card-icon fragments target WAPF-specific markup OPF does not
 * emit.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class WapfDesign {

	/**
	 * Flat keys WAPF emits as `:root` variables (class-design-helper.php:1558).
	 */
	private const VARIABLE_KEYS = [
		'apf-tooltip-bg', 'apf-tooltip-color', 'apf-margin-bottom',
		'apf-input-border-color', 'apf-input-border-color-foc', 'apf-input-height', 'apf-input-bg', 'apf-input-color',
		'apf-radius', 'apf-tooltip-icon', 'apf-label-color', 'apf-label-size', 'apf-label-weight',
		'apf-ts-radius', 'apf-ts-color', 'apf-ts-color-hov', 'apf-ts-color-sel', 'apf-ts-bg', 'apf-ts-bg-hov', 'apf-ts-bg-sel',
		'apf-ts-border-color-hov', 'apf-ts-border-color-sel',
		'apf-ns-width', 'apf-ns-color', 'apf-ns-bg',
		'apf-is-radius', 'apf-is-border-color-sel', 'apf-is-border-color-hov', 'apf-is-color', 'apf-is-color-hov',
		'apf-is-color-sel', 'apf-is-bg', 'apf-is-bg-hov', 'apf-is-bg-sel', 'apf-cs-select', 'apf-cs-select-hov', 'apf-cs-select-sel',
		'apf-progress-bg', 'apf-progress-color', 'apf-file-bg', 'apf-file-border', 'apf-file-color',
		'apf-cs-border-color-hov', 'apf-cs-border-color-sel',
		'apf-iq-gap', 'apf-iq-img-radius',
		'apf-card-shadow', 'apf-card-bg', 'apf-card-bg-hov', 'apf-card-bg-sel', 'apf-card-radius',
		'apf-card-border-color-hov', 'apf-card-border-color-sel', 'apf-card-color', 'apf-card-color-hov', 'apf-card-color-sel',
		'apf-cq-bg', 'apf-cq-color', 'apf-cq-radius', 'apf-cq-shadow', 'apf-cq-border', 'apf-cqns-bg',
		'apf-date-color', 'apf-date-color-hov', 'apf-date-color-sel', 'apf-date-bg', 'apf-date-bg-hov', 'apf-date-bg-sel',
	];

	/** Current `wapf_design_settings` option as an array (empty when unset). */
	public static function settings(): array {
		$settings = get_option( 'wapf_design_settings', [] );
		return is_array( $settings ) ? $settings : [];
	}

	/** Whether WAPF's styled skin is enabled for a native control. */
	public static function styled( string $control ): bool {
		return 'styled' === (string) ( self::settings()[ 'apf-' . $control . '-display' ] ?? '' );
	}

	/** The complete `:root` + checkbox/radio skin CSS, or '' when unconfigured. */
	public static function css(): string {
		$settings = self::settings();
		if ( ! $settings ) {
			return '';
		}

		$vars = [];
		foreach ( self::VARIABLE_KEYS as $key ) {
			$value = self::safe_value( $settings[ $key ] ?? null );
			if ( null !== $value ) {
				$vars[] = '--' . $key . ':' . $value;
			}
		}
		$vars[] = '--apf-input-border:' . self::border( $settings['apf-input-border-width'] ?? null, $settings['apf-input-border-color'] ?? null );
		$vars[] = '--apf-ts-border:' . self::border( $settings['apf-ts-border-width'] ?? null, $settings['apf-ts-border-color'] ?? null );
		$vars[] = '--apf-is-border:' . self::border( $settings['apf-is-border-width'] ?? null, $settings['apf-is-border-color'] ?? null );
		$vars[] = '--apf-cs-border:' . self::border( $settings['apf-cs-border-width'] ?? null, $settings['apf-cs-border-color'] ?? null );
		$vars[] = '--apf-card-border:' . self::border( $settings['apf-card-border-width'] ?? null, $settings['apf-card-border-color'] ?? null );
		$vars[] = '--apf-date-border-color:' . self::matching_border_color( (string) ( $settings['apf-date-bg'] ?? '#ffffff' ) );
		$vars[] = '--apf-date-color-muted:' . self::hex_to_rgba( self::svg_hex( $settings['apf-date-color'] ?? '#212121' ), 0.45 );

		$checkbox_style = '';
		if ( self::styled( 'cb' ) ) {
			foreach ( [ 'apf-cb-radius', 'apf-cb-bg', 'apf-cb-bg-hov', 'apf-cb-bg-sel', 'apf-cb-border-color-hov', 'apf-cb-border-color-sel' ] as $key ) {
				$value = self::safe_value( $settings[ $key ] ?? null );
				if ( null !== $value ) {
					$vars[] = '--' . $key . ':' . $value;
				}
			}
			$vars[] = '--apf-cb-border:' . self::border( $settings['apf-cb-border-width'] ?? null, $settings['apf-cb-border-color'] ?? null );
			$checkbox_style = self::checkbox_skin( self::svg_hex( $settings['apf-cb-tick-color-sel'] ?? '#000000' ) );
		}

		$radio_style = '';
		if ( self::styled( 'radio' ) ) {
			foreach ( [ 'apf-radio-bg', 'apf-radio-bg-hov', 'apf-radio-bg-sel', 'apf-radio-border-color-hov', 'apf-radio-border-color-sel' ] as $key ) {
				$value = self::safe_value( $settings[ $key ] ?? null );
				if ( null !== $value ) {
					$vars[] = '--' . $key . ':' . $value;
				}
			}
			$vars[] = '--apf-radio-border:' . self::border( $settings['apf-radio-border-width'] ?? null, $settings['apf-radio-border-color'] ?? null );
			$radio_style = self::radio_skin( self::svg_hex( $settings['apf-radio-tick-color-sel'] ?? '#000000' ) );
		}

		return ':root{' . implode( ';', $vars ) . '}' . $checkbox_style . $radio_style;
	}

	/** CSS for the `.wapf-custom` checkbox skin (class-design-helper.php get_checkbox_style_css). */
	private static function checkbox_skin( string $tick ): string {
		return '.wapf-checkbox input[type=checkbox]{position:absolute;opacity:0;width:1px;height:1px;padding:0;}'
			. '.wapf-checkbox .wapf-custom{min-height:16px;min-width:16px;height:1.1em;width:1.1em;position:relative;display:inline-block;background:var(--apf-cb-bg,transparent);border-radius:var(--apf-cb-radius,0);border:var(--apf-cb-border,none);}'
			. '.wapf-checkbox .wapf-input-label:hover .wapf-custom{background-color:var(--apf-cb-bg-hov,transparent);border-color:var(--apf-cb-border-color-hov,transparent);}'
			. '.wapf-checkbox input[type=checkbox]:checked+.wapf-custom{background-color:var(--apf-cb-bg-sel,transparent);border-color:var(--apf-cb-border-color-sel,transparent);}'
			. '.wapf-checkbox input:checked+.wapf-custom:after{position:absolute;top:0;left:0;bottom:0;right:0;content:\'\';background:no-repeat center center url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 45.701 45.7\'%3E%3Cpath fill=\'%23' . $tick . '\' d=\'M20.687 38.332a5.308 5.308 0 0 1-7.505 0L1.554 26.704A5.306 5.306 0 1 1 9.059 19.2l6.928 6.927a1.344 1.344 0 0 0 1.896 0L36.642 7.368a5.308 5.308 0 0 1 7.505 7.504l-23.46 23.46z\'/%3E%3C/svg%3E");background-size:.58em;}';
	}

	/** CSS for the `.wapf-custom` radio skin (class-design-helper.php get_radio_style_css). */
	private static function radio_skin( string $tick ): string {
		return '.wapf-radio input[type=radio]{position:absolute;opacity:0;width:1px;height:1px;padding:0;}'
			. '.wapf-radio .wapf-custom{min-height:16px;min-width:16px;height:1.1em;width:1.1em;position:relative;display:inline-block;background:var(--apf-radio-bg,transparent);border-radius:50px;border:var(--apf-radio-border,none);}'
			. '.wapf-radio .wapf-input-label:hover .wapf-custom{background-color:var(--apf-radio-bg-hov,transparent);border-color:var(--apf-radio-border-color-hov,transparent);}'
			. '.wapf-radio input[type=radio]:checked+.wapf-custom{background-color:var(--apf-radio-bg-sel,transparent);border-color:var(--apf-radio-border-color-sel,transparent);}'
			. '.wapf-radio input:checked+.wapf-custom:after{content:\'\';position:absolute;top:0;left:0;width:100%;height:100%;background:no-repeat center url("data:image/svg+xml,%3Csvg viewBox=\'0 0 48 48\' fill=\'%23' . $tick . '\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Ccircle cx=\'24\' cy=\'24\' r=\'24\'/%3E%3C/svg%3E");background-size:.42em;}';
	}

	/**
	 * Sanitize one stored design value before it enters a stylesheet. The
	 * option is normally written through WAPF's own sanitizer; this rejects
	 * any value that could break out of the declaration or rule.
	 */
	private static function safe_value( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 200 ) {
			return null;
		}
		if ( 0 === strpos( $value, 'setting:' ) ) {
			$id = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', substr( $value, 8 ) );
			return '' === $id ? null : 'var(--' . $id . ')';
		}
		if ( preg_match( '/[<>{};\\\\"\'`]/', $value ) ) {
			return null;
		}
		return $value;
	}

	/** Build `Npx solid COLOR` or `none`, mirroring Design_Helper::create_border. */
	private static function border( $width, $color ): string {
		$width = self::safe_value( $width );
		$color = self::safe_value( $color );
		if ( null === $width || null === $color ) {
			return 'none';
		}
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em|%|pt)$/', $width, $match ) || (int) $match[1] < 1 ) {
			return 'none';
		}
		return (int) $match[1] . 'px solid ' . $color;
	}

	/** Return a `#rrggbb` suitable for an SVG fill; unparseable values fall back to black. */
	private static function svg_hex( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
			$hex = ltrim( $value, '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return strtolower( $hex );
		}
		return '000000';
	}

	/** Lighten/darken a hex by 34 steps toward the opposite extreme (Design_Helper). */
	private static function matching_border_color( string $hex ): string {
		if ( 0 !== strpos( $hex, '#' ) ) {
			$hex = '#ffffff';
		}
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			$hex = 'ffffff';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$brightness = ( ( $r * 299 ) + ( $g * 587 ) + ( $b * 114 ) ) / 1000;
		if ( $brightness > 128 ) {
			$r = max( $r - 34, 0 );
			$g = max( $g - 34, 0 );
			$b = max( $b - 34, 0 );
		} else {
			$r = min( $r + 34, 255 );
			$g = min( $g + 34, 255 );
			$b = min( $b + 34, 255 );
		}
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/** Hex string to `rgba(r,g,b,opacity)`; falls back to black. */
	private static function hex_to_rgba( string $hex, float $opacity ): string {
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( sprintf( '%.2f', $opacity ), '0' ), '.' ) );
	}
}
