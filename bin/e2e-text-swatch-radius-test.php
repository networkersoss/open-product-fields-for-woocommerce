<?php
/**
 * Disposable WordPress fixture for the text-swatch corner radius.
 *
 * WAPF Extended 3.1.5 keeps the text-swatch corner radius in one design
 * setting (`apf-ts-radius`, includes/classes/class-design-helper.php:521) and
 * its themed stylesheet applies it as
 * `border-radius: var(--apf-ts-radius, 4px)` on `.wapf-swatch--text`.
 *
 * Usage: wp --path=<disposable clone> eval-file this-file <phase>
 *   prepare                 create the product + field group, remember the option state
 *   configure <px|unset>    set the OPF radius option
 *   migrate <px|unset>      set the imported WAPF design setting `apf-ts-radius`
 *   verify                  assert the served markup + computed radius contract
 *   cleanup                 delete the fixture and restore the option state
 */

use OPF\Service\Admin\Settings;
use OPF\Service\FieldGroups;

$root  = realpath( ABSPATH );
$state = sys_get_temp_dir() . '/opf-text-swatch-radius-evidence/state.json';
$assert = static function ( string $label, bool $ok ): void {
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( false === $root || '/home/followersya-5hqi7/opf-test/wordpress' !== $root ) {
	throw new RuntimeException( 'Refusing to run outside the disposable text-swatch clone.' );
}

$phase = $args[0] ?? '';

if ( 'prepare' === $phase ) {
	$assert( 'fixture state does not already exist', ! file_exists( $state ) );

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF Text Swatch Radius' );
	$product->set_slug( 'opf-text-swatch-radius' );
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
					'id' => 'finish',
					'label' => 'Finish',
					'type' => 'swatch',
					'swatch_style' => 'text',
					'choices' => [
						[ 'slug' => 'matte', 'label' => 'Matte' ],
						[ 'slug' => 'gloss', 'label' => 'Gloss' ],
					],
				],
				[
					// Regression control: WAPF renders plain checkbox choices as
					// `.wapf-checkbox`, never as `.wapf-swatch--text`, so the
					// text-swatch radius must not reach them.
					'id' => 'extras',
					'label' => 'Extras',
					'type' => 'checkbox',
					'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ],
				],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ],
			],
		],
		[ 'title' => 'Text swatch radius fixture', 'status' => 'publish' ]
	);
	$assert( 'product and field group created', $product_id > 0 && $group_id > 0 );

	if ( ! is_dir( dirname( $state ) ) && ! mkdir( dirname( $state ), 0700, true ) ) {
		throw new RuntimeException( 'Could not create the isolated evidence directory.' );
	}
	file_put_contents(
		$state,
		wp_json_encode(
			[
				'product_id'             => $product_id,
				'group_id'               => $group_id,
				'option_present'         => false !== get_option( 'opf_text_swatch_radius', false ),
				'option_value'           => get_option( 'opf_text_swatch_radius', null ),
				'design_present'         => false !== get_option( 'wapf_design_settings', false ),
				'design_value'           => get_option( 'wapf_design_settings', null ),
			],
			JSON_PRETTY_PRINT
		)
	);
	echo 'TEXT_SWATCH_URL=' . get_permalink( $product_id ) . PHP_EOL;
	echo "SUCCESS prepare\n";
	return;
}

$assert( 'fixture state exists', file_exists( $state ) );
$data = json_decode( (string) file_get_contents( $state ), true );

if ( 'configure' === $phase ) {
	$value = $args[1] ?? '';
	if ( 'unset' === $value ) {
		delete_option( 'opf_text_swatch_radius' );
	} else {
		update_option( 'opf_text_swatch_radius', (string) $value, false );
	}
	echo 'SUCCESS configure ' . $value . PHP_EOL;
	return;
}

if ( 'migrate' === $phase ) {
	$value   = $args[1] ?? '';
	$design  = get_option( 'wapf_design_settings', [] );
	$design  = is_array( $design ) ? $design : [];
	if ( 'unset' === $value ) {
		unset( $design['apf-ts-radius'] );
	} else {
		$design['apf-ts-radius'] = (string) $value;
	}
	if ( $design ) {
		update_option( 'wapf_design_settings', $design );
	} else {
		delete_option( 'wapf_design_settings' );
	}
	echo 'SUCCESS migrate ' . $value . PHP_EOL;
	return;
}

