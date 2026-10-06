<?php
/** Product-local content preview configuration and admin editor. */

namespace OPF\Service;

use OPF\Engine\ProductPreview as PreviewSchema;

defined( 'ABSPATH' ) || exit;

final class LivePreview {
	public const META_KEY = '_opf_live_previews';

	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', [ __CLASS__, 'add_product_data_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ __CLASS__, 'render_product_data_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_data' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	/** Load the visual editor only on WooCommerce product edit screens. */
	public static function enqueue_admin_assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_script( 'opf-live-preview-admin', OPF_URL . 'assets/js/opf-live-preview-admin.js', [], OPF_VERSION, true );
		wp_enqueue_style( 'opf-live-preview-admin', OPF_URL . 'assets/css/opf-live-preview-admin.css', [], OPF_VERSION );
		LivePreviewFonts::enqueue_faces( 'opf-live-preview-admin' );
	}

	/** @param array<string,array<string,mixed>> $tabs */
	public static function add_product_data_tab( array $tabs ): array {
		$tabs['opf_live_preview'] = [
			'label'    => __( 'Live Preview', 'open-product-fields-for-woocommerce' ),
			'target'   => 'opf_live_preview_product_data',
			'priority' => 80,
		];
		return $tabs;
	}

	public static function render_product_data_panel(): void {
		$product_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product = $product_id ? wc_get_product( $product_id ) : null;
		$config = $product ? self::stored_config( $product ) : [];
		$product_id = $product ? (int) $product->get_id() : 0;
		$preview_fonts = array_values( array_map( static function ( array $font ): array {
			return [ 'name' => $font['name'], 'format' => $font['format'] ];
		}, LivePreviewFonts::registered() ) );
		$eligible_groups = [];
		if ( $product_id ) {
			foreach ( FieldGroups::all() as $entry ) {
				if ( self::group_targets_product( $entry['group']->data, $product_id ) ) {
					$fields = [];
					$dynamic_fields = [];
					foreach ( $entry['group']->data['fields'] as $field ) {
						if ( in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'upload' ], true ) ) {
							$fields[] = [ 'id' => $field['id'], 'label' => $field['label'] ?: $field['id'], 'type' => $field['type'] ];
						}
						if ( in_array( $field['type'], [ 'select', 'radio', 'checkbox', 'swatch' ], true ) && ! empty( $field['choices'] ) ) {
							$dynamic_fields[] = [ 'id' => $field['id'], 'label' => $field['label'] ?: $field['id'], 'choices' => array_map( static function ( $choice ) {
								return [ 'slug' => (string) $choice['slug'], 'label' => (string) $choice['label'] ];
							}, $field['choices'] ) ];
						}
					}
					if ( $fields ) {
						$eligible_groups[] = [ 'id' => (int) $entry['id'], 'title' => $entry['title'], 'fields' => $fields, 'dynamic_fields' => $dynamic_fields ];
					}
				}
			}
		}
		$gallery_images = [];
		if ( $product ) {
			$ids = array_values( array_unique( array_merge( [ (int) $product->get_image_id() ], array_map( 'absint', $product->get_gallery_image_ids() ) ) ) );
			foreach ( $ids as $index => $image_id ) {
				if ( $image_id ) {
					$gallery_images[] = [ 'id' => $image_id, 'index' => $index, 'label' => get_the_title( $image_id ) ?: sprintf( /* translators: %d: image position in the product gallery. */ __( 'Image %d', 'open-product-fields-for-woocommerce' ), $index + 1 ), 'url' => wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) ];
				}
			}
		}
		?>
		<div id="opf_live_preview_product_data" class="panel woocommerce_options_panel hidden">
			<div class="opf-preview-editor" data-opf-preview-editor
				data-groups="<?php echo esc_attr( (string) wp_json_encode( $eligible_groups ) ); ?>"
				data-images="<?php echo esc_attr( (string) wp_json_encode( $gallery_images ) ); ?>"
				data-fonts="<?php echo esc_attr( (string) wp_json_encode( $preview_fonts ) ); ?>"
				data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
				<p><?php esc_html_e( 'Choose a field and product image, then place and style its text overlay.', 'open-product-fields-for-woocommerce' ); ?></p>
				<p class="description"><?php esc_html_e( 'Overlays change the product image preview only. They do not change cart or order data.', 'open-product-fields-for-woocommerce' ); ?></p>
				<div class="opf-preview-editor__items">
					<label><?php esc_html_e( 'Preview items', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-item></select></label>
					<button type="button" class="button" data-opf-preview-add><?php esc_html_e( 'Add overlay', 'open-product-fields-for-woocommerce' ); ?></button>
					<button type="button" class="button" data-opf-preview-remove><?php esc_html_e( 'Remove selected overlay', 'open-product-fields-for-woocommerce' ); ?></button>
				</div>
				<div class="opf-preview-editor__controls">
					<label><?php esc_html_e( 'Field group', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-group></select></label>
					<label><?php esc_html_e( 'Text field', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-field></select></label>
					<label><?php esc_html_e( 'Product image', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-image></select></label>
					<label><?php esc_html_e( 'Text color', 'open-product-fields-for-woocommerce' ); ?><input type="color" value="#000000" data-opf-preview-color></label>
					<label><?php esc_html_e( 'Font family', 'open-product-fields-for-woocommerce' ); ?><input type="text" value="Arial, sans-serif" maxlength="120" data-opf-preview-font-family></label>
					<label><?php esc_html_e( 'Registered font picker', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-font-picker><option value=""><?php esc_html_e( 'Use entered font family', 'open-product-fields-for-woocommerce' ); ?></option></select></label>
					<label><?php esc_html_e( 'Font size', 'open-product-fields-for-woocommerce' ); ?><input type="number" min="1" max="256" value="24" data-opf-preview-size></label>
					<label><?php esc_html_e( 'Mobile font size', 'open-product-fields-for-woocommerce' ); ?><input type="number" min="1" max="256" value="18" data-opf-preview-mobile-size></label>
					<label><?php esc_html_e( 'Font weight', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-weight><option value="normal">Normal</option><option value="bold">Bold</option></select></label>
					<label><?php esc_html_e( 'Font style', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-font-style><option value="normal">Normal</option><option value="italic">Italic</option></select></label>
					<label><?php esc_html_e( 'Alignment', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-alignment><option value="left">Left</option><option value="center" selected>Center</option><option value="right">Right</option></select></label>
					<label><?php esc_html_e( 'Mobile alignment', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-mobile-alignment><option value="left">Left</option><option value="center" selected>Center</option><option value="right">Right</option></select></label>
					<label><?php esc_html_e( 'Box width (%)', 'open-product-fields-for-woocommerce' ); ?><input type="range" min="5" max="100" value="50" data-opf-preview-width></label>
					<label><?php esc_html_e( 'Box height (%)', 'open-product-fields-for-woocommerce' ); ?><input type="range" min="5" max="100" value="20" data-opf-preview-height></label>
					<label data-opf-upload-style><?php esc_html_e( 'Upload image shape', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-shape><option value="rectangle">Rectangle</option><option value="oval">Oval</option></select></label>
					<label data-opf-upload-style><?php esc_html_e( 'Upload image fit', 'open-product-fields-for-woocommerce' ); ?><select data-opf-preview-fit><option value="fill">Stretch to fill</option><option value="contain">Scale to fit</option></select></label>
					<label data-opf-text-style><?php esc_html_e( 'Dynamic style', 'open-product-fields-for-woocommerce' ); ?><select data-opf-dynamic-property><option value="">None</option><option value="color">Color</option><option value="font_family">Font family</option><option value="font_size">Font size</option><option value="alignment">Alignment</option></select></label>
					<label data-opf-text-style><?php esc_html_e( 'Dynamic value field', 'open-product-fields-for-woocommerce' ); ?><select data-opf-dynamic-field></select></label>
					<label data-opf-text-style><?php esc_html_e( 'Dynamic default', 'open-product-fields-for-woocommerce' ); ?><input type="text" data-opf-dynamic-default></label>
					<div class="opf-preview-editor__dynamic-values" data-opf-text-style data-opf-dynamic-values></div>
				</div>
				<div class="opf-preview-editor__canvas" data-opf-preview-canvas>
					<img data-opf-preview-canvas-image alt="" />
					<div class="opf-preview-editor__box" data-opf-preview-box tabindex="0" role="group" aria-label="Preview text placement"><span data-opf-preview-sample>Preview text</span></div>
				</div>
				<p class="description"><?php esc_html_e( 'Drag the text box to position it. Use arrow keys when the box is focused.', 'open-product-fields-for-woocommerce' ); ?></p>
				<textarea hidden name="_opf_live_previews" data-opf-preview-json><?php echo esc_textarea( (string) wp_json_encode( $config ) ); ?></textarea>
				<?php if ( ! $eligible_groups || ! $gallery_images ) : ?><p class="description"><?php esc_html_e( 'Add a product-targeted text field group and a product image to configure a preview.', 'open-product-fields-for-woocommerce' ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Save validated preview data. Invalid JSON leaves the last saved configuration untouched. */
	public static function save_product_data( $product ): void {
		if ( ! $product instanceof \WC_Product || ! isset( $_POST['_opf_live_previews'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$raw = wp_unslash( $_POST['_opf_live_previews'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) {
			return;
		}
		$decoded = '' === trim( $raw ) ? [] : json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ( '' !== trim( $raw ) && JSON_ERROR_NONE !== json_last_error() ) ) {
			return;
		}
		$product->update_meta_data( self::META_KEY, PreviewSchema::normalize( $decoded ) );
	}

	/** Resolve only product-targeted OPF fields and safe image references. */
	public static function for_product( \WC_Product $product ): array {
		$groups = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			if ( ! self::group_targets_product( $entry['group']->data, (int) $product->get_id() ) ) {
				continue;
			}
			$groups[ (int) $entry['id'] ] = $entry['group'];
		}
		$previews = PreviewSchema::normalize( $product->get_meta( self::META_KEY, true ) );
		$image_ids = array_values( array_unique( array_merge( [ (int) $product->get_image_id() ], array_map( 'absint', $product->get_gallery_image_ids() ) ) ) );
		$resolved = [];
		foreach ( $previews as $item ) {
			$group = $groups[ (int) $item['group_id'] ] ?? null;
			$field = null;
			if ( $group ) {
				foreach ( $group->data['fields'] as $candidate ) {
					if ( $candidate['id'] === $item['field_id'] ) {
						$field = $candidate;
						break;
					}
				}
			}
			$allowed_types = 'text' === $item['source'] ? [ 'text', 'textarea', 'email', 'url' ] : [ 'upload' ];
			if ( ! $field || ! in_array( $field['type'], $allowed_types, true ) ) {
				continue;
			}
			if ( 'gallery' === $item['target'] ) {
				$image_index = array_search( (int) $item['image_id'], $image_ids, true );
				if ( false === $image_index ) {
					continue;
				}
				$url = wp_get_attachment_image_url( (int) $item['image_id'], 'woocommerce_single' );
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$item['target_index'] = (int) $image_index;
			}
			$resolved[] = $item;
		}
		return $resolved;
	}

	/** @param array<string,mixed> $data */
	private static function group_targets_product( array $data, int $product_id ): bool {
		foreach ( (array) ( $data['rule_groups'] ?? [] ) as $rule_group ) {
			foreach ( (array) ( $rule_group['rules'] ?? [] ) as $rule ) {
				if ( 'product' === ( $rule['subject'] ?? '' ) && 'in' === ( $rule['operator'] ?? '' ) && in_array( (string) $product_id, array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return array<int,array<string,mixed>> */
	private static function stored_config( \WC_Product $product ): array {
		return PreviewSchema::normalize( $product->get_meta( self::META_KEY, true ) );
	}
}
