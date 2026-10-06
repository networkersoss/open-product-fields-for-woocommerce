<?php
/**
 * Disposable WordPress fixture for the text-swatch chip decoration.
 *
 * WAPF Extended 3.1.5's themed stylesheet (`assets/css/frontend-themed.min.css`)
 * applies `border: var(--apf-ts-border, none)`, `color: var(--apf-ts-color,
 * inherit)`, `background: var(--apf-ts-bg, transparent)` and
 * `border-radius: var(--apf-ts-radius, 4px)` to `.wapf-swatch--text`; the
 * `--apf-ts-*` values come from the migrated `wapf_design_settings` option
 * (`includes/classes/class-design-helper.php:1514,1535-1536`).
 *
 * The fixture renders one OPF single-select text swatch and one multi-choice text
 * swatch (both are `.wapf-swatch--text` chips in WAPF:
 * `views/frontend/fields/text-swatch.php:17` and
 * `views/frontend/fields/multi-text-swatch.php:18`) plus one OPF checkbox group
 * and one radio group as the regression controls (OPF puts `.opf-swatch--text`
 * on plain checkbox/radio choices too, WAPF keeps those on
 * `.wapf-checkbox`/`.wapf-radio`).
 *
 * Usage: wp --path=<disposable clone> eval-file this-file <phase>
 *   prepare                 create the product + field group, remember the option state
 *   design <json|empty|unset>
 *                           set the migrated WAPF design settings (`empty` saves an
 *                           empty array, the saved-but-empty option)
 *   radius <px|unset>       set the OPF text-swatch radius option
 *   verify                  assert the served fixture exists
 *   cleanup                 delete the fixture and restore the option state
 */

use OPF\Service\FieldGroups;

