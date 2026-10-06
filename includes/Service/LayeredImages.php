<?php
/** Product Data configuration and storefront registry for layered images. */

namespace OPF\Service;

use OPF\Engine\LayeredImageConfig;

defined( 'ABSPATH' ) || exit;

final class LayeredImages {
	public const META_KEY = '_opf_layered_images';
	public const ORDER_IMAGE_META_KEY = '_opf_layered_image_order_url';

	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', [ __CLASS__, 'add_product_data_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ __CLASS__, 'render_product_data_panel' ] );
		add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_data' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_filter( 'woocommerce_cart_item_thumbnail', [ __CLASS__, 'filter_cart_item_thumbnail' ], 20, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', [ __CLASS__, 'filter_store_api_cart_item_images' ], 20, 3 );
		add_filter( 'woocommerce_order_item_thumbnail', [ __CLASS__, 'filter_order_item_thumbnail' ], 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_order_item_image' ], 20, 4 );
		add_action( 'woocommerce_before_delete_order', [ __CLASS__, 'delete_order_images' ], 20, 1 );
	}

	/** Replace classic cart/checkout thumbnails with the selected layered image. */
	public static function filter_cart_item_thumbnail( string $html, array $cart_item, string $cart_item_key ): string {
		$image = self::cart_item_composite( $cart_item );
		if ( ! $image ) {
			return $html;
		}
		$alt = (string) ( $image['alt'] ?? '' );
		return sprintf(
			'<img src="%1$s" alt="%2$s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" loading="lazy" />',
			esc_url( $image['src'] ),
			esc_attr( $alt )
		);
	}

	/**
	 * Replace Store API images consumed by WooCommerce Cart and Checkout blocks.
	 *
	 * @param array<int,object> $images     Product image objects.
	 * @param array<string,mixed> $cart_item Cart line.
	 * @param string $cart_item_key Cart key.
	 * @return array<int,object>
	 */
	public static function filter_store_api_cart_item_images( array $images, array $cart_item, string $cart_item_key ): array {
		$image = self::cart_item_composite( $cart_item );
		if ( ! $image ) {
			return $images;
		}
		return [ (object) [
			'id'        => 0,
			'src'       => $image['src'],
			'thumbnail' => $image['src'],
			'srcset'    => '',
			'sizes'     => '',
			'name'      => $image['name'],
			'alt'       => $image['alt'],
		] ];
	}

	/**
	 * Use the saved OPF field values to show a layered image in Woo order emails.
	 *
	 * WooCommerce's email-order-items template passes its default image HTML and
	 * WC_Order_Item_Product to `woocommerce_order_item_thumbnail`. WAPF's public
	 * changelog describes an email-image developer API but does not publish its
	 * signature; this is OPF's native integration with Woo's documented hook.
	 *
	 * @param string $html Default WooCommerce image markup.
	 * @param mixed  $item Order line item.
	 */
	public static function filter_order_item_thumbnail( string $html, $item ): string {
		return self::order_item_image_html( $item ) ?? $html;
	}

	/** Developer helper for custom order-email templates; null means use Woo's image. */
	public static function order_item_image_html( $item ): ?string {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}
		$product = $item->get_product();
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$snapshot_url = self::stored_order_image_url( (string) $item->get_meta( self::ORDER_IMAGE_META_KEY, true ) );
		if ( $snapshot_url ) {
			return self::email_image_markup( $snapshot_url, $product->get_name() );
		}
		$values = $item->get_meta( '_opf_fields', true );
		if ( is_string( $values ) ) {
			$decoded = json_decode( $values, true );
			$values = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $values ) || ! $values ) {
			return null;
		}
		$image = self::cart_item_composite( [ 'data' => $product, CartIntegration::ITEM_KEY => $values ] );
		return $image ? self::email_image_markup( $image['src'], $image['alt'] ) : null;
	}

	/** Persist a stable order-specific copy so later product edits cannot change order email art. */
	public static function persist_order_item_image( $item, string $cart_item_key, array $cart_item, \WC_Order $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || $item->get_meta( self::ORDER_IMAGE_META_KEY, true ) ) {
			return;
		}
		$image = self::cart_item_composite( $cart_item );
		if ( ! $image ) {
			return;
		}
		$source = self::cache_image_path( (string) $image['src'] );
		if ( ! $source ) {
			return;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
			return;
		}
		$directory = trailingslashit( $upload['basedir'] ) . 'opf-layered-images/orders';
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return;
		}
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$token = hash_hmac( 'sha256', implode( '|', [ $order->get_id(), $cart_item_key, $product->get_id(), basename( $source ) ] ), wp_salt( 'auth' ) );
		$destination = trailingslashit( $directory ) . $order->get_id() . '-' . $token . '.png';
		if ( ! is_file( $destination ) ) {
			$temp = tempnam( $directory, '.opf-' );
			if ( ! $temp || ! @copy( $source, $temp ) || filesize( $temp ) !== filesize( $source ) || ! @rename( $temp, $destination ) ) {
				if ( $temp && is_file( $temp ) ) {
					wp_delete_file( $temp );
				}
				return;
			}
		}
		$item->update_meta_data( self::ORDER_IMAGE_META_KEY, trailingslashit( $upload['baseurl'] ) . 'opf-layered-images/orders/' . rawurlencode( basename( $destination ) ) );
	}

	/** Delete only OPF-owned order image files when WooCommerce permanently deletes an order. */
	public static function delete_order_images( $order_id ): void {
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$path = self::order_image_path( (string) $item->get_meta( self::ORDER_IMAGE_META_KEY, true ) );
			if ( $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	private static function email_image_markup( string $url, string $alt ): string {
		return sprintf( '<img src="%1$s" alt="%2$s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" />', esc_url( $url ), esc_attr( $alt ) );
	}

	private static function cache_image_path( string $url ): ?string {
		$upload = wp_upload_dir();
		$prefix = trailingslashit( (string) ( $upload['baseurl'] ?? '' ) ) . 'opf-layered-images/';
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$base_path = wp_parse_url( $prefix, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! is_string( $base_path ) || 0 !== strpos( $path, $base_path ) ) {
			return null;
		}
		$filename = basename( $path );
		if ( ! preg_match( '/^[1-9][0-9]{0,9}-[a-f0-9]{64}\.png$/D', $filename ) ) {
			return null;
		}
		$file = trailingslashit( (string) $upload['basedir'] ) . 'opf-layered-images/' . $filename;
		return is_file( $file ) ? $file : null;
	}

	private static function stored_order_image_url( string $url ): ?string {
		$path = self::order_image_path( $url );
		if ( ! $path || ! is_file( $path ) ) {
			return null;
		}
		$upload = wp_upload_dir();
		return trailingslashit( (string) $upload['baseurl'] ) . 'opf-layered-images/orders/' . rawurlencode( basename( $path ) );
	}

	private static function order_image_path( string $url ): ?string {
		$upload = wp_upload_dir();
		$prefix = trailingslashit( (string) ( $upload['baseurl'] ?? '' ) ) . 'opf-layered-images/orders/';
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$base_path = wp_parse_url( $prefix, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! is_string( $base_path ) || 0 !== strpos( $path, $base_path ) ) {
			return null;
		}
		$filename = basename( $path );
		if ( ! preg_match( '/^[1-9][0-9]{0,9}-[a-f0-9]{64}\.png$/D', $filename ) ) {
			return null;
		}
		return trailingslashit( (string) $upload['basedir'] ) . 'opf-layered-images/orders/' . $filename;
	}

	/** Resolve a cart line to a generated image only when layered settings apply. */
	private static function cart_item_composite( array $cart_item ): ?array {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$resolved = self::for_product( $product );
		if ( ! $resolved ) {
			return null;
		}
		$config = $resolved[0];
		$selected = [];
		$selected_layers = [];
		$values = is_array( $cart_item[ CartIntegration::ITEM_KEY ] ?? null ) ? $cart_item[ CartIntegration::ITEM_KEY ] : [];
		foreach ( $config['layers'] as $layer ) {
			$raw = $values[ (string) $layer['group_id'] ][ $layer['field_id'] ] ?? null;
			$choices = is_array( $raw ) ? $raw : [ $raw ];
			$is_list = [] === $choices || array_keys( $choices ) === range( 0, count( $choices ) - 1 );
			if ( $is_list ) {
				$selected_choices = array_map( 'strval', $choices );
			} else {
				$selected_choices = array_map( 'strval', array_keys( array_filter( $choices, static fn( $quantity ): bool => is_numeric( $quantity ) && (float) $quantity > 0 ) ) );
			}
			if ( in_array( (string) $layer['choice'], $selected_choices, true ) ) {
				$selected[] = (int) $layer['image_id'];
				$selected_layers[] = $layer;
			}
		}
		if ( ! $selected && ! empty( $config['delay'] ) ) {
			return null;
		}
	$attachment_ids = array_merge( [ (int) $config['base_image_id'] ], $selected );
		$url = self::composite_image_url( (int) $product->get_id(), $attachment_ids, $config, $selected_layers );
		if ( ! $url ) {
			return null;
		}
		$name = $product->get_name();
		return [ 'src' => $url, 'name' => $name, 'alt' => $name, 'width' => (int) $config['base_width'], 'height' => (int) $config['base_height'] ];
	}

	/** Estimate GD's canvas + decoded source memory against the PHP limit. */
	private static function has_composite_memory( int $pixels ): bool {
		$limit = self::memory_limit_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		$required = ( $pixels * 16 ) + ( 8 * 1024 * 1024 );
		return memory_get_usage( true ) + $required < $limit;
	}

	private static function memory_limit_bytes( string $limit ): int {
		$limit = trim( $limit );
		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}
		$unit = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;
		switch ( $unit ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}
		return $value;
	}

	/**
	 * Return a stable public PNG URL for the base and selected local attachment layers.
	 *
	 * The source attachments are validated by for_product() before this method is
	 * called. A bounded pixel count protects PHP memory when composing cart images.
	 *
	 * @param int[] $attachment_ids Base image followed by transparent PNG layers.
	 */
	private static function composite_image_url( int $product_id, array $attachment_ids, array $config, array $selected_layers ): ?string {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagepng' ) || ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagecopy' ) || ! $attachment_ids ) {
			return null;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
			return null;
		}
		$signature_parts = [];
		$paths = [];
		$dimensions = null;
		foreach ( $attachment_ids as $index => $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			$size = $path && is_file( $path ) ? @getimagesize( $path ) : false;
			$file_size = $path && is_file( $path ) ? filesize( $path ) : false;
			$modified = $path && is_file( $path ) ? filemtime( $path ) : false;
			$pixel_count = is_array( $size ) ? (int) $size[0] * (int) $size[1] : 0;
			if ( ! $path || ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) || false === $file_size || false === $modified || $file_size > 20971520 || $pixel_count > 3000000 || ! self::has_composite_memory( $pixel_count ) ) {
				return null;
			}
			$current_dimensions = [ (int) $size[0], (int) $size[1] ];
			if ( null === $dimensions ) {
				$dimensions = $current_dimensions;
			} elseif ( $dimensions !== $current_dimensions ) {
				return null;
			}
			$paths[] = $path;
			$content_hash = hash_file( 'sha256', $path );
			if ( ! is_string( $content_hash ) ) {
				return null;
			}
			$signature_parts[] = [ (int) $attachment_id, (int) $file_size, (int) $modified, $content_hash ];
		}
		if ( ! $dimensions ) {
			return null;
		}
		$cache_payload = wp_json_encode( [ $product_id, $signature_parts, $config, $selected_layers ] );
		$hash = hash_hmac( 'sha256', (string) $cache_payload, wp_salt( 'auth' ) );
		$directory = trailingslashit( $upload['basedir'] ) . 'opf-layered-images';
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return null;
		}
		$path = trailingslashit( $directory ) . $product_id . '-' . $hash . '.png';
		if ( ! is_file( $path ) ) {
			$base_bytes = file_get_contents( $paths[0] );
			$base = is_string( $base_bytes ) ? @imagecreatefromstring( $base_bytes ) : false;
			if ( false === $base ) {
				return null;
			}
			$canvas = imagecreatetruecolor( $dimensions[0], $dimensions[1] );
			if ( false === $canvas ) {
				imagedestroy( $base );
				return null;
			}
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			imagecopy( $canvas, $base, 0, 0, 0, 0, $dimensions[0], $dimensions[1] );
			imagedestroy( $base );
			foreach ( array_slice( $paths, 1 ) as $layer_path ) {
				$layer = @imagecreatefrompng( $layer_path );
				if ( false === $layer ) {
					imagedestroy( $canvas );
					return null;
				}
				imagealphablending( $canvas, true );
				imagecopy( $canvas, $layer, 0, 0, 0, 0, $dimensions[0], $dimensions[1] );
				imagedestroy( $layer );
			}
			$temp = $path . '.' . wp_generate_password( 12, false, false ) . '.tmp';
			$saved = imagepng( $canvas, $temp );
			imagedestroy( $canvas );
			if ( ! $saved ) {
				if ( is_file( $temp ) ) {
					wp_delete_file( $temp );
				}
				return null;
			}
			if ( ! @rename( $temp, $path ) ) {
				if ( is_file( $temp ) ) {
					wp_delete_file( $temp );
				}
				if ( ! is_file( $path ) ) {
					return null;
				}
			}
		}
		self::prune_product_image_cache( $directory, $product_id, $path );
		return trailingslashit( $upload['baseurl'] ) . 'opf-layered-images/' . rawurlencode( basename( $path ) );
	}

	/** Keep a bounded number of recent composites for each product. */
	private static function prune_product_image_cache( string $directory, int $product_id, string $keep_path ): void {
		$files = glob( trailingslashit( $directory ) . $product_id . '-[a-f0-9]*.png' ) ?: [];
		usort( $files, static function ( string $left, string $right ): int {
			return (int) @filemtime( $right ) <=> (int) @filemtime( $left );
		} );
		$kept = 0;
		foreach ( $files as $file ) {
			if ( $file === $keep_path || $kept < 11 ) {
				++$kept;
				continue;
			}
			wp_delete_file( $file );
		}
	}

	/** Remove generated cart images for a deleted disposable/test product. */
	public static function delete_cart_image_cache( int $product_id ): void {
		$upload = wp_upload_dir();
		$directory = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'opf-layered-images';
		if ( $product_id > 0 && is_dir( $directory ) ) {
			foreach ( glob( trailingslashit( $directory ) . $product_id . '-[a-f0-9]*.png' ) ?: [] as $file ) {
				wp_delete_file( $file );
			}
		}
	}

	public static function enqueue_admin_assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'opf-layered-images-admin', OPF_URL . 'assets/js/opf-layered-images-admin.js', [ 'media-editor', 'media-views' ], OPF_VERSION, true );
	}

	public static function add_product_data_tab( array $tabs ): array {
		$tabs['opf_layered_images'] = [
			'label'    => __( 'Layered Images', 'open-product-fields-for-woocommerce' ),
			'target'   => 'opf_layered_images_product_data',
			'priority' => 81,
		];
		return $tabs;
	}

	public static function render_product_data_panel(): void {
		$product_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product = $product_id ? wc_get_product( $product_id ) : null;
		$config = $product ? LayeredImageConfig::normalize( $product->get_meta( self::META_KEY, true ) ) : LayeredImageConfig::normalize( [] );
		$images = self::image_library();
		$fields = [];
		if ( $product ) {
			foreach ( FieldGroups::for_product( $product ) as $entry ) {
				foreach ( $entry['group']->data['fields'] as $field ) {
					if ( ! in_array( $field['type'], [ 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
						continue;
					}
					foreach ( $field['choices'] as $choice ) {
						$fields[] = [
							'group_id' => (int) $entry['id'],
							'group'    => (string) $entry['title'],
							'field_id' => (string) $field['id'],
							'field'    => (string) ( $field['label'] ?: $field['id'] ),
							'choice'   => (string) $choice['slug'],
							'label'    => (string) $choice['label'],
						];
					}
				}
			}
		}
		$image_ids = $product ? array_values( array_unique( array_merge( [ (int) $product->get_image_id() ], array_map( 'absint', $product->get_gallery_image_ids() ) ) ) ) : [];
		?>
		<div id="opf_layered_images_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field"><label for="_opf_layered_enabled"><?php esc_html_e( 'Enable layered images', 'open-product-fields-for-woocommerce' ); ?></label><input type="checkbox" id="_opf_layered_enabled" name="_opf_layered_images[enabled]" value="1" <?php checked( $config['enabled'] ); ?>></p>
				<p class="form-field"><label for="_opf_layered_base"><?php esc_html_e( 'Base background image', 'open-product-fields-for-woocommerce' ); ?></label><select id="_opf_layered_base" name="_opf_layered_images[base_image_id]"><?php self::image_options( $images, $config['base_image_id'] ); ?></select> <button type="button" class="button" data-opf-layered-upload="_opf_layered_base"><?php esc_html_e( 'Upload or select', 'open-product-fields-for-woocommerce' ); ?></button></p>
				<p class="form-field"><label for="_opf_layered_target"><?php esc_html_e( 'Display on image', 'open-product-fields-for-woocommerce' ); ?></label><select id="_opf_layered_target" name="_opf_layered_images[target]"><option value="main" <?php selected( $config['target'], 'main' ); ?>><?php esc_html_e( 'Main product image', 'open-product-fields-for-woocommerce' ); ?></option><option value="gallery" <?php selected( $config['target'], 'gallery' ); ?>><?php esc_html_e( 'Selected gallery image', 'open-product-fields-for-woocommerce' ); ?></option></select></p>
				<p class="form-field"><label for="_opf_layered_target_image"><?php esc_html_e( 'Gallery image', 'open-product-fields-for-woocommerce' ); ?></label><select id="_opf_layered_target_image" name="_opf_layered_images[target_image_id]"><?php self::image_options( $images, $config['target_image_id'], $image_ids ); ?></select></p>
				<p class="form-field"><label for="_opf_layered_delay"><?php esc_html_e( 'Delay until first choice', 'open-product-fields-for-woocommerce' ); ?></label><input type="checkbox" id="_opf_layered_delay" name="_opf_layered_images[delay]" value="1" <?php checked( $config['delay'] ); ?>></p>
				<p class="form-field"><label for="_opf_layered_scroll"><?php esc_html_e( 'Auto-scroll to product image', 'open-product-fields-for-woocommerce' ); ?></label><input type="checkbox" id="_opf_layered_scroll" name="_opf_layered_images[auto_scroll]" value="1" <?php checked( $config['auto_scroll'] ); ?>></p>
			</div>
			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Choice image layers', 'open-product-fields-for-woocommerce' ); ?></strong></p>
				<p class="form-field description"><?php esc_html_e( 'Choose a PNG with transparent pixels for each option. Every layer must match the base image dimensions; invalid layers are rejected when saved.', 'open-product-fields-for-woocommerce' ); ?></p>
				<?php foreach ( $fields as $field ) :
					$key = self::layer_key( $field['group_id'], $field['field_id'], $field['choice'] );
					$current = 0;
					foreach ( $config['layers'] as $layer ) {
						if ( $layer['group_id'] === $field['group_id'] && $layer['field_id'] === $field['field_id'] && $layer['choice'] === $field['choice'] ) {
							$current = $layer['image_id'];
							break;
						}
					}
					?>
					<p class="form-field"><label for="_opf_layer_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['group'] . ' — ' . $field['field'] . ' — ' . $field['label'] ); ?></label><select class="opf-layered-choice-image" id="_opf_layer_<?php echo esc_attr( $key ); ?>" name="_opf_layered_images[layers][<?php echo esc_attr( $key ); ?>]"><?php self::image_options( $images, $current, [], true ); ?></select> <button type="button" class="button" data-opf-layered-upload="_opf_layer_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Upload or select', 'open-product-fields-for-woocommerce' ); ?></button></p>
				<?php endforeach; ?>
				<?php if ( ! $fields ) : ?><p class="form-field description"><?php esc_html_e( 'Add a product-targeted select, radio, checkbox, or swatch field with choices to configure layers.', 'open-product-fields-for-woocommerce' ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function save_product_data( $product ): void {
		if ( ! $product instanceof \WC_Product || ! isset( $_POST['_opf_layered_images'] ) || ! is_array( $_POST['_opf_layered_images'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$raw = wp_unslash( $_POST['_opf_layered_images'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$layers = [];
		foreach ( (array) ( $raw['layers'] ?? [] ) as $key => $image_id ) {
			if ( ! preg_match( '/^([1-9][0-9]{0,9}):([a-zA-Z0-9][a-zA-Z0-9_-]{0,63}):([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})$/D', (string) $key, $match ) ) {
				continue;
			}
			$image_id = absint( $image_id );
			if ( $image_id ) {
				$layers[] = [ 'group_id' => (int) $match[1], 'field_id' => $match[2], 'choice' => $match[3], 'image_id' => $image_id ];
			}
		}
		$config = LayeredImageConfig::normalize( [
			'enabled' => ! empty( $raw['enabled'] ),
			'target' => sanitize_key( $raw['target'] ?? 'main' ),
			'target_image_id' => absint( $raw['target_image_id'] ?? 0 ),
			'base_image_id' => absint( $raw['base_image_id'] ?? 0 ),
			'delay' => ! empty( $raw['delay'] ),
			'auto_scroll' => ! empty( $raw['auto_scroll'] ),
			'layers' => $layers,
		] );
		$config = self::validate_media( $config );
		$product->update_meta_data( self::META_KEY, $config );
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_product( \WC_Product $product ): array {
		$config = LayeredImageConfig::normalize( $product->get_meta( self::META_KEY, true ) );
		if ( ! $config['enabled'] ) {
			return [];
		}
		$valid_targets = array_values( array_unique( array_merge( [ (int) $product->get_image_id() ], array_map( 'absint', $product->get_gallery_image_ids() ) ) ) );
		$target_index = 0;
		if ( 'gallery' === $config['target'] ) {
			$target_index = array_search( $config['target_image_id'], $valid_targets, true );
			if ( false === $target_index ) {
				return [];
			}
		}
		$groups = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$groups[ (int) $entry['id'] ] = $entry['group'];
		}
		$base = self::image_details( $config['base_image_id'] );
		if ( ! $base ) {
			return [];
		}
		$layers = [];
		foreach ( $config['layers'] as $layer ) {
			$group = $groups[ $layer['group_id'] ] ?? null;
			$field = null;
			if ( $group ) {
				foreach ( $group->data['fields'] as $candidate ) {
					if ( $candidate['id'] === $layer['field_id'] && in_array( $candidate['type'], [ 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
						$field = $candidate;
						break;
					}
				}
			}
			$choice_exists = $field && in_array( $layer['choice'], array_column( $field['choices'], 'slug' ), true );
			$image = self::image_details( $layer['image_id'] );
			if ( ! $choice_exists || ! $image || 'image/png' !== $image['mime'] || ! self::has_transparent_pixels( $layer['image_id'] ) || $base['width'] !== $image['width'] || $base['height'] !== $image['height'] ) {
				continue;
			}
			$layers[] = [ 'group_id' => $layer['group_id'], 'field_id' => $layer['field_id'], 'choice' => $layer['choice'], 'image_id' => $layer['image_id'], 'url' => $image['url'] ];
		}
		if ( ! $layers ) {
			return [];
		}
		return [ [
			'enabled' => true,
			'target' => $config['target'],
			'target_index' => (int) $target_index,
			'base_image_id' => (int) $config['base_image_id'],
			'base_url' => $base['url'],
			'base_width' => $base['width'],
			'base_height' => $base['height'],
			'delay' => $config['delay'],
			'auto_scroll' => $config['auto_scroll'],
			'layers' => $layers,
		] ];
	}

	/** @param array<string,mixed> $config */
	private static function validate_media( array $config ): array {
		if ( ! $config['enabled'] ) {
			return $config;
		}
		$base = self::image_details( $config['base_image_id'] );
		if ( ! $base ) {
			$config['enabled'] = false;
			return $config;
		}
		$config['layers'] = array_values( array_filter( $config['layers'], static function ( $layer ) use ( $base ) {
			$image = self::image_details( $layer['image_id'] );
			return $image && 'image/png' === $image['mime'] && self::has_transparent_pixels( $layer['image_id'] ) && $image['width'] === $base['width'] && $image['height'] === $base['height'];
		} ) );
		if ( ! $config['layers'] ) {
			$config['enabled'] = false;
		}
		return $config;
	}

	private static function image_details( int $id ): ?array {
		$mime = get_post_mime_type( $id );
		$path = get_attached_file( $id );
		$url = wp_get_attachment_image_url( $id, 'full' );
		$size = $path && is_file( $path ) ? @getimagesize( $path ) : false;
		if ( ! $id || ! $mime || 0 !== strpos( $mime, 'image/' ) || ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) || ! is_string( $url ) || '' === $url ) {
			return null;
		}
		return [ 'mime' => $mime, 'url' => $url, 'width' => (int) $size[0], 'height' => (int) $size[1] ];
	}

	/** Require PNG truecolor/grayscale alpha or an indexed PNG tRNS chunk. */
	private static function has_alpha_channel( int $id ): bool {
		$path = get_attached_file( $id );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$header = fread( $handle, 26 );
		fclose( $handle );
		if ( ! is_string( $header ) || strlen( $header ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $header, 0, 8 ) ) {
			return false;
		}
		$color_type = ord( $header[25] );
		if ( in_array( $color_type, [ 4, 6 ], true ) ) {
			return true;
		}
		if ( 3 !== $color_type ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		fseek( $handle, 8 );
		$offset = 8;
		$has_transparency = false;
		while ( $offset < 1048576 ) {
			$chunk_header = fread( $handle, 8 );
			if ( ! is_string( $chunk_header ) || 8 !== strlen( $chunk_header ) ) {
				break;
			}
			$chunk_length = unpack( 'Nlength', substr( $chunk_header, 0, 4 ) );
			$chunk_type = substr( $chunk_header, 4, 4 );
			if ( 'tRNS' === $chunk_type ) {
				$has_transparency = true;
				break;
			}
			if ( 'IDAT' === $chunk_type || ! is_array( $chunk_length ) ) {
				break;
			}
			$skip = (int) $chunk_length['length'] + 4;
			if ( 0 !== fseek( $handle, $skip, SEEK_CUR ) ) {
				break;
			}
			$offset += 8 + $skip;
		}
		fclose( $handle );
		return $has_transparency;
	}

	/** Confirm at least one pixel is actually transparent, not merely alpha-capable. */
	private static function has_transparent_pixels( int $id ): bool {
		$path = get_attached_file( $id );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		$modified = filemtime( $path );
		if ( false === $size || false === $modified ) {
			return false;
		}
		$signature = (string) $size . ':' . (string) $modified;
		$cached = get_post_meta( $id, '_opf_layered_transparency_check', true );
		if ( is_array( $cached ) && ( $cached['signature'] ?? '' ) === $signature && isset( $cached['transparent'] ) ) {
			return (bool) $cached['transparent'];
		}
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return self::cache_transparency_verdict( $id, $signature, false );
		}
		$image = @imagecreatefrompng( $path );
		if ( false === $image ) {
			return self::cache_transparency_verdict( $id, $signature, false );
		}
		$width = imagesx( $image );
		$height = imagesy( $image );
		if ( $width <= 0 || $height <= 0 || $width * $height > 16000000 ) {
			imagedestroy( $image );
			return self::cache_transparency_verdict( $id, $signature, false );
		}
		$true_color = imageistruecolor( $image );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$pixel = imagecolorat( $image, $x, $y );
				if ( $true_color ) {
					$alpha = ( $pixel >> 24 ) & 0x7F;
				} else {
					$color = imagecolorsforindex( $image, $pixel );
					$alpha = (int) ( $color['alpha'] ?? 0 );
				}
				if ( $alpha > 0 ) {
					imagedestroy( $image );
					return self::cache_transparency_verdict( $id, $signature, true );
				}
			}
		}
		imagedestroy( $image );
		return self::cache_transparency_verdict( $id, $signature, false );
	}

	private static function cache_transparency_verdict( int $id, string $signature, bool $transparent ): bool {
		update_post_meta( $id, '_opf_layered_transparency_check', [ 'signature' => $signature, 'transparent' => $transparent ] );
		return $transparent;
	}

	private static function image_options( array $images, int $selected, array $allowed = [], bool $layer = false ): void {
		echo '<option value="">' . esc_html__( '— Select image —', 'open-product-fields-for-woocommerce' ) . '</option>';
		foreach ( $images as $image ) {
			if ( $allowed && ! in_array( (int) $image['id'], $allowed, true ) ) {
				continue;
			}
			$attributes = ' data-width="' . esc_attr( (string) $image['width'] ) . '" data-height="' . esc_attr( (string) $image['height'] ) . '" data-alpha="' . ( ! empty( $image['alpha'] ) ? '1' : '0' ) . '" data-url="' . esc_url( $image['url'] ) . '"';
			if ( $layer && ( 'image/png' !== $image['mime'] || empty( $image['alpha'] ) ) ) {
				$attributes .= ' data-layer-invalid="1"';
			}
			echo '<option value="' . esc_attr( (string) $image['id'] ) . '"' . selected( $selected, (int) $image['id'], false ) . $attributes . '>' . esc_html( $image['label'] ) . '</option>';
		}
	}

	private static function image_library(): array {
		$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC' ] );
		$images = [];
		foreach ( $attachments as $attachment ) {
			$id = (int) $attachment->ID;
			$size = wp_get_attachment_metadata( $id );
			$images[] = [ 'id' => $id, 'label' => get_the_title( $id ) ?: sprintf( /* translators: %d: attachment ID. */ __( 'Image %d', 'open-product-fields-for-woocommerce' ), $id ), 'width' => (int) ( $size['width'] ?? 0 ), 'height' => (int) ( $size['height'] ?? 0 ), 'mime' => get_post_mime_type( $id ), 'url' => wp_get_attachment_image_url( $id, 'full' ), 'alpha' => self::has_alpha_channel( $id ) ];
		}
		return $images;
	}

	private static function layer_key( int $group_id, string $field_id, string $choice ): string {
		return $group_id . ':' . $field_id . ':' . $choice;
	}
}
