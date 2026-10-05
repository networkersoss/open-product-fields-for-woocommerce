<?php
/**
 * Field group builder: metaboxes on the opf_field_group edit screen.
 *
 * Field editing uses vanilla JS and REST; product selectors reuse WooCommerce's
 * native admin search. The field-group model is a JSON document edited
 * through the UI and persisted via POST /opf/v1/groups.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service\Admin;

defined( 'ABSPATH' ) || exit;

final class Builder {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'disable_block_editor' ], 10, 2 );
	}

	/**
	 * Keep the builder out of the block editor.
	 *
	 * @param bool   $use       Whether block editor is used.
	 * @param string $post_type Post type.
	 */
	public static function disable_block_editor( bool $use, string $post_type ): bool {
		return 'opf_field_group' === $post_type ? false : $use;
	}

	/**
	 * Add metaboxes.
	 */
	public static function add_meta_boxes(): void {
		add_meta_box( 'opf-builder', __( 'Fields', 'open-product-fields-for-woocommerce' ), [ __CLASS__, 'render_builder' ], 'opf_field_group', 'normal', 'high' );
		add_meta_box( 'opf-placement', __( 'Placement', 'open-product-fields-for-woocommerce' ), [ __CLASS__, 'render_placement' ], 'opf_field_group', 'side', 'high' );
	}

	/**
	 * Builder metabox.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_builder( \WP_Post $post ): void {
		$group = \OPF\Service\FieldGroups::group_from_post( $post );
		$model = $group ? $group->data : [ 'schema' => \OPF\Engine\FieldGroup::SCHEMA, 'fields' => [], 'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above' ];
		$cat_terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
		$product_cats = [];
		if ( ! is_wp_error( $cat_terms ) ) {
			foreach ( (array) $cat_terms as $term ) {
				$product_cats[] = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name ];
			}
		}
		?>
		<div id="opf-builder-app"
			data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-model="<?php echo esc_attr( (string) wp_json_encode( $model, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ); ?>"
			data-product-cats="<?php echo esc_attr( (string) wp_json_encode( $product_cats, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-rest="<?php echo esc_url( esc_url_raw( rest_url( 'opf/v1/groups' ) ) ); ?>"
			data-preview-rest="<?php echo esc_url( esc_url_raw( rest_url( 'opf/v1/preview' ) ) ); ?>">
			<noscript><?php esc_html_e( 'The field builder requires JavaScript.', 'open-product-fields-for-woocommerce' ); ?></noscript>
		</div>
		<?php
	}

	/**
	 * Placement metabox (terms the group applies to). Server-rendered
	 * multi-selects; empty selection = every product.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_placement( \WP_Post $post ): void {
		$group      = \OPF\Service\FieldGroups::group_from_post( $post );
		$cat_terms  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
		$tag_terms  = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => 500 ] );
		$roles      = function_exists( 'get_editable_roles' ) ? get_editable_roles() : [];
		$languages  = self::language_options();
		$types      = function_exists( 'wc_get_product_types' ) ? wc_get_product_types() : [];
		$attribute_options = [];
		$variation_attribute_options = [];
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute_taxonomy ) {
				$taxonomy = 'pa_' . $attribute_taxonomy->attribute_name;
				// WAPF `patts` terms are `attr|slug` (attr without `pa_`), plus
				// the `attr|*` wildcard meaning "any value of this attribute".
				$variation_attribute_options[] = [
					'value' => $attribute_taxonomy->attribute_name . '|*',
					/* translators: %s: attribute label. */
					'label' => sprintf( __( '%s: any value', 'open-product-fields-for-woocommerce' ), (string) $attribute_taxonomy->attribute_label ),
				];
				foreach ( (array) get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200 ] ) as $term ) {
					$attribute_options[] = [ 'value' => $taxonomy . ':' . $term->term_id, 'label' => (string) $attribute_taxonomy->attribute_label . ': ' . $term->name ];
					$variation_attribute_options[] = [ 'value' => $attribute_taxonomy->attribute_name . '|' . $term->slug, 'label' => (string) $attribute_taxonomy->attribute_label . ': ' . $term->name ];
				}
			}
		}

		$selected = [
			'product'         => [],
			'product_not'     => [],
			'product_var'     => [],
			'product_var_not' => [],
			'var_att'         => [],
			'var_att_not'     => [],
			'product_cat'     => [],
			'product_cat_not' => [],
			'product_tag'     => [],
			'product_tag_not' => [],
			'product_type'    => [],
			'product_type_not' => [],
			'attributes'      => [],
			'attributes_not'  => [],
			'user_auth'       => '',
			'user_role'       => [],
			'user_role_not'   => [],
			'user_language'   => '',
			'user_language_op' => 'in',
		];
		if ( $group ) {
			foreach ( $group->data['rule_groups'] as $rule_group ) {
				foreach ( $rule_group['rules'] as $rule ) {
					if ( 'product' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? 'product_not' : 'product';
						$selected[ $key ] = array_merge( $selected[ $key ], $rule['terms'] );
					} elseif ( 'product_var' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? 'product_var_not' : 'product_var';
						$selected[ $key ] = array_merge( $selected[ $key ], $rule['terms'] );
					} elseif ( 'var_att' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? 'var_att_not' : 'var_att';
						$selected[ $key ] = array_merge( $selected[ $key ], $rule['terms'] );
					} elseif ( in_array( $rule['subject'], [ 'product_cat', 'product_tag' ], true ) && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? $rule['subject'] . '_not' : $rule['subject'];
						$selected[ $key ] = array_merge( $selected[ $key ], $rule['terms'] );
					} elseif ( 'product_type' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? 'product_type_not' : 'product_type';
						$selected[ $key ] = array_merge( $selected[ $key ], $rule['terms'] );
					} elseif ( 0 === strpos( $rule['subject'], 'pa_' ) && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? 'attributes_not' : 'attributes';
						foreach ( $rule['terms'] as $term_id ) {
							$selected[ $key ][] = $rule['subject'] . ':' . $term_id;
						}
					} elseif ( 'product_attribute' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						// WAPF composite `pa_x:term` terms are already in the
						// option-value format used by the attribute selects.
						$key = 'not_in' === $rule['operator'] ? 'attributes_not' : 'attributes';
						$selected[ $key ] = array_merge( $selected[ $key ], array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ) );
					} elseif ( 'user_auth' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in', 'logged_in', 'logged_out' ], true ) ) {
						$logged_out = in_array( $rule['operator'], [ 'not_in', 'logged_out' ], true );
						$selected['user_auth'] = $logged_out ? 'logged_out' : 'logged_in';
					} elseif ( 'user_role' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$key = 'not_in' === $rule['operator'] ? 'user_role_not' : 'user_role';
						$selected[ $key ] = array_merge( $selected[ $key ], $rule['terms'] );
					} elseif ( 'user_language' === $rule['subject'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
						$selected['user_language'] = (string) ( $rule['terms'][0] ?? '' );
						$selected['user_language_op'] = $rule['operator'];
					}
				}
			}
		}
		?>
		<p class="description"><?php esc_html_e( 'Leave product and customer conditions empty to show this group everywhere.', 'open-product-fields-for-woocommerce' ); ?></p>
		<?php foreach ( [ 'products' => 'product', 'excluded-products' => 'product_not' ] as $control => $key ) : ?>
			<p><label for="opf-placement-<?php echo esc_attr( $control ); ?>-picker"><strong><?php echo esc_html( 'product' === $key ? __( 'Include products', 'open-product-fields-for-woocommerce' ) : __( 'Exclude products', 'open-product-fields-for-woocommerce' ) ); ?></strong></label></p>
			<select id="opf-placement-<?php echo esc_attr( $control ); ?>-picker" class="wc-product-search" multiple="multiple" style="width:100%" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'open-product-fields-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products">
				<?php foreach ( array_unique( $selected[ $key ] ) as $product_id ) : ?>
					<?php $product = wc_get_product( (int) $product_id ); ?>
					<option value="<?php echo esc_attr( $product_id ); ?>" selected><?php echo esc_html( $product ? $product->get_formatted_name() : '#' . $product_id ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endforeach; ?>
		<p><label for="opf-placement-products"><strong><?php esc_html_e( 'Include product IDs', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
		<input type="text" id="opf-placement-products" class="widefat" inputmode="numeric" pattern="\s*[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*\s*" value="<?php echo esc_attr( implode( ', ', array_unique( $selected['product'] ) ) ); ?>">
		<p><label for="opf-placement-excluded-products"><strong><?php esc_html_e( 'Exclude product IDs', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
		<input type="text" id="opf-placement-excluded-products" class="widefat" inputmode="numeric" pattern="\s*[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*\s*" value="<?php echo esc_attr( implode( ', ', array_unique( $selected['product_not'] ) ) ); ?>">
		<p class="description"><?php esc_html_e( 'Search by product name or ID, or enter comma-separated IDs directly. Include matches any listed product; exclude removes every listed product. For variations, use the parent product.', 'open-product-fields-for-woocommerce' ); ?></p>
		<?php foreach ( [ 'variations' => 'product_var', 'excluded-variations' => 'product_var_not' ] as $control => $key ) : ?>
			<p><label for="opf-placement-<?php echo esc_attr( $control ); ?>-picker"><strong><?php echo esc_html( 'product_var' === $key ? __( 'Show on variations', 'open-product-fields-for-woocommerce' ) : __( 'Hide on variations', 'open-product-fields-for-woocommerce' ) ); ?></strong></label></p>
			<select id="opf-placement-<?php echo esc_attr( $control ); ?>-picker" class="wc-product-search" multiple="multiple" style="width:100%" data-placeholder="<?php esc_attr_e( 'Search for a variation…', 'open-product-fields-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations">
				<?php foreach ( array_unique( $selected[ $key ] ) as $variation_id ) : ?>
					<?php $variation_product = wc_get_product( (int) $variation_id ); ?>
					<option value="<?php echo esc_attr( (string) $variation_id ); ?>" selected><?php echo esc_html( $variation_product ? $variation_product->get_formatted_name() : '#' . $variation_id ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endforeach; ?>
		<p><label for="opf-placement-variations"><strong><?php esc_html_e( 'Include variation IDs', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
		<input type="text" id="opf-placement-variations" class="widefat" inputmode="numeric" pattern="\s*[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*\s*" value="<?php echo esc_attr( implode( ', ', array_unique( $selected['product_var'] ) ) ); ?>">
		<p><label for="opf-placement-excluded-variations"><strong><?php esc_html_e( 'Exclude variation IDs', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
		<input type="text" id="opf-placement-excluded-variations" class="widefat" inputmode="numeric" pattern="\s*[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*\s*" value="<?php echo esc_attr( implode( ', ', array_unique( $selected['product_var_not'] ) ) ); ?>">
		<p class="description"><?php esc_html_e( 'Variation rules gate the fields while the shopper picks options: included variations show this group\'s fields, excluded ones hide them. The group still needs a matching product/category rule (or no rules) to render.', 'open-product-fields-for-woocommerce' ); ?></p>
		<p><strong><?php esc_html_e( 'Show only on variations with attribute', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-variation-attributes" style="width:100%">
			<?php foreach ( $variation_attribute_options as $option ) : ?>
				<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( in_array( $option['value'], $selected['var_att'], true ) ); ?>>
					<?php echo esc_html( $option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Hide on products offering attribute values', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-excluded-variation-attributes" style="width:100%">
			<?php foreach ( $variation_attribute_options as $option ) : ?>
				<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( in_array( $option['value'], $selected['var_att_not'], true ) ); ?>>
					<?php echo esc_html( $option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Attribute rules use attr|value pairs; “any value” matches every filled choice of that attribute on the selected variation. Excluded pairs keep the whole group off products that offer them.', 'open-product-fields-for-woocommerce' ); ?></p>
		<p><strong><?php esc_html_e( 'Product categories', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="8" id="opf-placement-cats" style="width:100%">
			<?php foreach ( (array) $cat_terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, $selected['product_cat'], true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Product tags', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="8" id="opf-placement-tags" style="width:100%">
			<?php foreach ( (array) $tag_terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, $selected['product_tag'], true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Exclude categories', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-excluded-cats" style="width:100%">
			<?php foreach ( (array) $cat_terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, $selected['product_cat_not'], true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Exclude tags', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-excluded-tags" style="width:100%">
			<?php foreach ( (array) $tag_terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, $selected['product_tag_not'], true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Product types', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="4" id="opf-placement-types" style="width:100%">
			<?php foreach ( $types as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( in_array( (string) $slug, $selected['product_type'], true ) ); ?>>
					<?php echo esc_html( (string) $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Exclude product types', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="4" id="opf-placement-excluded-types" style="width:100%">
			<?php foreach ( $types as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( in_array( (string) $slug, $selected['product_type_not'], true ) ); ?>>
					<?php echo esc_html( (string) $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Attribute values', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-attributes" style="width:100%">
			<?php foreach ( $attribute_options as $option ) : ?>
				<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( in_array( $option['value'], $selected['attributes'], true ) ); ?>>
					<?php echo esc_html( $option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Exclude attribute values', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-excluded-attributes" style="width:100%">
			<?php foreach ( $attribute_options as $option ) : ?>
				<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( in_array( $option['value'], $selected['attributes_not'], true ) ); ?>>
					<?php echo esc_html( $option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Customer login', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select id="opf-placement-auth" style="width:100%">
			<option value="" <?php selected( '' === $selected['user_auth'] ); ?>><?php esc_html_e( 'Any visitor', 'open-product-fields-for-woocommerce' ); ?></option>
			<option value="logged_in" <?php selected( 'logged_in' === $selected['user_auth'] ); ?>><?php esc_html_e( 'Logged in', 'open-product-fields-for-woocommerce' ); ?></option>
			<option value="logged_out" <?php selected( 'logged_out' === $selected['user_auth'] ); ?>><?php esc_html_e( 'Not logged in', 'open-product-fields-for-woocommerce' ); ?></option>
		</select>
		<p><strong><?php esc_html_e( 'Require every selected user role', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-roles" style="width:100%">
			<?php foreach ( $roles as $role_id => $role ) : ?>
				<option value="<?php echo esc_attr( (string) $role_id ); ?>" <?php selected( in_array( (string) $role_id, $selected['user_role'], true ) ); ?>><?php echo esc_html( (string) $role['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Exclude every selected user role', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="5" id="opf-placement-excluded-roles" style="width:100%">
			<?php foreach ( $roles as $role_id => $role ) : ?>
				<option value="<?php echo esc_attr( (string) $role_id ); ?>" <?php selected( in_array( (string) $role_id, $selected['user_role_not'], true ) ); ?>><?php echo esc_html( (string) $role['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( $languages || '' !== $selected['user_language'] ) : ?>
			<p><strong><?php esc_html_e( 'Current language', 'open-product-fields-for-woocommerce' ); ?></strong></p>
			<select id="opf-placement-language-operator" style="width:100%">
				<option value="in" <?php selected( 'in' === $selected['user_language_op'] ); ?>><?php esc_html_e( 'Is', 'open-product-fields-for-woocommerce' ); ?></option>
				<option value="not_in" <?php selected( 'not_in' === $selected['user_language_op'] ); ?>><?php esc_html_e( 'Is not', 'open-product-fields-for-woocommerce' ); ?></option>
			</select>
			<select id="opf-placement-language" style="width:100%">
				<option value=""><?php esc_html_e( 'Any language', 'open-product-fields-for-woocommerce' ); ?></option>
				<?php if ( $selected['user_language'] && ! in_array( $selected['user_language'], array_column( $languages, 'id' ), true ) ) : ?>
					<option value="<?php echo esc_attr( $selected['user_language'] ); ?>" selected><?php echo esc_html( $selected['user_language'] ); ?></option>
				<?php endif; ?>
				<?php foreach ( $languages as $language ) : ?>
					<option value="<?php echo esc_attr( $language['id'] ); ?>" <?php selected( $language['id'] === $selected['user_language'] ); ?>><?php echo esc_html( $language['text'] ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Placement changes are saved together with the fields.', 'open-product-fields-for-woocommerce' ); ?></p>
		<?php
	}

	/**
	 * Languages exposed by installed Polylang or WPML, in the identifiers WAPF evaluates.
	 *
	 * @return array<int,array{id:string,text:string}>
	 */
	private static function language_options(): array {
		if ( function_exists( 'pll_languages_list' ) ) {
			$available = pll_languages_list( [ 'fields' => null ] );
			if ( is_array( $available ) ) {
				$languages = [];
				foreach ( $available as $language ) {
					if ( is_object( $language ) && isset( $language->locale, $language->name ) ) {
						$languages[] = [ 'id' => (string) $language->locale, 'text' => (string) $language->name ];
					}
				}
				return $languages;
			}
		}

		if ( function_exists( 'icl_get_languages' ) ) {
			$available = icl_get_languages( 'skip_missing=0&orderby=code' );
			if ( is_array( $available ) ) {
				$languages = [];
				foreach ( $available as $language ) {
					if ( is_array( $language ) && isset( $language['code'], $language['native_name'] ) ) {
						$languages[] = [ 'id' => (string) $language['code'], 'text' => (string) $language['native_name'] ];
					}
				}
				return $languages;
			}
		}

		return [];
	}
}