$assert( 'fixture product and group exist', get_post( (int) $data['product_id'] ) && get_post( (int) $data['group_id'] ) );

if ( 'save' === $phase ) {
	// Real WooCommerce settings save: the Products > Product fields form posts
	// every field, so the fixture posts the current values plus the radius under
	// test and lets WC_Admin_Settings::save_fields() run the registered
	// sanitizer and option write.
	$raw      = (string) ( $args[1] ?? '' );
	$expected = (int) ( $args[2] ?? 0 );
	$settings = Settings::product_fields_settings( [], 'opf_product_fields' );
	$post     = [];
	foreach ( $settings as $setting ) {
		$id = $setting['id'] ?? '';
		if ( '' === $id || in_array( $setting['type'] ?? '', [ 'title', 'sectionend' ], true ) ) {
			continue;
		}
		$current = get_option( $id, $setting['default'] ?? '' );
		$post[ $id ] = is_scalar( $current ) ? (string) $current : '';
	}
	$post['opf_text_swatch_radius'] = $raw;
	\WC_Admin_Settings::save_fields( $settings, $post );
	$assert( 'WooCommerce settings save stored the clamped radius', Settings::text_swatch_radius() === $expected );
	$assert( 'the stored option is the clamped value, not the posted string', (string) get_option( 'opf_text_swatch_radius' ) === (string) $expected );
	echo 'SUCCESS save raw=' . $raw . ' stored=' . $expected . PHP_EOL;
	return;
}

if ( 'verify' === $phase ) {
	$requested = $args[1] ?? '';
	$expected  = 'unset' === $requested ? null : (int) $requested;
	$assert( 'radius option reads back as configured', Settings::text_swatch_radius() === $expected );

	$group = FieldGroups::group_from_post( get_post( (int) $data['group_id'] ) );
	$assert( 'fixture group resolves its text swatch', null !== $group && 'swatch' === ( $group->data['fields'][0]['type'] ?? '' ) );
	$assert( 'text swatch normalized as the text style', 'text' === ( $group->data['fields'][0]['swatch_style'] ?? '' ) );

	// Settings exposure: render the real WooCommerce settings form fields.
	ob_start();
	\WC_Admin_Settings::output_fields( Settings::product_fields_settings( [], 'opf_product_fields' ) );
	$form = (string) ob_get_clean();
	$assert( 'settings form renders the text swatch corner radius control', false !== strpos( $form, 'id="opf_text_swatch_radius"' ) );
	$assert( 'settings control is a bounded number input', 1 === preg_match( '/id="opf_text_swatch_radius"[^>]*type="number"[^>]*min="0"[^>]*max="50"/', $form ) );
	$assert(
		'settings control shows the stored radius',
		1 === preg_match( '/id="opf_text_swatch_radius"[^>]*value="' . ( null === $expected ? '4' : (string) $expected ) . '"/', $form )
	);
	echo 'SUCCESS verify ' . ( null === $expected ? 'unset' : (string) $expected ) . PHP_EOL;
	return;
}

if ( 'cleanup' === $phase ) {
	if ( WC()->cart ) {
		WC()->cart->empty_cart( true );
	}
	wp_delete_post( (int) $data['product_id'], true );
	wp_delete_post( (int) $data['group_id'], true );
	FieldGroups::flush_cache();
	$assert( 'product and field group removed', ! get_post( (int) $data['product_id'] ) && ! get_post( (int) $data['group_id'] ) );

	if ( ! empty( $data['option_present'] ) ) {
		update_option( 'opf_text_swatch_radius', $data['option_value'], false );
	} else {
		delete_option( 'opf_text_swatch_radius' );
	}
	if ( ! empty( $data['design_present'] ) ) {
		update_option( 'wapf_design_settings', $data['design_value'] );
	} else {
		delete_option( 'wapf_design_settings' );
	}
	$restored = ! empty( $data['option_present'] ) ? (string) $data['option_value'] : null;
	$assert(
		'radius option restored',
		( null === $restored && null === Settings::text_swatch_radius() )
			|| ( null !== $restored && (int) $restored === Settings::text_swatch_radius() )
	);

	unlink( $state );
	$assert( 'fixture state removed', ! file_exists( $state ) );
	echo "SUCCESS cleanup\n";
	return;
}

throw new RuntimeException( 'Expected prepare, configure, migrate, save, verify, or cleanup.' );
