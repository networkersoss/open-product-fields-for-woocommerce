<?php
/**
 * Field group builder: metaboxes on the opf_field_group edit screen.
 *
 * The builder UI is dependency-free vanilla JS (no jQuery, no build step)
 * talking to the REST API. The field-group model is a JSON document edited
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
		$model = $group ? $group->data : [ 'schema' => 1, 'fields' => [], 'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above' ];
		?>
		<div id="opf-builder-app"
			data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-model="<?php echo esc_attr( (string) wp_json_encode( $model, JSON_UNESCAPED_UNICODE ) ); ?>"
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
		$attribute_terms = [];
		if ( function_exists( 'wc_get_attribute_taxonomies' ) && function_exists( 'wc_attribute_taxonomy_name' ) ) {
			foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
				$name = is_object( $attribute ) ? (string) ( $attribute->attribute_name ?? '' ) : '';
				if ( '' === $name ) {
					continue;
				}
				$taxonomy = wc_attribute_taxonomy_name( $name );
				if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$attribute_terms[] = [
					'label'    => (string) ( $attribute->attribute_label ?? $name ),
					'taxonomy' => $taxonomy,
					'terms'    => $terms,
				];
			}
		}

		$selected = [ 'product_cat' => [], 'product_tag' => [], 'product_attribute' => [] ];
		if ( $group ) {
			foreach ( $group->data['rule_groups'] as $rule_group ) {
				foreach ( $rule_group['rules'] as $rule ) {
					if ( 'in' === $rule['operator'] && isset( $selected[ $rule['subject'] ] ) ) {
						$selected[ $rule['subject'] ] = array_merge( $selected[ $rule['subject'] ], $rule['terms'] );
					}
				}
			}
		}
		?>
		<p class="description"><?php esc_html_e( 'Leave all placement selectors empty to show this group on every product.', 'open-product-fields-for-woocommerce' ); ?></p>
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
		<?php if ( ! empty( $attribute_terms ) ) : ?>
			<p><strong><?php esc_html_e( 'Product attributes', 'open-product-fields-for-woocommerce' ); ?></strong></p>
			<select multiple size="8" id="opf-placement-attributes" style="width:100%">
				<?php foreach ( $attribute_terms as $attribute ) : ?>
					<optgroup label="<?php echo esc_attr( $attribute['label'] ); ?>">
						<?php foreach ( (array) $attribute['terms'] as $term ) : ?>
							<?php $key = $attribute['taxonomy'] . ':' . (string) $term->term_id; ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( in_array( $key, $selected['product_attribute'], true ) ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Placement changes are saved together with the fields.', 'open-product-fields-for-woocommerce' ); ?></p>
		<?php
	}
}
