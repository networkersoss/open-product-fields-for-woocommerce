<?php
/** Merchant-managed font files for product-local live previews. */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class LivePreviewFonts {
	public const OPTION = 'opf_live_preview_fonts';
	public const MAX_FONTS = 20;
	private const MAX_BYTES = 5242880;
	/** @var array<string,bool> */
	private static $enqueued_handles = [];

	public static function init(): void {
		add_action( 'woocommerce_admin_field_opf_preview_font_manager', [ __CLASS__, 'render_settings_field' ] );
		add_action( 'woocommerce_settings_save_products', [ __CLASS__, 'save_settings_upload' ] );
	}

	/** Rendered inside WooCommerce → Settings → Products → Live Content Preview. */
	public static function render_settings_field( array $field ): void {
		$fonts = self::registered();
		?>
		<tr valign="top"><th scope="row"><?php echo esc_html( $field['title'] ?? __( 'Custom preview fonts', 'open-product-fields-for-woocommerce' ) ); ?></th><td>
			<p><?php esc_html_e( 'Upload named WOFF or WOFF2 files (maximum 5 MB each). Use the registered name in a preview style or map it from a local Select field.', 'open-product-fields-for-woocommerce' ); ?></p>
			<p><label for="opf-preview-font-name"><?php esc_html_e( 'Font name', 'open-product-fields-for-woocommerce' ); ?></label> <input id="opf-preview-font-name" name="opf_preview_font_name" type="text" maxlength="64" autocomplete="off"></p>
			<p><label for="opf-preview-font-file"><?php esc_html_e( 'Font file', 'open-product-fields-for-woocommerce' ); ?></label> <input id="opf-preview-font-file" name="opf_preview_font_file" type="file" accept=".woff,.woff2"></p>
			<?php if ( $fonts ) : ?>
				<p><label for="opf-preview-font-delete"><?php esc_html_e( 'Remove a registered font', 'open-product-fields-for-woocommerce' ); ?></label> <select id="opf-preview-font-delete" name="opf_preview_font_delete"><option value=""><?php esc_html_e( 'Keep all fonts', 'open-product-fields-for-woocommerce' ); ?></option><?php foreach ( $fonts as $font ) : ?><option value="<?php echo esc_attr( $font['id'] ); ?>"><?php echo esc_html( $font['name'] . ' (' . strtoupper( $font['format'] ) . ')' ); ?></option><?php endforeach; ?></select></p>
				<ul><?php foreach ( $fonts as $font ) : ?><li><?php echo esc_html( $font['name'] . ' (' . strtoupper( $font['format'] ) . ')' ); ?></li><?php endforeach; ?></ul>
			<?php else : ?><p><?php esc_html_e( 'No custom fonts registered yet.', 'open-product-fields-for-woocommerce' ); ?></p><?php endif; ?>
			<?php if ( class_exists( '\WC_Admin_Settings' ) ) : ?><p class="description"><?php esc_html_e( 'Use the Save changes button below to upload or remove a font.', 'open-product-fields-for-woocommerce' ); ?></p><?php endif; ?>
		</td></tr>
		<?php
	}

	/** Add safe WOFF/WOFF2 rules after the target stylesheet is enqueued. */
	public static function enqueue_faces( string $style_handle ): void {
		if ( ! function_exists( 'wp_add_inline_style' ) || isset( self::$enqueued_handles[ $style_handle ] ) ) {
			return;
		}
		$rules = [];
		foreach ( self::registered() as $font ) {
			$family = self::css_family( $font['name'] );
			$rules[] = '@font-face{font-family:"' . $family . '";src:url("' . esc_url( $font['url'] ) . '") format("' . $font['format'] . '");font-style:normal;font-weight:100 900;font-display:swap;}';
		}
		if ( $rules ) {
			wp_add_inline_style( $style_handle, implode( "\n", $rules ) );
			self::$enqueued_handles[ $style_handle ] = true;
		}
	}

	/** @return array<string,array{id:string,name:string,url:string,file:string,format:string,mime:string}> */
	public static function registered(): array {
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$fonts = [];
		$upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [];
		$allowed_url_prefix = ! empty( $upload['baseurl'] ) ? trailingslashit( $upload['baseurl'] ) . 'opf-preview-fonts/' : '';
		foreach ( $raw as $id => $font ) {
			if ( ! is_array( $font ) || ! preg_match( '/^[a-f0-9-]{16,40}$/iD', (string) $id ) ) {
				continue;
			}
			$name = self::normalize_font_name( $font['name'] ?? '' );
			$format = in_array( $font['format'] ?? '', [ 'woff', 'woff2' ], true ) ? $font['format'] : '';
			$mime = self::mime_for_extension( $format );
			$file = is_string( $font['file'] ?? null ) ? ltrim( $font['file'], '/' ) : '';
			$url = is_string( $font['url'] ?? null ) ? esc_url_raw( $font['url'] ) : '';
			if ( '' === $name || '' === $format || ( $font['mime'] ?? '' ) !== $mime || ! preg_match( '#^opf-preview-fonts/[a-zA-Z0-9._-]+\.' . $format . '$#D', $file ) || '' === $url || '' === $allowed_url_prefix || 0 !== strpos( $url, $allowed_url_prefix ) ) {
				continue;
			}
			$fonts[ (string) $id ] = [ 'id' => (string) $id, 'name' => $name, 'url' => $url, 'file' => $file, 'format' => $format, 'mime' => $mime ];
		}
		return array_slice( $fonts, 0, self::MAX_FONTS, true );
	}

	public static function is_valid_font_file( string $path, string $extension, string $mime = '' ): bool {
		$extension = strtolower( $extension );
		$expected_mime = self::mime_for_extension( $extension );
		$allowed_mimes = [
			'woff'  => [ 'font/woff', 'application/font-woff', 'application/x-font-woff', 'application/octet-stream' ],
			'woff2' => [ 'font/woff2', 'application/font-woff2', 'application/octet-stream' ],
		];
		if ( '' === $expected_mime || ( '' !== $mime && ! in_array( strtolower( $mime ), $allowed_mimes[ $extension ] ?? [], true ) ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 4 || $size > self::MAX_BYTES ) {
			return false;
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$signature = fread( $handle, 4 );
		fclose( $handle );
		return ( 'woff' === $extension && 'wOFF' === $signature ) || ( 'woff2' === $extension && 'wOF2' === $signature );
	}

	public static function normalize_font_name( $name ): string {
		if ( ! is_string( $name ) ) {
			return '';
		}
		$name = trim( $name );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9 _-]{0,63}$/D', $name ) ? $name : '';
	}

	/** Process optional upload/removal when WooCommerce saves the dedicated settings section. */
	public static function save_settings_upload(): void {
		if ( 'opf_live_preview' !== ( $_GET['section'] ?? '' ) || ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'woocommerce-settings' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}
		$fonts = self::registered();
		$delete_id = isset( $_POST['opf_preview_font_delete'] ) ? sanitize_text_field( wp_unslash( $_POST['opf_preview_font_delete'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $delete_id && isset( $fonts[ $delete_id ] ) ) {
			self::delete_file( $fonts[ $delete_id ]['file'] );
			unset( $fonts[ $delete_id ] );
		}
		$file = $_FILES['opf_preview_font_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$has_upload = is_array( $file ) && ( UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' !== (string) ( $file['name'] ?? '' ) );
		if ( $has_upload ) {
			$name = self::normalize_font_name( isset( $_POST['opf_preview_font_name'] ) ? wp_unslash( $_POST['opf_preview_font_name'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$filename = is_string( $file['name'] ?? null ) ? sanitize_file_name( $file['name'] ) : '';
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$mime = is_string( $file['type'] ?? null ) ? sanitize_mime_type( $file['type'] ) : '';
			if ( '' === $name || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! self::is_valid_font_file( (string) ( $file['tmp_name'] ?? '' ), $extension, $mime ) ) {
				self::settings_message( false, __( 'Upload a valid WOFF or WOFF2 file no larger than 5 MB and provide a plain font name.', 'open-product-fields-for-woocommerce' ) );
				return;
			}
			$matching_id = '';
			foreach ( $fonts as $id => $registered ) {
				if ( 0 === strcasecmp( $registered['name'], $name ) ) {
					$matching_id = (string) $id;
					break;
				}
			}
			if ( '' === $matching_id && count( $fonts ) >= self::MAX_FONTS ) {
				self::settings_message( false, __( 'The custom font limit has been reached. Remove a font before adding another.', 'open-product-fields-for-woocommerce' ) );
				return;
			}
			$upload = wp_upload_dir();
			if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
				self::settings_message( false, __( 'The WordPress upload directory is unavailable.', 'open-product-fields-for-woocommerce' ) );
				return;
			}
			$directory = trailingslashit( $upload['basedir'] ) . 'opf-preview-fonts';
			if ( ! wp_mkdir_p( $directory ) ) {
				self::settings_message( false, __( 'The preview font directory could not be created.', 'open-product-fields-for-woocommerce' ) );
				return;
			}
			$font_slug = sanitize_title( $name );
			$stored_name = wp_unique_filename( $directory, $font_slug . '.' . $extension );
			$destination = trailingslashit( $directory ) . $stored_name;
			if ( ! move_uploaded_file( (string) $file['tmp_name'], $destination ) ) {
				self::settings_message( false, __( 'The preview font could not be stored.', 'open-product-fields-for-woocommerce' ) );
				return;
			}
			@chmod( $destination, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$id = $matching_id ?: strtolower( wp_generate_uuid4() );
			$old_file = $matching_id ? $fonts[ $matching_id ]['file'] : '';
			$relative_file = 'opf-preview-fonts/' . $stored_name;
			$fonts[ $id ] = [ 'id' => $id, 'name' => $name, 'url' => trailingslashit( $upload['baseurl'] ) . $relative_file, 'file' => $relative_file, 'format' => $extension, 'mime' => self::mime_for_extension( $extension ) ];
			update_option( self::OPTION, $fonts, false );
			if ( '' !== $old_file ) {
				self::delete_file( $old_file );
			}
			self::settings_message( true, __( 'Font uploaded and registered.', 'open-product-fields-for-woocommerce' ) );
			return;
		}
		if ( '' !== $delete_id && isset( $_POST['opf_preview_font_delete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( self::OPTION, $fonts, false );
			self::settings_message( true, __( 'Font removed.', 'open-product-fields-for-woocommerce' ) );
		}
	}

	private static function delete_file( string $relative_file ): void {
		$upload = wp_upload_dir();
		$base = ! empty( $upload['basedir'] ) ? realpath( trailingslashit( $upload['basedir'] ) . 'opf-preview-fonts' ) : false;
		$path = $base ? realpath( trailingslashit( $upload['basedir'] ) . ltrim( $relative_file, '/' ) ) : false;
		if ( $base && $path && 0 === strpos( $path, trailingslashit( $base ) ) ) {
			wp_delete_file( $path );
		}
	}

	private static function mime_for_extension( string $extension ): string {
		return [ 'woff' => 'font/woff', 'woff2' => 'font/woff2' ][ strtolower( $extension ) ] ?? '';
	}

	private static function css_family( string $family ): string {
		return preg_replace( '/[^A-Za-z0-9 _-]/', '', $family );
	}

	private static function settings_message( bool $success, string $message ): void {
		if ( ! class_exists( '\WC_Admin_Settings' ) ) {
			return;
		}
		if ( $success ) {
			\WC_Admin_Settings::add_message( $message );
		} else {
			\WC_Admin_Settings::add_error( $message );
		}
	}
}
