<?php
/** Private, session-owned product uploads. No media-library or public URLs. */
namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class Uploads {
	private const PREFIX = 'opf_upload_';
	private const TTL = DAY_IN_SECONDS;
	/** @var string|null */
	private static $download_path = null;
	/** @var array */
	private static $native_values = [];

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'serve_download' ], 10, 4 );
		add_action( 'opf_upload_cleanup', [ __CLASS__, 'cleanup' ] );
		add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'check_cart' ] );
		add_action( 'woocommerce_order_item_meta_end', [ __CLASS__, 'order_links' ], 10, 3 );
		add_action( 'woocommerce_after_order_itemmeta', [ __CLASS__, 'admin_order_links' ], 10, 2 );
		add_action( 'woocommerce_new_order_item', [ __CLASS__, 'bind_order' ], 10, 3 );
		UploadReissue::init();
		add_action( 'admin_post_opf_delete_order_uploads', [ __CLASS__, 'delete_order_uploads' ] );
		add_action( 'woocommerce_admin_order_data_after_order_details', [ __CLASS__, 'delete_order_button' ] );
		register_deactivation_hook( OPF_FILE, [ __CLASS__, 'deactivate' ] );
		if ( ! wp_next_scheduled( 'opf_upload_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'opf_upload_cleanup' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'opf_upload_cleanup' );
	}

	public static function modern(): bool {
		return 'yes' === get_option( 'opf_upload_ajax', get_option( 'wapf_upload_ajax', 'no' ) );
	}

	/**
	 * Per-field file count cap. WAPF uses PHP's configured batch limit when the
	 * field allows multiple files; the image-upload add-on's `max_files` count
	 * (-1 = unlimited) overrides it when the schema carries the key.
	 */
	public static function max_files( array $field ): int {
		if ( isset( $field['max_files'] ) && is_numeric( $field['max_files'] ) ) {
			$configured = (int) $field['max_files'];
			return -1 === $configured ? PHP_INT_MAX : max( 1, $configured );
		}
		return empty( $field['multiple'] ) ? 1 : max( 1, (int) ini_get( 'max_file_uploads' ) );
	}

	/** Native multipart form transport uses the same receiver and validation. */
	public static function native( \WC_Product $product ): array {
		$files = $_FILES['opf_upload'] ?? [];
		if ( ! is_array( $files ) || empty( $files['name'] ) ) return [];
		$pid = $product->get_id();
		if ( isset( self::$native_values[ $pid ] ) ) return self::$native_values[ $pid ];
		self::$native_values[ $pid ] = [];
		$request = new \WP_REST_Request( 'POST', '/opf/v1/uploads' );
		$request->set_header( 'origin', $_SERVER['HTTP_ORIGIN'] ?? '' );
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		$referer = $_SERVER['HTTP_REFERER'] ?? '';
		if ( ! $origin && $referer ) {
			$url = wp_parse_url( $referer );
			$request->set_header( 'origin', ( $url['scheme'] ?? '' ) . '://' . ( $url['host'] ?? '' ) . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ) );
		}
		$allowed = ! is_wp_error( self::same_origin( $request ) ) && ( $origin || $referer ) && wp_verify_nonce( (string) ( $_POST['opf_upload_native_nonce'] ?? '' ), 'opf_upload_native' );
		foreach ( FieldGroups::for_product( $product ) as $entry ) foreach ( $entry['group']->data['fields'] as $field ) {
			$gid = (string) $entry['id']; $fid = $field['id'];
			if ( 'upload' !== $field['type'] || ! isset( $files['name'][ $gid ][ $fid ] ) ) continue;
			$names = $files['name'][ $gid ][ $fid ];
			if ( ! is_array( $names ) ) $names = [ $names ];
			if ( count( $names ) > self::max_files( $field ) ) { self::$native_values[ $pid ][ $gid ][ $fid ] = [ 'invalid' ]; continue; }
			foreach ( $names as $index => $name ) {
				$file = [];
				foreach ( [ 'name', 'tmp_name', 'error', 'size', 'type' ] as $key ) $file[ $key ] = $files[ $key ][ $gid ][ $fid ][ $index ] ?? null;
				if ( UPLOAD_ERR_NO_FILE === $file['error'] ) continue;
				$request->set_param( 'product_id', $pid ); $request->set_param( 'group_id', $gid ); $request->set_param( 'field_id', $fid );
				$request->set_file_params( [ 'file' => $file ] );
				$response = $allowed ? self::receive( $request ) : self::error( 'opf_upload_nonce', __( 'Refresh the page before uploading.', 'open-product-fields-for-woocommerce' ), 403 );
				self::$native_values[ $pid ][ $gid ][ $fid ][] = is_wp_error( $response ) ? 'invalid' : $response->get_data()['token'];
			}
		}
		return self::$native_values[ $pid ];
	}

	/** Checkout revalidates references: removing/expiring bytes cannot create an order. */
	public static function check_cart(): void {
		if ( ! WC()->cart ) return;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( $product->is_type( 'variation' ) ) $product = wc_get_product( $product->get_parent_id() );
			$values = $item[ CartIntegration::ITEM_KEY ] ?? [];
			foreach ( FieldGroups::for_product( $product ) as $entry ) foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' !== $field['type'] || ! \OPF\Engine\Evaluator::is_visible( $field, $values[ $entry['id'] ] ?? [] ) ) continue;
				foreach ( self::validate_tokens( $field, self::tokens( $values[ $entry['id'] ][ $field['id'] ] ?? [] ), $product->get_id(), (string) $entry['id'] ) as $message ) wc_add_notice( $message, 'error' );
			}
		}
	}

	public static function order_links( $item_id, $item, $order ): void {
		foreach ( (array) $item->get_meta( '_opf_uploads', true ) as $file ) {
			if ( ! is_array( $file ) || empty( $file['token'] ) ) continue;
			$url = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'opf/v1/uploads/' . $file['token'] ) );
			echo '<p class="opf-order-upload"><a href="' . esc_url( $url ) . '">' . esc_html( $file['name'] ) . '</a></p>';
		}
	}

	public static function admin_order_links( $item_id, $item ): void {
		self::order_links( $item_id, $item, null );
	}

	public static function delete_order_button( \WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		$url = wp_nonce_url( add_query_arg( [ 'action' => 'opf_delete_order_uploads', 'order_id' => $order->get_id() ], admin_url( 'admin-post.php' ) ), 'opf_delete_order_uploads_' . $order->get_id() );
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Delete uploaded files', 'open-product-fields-for-woocommerce' ) . '</a></p>';
	}

	public static function delete_order_uploads(): void {
		$id = absint( $_GET['order_id'] ?? 0 );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'opf_delete_order_uploads_' . $id ) ) wp_die( 'Upload deletion is not authorized.', '', [ 'response' => 403 ] );
		$order = wc_get_order( $id );
		if ( ! $order ) wp_die( 'Order not found.', '', [ 'response' => 404 ] );
		foreach ( $order->get_items() as $item ) {
			foreach ( (array) $item->get_meta( '_opf_uploads', true ) as $file ) {
				if ( ! is_array( $file ) || empty( $file['token'] ) ) continue;
				$record = self::record( $file['token'] );
				if ( ! $record || (int) $record['order_id'] !== $id ) continue;
				try { self::delete( $file['token'] ); } catch ( \Throwable $error ) { wp_die( 'Could not delete private upload.', '', [ 'response' => 503 ] ); }
			}
			$item->delete_meta_data( '_opf_uploads' ); $item->save();
		}
		wp_safe_redirect( $order->get_edit_order_url() ); exit;
	}

	public static function routes(): void {
		register_rest_route( 'opf/v1', '/uploads/session', [
			'methods' => 'POST', 'callback' => [ __CLASS__, 'session' ],
			'permission_callback' => [ __CLASS__, 'same_origin' ],
		] );
		register_rest_route( 'opf/v1', '/uploads', [
			'methods' => 'POST', 'callback' => [ __CLASS__, 'receive' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
		] );
		register_rest_route( 'opf/v1', '/uploads/(?P<token>[a-f0-9]{64})', [
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'remove' ], 'permission_callback' => [ __CLASS__, 'permission' ] ],
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'download' ], 'permission_callback' => [ __CLASS__, 'same_origin' ] ],
		] );
	}

	/** Reject cross-origin browser requests, including the session bootstrap. */
	public static function same_origin( \WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		$home = wp_parse_url( home_url() );
		$given = wp_parse_url( $origin );
		if ( $origin && ( ! is_array( $given ) || strtolower( $given['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || ( $given['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) || ( $given['port'] ?? null ) !== ( $home['port'] ?? null ) ) ) {
			return self::error( 'opf_upload_origin', __( 'Upload request origin is not allowed.', 'open-product-fields-for-woocommerce' ), 403 );
		}
		return Renderer::visible_to_viewer() ? true : self::error( 'opf_upload_unavailable', __( 'Uploads are unavailable.', 'open-product-fields-for-woocommerce' ), 403 );
	}

	private static function load_session(): void {
		if ( ! WC()->session ) {
			wc_load_cart();
		}
		WC()->session->set_customer_session_cookie( true );
	}

	public static function owner(): string {
		self::load_session();
		return hash_hmac( 'sha256', (string) WC()->session->get_customer_id(), wp_salt( 'auth' ) );
	}

	public static function session(): \WP_REST_Response {
		self::load_session();
		$nonce = WC()->session->get( 'opf_upload_nonce' );
		if ( ! is_string( $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $nonce ) ) {
			$nonce = bin2hex( random_bytes( 32 ) );
			WC()->session->set( 'opf_upload_nonce', $nonce );
		}
		$response = new \WP_REST_Response( [ 'nonce' => $nonce ] );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	public static function permission( \WP_REST_Request $request ) {
		$allowed = self::same_origin( $request );
		if ( is_wp_error( $allowed ) ) return $allowed;
		self::load_session();
		$expected = WC()->session->get( 'opf_upload_nonce' );
		$given = $request->get_header( 'x-opf-upload-nonce' );
		return is_string( $expected ) && is_string( $given ) && strlen( $given ) === 64 && hash_equals( $expected, $given )
			? true : self::error( 'opf_upload_nonce', __( 'Refresh the page before uploading.', 'open-product-fields-for-woocommerce' ), 403 );
	}

	/** Resolve only an upload field currently applicable to a published product. */
	private static function field( int $product_id, string $gid, string $fid ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) return null;
		if ( $product->is_type( 'variation' ) ) $product = wc_get_product( $product->get_parent_id() );
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			if ( (string) $entry['id'] !== $gid ) continue;
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' === $field['type'] && $field['id'] === $fid ) return $field;
			}
		}
		return null;
	}

	/** Fail closed unless the storage root is outside all known public roots. */
	private static function root(): string {
		$configured = defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ? OPF_UPLOAD_PRIVATE_DIR : dirname( dirname( rtrim( ABSPATH, '/\\' ) ) ) . '/opf-private-uploads-' . substr( hash( 'sha256', ABSPATH ), 0, 16 );
		if ( ! is_string( $configured ) || ! $configured || is_link( $configured ) ) throw new \RuntimeException( 'Private upload storage is unavailable.' );
		$parent = realpath( dirname( $configured ) );
		if ( false === $parent ) throw new \RuntimeException( 'Private upload storage is unavailable.' );
		$path = $parent . '/' . basename( $configured );
		foreach ( [ ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? '' ] as $public ) {
			$public = $public ? realpath( $public ) : false;
			if ( $public && ( $path === $public || 0 === strpos( $path, $public . '/' ) ) ) throw new \RuntimeException( 'Private upload storage must be outside the web root.' );
		}
		if ( ! is_dir( $path ) && ! mkdir( $path, 0700 ) ) throw new \RuntimeException( 'Private upload storage is unavailable.' );
		if ( realpath( $path ) !== $path || ! is_writable( $path ) ) throw new \RuntimeException( 'Private upload storage is unavailable.' );
		chmod( $path, 0700 );
		return $path;
	}

	public static function record( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return null;
		$record = get_option( self::PREFIX . $token );
		return is_array( $record ) ? $record : null;
	}

	private static function path( string $token ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return null;
		$path = self::root() . '/' . $token . '.bin';
		return ! is_link( $path ) && is_file( $path ) && realpath( $path ) === $path ? $path : null;
	}

	/** Extension + WordPress allowlist + content MIME, never browser MIME. */
	public static function validate_file( array $file, array $field ) {
		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || ! is_file( $file['tmp_name'] ) ) return self::error( 'opf_upload_file', __( 'Choose a valid file.', 'open-product-fields-for-woocommerce' ) );
		$size = filesize( $file['tmp_name'] );
		$maximum = $field['max_size'] > 0 ? min( $field['max_size'] * MB_IN_BYTES, wp_max_upload_size() ) : wp_max_upload_size();
		if ( ! $size || $size > $maximum ) return self::error( 'opf_upload_size', __( 'The file exceeds the allowed size.', 'open-product-fields-for-woocommerce' ) );
		$name = sanitize_file_name( basename( $file['name'] ) );
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		// Executable and active-content formats stay forbidden even if an admin allows them.
		if ( ( $field['accepted_types'] && ! in_array( $ext, $field['accepted_types'], true ) ) || in_array( $ext, [ 'php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js', 'exe', 'sh' ], true ) ) return self::error( 'opf_upload_type', __( 'This file type is not allowed.', 'open-product-fields-for-woocommerce' ) );
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $name, get_allowed_mime_types() );
		if ( ! class_exists( \finfo::class ) ) return self::error( 'opf_upload_validation', __( 'Secure upload validation is unavailable.', 'open-product-fields-for-woocommerce' ), 503 );
		$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || $checked['ext'] !== $ext || $checked['type'] !== $mime ) return self::error( 'opf_upload_type', __( 'The file content does not match its allowed type.', 'open-product-fields-for-woocommerce' ) );
		$minimum_size = isset( $field['min_size_mb'] ) && is_numeric( $field['min_size_mb'] ) && (float) $field['min_size_mb'] > 0 ? (int) ceil( (float) $field['min_size_mb'] * MB_IN_BYTES ) : 0;
		if ( $minimum_size && $size < $minimum_size ) return self::error( 'opf_upload_min_size', __( 'The file is smaller than the minimum size.', 'open-product-fields-for-woocommerce' ) );
		$min_width  = isset( $field['min_width'] ) ? max( 0, (int) $field['min_width'] ) : 0;
		$min_height = isset( $field['min_height'] ) ? max( 0, (int) $field['min_height'] ) : 0;
		$aspect     = 'forced' === ( $field['image_editor_mode'] ?? '' ) && ! empty( $field['image_editor_crop'] ) && 'free' !== ( $field['image_editor_aspect_ratio'] ?? 'free' ) ? array_map( 'intval', explode( ':', (string) $field['image_editor_aspect_ratio'] ) ) : [];
		if ( $min_width || $min_height || $aspect ) {
			$dimensions = self::image_dimensions( $file['tmp_name'], (string) $mime );
			if ( null === $dimensions ) return self::error( 'opf_upload_image', __( 'The image could not be verified.', 'open-product-fields-for-woocommerce' ) );
			if ( $dimensions[0] < $min_width || $dimensions[1] < $min_height ) return self::error( 'opf_upload_dimensions', __( 'The image does not meet the minimum dimensions.', 'open-product-fields-for-woocommerce' ) );
			// Canvas output rounds each dimension independently: allow half a
			// pixel on both sides, |w*q - h*p| <= (p + q) / 2.
			if ( $aspect && ( count( $aspect ) !== 2 || $aspect[0] < 1 || $aspect[1] < 1 || abs( $dimensions[0] * $aspect[1] - $dimensions[1] * $aspect[0] ) > ( $aspect[0] + $aspect[1] ) / 2 ) ) return self::error( 'opf_upload_aspect', __( 'The image does not match its required crop aspect ratio.', 'open-product-fields-for-woocommerce' ) );
		}
		return [ 'name' => $name, 'mime' => $mime, 'size' => $size ];
	}

	/** Measured pixel dimensions once the file's image MIME is verified. */
	private static function image_dimensions( string $tmp, string $mime ): ?array {
		if ( 0 !== strpos( $mime, 'image/' ) ) return null;
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) || strtolower( (string) ( $info['mime'] ?? '' ) ) !== strtolower( $mime ) ) return null;
		return [ (int) $info[0], (int) $info[1] ];
	}

	public static function receive( \WP_REST_Request $request ) {
		$pid = (int) $request->get_param( 'product_id' );
		$gid = (string) $request->get_param( 'group_id' );
		$fid = (string) $request->get_param( 'field_id' );
		$field = self::field( $pid, $gid, $fid );
		if ( ! $field ) return self::error( 'opf_upload_field', __( 'This upload field is unavailable.', 'open-product-fields-for-woocommerce' ), 404 );
		$files = $request->get_file_params();
		$file = $files['file'] ?? [];
		$checked = self::validate_file( $file, $field );
		if ( is_wp_error( $checked ) ) return $checked;
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) return self::error( 'opf_upload_file', __( 'Choose a valid file.', 'open-product-fields-for-woocommerce' ) );
		$lock = null;
		try {
			$root = self::root();
			$owner = self::owner();
			// One site lock covers quota inspection, bytes, and durable metadata.
			$lock_path = $root . '/.upload.lock';
			if ( is_link( $lock_path ) ) throw new \RuntimeException();
			$lock = fopen( $lock_path, 'c' );
			if ( ! $lock || ! flock( $lock, LOCK_EX ) ) throw new \RuntimeException();
			chmod( $lock_path, 0600 );
			$site_files = 0; $site_bytes = 0;
			foreach ( glob( $root . '/*.bin' ) ?: [] as $stored ) {
				if ( is_link( $stored ) ) throw new \RuntimeException();
				$site_files++; $site_bytes += filesize( $stored );
			}
			$max_bytes = defined( 'OPF_UPLOAD_MAX_BYTES' ) ? max( 1, (int) OPF_UPLOAD_MAX_BYTES ) : 1024 * MB_IN_BYTES;
			$max_files = defined( 'OPF_UPLOAD_MAX_FILES' ) ? max( 1, (int) OPF_UPLOAD_MAX_FILES ) : 10000;
			if ( $site_files >= $max_files || $site_bytes + $checked['size'] > $max_bytes ) return self::error( 'opf_upload_capacity', __( 'Uploads are temporarily unavailable. Please contact the shop.', 'open-product-fields-for-woocommerce' ), 503 );
			$count = 0; $bytes = 0; $total = 0;
			foreach ( self::records() as $record ) {
				if ( $record['owner'] !== $owner || ! empty( $record['order_id'] ) || $record['created'] + self::TTL <= time() ) continue;
				$total++; $bytes += $record['size'];
				if ( $record['product_id'] === $pid && $record['group_id'] === $gid && $record['field_id'] === $fid ) $count++;
			}
			$budget = max( wp_max_upload_size(), (int) apply_filters( 'opf_upload_session_budget', 100 * MB_IN_BYTES ) );
			if ( $count >= self::max_files( $field ) || $total >= self::max_files( [ 'multiple' => true ] ) || $bytes + $checked['size'] > $budget ) return self::error( 'opf_upload_limit', __( 'Remove a file before uploading another.', 'open-product-fields-for-woocommerce' ) );
			$token = bin2hex( random_bytes( 32 ) );
			$path = $root . '/' . $token . '.bin';
			if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) throw new \RuntimeException();
			chmod( $path, 0600 );
			$record = $checked + [ 'owner' => $owner, 'product_id' => $pid, 'group_id' => $gid, 'field_id' => $fid, 'created' => time(), 'order_id' => 0, 'cart' => false ];
			if ( ! add_option( self::PREFIX . $token, $record, '', false ) ) { unlink( $path ); throw new \RuntimeException(); }
			return new \WP_REST_Response( [ 'token' => $token, 'name' => $checked['name'], 'size' => $checked['size'] ], 201, [ 'Cache-Control' => 'private, no-store' ] );
		} catch ( \Throwable $error ) {
			return self::error( 'opf_upload_storage', __( 'Private upload storage is unavailable.', 'open-product-fields-for-woocommerce' ), 503 );
		} finally {
			if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
		}
	}

	/** Preserve malformed tokens so optional fields cannot silently drop attacks. */
	public static function tokens( $value ): array {
		if ( '' === $value || null === $value || [] === $value ) return [];
		if ( ! is_array( $value ) ) $value = [ $value ];
		if ( count( $value ) > self::max_files( [ 'multiple' => true ] ) ) return [ 'invalid' ];
		foreach ( $value as $token ) if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return [ 'invalid' ];
		return array_values( array_unique( $value ) );
	}

	/** Return the existing WC order bound to this record, or null if stale. */
	private static function bound_order( array $record ): ?\WC_Order {
		$order_id = (int) ( $record['order_id'] ?? 0 );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) return null;
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * An order claims a token only while that order is no longer retryable:
	 * checkout-draft, pending, and failed bindings belong to the in-flight
	 * purchase, so the shopper's retry or re-checkout must still validate.
	 * A deleted order's binding is stale and cannot claim the file either.
	 */
	private static function claimed( array $record ): bool {
		$order = self::bound_order( $record );
		return $order && ! $order->has_status( 'checkout-draft' ) && ! $order->needs_payment();
	}

	public static function validate_tokens( array $field, array $tokens, int $pid, string $gid ): array {
		$min_files = isset( $field['min_files'] ) ? max( 0, (int) $field['min_files'] ) : 0;
		if ( ! $tokens ) {
			if ( ! empty( $field['required'] ) ) return [ sprintf( '"%s" is a required field.', $field['label'] ) ];
			return $min_files > 0 ? [ sprintf( '"%s" requires at least %d file(s).', $field['label'], $min_files ) ] : [];
		}
		if ( count( $tokens ) < $min_files ) return [ sprintf( '"%s" requires at least %d file(s).', $field['label'], $min_files ) ];
		if ( count( $tokens ) > self::max_files( $field ) ) return [ 'Too many uploaded files.' ];
		foreach ( $tokens as $token ) {
			$record = self::record( $token );
			try { $path = self::path( $token ); } catch ( \Throwable $error ) { $path = null; }
			if ( ! $record || ! $path || ! hash_equals( $record['owner'], self::owner() ) || $record['product_id'] !== $pid || $record['group_id'] !== $gid || $record['field_id'] !== $field['id'] || self::claimed( $record ) || $record['created'] + self::TTL <= time() ) return [ 'An uploaded file is unavailable. Please upload it again.' ];
			$checked = self::validate_file( [ 'name' => $record['name'], 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK ], $field );
			if ( is_wp_error( $checked ) || $checked['size'] !== $record['size'] ) return [ 'An uploaded file no longer meets this field’s requirements. Please upload it again.' ];
		}
		return [];
	}

	public static function validate_product( \WC_Product $product, array $values ): array {
		$errors = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) foreach ( $entry['group']->data['fields'] as $field ) {
			$given = $values[ $entry['id'] ] ?? [];
			if ( 'upload' !== $field['type'] || ! \OPF\Engine\Evaluator::is_visible( $field, $given ) ) continue;
			$errors = array_merge( $errors, self::validate_tokens( $field, self::tokens( $given[ $field['id'] ] ?? [] ), $product->get_id(), (string) $entry['id'] ) );
		}
		return $errors;
	}

	public static function display( array $tokens ): string {
		$names = [];
		foreach ( $tokens as $token ) { $record = self::record( $token ); if ( $record ) $names[] = $record['name']; }
		return implode( ', ', $names );
	}

	public static function mark_cart( array $values ): void {
		foreach ( $values as $fields ) foreach ( $fields as $tokens ) {
			if ( ! is_array( $tokens ) ) continue;
			foreach ( $tokens as $token ) {
				if ( ! is_string( $token ) ) continue;
				$record = self::record( $token );
				if ( $record && hash_equals( $record['owner'], self::owner() ) && ! self::claimed( $record ) ) {
					$record['cart'] = true; $record['created'] = time();
					update_option( self::PREFIX . $token, $record, false );
				}
			}
		}
	}

	public static function persist( array $values, \WC_Order_Item_Product $item, \WC_Order $order ): void {
		$references = [];
		foreach ( $values as $fields ) foreach ( $fields as $tokens ) {
			if ( ! is_array( $tokens ) ) continue;
			foreach ( $tokens as $token ) {
				if ( ! is_string( $token ) ) continue;
				$record = self::record( $token );
				if ( ! $record || ! hash_equals( $record['owner'], self::owner() ) || self::claimed( $record ) ) continue;
				if ( (int) $record['order_id'] !== (int) $order->get_id() ) {
					$record['order_id'] = $order->get_id();
					update_option( self::PREFIX . $token, $record, false );
				}
				$references[] = [ 'token' => $token, 'name' => $record['name'] ];
			}
		}
		if ( $references ) $item->add_meta_data( '_opf_uploads', $references, true );
	}

	/** Classic checkout saves the order after constructing its line items. */
	public static function bind_order( $item_id, $item, $order_id ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! $order_id ) return;
		foreach ( (array) $item->get_meta( '_opf_uploads', true ) as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['token'] ) ) continue;
			$record = self::record( $file['token'] );
			if ( $record && ! self::claimed( $record ) && hash_equals( $record['owner'], self::owner() ) ) {
				$record['order_id'] = (int) $order_id;
				update_option( self::PREFIX . $file['token'], $record, false );
			}
		}
	}

	/**
	 * Mint a fresh session-owned copy of an order-bound upload for order-again.
	 * UploadReissue has already proven the request may access the source file;
	 * this enforces the same storage limits as a fresh upload and reuses a live
	 * reissue for the same session+source so repeated reorder clicks cannot
	 * multiply private bytes. Returns the new token, or null on any failure.
	 *
	 * @param string $source_token Token of the order-bound source upload.
	 * @param array  $source       Source upload record.
	 */
	public static function reissue_token( string $source_token, array $source ): ?string {
		$lock = null;
		try {
			$root        = self::root();
			$source_path = self::path( $source_token );
			if ( ! $source_path ) {
				return null;
			}
			$lock_path = $root . '/.upload.lock';
			if ( is_link( $lock_path ) ) {
				throw new \RuntimeException();
			}
			$lock = fopen( $lock_path, 'c' );
			if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
				throw new \RuntimeException();
			}
			chmod( $lock_path, 0600 );
			$owner      = self::owner();
			$site_files = 0;
			$site_bytes = 0;
			foreach ( glob( $root . '/*.bin' ) ?: [] as $stored ) {
				if ( is_link( $stored ) ) {
					throw new \RuntimeException();
				}
				$site_files++;
				$site_bytes += filesize( $stored );
			}
			$total = 0;
			$bytes = 0;
			foreach ( self::records() as $token => $record ) {
				if ( ( $record['reissued_from'] ?? '' ) === $source_token && hash_equals( (string) $record['owner'], $owner ) && ! self::claimed( $record ) && $record['created'] + self::TTL > time() && null !== self::path( $token ) ) {
					return $token;
				}
				if ( $record['owner'] !== $owner || ! empty( $record['order_id'] ) || $record['created'] + self::TTL <= time() ) {
					continue;
				}
				$total++;
				$bytes += $record['size'];
			}
			$max_bytes = defined( 'OPF_UPLOAD_MAX_BYTES' ) ? max( 1, (int) OPF_UPLOAD_MAX_BYTES ) : 1024 * MB_IN_BYTES;
			$max_files = defined( 'OPF_UPLOAD_MAX_FILES' ) ? max( 1, (int) OPF_UPLOAD_MAX_FILES ) : 10000;
			$budget    = max( wp_max_upload_size(), (int) apply_filters( 'opf_upload_session_budget', 100 * MB_IN_BYTES ) );
			if ( $site_files >= $max_files || $site_bytes + $source['size'] > $max_bytes || $total >= self::max_files( [ 'multiple' => true ] ) || $bytes + $source['size'] > $budget ) {
				return null;
			}
			$token = bin2hex( random_bytes( 32 ) );
			$path  = $root . '/' . $token . '.bin';
			if ( ! copy( $source_path, $path ) ) {
				throw new \RuntimeException();
			}
			chmod( $path, 0600 );
			$record = [
				'name'          => $source['name'],
				'mime'          => $source['mime'],
				'size'          => $source['size'],
				'owner'         => $owner,
				'product_id'    => $source['product_id'],
				'group_id'      => $source['group_id'],
				'field_id'      => $source['field_id'],
				'created'       => time(),
				'order_id'      => 0,
				'cart'          => false,
				'reissued_from' => $source_token,
			];
			if ( ! add_option( self::PREFIX . $token, $record, '', false ) ) {
				unlink( $path );
				throw new \RuntimeException();
			}
			return $token;
		} catch ( \Throwable $error ) {
			return null;
		} finally {
			if ( is_resource( $lock ) ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
	}

	public static function remove( \WP_REST_Request $request ) {
		$token = (string) $request['token']; $record = self::record( $token );
		if ( ! $record || ! hash_equals( $record['owner'], self::owner() ) ) return self::error( 'opf_upload_missing', __( 'File not found.', 'open-product-fields-for-woocommerce' ), 404 );
		if ( ! empty( $record['cart'] ) || self::bound_order( $record ) ) return self::error( 'opf_upload_claimed', __( 'This file is attached to a cart or order.', 'open-product-fields-for-woocommerce' ), 409 );
		try { self::delete( $token ); } catch ( \Throwable $error ) { return self::error( 'opf_upload_storage', __( 'Private upload storage is unavailable.', 'open-product-fields-for-woocommerce' ), 503 ); }
		return new \WP_REST_Response( null, 204 );
	}

	public static function download( \WP_REST_Request $request ) {
		$token = (string) $request['token']; $record = self::record( $token );
		if ( ! $record ) return self::error( 'opf_upload_missing', __( 'File not found.', 'open-product-fields-for-woocommerce' ), 404 );
		$order = ! empty( $record['order_id'] ) ? wc_get_order( $record['order_id'] ) : null;
		$allowed = hash_equals( $record['owner'], self::owner() ) || ( $order && ( current_user_can( 'manage_woocommerce' ) || ( get_current_user_id() > 0 && $order->get_customer_id() === get_current_user_id() ) ) );
		if ( ! $allowed ) return self::error( 'opf_upload_missing', __( 'File not found.', 'open-product-fields-for-woocommerce' ), 404 );
		try { $path = self::path( $token ); } catch ( \Throwable $error ) { $path = null; }
		if ( ! $path || ( empty( $record['order_id'] ) && $record['created'] + self::TTL <= time() ) ) return self::error( 'opf_upload_missing', __( 'File not found.', 'open-product-fields-for-woocommerce' ), 404 );
		self::$download_path = $path;
		return new \WP_REST_Response( null, 200, [ 'Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="' . str_replace( [ '"', "\r", "\n" ], '', $record['name'] ) . '"', 'Content-Length' => $record['size'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff' ] );
	}

	public static function serve_download( bool $served, $result, \WP_REST_Request $request, $server ): bool {
		if ( null === self::$download_path || 'GET' !== $request->get_method() || 0 !== strpos( $request->get_route(), '/opf/v1/uploads/' ) ) return $served;
		readfile( self::$download_path ); self::$download_path = null;
		return true;
	}

	private static function records(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ), ARRAY_A );
		$records = [];
		foreach ( $rows as $row ) {
			$token = substr( $row['option_name'], strlen( self::PREFIX ) );
			if ( preg_match( '/^[a-f0-9]{64}$/', $token ) ) { $record = maybe_unserialize( $row['option_value'] ); if ( is_array( $record ) ) $records[ $token ] = $record; }
		}
		return $records;
	}

	private static function delete( string $token ): void {
		$path = self::path( $token );
		if ( $path && ! unlink( $path ) ) throw new \RuntimeException();
		delete_option( self::PREFIX . $token );
	}

	public static function cleanup(): void {
		$lock = null;
		try {
			$root = self::root();
			if ( is_link( $root . '/.upload.lock' ) ) return;
			$lock = fopen( $root . '/.upload.lock', 'c' );
			if ( ! $lock || ! flock( $lock, LOCK_EX ) ) return;
			$records = self::records();
			foreach ( $records as $token => $record ) {
				// Keep files attached to any extant order, even retryable drafts;
				// a deleted/GC'd order leaves a stale reference that TTL can reap.
				if ( self::bound_order( $record ) || $record['created'] + self::TTL > time() ) continue;
				self::delete( $token );
			}
			// Recover interrupted moves whose database record was never written.
			foreach ( glob( $root . '/*.bin' ) ?: [] as $path ) {
				$token = basename( $path, '.bin' );
				if ( preg_match( '/^[a-f0-9]{64}$/', $token ) && ! isset( $records[ $token ] ) && ! is_link( $path ) && filemtime( $path ) + self::TTL <= time() ) unlink( $path );
			}
		} catch ( \Throwable $error ) {
			// Retry on the next scheduled cleanup, preserving metadata on failure.
		} finally {
			if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
		}
	}

	/** Messages arrive already translated: every call site wraps its own literal in __(). */
	private static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