$root  = realpath( ABSPATH );
$state = sys_get_temp_dir() . '/opf-text-swatch-chip-evidence/state.json';
$assert = static function ( string $label, bool $ok ): void {
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( false === $root || '/home/followersya-5hqi7/opf-test/wordpress' !== $root ) {
	throw new RuntimeException( 'Refusing to run outside the disposable text-swatch chip clone.' );
}

$phase = $args[0] ?? '';

if ( 'prepare' === $phase ) {
	$assert( 'fixture state does not already exist', ! file_exists( $state ) );

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF Text Swatch Chip' );
	$product->set_slug( 'opf-text-swatch-chip' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product_id = (int) $product->save();

	$group_id = FieldGroups::save(
		0,
		[
			'fields' => [
				[
					'id'           => 'finish',
					'label'        => 'Finish',
					'type'         => 'swatch',
					'swatch_style' => 'text',
					'choices'      => [
						[ 'slug' => 'matte', 'label' => 'Matte' ],
						[ 'slug' => 'gloss', 'label' => 'Gloss' ],
					],
				],
				[
					// Multi-choice text swatch: WAPF renders it as
					// `views/frontend/fields/multi-text-swatch.php:18` — the same
					// `.wapf-swatch--text` chip with a checkbox input, so the hover and
					// selected rules must cover it too (no `wapf-single-select`).
					'id'           => 'finish_multi',
					'label'        => 'Finishes',
					'type'         => 'swatch',
					'swatch_style' => 'text',
					'multiple'     => true,
					'choices'      => [
						[ 'slug' => 'matte', 'label' => 'Matte' ],
						[ 'slug' => 'gloss', 'label' => 'Gloss' ],
					],
				],
				[
					// Regression control: WAPF renders plain checkbox choices as
					// `.wapf-checkbox`, never as `.wapf-swatch--text`, so the chip
					// decoration must not reach them.
					'id'      => 'extras',
					'label'   => 'Extras',
					'type'    => 'checkbox',
					'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ],
				],
				[
					// Same regression control for radios (`views/frontend/fields/radio.php:14`
					// uses `.wapf-radio`, not `.wapf-swatch--text`).
					'id'      => 'size',
					'label'   => 'Size',
					'type'    => 'radio',
					'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ] ],
				],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ],
			],
		],
		[ 'title' => 'Text swatch chip fixture', 'status' => 'publish' ]
	);
	$assert( 'product and field group created', $product_id > 0 && $group_id > 0 );

	if ( ! is_dir( dirname( $state ) ) && ! mkdir( dirname( $state ), 0700, true ) ) {
		throw new RuntimeException( 'Could not create the isolated evidence directory.' );
	}
	file_put_contents(
		$state,
		wp_json_encode(
			[
				'product_id'     => $product_id,
				'group_id'       => $group_id,
				'radius_present' => false !== get_option( 'opf_text_swatch_radius', false ),
				'radius_value'   => get_option( 'opf_text_swatch_radius', null ),
				'design_present' => false !== get_option( 'wapf_design_settings', false ),
				'design_value'   => get_option( 'wapf_design_settings', null ),
			],
			JSON_PRETTY_PRINT
		)
	);
	echo 'TEXT_SWATCH_CHIP_URL=' . get_permalink( $product_id ) . PHP_EOL;
	echo "SUCCESS prepare\n";
	return;
}

$assert( 'fixture state exists', file_exists( $state ) );
$data = json_decode( (string) file_get_contents( $state ), true );

if ( 'design' === $phase ) {
	$raw = (string) ( $args[1] ?? 'unset' );
	if ( 'unset' === $raw ) {
		delete_option( 'wapf_design_settings' );
	} elseif ( 'empty' === $raw ) {
		// WAPF's regime decision is `empty( get_option( 'wapf_design_settings',
		// false ) )` (class-design-helper.php:1139-1144), so a *saved* but empty
		// array must serve the default stylesheet exactly like a missing option.
		update_option( 'wapf_design_settings', [] );
	} else {
		$design = json_decode( $raw, true );
		$assert( 'design payload is a JSON object', is_array( $design ) && $design );
		update_option( 'wapf_design_settings', $design );
	}
	echo 'SUCCESS design ' . $raw . PHP_EOL;
	return;
}

if ( 'radius' === $phase ) {
	$value = (string) ( $args[1] ?? 'unset' );
	if ( 'unset' === $value ) {
		delete_option( 'opf_text_swatch_radius' );
	} else {
		update_option( 'opf_text_swatch_radius', $value, false );
	}
	echo 'SUCCESS radius ' . $value . PHP_EOL;
	return;
}

$assert( 'fixture product and group exist', get_post( (int) $data['product_id'] ) && get_post( (int) $data['group_id'] ) );

if ( 'verify' === $phase ) {
	$group = FieldGroups::group_from_post( get_post( (int) $data['group_id'] ) );
	$assert( 'fixture group resolves its text swatch', null !== $group && 'swatch' === ( $group->data['fields'][0]['type'] ?? '' ) );
	echo 'SUCCESS verify' . PHP_EOL;
	return;
}

if ( 'cleanup' === $phase ) {
	wp_delete_post( (int) $data['product_id'], true );
	wp_delete_post( (int) $data['group_id'], true );
	FieldGroups::flush_cache();
	$assert( 'product and field group removed', ! get_post( (int) $data['product_id'] ) && ! get_post( (int) $data['group_id'] ) );

	if ( ! empty( $data['radius_present'] ) ) {
		update_option( 'opf_text_swatch_radius', $data['radius_value'], false );
	} else {
		delete_option( 'opf_text_swatch_radius' );
	}
	if ( ! empty( $data['design_present'] ) ) {
		update_option( 'wapf_design_settings', $data['design_value'] );
	} else {
		delete_option( 'wapf_design_settings' );
	}

	unlink( $state );
	$assert( 'fixture state removed', ! file_exists( $state ) );
	echo "SUCCESS cleanup\n";
	return;
}

throw new RuntimeException( 'Expected prepare, design, radius, verify, or cleanup.' );
