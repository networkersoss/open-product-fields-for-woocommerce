<?php
/**
 * Upload validation, private storage, resolution, and cleanup.
 *
 * Files live outside the served webroot in `.opf-private/` with opaque
 * 48-hex tokens. Legacy files under `<uploads>/.opf-private/` are moved into
 * the private directory without changing their token or contents. The
 * customer-visible name is metadata only; nothing about the stored filename
 * reveals the original name, type, or order. Validation and the $_FILES
 * normalizer are pure PHP so they run in the unit suite; storage, resolution,
 * and cleanup use WordPress/WooCommerce APIs.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class UploadService {

	/**
	 * Private storage directory name, outside the served webroot by default.
	 */
	public const DIR_NAME = '.opf-private';

	/**
	 * Stored files are named with a 48-character lowercase hex token.
	 */
	public const TOKEN_PATTERN = '/^[a-f0-9]{48}$/';

	/** Woo session key for uploads waiting to be attached to a cart line. */
	private const STAGED_SESSION_KEY = 'opf_staged_uploads';

	/** Written only after all legacy public-directory files were preserved. */
	private const MIGRATION_MARKER = '.legacy-migrated-v1';

	/** Maximum legacy token files handled by one fallback copy pass. */
	private const LEGACY_MIGRATION_LIMIT = 10000;

	/** Avoid repeating a successful legacy scan within one PHP request. */
	private static bool $legacy_migration_checked = false;

	/** Avoid emitting the same migration error repeatedly within one request. */
	private static bool $legacy_migration_error_logged = false;

	/**
	 * Extensions that execute or render as markup. Private storage still
	 * refusing these keeps a stolen token from becoming stored XSS if an
	 * administrator configures a server-specific storage override incorrectly.
	 */
	private const BLOCKED_EXTENSIONS = [
		'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pl', 'py',
		'cgi', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
		'js', 'mjs', 'cjs',
		'html', 'htm', 'xhtml', 'xht', 'svg', 'svgz', 'swf',
		'htaccess',
	];

	/**
	 * Flatten $_FILES input into a list of canonical file arrays.
	 *
	 * Handles PHP's parallel-array structure (`name`/`type`/`tmp_name`/
	 * `error`/`size`), nested group and field keys, single-file entries, and
	 * `[]` multi-file controls.
	 *
	 * @param array<mixed> $input $_FILES-style array.
	 * @param string       $gid   Optional group id to descend into.
	 * @param string       $fid   Optional field id to descend into.
	 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	public static function normalize_files( array $input, string $gid = '', string $fid = '' ): array {
		$path = array_values( array_filter( [ $gid, $fid ], static fn( string $part ): bool => '' !== $part ) );
		return self::collect_files( $input, $path );
	}

	/**
	 * Depth-first collection through a $_FILES tree.
	 *
	 * @param mixed    $node Current node.
	 * @param string[] $path Remaining group/field keys.
	 * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	private static function collect_files( $node, array $path ): array {
		if ( ! is_array( $node ) ) {
			return [];
		}
		if ( isset( $node['name'] ) && ! is_array( $node['name'] ) ) {
			return [ self::file( $node ) ];
		}
		if ( isset( $node['name'] ) ) {
			$node = self::transpose( $node );
		}

		if ( $path ) {
			$key = array_shift( $path );
			return array_key_exists( $key, $node ) ? self::collect_files( $node[ $key ], $path ) : [];
		}

		$files = [];
		foreach ( $node as $child ) {
			$files = array_merge( $files, self::collect_files( $child, [] ) );
		}
		return $files;
	}

	/**
	 * Turn PHP's parallel $_FILES arrays into per-file entries.
	 *
	 * @param array<mixed> $node Parallel structure.
	 * @return array<mixed>
	 */
	private static function transpose( array $node ): array {
		$out = [];
		foreach ( array_keys( $node['name'] ) as $key ) {
			$child = [];
			foreach ( [ 'name', 'type', 'tmp_name', 'error', 'size' ] as $prop ) {
				if ( isset( $node[ $prop ] ) && is_array( $node[ $prop ] ) && array_key_exists( $key, $node[ $prop ] ) ) {
					$child[ $prop ] = $node[ $prop ][ $key ];
				}
			}
			$out[ $key ] = $child;
		}
		return $out;
	}

	/**
	 * Canonical shape for one file entry.
	 *
	 * @param array<mixed> $entry Raw entry.
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
	 */
	private static function file( array $entry ): array {
		return [
			'name'     => (string) ( $entry['name'] ?? '' ),
			'type'     => (string) ( $entry['type'] ?? '' ),
			'tmp_name' => (string) ( $entry['tmp_name'] ?? '' ),
			'error'    => (int) ( $entry['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $entry['size'] ?? 0 ),
		];
	}

	/**
	 * Whether at least one entry is a real upload attempt.
	 *
	 * @param array<int,array<string,mixed>> $files Normalized files.
	 */
	public static function has_files( array $files ): bool {
		return self::attempts( $files ) > 0;
	}

	/**
	 * Number of real upload attempts (ignores empty file inputs).
	 *
	 * @param array<int,array<string,mixed>> $files Normalized files.
	 */
	public static function attempts( array $files ): int {
		$count = 0;
		foreach ( $files as $file ) {
			if ( is_array( $file ) && (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Pure constraint validation: count, upload error, size, type, and dimensions.
	 *
	 * @param array<int,array<string,mixed>> $files Normalized files.
	 * @param array<string,mixed>            $field Field definition.
	 * @return string[]
	 */
	public static function validate( array $files, array $field ): array {
		$max_files = (int) ( $field['max_files'] ?? 1 );
		$min_files = max( 0, (int) ( $field['min_files'] ?? 0 ) );
		$max_size  = max( 1, (int) ( $field['max_size'] ?? 10485760 ) );
		$allowed   = self::allowed_types( $field );
		$label     = (string) ( $field['label'] ?? '' );
		$errors    = [];

		$file_count = self::attempts( $files );
		if ( $min_files > $file_count ) {
			$errors[] = sprintf( '"%s" requires at least %d file(s).', $label, $min_files );
		}
		if ( -1 !== $max_files && $file_count > $max_files ) {
			$errors[] = sprintf( '"%s" allows at most %d file(s).', $label, $max_files );
		}

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
				continue;
			}
			if ( (int) ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
				$errors[] = sprintf( '"%s" contains an upload error.', $label );
				continue;
			}
			if ( (int) ( $file['size'] ?? 0 ) > $max_size ) {
				$errors[] = sprintf( '"%s" contains a file larger than the permitted size.', $label );
				continue;
			}
			if ( ! empty( $field['min_size_mb'] ) ) {
				$actual_size = self::measured_size( $file );
				$minimum     = (int) ceil( (float) $field['min_size_mb'] * 1048576 );
				if ( null === $actual_size ) {
					$errors[] = sprintf( '"%s" file size could not be verified.', $label );
					continue;
				}
				if ( $actual_size < $minimum ) {
					$errors[] = sprintf( '"%s" contains a file smaller than the minimum size.', $label );
					continue;
				}
			}
			if ( ! self::type_is_allowed( $file, $allowed ) ) {
				$errors[] = sprintf( '"%s" contains a file type that is not allowed.', $label );
				continue;
			}
			if ( ! empty( $field['min_width'] ) || ! empty( $field['min_height'] ) ) {
				$tmp = (string) ( $file['tmp_name'] ?? '' );
				if ( '' === $tmp && ! empty( $file['token'] ) ) {
					$tmp = (string) ( self::path( (string) $file['token'] ) ?? '' );
				}
				$dimensions = self::verified_image_dimensions( $tmp, (string) ( $file['name'] ?? '' ), $allowed );
				if ( null === $dimensions ) {
					$errors[] = sprintf( '"%s" contains an image that could not be verified.', $label );
					continue;
				}
				if ( $dimensions[0] < (int) ( $field['min_width'] ?? 0 ) ) {
					$errors[] = sprintf( '"%s" image must be at least %d pixels wide.', $label, (int) $field['min_width'] );
				}
				if ( $dimensions[1] < (int) ( $field['min_height'] ?? 0 ) ) {
					$errors[] = sprintf( '"%s" image must be at least %d pixels high.', $label, (int) $field['min_height'] );
				}
			}
			$aspect_tmp = (string) ( $file['tmp_name'] ?? '' );
			if ( '' === $aspect_tmp && ! empty( $file['token'] ) ) {
				$aspect_tmp = (string) ( self::path( (string) $file['token'] ) ?? '' );
			}
			if ( ! self::forced_aspect_matches( $aspect_tmp, (string) ( $file['name'] ?? '' ), $allowed, $field ) ) {
				$errors[] = sprintf( '"%s" image does not match its required crop aspect ratio.', $label );
			}
		}

		return $errors;
	}

	/**
	 * Normalized allow-list: lowercase extensions without a leading dot, plus
	 * MIME types (optionally with a trailing `/*` wildcard).
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @return string[]
	 */
	private static function allowed_types( array $field ): array {
		$out = [];
		foreach ( (array) ( $field['allowed_types'] ?? [] ) as $entry ) {
			$entry = ltrim( strtolower( trim( (string) $entry ) ), '.' );
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Check a client-reported file against the allow-list. Content is verified
	 * again at storage time with the WordPress MIME checker.
	 *
	 * @param array<string,mixed> $file    Normalized file entry.
	 * @param string[]            $allowed Normalized allow-list.
	 */
	private static function type_is_allowed( array $file, array $allowed ): bool {
		if ( empty( $allowed ) ) {
			return true;
		}
		$ext  = ltrim( strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) ), '.' );
		$mime = strtolower( (string) ( $file['type'] ?? '' ) );
		foreach ( $allowed as $entry ) {
			if ( $entry === $ext || self::mime_is_allowed( $mime, [ $entry ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read dimensions only after Fileinfo verifies image bytes, then require
	 * PHP and WordPress to agree on the actual MIME type.
	 *
	 * @param string   $tmp     Temporary file path.
	 * @param string   $name    Original filename, used only by the WP checker.
	 * @param string[] $allowed Configured type allow-list.
	 * @return array{0:int,1:int}|null
	 */
	private static function verified_image_dimensions( string $tmp, string $name, array $allowed ): ?array {
		if ( '' === $tmp || ! is_file( $tmp ) || ! is_readable( $tmp ) || ! class_exists( '\\finfo' ) ) {
			return null;
		}
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = strtolower( (string) $finfo->file( $tmp ) );
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}
		if ( ! empty( $allowed ) && ! self::mime_is_allowed( $mime, self::allowed_mimes( $allowed ) ) ) {
			return null;
		}
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$checked = wp_check_filetype_and_ext( $tmp, $name, self::mime_subset( $allowed ) ?: null );
			if ( empty( $checked['type'] ) || strtolower( (string) $checked['type'] ) !== $mime || empty( $checked['ext'] ) ) {
				return null;
			}
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) || strtolower( (string) ( $info['mime'] ?? '' ) ) !== $mime ) {
			return null;
		}
		return [ (int) $info[0], (int) $info[1] ];
	}

	/** Resize an oversized image with the WP editor, preserving aspect ratio. */
	private static function resize_image( string $tmp, string $name, string $mime, string $extension, array $allowed, int $max_width, int $max_height ): ?string {
		$dimensions = self::verified_image_dimensions( $tmp, $name, $allowed );
		if ( null === $dimensions ) {
			return null;
		}
		if ( ( 0 === $max_width || $dimensions[0] <= $max_width ) && ( 0 === $max_height || $dimensions[1] <= $max_height ) ) {
			return $tmp;
		}
		if ( ! function_exists( 'wp_get_image_editor' ) || ! function_exists( 'is_wp_error' ) ) {
			return null;
		}
		$editor = wp_get_image_editor( $tmp );
		if ( is_wp_error( $editor ) || ! is_object( $editor ) || ! method_exists( $editor, 'resize' ) || ! method_exists( $editor, 'save' ) ) {
			return null;
		}
		$resize = $editor->resize( $max_width > 0 ? $max_width : null, $max_height > 0 ? $max_height : null, false );
		if ( is_wp_error( $resize ) || true !== $resize ) {
			return null;
		}
		try {
			$destination = sys_get_temp_dir() . '/opf-resized-' . bin2hex( random_bytes( 12 ) ) . '.' . $extension;
		} catch ( \Exception $e ) {
			return null;
		}
		$saved       = $editor->save( $destination, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( (string) $saved['path'] ) ) {
			if ( is_file( $destination ) ) {
				unlink( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return null;
		}
		$path = (string) $saved['path'];
		$check = function_exists( 'wp_check_filetype_and_ext' ) ? wp_check_filetype_and_ext( $path, 'resized.' . $extension, self::mime_subset( $allowed ) ?: null ) : [];
		$after = self::verified_image_dimensions( $path, 'resized.' . $extension, $allowed );
		if ( empty( $check['ext'] ) || strtolower( (string) ( $check['type'] ?? '' ) ) !== $mime || null === $after || ( $max_width > 0 && $after[0] > $max_width ) || ( $max_height > 0 && $after[1] > $max_height ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return null;
		}
		return $path;
	}

	/** Return file bytes from a readable temp file or owned staged token only. */
	private static function measured_size( array $file ): ?int {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp && ! empty( $file['token'] ) ) {
			$tmp = (string) ( self::path( (string) $file['token'] ) ?? '' );
		}
		if ( '' === $tmp || ! is_file( $tmp ) || ! is_readable( $tmp ) ) {
			return null;
		}
		$size = filesize( $tmp );
		return false === $size ? null : (int) $size;
	}

	/** Move a generated resize output, with verified cross-filesystem fallback. */
	private static function move_resized_file( string $source, string $destination ): bool {
		if ( rename( $source, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			return false;
		}
		try {
			$staging = $destination . '.copy-' . bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			return false;
		}
		if ( ! copy( $source, $staging ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( is_file( $staging ) ) {
				unlink( $staging ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return false;
		}
		$source_size = filesize( $source );
		$copy_size   = filesize( $staging );
		$source_hash = hash_file( 'sha256', $source );
		$copy_hash   = hash_file( 'sha256', $staging );
		if ( false === $source_size || false === $copy_size || $source_size !== $copy_size || false === $source_hash || ! hash_equals( $source_hash, (string) $copy_hash ) || ! rename( $staging, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( is_file( $staging ) ) {
				unlink( $staging ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return false;
		}
		unlink( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return true;
	}

	/**
	 * Resolve extension allow-list entries to their registered MIME types.
	 *
	 * @param string[] $allowed Configured type allow-list.
	 * @return string[]
	 */
	private static function allowed_mimes( array $allowed ): array {
		$mimes = array_values( array_filter( $allowed, static fn( $entry ): bool => false !== strpos( $entry, '/' ) ) );
		if ( function_exists( 'wp_get_mime_types' ) ) {
			foreach ( wp_get_mime_types() as $extensions => $mime ) {
				foreach ( explode( '|', (string) $extensions ) as $extension ) {
					if ( in_array( strtolower( $extension ), $allowed, true ) ) {
						$mimes[] = strtolower( (string) $mime );
						break;
					}
				}
			}
		} else {
			$known_image_mimes = [
				'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
				'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'avif' => 'image/avif',
			];
			foreach ( $allowed as $extension => $entry ) {
				if ( isset( $known_image_mimes[ $entry ] ) ) {
					$mimes[] = $known_image_mimes[ $entry ];
				}
			}
		}
		return array_values( array_unique( $mimes ) );
	}

	/** Enforce forced fixed-ratio editor output from actual image bytes. */
	private static function forced_aspect_matches( string $tmp, string $name, array $allowed, array $field ): bool {
		if ( 'forced' !== ( $field['image_editor_mode'] ?? '' ) || empty( $field['image_editor_crop'] ) || 'free' === ( $field['image_editor_aspect_ratio'] ?? 'free' ) ) {
			return true;
		}
		$parts = array_map( 'intval', explode( ':', (string) $field['image_editor_aspect_ratio'] ) );
		$dimensions = self::verified_image_dimensions( $tmp, $name, $allowed );
		if ( count( $parts ) !== 2 || $parts[0] < 1 || $parts[1] < 1 || null === $dimensions ) {
			return false;
		}
		// Each canvas dimension rounds independently, so allow half a pixel on
		// both sides of the ratio: |w*q - h*p| <= (p + q) / 2.
		$tolerance = ( $parts[0] + $parts[1] ) / 2;
		return abs( $dimensions[0] * $parts[1] - $dimensions[1] * $parts[0] ) <= $tolerance;
	}

	/**
	 * Move one validated upload into private storage.
	 *
	 * @param array<string,mixed> $file  Normalized file entry.
	 * @param array<string,mixed> $field Field definition.
	 * @return array{token:string,name:string,size:int,mime:string,ext:string}|null
	 */
	public static function store( array $file, array $field ): ?array {
		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		$name = (string) ( $file['name'] ?? 'upload' );
		if ( '' === $tmp || ! self::is_uploaded( $tmp ) ) {
			return null;
		}
		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'wp_mkdir_p' ) || ! function_exists( 'wp_check_filetype_and_ext' ) || ! function_exists( 'sanitize_file_name' ) ) {
			return null;
		}
		$real_size = filesize( $tmp );
		$size      = false !== $real_size ? (int) $real_size : (int) ( $file['size'] ?? 0 );
		if ( $size > max( 1, (int) ( $field['max_size'] ?? 10485760 ) ) ) {
			return null;
		}
		if ( ! empty( $field['min_size_mb'] ) && $size < (int) ceil( (float) $field['min_size_mb'] * 1048576 ) ) {
			return null;
		}

		$allowed = self::allowed_types( $field );
		$mimes   = self::mime_subset( $allowed );
		$checked = wp_check_filetype_and_ext( $tmp, $name, $mimes ?: null );
		if ( empty( $checked['ext'] ) ) {
			return null;
		}

		// The checker resolved the real content type; re-verify it against the
		// allow-list so a client cannot claim an allowed MIME for other bytes,
		// and refuse executables/markup outright.
		$real = [
			'name' => 'file.' . strtolower( (string) $checked['ext'] ),
			'type' => strtolower( (string) ( $checked['type'] ?? '' ) ),
		];
		if ( ! self::type_is_allowed( $real, $allowed ) || self::extension_is_blocked( (string) $checked['ext'] ) ) {
			return null;
		}
		if ( ! empty( $field['min_width'] ) || ! empty( $field['min_height'] ) ) {
			$dimensions = self::verified_image_dimensions( $tmp, $name, $allowed );
			if ( null === $dimensions || $dimensions[0] < (int) ( $field['min_width'] ?? 0 ) || $dimensions[1] < (int) ( $field['min_height'] ?? 0 ) ) {
				return null;
			}
		}
		if ( ! self::forced_aspect_matches( $tmp, $name, $allowed, $field ) ) {
			return null;
		}

		$dir = self::dir();
		if ( null === $dir ) {
			return null;
		}

		try {
			$token = bin2hex( random_bytes( 24 ) );
		} catch ( \Exception $e ) {
			return null;
		}
		$ext  = strtolower( (string) ( $checked['ext'] ?? '' ) );
		$original_tmp = $tmp;
		if ( ! empty( $field['auto_resize'] ) ) {
			$tmp = self::resize_image( $tmp, $name, (string) ( $checked['type'] ?? '' ), $ext, $allowed, (int) ( $field['max_width'] ?? 0 ), (int) ( $field['max_height'] ?? 0 ) );
			if ( null === $tmp ) {
				return null;
			}
			if ( $tmp !== $original_tmp ) {
				$stored_size = filesize( $tmp );
				if ( false === $stored_size ) {
					unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					return null;
				}
				$size = (int) $stored_size;
			}
		}
		if ( ! self::forced_aspect_matches( $tmp, $name, $allowed, $field ) ) {
			if ( $tmp !== $original_tmp && is_file( $tmp ) ) {
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return null;
		}
		$path = $dir . '/' . $token . ( '' !== $ext ? '.' . $ext : '' );
		$moved = $tmp !== $original_tmp ? self::move_resized_file( $tmp, $path ) : self::move( $tmp, $path );
		if ( ! $moved ) {
			if ( isset( $original_tmp ) && $tmp !== $original_tmp && is_file( $tmp ) ) {
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return null;
		}

		return [
			'token' => $token,
			'name'  => sanitize_file_name( $name ),
			'size'  => $size,
			'mime'  => (string) ( $checked['type'] ?? $file['type'] ?? 'application/octet-stream' ),
			'ext'   => $ext,
		];
	}

	/** Bind a private upload to the current WooCommerce customer session. */
	public static function register_staged( array $file, int $product_id, string $group_id, string $field_id ): bool {
		$session = self::session();
		$token   = (string) ( $file['token'] ?? '' );
		if ( ! $session || ! preg_match( self::TOKEN_PATTERN, $token ) || $product_id < 1 || '' === $group_id || '' === $field_id ) {
			return false;
		}
		$staged = self::staged_registry();
		if ( ! isset( $staged[ $token ] ) && count( $staged ) >= 30 ) {
			return false;
		}
		$staged[ $token ] = [
			'product_id' => $product_id,
			'group_id'   => $group_id,
			'field_id'   => $field_id,
			'file'       => $file,
			'created'    => time(),
		];
		$session->set( self::STAGED_SESSION_KEY, $staged );
		return true;
	}

	/** Resolve a ticket only when it belongs to the exact current field context. */
	public static function staged_file( string $token, int $product_id, string $group_id, string $field_id ): ?array {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$record = (array) ( self::staged_registry()[ $token ] ?? [] );
		if ( (int) ( $record['product_id'] ?? 0 ) !== $product_id || (string) ( $record['group_id'] ?? '' ) !== $group_id || (string) ( $record['field_id'] ?? '' ) !== $field_id || ! is_array( $record['file'] ?? null ) ) {
			return null;
		}
		return $record['file'];
	}

	/** Count live staged files for one product field in the current session. */
	public static function staged_count( int $product_id, string $group_id, string $field_id ): int {
		$staged  = self::staged_registry();
		$count   = 0;
		foreach ( $staged as $record ) {
			if ( is_array( $record ) && (int) ( $record['product_id'] ?? 0 ) === $product_id && (string) ( $record['group_id'] ?? '' ) === $group_id && (string) ( $record['field_id'] ?? '' ) === $field_id ) {
				$count++;
			}
		}
		return $count;
	}

	/** Whether a token is present in the current session's staging registry. */
	public static function is_staged_token( string $token ): bool {
		return null !== self::staged_file_for_session( $token );
	}

	/** Consume a staged ticket after the cart item has safely adopted its file. */
	public static function consume_staged( string $token ): void {
		$session = self::session();
		if ( ! $session || ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return;
		}
		$staged = self::staged_registry();
		unset( $staged[ $token ] );
		$session->set( self::STAGED_SESSION_KEY, $staged );
	}

	/** Remove a staged ticket and optionally its private file. */
	public static function delete_staged( string $token ): bool {
		$file = self::staged_file_for_session( $token );
		if ( null === $file ) {
			return false;
		}
		self::consume_staged( $token );
		self::delete( $token );
		return true;
	}

	/** Fetch an owned staged file without exposing its context to the caller. */
	private static function staged_file_for_session( string $token ): ?array {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$record  = (array) ( self::staged_registry()[ $token ] ?? [] );
		return is_array( $record['file'] ?? null ) ? $record['file'] : null;
	}

	/** Prune abandoned one-day staging tickets and their private files. */
	private static function staged_registry(): array {
		$session = self::session();
		if ( ! $session ) return [];
		$staged = (array) $session->get( self::STAGED_SESSION_KEY, [] );
		$changed = false;
		foreach ( $staged as $token => $record ) {
			$created = is_array( $record ) ? (int) ( $record['created'] ?? 0 ) : 0;
			if ( $created > 0 && $created < time() - DAY_IN_SECONDS ) {
				unset( $staged[ $token ] );
				self::delete( (string) $token );
				$changed = true;
			}
		}
		if ( $changed ) $session->set( self::STAGED_SESSION_KEY, $staged );
		return $staged;
	}

	/** Current WooCommerce session, if the cart subsystem is initialized. */
	private static function session() {
		return function_exists( 'WC' ) && WC() && isset( WC()->session ) ? WC()->session : null;
	}

	/**
	 * Test seam for CLI/E2E harnesses: a real upload always passes the PHP
	 * check. Filtering this to true inside production code would remove the
	 * only guard against arbitrary local-file disclosure.
	 *
	 * @param string $tmp Temporary path.
	 */
	private static function is_uploaded( string $tmp ): bool {
		$ok = is_uploaded_file( $tmp );
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'opf_upload_is_uploaded_file', $ok, $tmp ) : $ok;
	}

	/**
	 * Move the temporary file into private storage. Real requests always go
	 * through move_uploaded_file(); CLI harnesses that opted into the
	 * opf_upload_is_uploaded_file filter may rename instead because PHP
	 * refuses to move files it did not receive over HTTP.
	 *
	 * @param string $tmp  Temporary path.
	 * @param string $path Destination path.
	 */
	private static function move( string $tmp, string $path ): bool {
		if ( is_uploaded_file( $tmp ) ) {
			return move_uploaded_file( $tmp, $path );
		}
		$allow = function_exists( 'apply_filters' ) && (bool) apply_filters( 'opf_upload_is_uploaded_file', false, $tmp );
		return $allow && rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * WordPress MIME map for the allow-list: extensions resolve through the
	 * WP map, MIME entries (including `image/*`) through their MIME value.
	 * Passing the subset to wp_check_filetype_and_ext makes the content
	 * checker itself reject bytes that do not match the allowed type.
	 *
	 * @param string[] $allowed Normalized allow-list.
	 * @return array<string,string>
	 */
	private static function mime_subset( array $allowed ): array {
		if ( ! function_exists( 'wp_get_mime_types' ) ) {
			return [];
		}
		$out = [];
		foreach ( wp_get_mime_types() as $exts => $mime ) {
			if ( self::mime_is_allowed( strtolower( (string) $mime ), $allowed ) ) {
				$out[ $exts ] = $mime;
				continue;
			}
			foreach ( explode( '|', (string) $exts ) as $ext ) {
				if ( in_array( strtolower( $ext ), $allowed, true ) ) {
					$out[ $exts ] = $mime;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a MIME type matches an allowed MIME entry (exact or wildcard).
	 *
	 * @param string   $mime    Lowercase MIME type.
	 * @param string[] $allowed Normalized allow-list.
	 */
	private static function mime_is_allowed( string $mime, array $allowed ): bool {
		foreach ( $allowed as $entry ) {
			if ( false === strpos( $entry, '/' ) ) {
				continue;
			}
			if ( $entry === $mime ) {
				return true;
			}
			if ( '/*' === substr( $entry, -2 ) && 0 === strpos( $mime, substr( $entry, 0, -1 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Executables and markup never enter private storage.
	 *
	 * @param string $ext Resolved extension.
	 */
	private static function extension_is_blocked( string $ext ): bool {
		$blocked = self::BLOCKED_EXTENSIONS;
		if ( function_exists( 'apply_filters' ) ) {
			$blocked = (array) apply_filters( 'opf_upload_blocked_extensions', $blocked, $ext );
		}
		return in_array( strtolower( ltrim( $ext, '.' ) ), array_map( 'strtolower', $blocked ), true );
	}

	/**
	 * Private storage outside the served webroot, created with deny guards.
	 */
	public static function dir(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'wp_mkdir_p' ) ) {
			return null;
		}
		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return null;
		}
		$webroot = self::document_root();
		if ( null === $webroot ) {
			return null;
		}

		$default_dir = dirname( $webroot ) . DIRECTORY_SEPARATOR . self::DIR_NAME;
		$requested   = function_exists( 'apply_filters' ) ? apply_filters( 'opf_upload_storage_dir', $default_dir, $webroot, (string) $uploads['basedir'] ) : $default_dir;
		if ( ! is_string( $requested ) || '' === trim( $requested ) || DIRECTORY_SEPARATOR !== substr( $requested, 0, 1 ) ) {
			return null;
		}
		$requested = rtrim( $requested, '/\\' );
		$parent    = realpath( dirname( $requested ) );
		if ( false === $parent ) {
			return null;
		}
		$dir = $parent . DIRECTORY_SEPARATOR . basename( $requested );
		if ( ! self::path_is_outside_root( $dir, $webroot ) ) {
			return null;
		}
		// Do not let a pre-existing symlink redirect migration or storage back
		// into the served tree. Resolve existing targets before touching legacy
		// files; the final realpath check below also covers newly-created paths.
		if ( is_link( $dir ) ) {
			return null;
		}
		if ( file_exists( $dir ) ) {
			$existing_dir = realpath( $dir );
			if ( false === $existing_dir || ! is_dir( $existing_dir ) || ! self::path_is_outside_root( $existing_dir, $webroot ) ) {
				return null;
			}
			$dir = $existing_dir;
			@chmod( $dir, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		$legacy_dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR_NAME;
		if ( ! self::migrate_legacy_storage( $legacy_dir, $dir ) ) {
			return null;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$resolved = realpath( $dir );
		if ( false === $resolved || ! self::path_is_outside_root( $resolved, $webroot ) ) {
			return null;
		}
		$dir = $resolved;
		@chmod( $dir, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations
		}
		return $dir;
	}

	/** Determine the actual served webroot; fail closed when it is unknown. */
	private static function document_root(): ?string {
		// WP-CLI synthesizes DOCUMENT_ROOT from ABSPATH, which points at
		// `web/wp` in Bedrock while Nginx serves `web`. Only trust the server's
		// value outside CLI; CLI/cron must configure an explicit webroot.
		$root = 'cli' !== PHP_SAPI && isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
		if ( '' === $root && defined( 'OPF_WEB_ROOT' ) ) {
			$root = (string) OPF_WEB_ROOT;
		}
		if ( '' === $root && function_exists( 'apply_filters' ) ) {
			$root = (string) apply_filters( 'opf_upload_document_root', '' );
		}
		$resolved = '' !== $root ? realpath( $root ) : false;
		return false !== $resolved && is_dir( $resolved ) ? rtrim( $resolved, DIRECTORY_SEPARATOR ) : null;
	}

	/** Verify the canonical directory is not the webroot or one of its children. */
	private static function path_is_outside_root( string $path, string $webroot ): bool {
		$root   = rtrim( $webroot, DIRECTORY_SEPARATOR );
		$prefix = ( '' === $root ? DIRECTORY_SEPARATOR : $root . DIRECTORY_SEPARATOR );
		return $path !== $root && 0 !== strpos( $path, $prefix );
	}

	/**
	 * Move existing token files outside the public uploads tree. A same-volume
	 * directory rename preserves all bytes atomically; cross-volume fallback
	 * copies and verifies each token before unlinking its source. Any conflict
	 * or failed verification stops uploads rather than falling back to public
	 * storage.
	 */
	private static function migrate_legacy_storage( string $legacy_dir, string $private_dir ): bool {
		$marker = $private_dir . '/' . self::MIGRATION_MARKER;
		if ( is_link( $marker ) ) {
			return self::migration_failed();
		}
		if ( self::$legacy_migration_checked || is_file( $marker ) ) {
			self::$legacy_migration_checked = true;
			return true;
		}

		if ( is_link( $legacy_dir ) ) {
			return self::migration_failed();
		}
		if ( is_dir( $legacy_dir ) && ! file_exists( $private_dir ) && @rename( $legacy_dir, $private_dir ) ) {
			self::$legacy_migration_checked = true;
			return self::write_migration_marker( $private_dir );
		}
		if ( ! is_dir( $private_dir ) && ! wp_mkdir_p( $private_dir ) ) {
			return self::migration_failed();
		}
		if ( ! is_dir( $legacy_dir ) ) {
			self::$legacy_migration_checked = true;
			return self::write_migration_marker( $private_dir );
		}

		$handle = @opendir( $legacy_dir );
		if ( false === $handle ) {
			return self::migration_failed();
		}
		$scanned        = 0;
		$created_targets = [];
		$sources_to_remove = [];
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry || '.htaccess' === $entry || 'index.php' === $entry ) {
				continue;
			}
			$scanned++;
			if ( $scanned > self::LEGACY_MIGRATION_LIMIT || ! preg_match( '/^[a-f0-9]{48}\.[a-z0-9]{1,8}$/D', $entry ) ) {
				closedir( $handle );
				foreach ( $created_targets as $created_target ) {
					@unlink( $created_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return self::migration_failed();
			}
			$source = $legacy_dir . DIRECTORY_SEPARATOR . $entry;
			$target = $private_dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $source ) || ! is_file( $source ) ) {
				closedir( $handle );
				foreach ( $created_targets as $created_target ) {
					@unlink( $created_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return self::migration_failed();
			}
			if ( file_exists( $target ) ) {
				$source_hash = hash_file( 'sha256', $source );
				$target_hash = hash_file( 'sha256', $target );
				if ( false === $source_hash || false === $target_hash || ! hash_equals( $source_hash, $target_hash ) || filesize( $source ) !== filesize( $target ) ) {
					closedir( $handle );
					foreach ( $created_targets as $created_target ) {
						@unlink( $created_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					}
					return self::migration_failed();
				}
				$sources_to_remove[] = $source;
				continue;
			}
			// Keep every source until the entire batch has been copied and
			// verified. This avoids a limit or I/O failure halfway through a
			// cross-volume migration leaving a split public directory.
			if ( ! @copy( $source, $target ) ) {
				closedir( $handle );
				foreach ( $created_targets as $created_target ) {
					@unlink( $created_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return self::migration_failed();
			}
			$created_targets[] = $target;
			$source_hash = hash_file( 'sha256', $source );
			$target_hash = hash_file( 'sha256', $target );
			if ( false === $source_hash || false === $target_hash || ! hash_equals( $source_hash, $target_hash ) || filesize( $source ) !== filesize( $target ) ) {
				closedir( $handle );
				foreach ( $created_targets as $created_target ) {
					@unlink( $created_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return self::migration_failed();
			}
			$sources_to_remove[] = $source;
			@chmod( $target, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}
		closedir( $handle );
		foreach ( $sources_to_remove as $source ) {
			if ( ! unlink( $source ) ) {
				return self::migration_failed();
			}
		}
		self::$legacy_migration_checked = true;
		return self::write_migration_marker( $private_dir );
	}

	/** Log migration failures without exposing file names or tokens. */
	private static function migration_failed(): bool {
		if ( ! self::$legacy_migration_error_logged ) {
			error_log( 'OPF upload storage migration could not be verified; uploads are disabled until it is resolved.' );
			self::$legacy_migration_error_logged = true;
		}
		return false;
	}

	/** Mark completed migration only after the external directory exists. */
	private static function write_migration_marker( string $private_dir ): bool {
		if ( ! is_dir( $private_dir ) ) {
			return self::migration_failed();
		}
		$marker = $private_dir . '/' . self::MIGRATION_MARKER;
		if ( is_link( $marker ) ) {
			return self::migration_failed();
		}
		if ( ! is_file( $marker ) && false === file_put_contents( $marker, 'ok', LOCK_EX ) ) {
			return self::migration_failed();
		}
		return true;
	}

	/**
	 * Absolute path for a stored token, or null when the token is malformed,
	 * missing, or resolves to more than one file.
	 *
	 * @param string $token Stored token.
	 */
	public static function path( string $token ): ?string {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$dir = self::dir();
		if ( null === $dir ) {
			return null;
		}
		$matches = glob( $dir . '/' . $token . '.*' );
		return is_array( $matches ) && 1 === count( $matches ) && is_file( $matches[0] ) ? $matches[0] : null;
	}

	/**
	 * Delete one stored upload.
	 *
	 * @param string $token Stored token.
	 */
	public static function delete( string $token ): bool {
		$path = self::path( $token );
		return null !== $path && unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Copy a stored upload to a fresh token. Used by order-again so a new
	 * order owns its files and deleting the old order cannot remove them.
	 *
	 * @param string $token Source token.
	 * @return string|null New token, or null when the source is gone.
	 */
	public static function duplicate( string $token ): ?string {
		$path = self::path( $token );
		$dir  = self::dir();
		if ( null === $path || null === $dir ) {
			return null;
		}
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$ext = preg_match( '/^[a-z0-9]{1,8}$/', $ext ) ? $ext : '';
		try {
			$new = bin2hex( random_bytes( 24 ) );
		} catch ( \Exception $e ) {
			return null;
		}
		$dest = $dir . '/' . $new . ( '' !== $ext ? '.' . $ext : '' );
		return copy( $path, $dest ) ? $new : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
	}

	/**
	 * Delete unreferenced uploads older than the given age.
	 *
	 * A file is referenced while any order meta `_opf_upload_tokens` contains
	 * its token. Cart-only uploads that never became an order expire here.
	 *
	 * @param int $max_age Minimum age in seconds before an orphan is eligible.
	 *                     Defaults to one week.
	 * @return int Number of deleted files.
	 */
	public static function cleanup_orphans( int $max_age = 604800 ): int {
		$dir = self::dir();
		if ( null === $dir || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$deleted = 0;
		$threshold = time() - max( 0, $max_age );
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$base = basename( $path );
			if ( ! preg_match( '/^([a-f0-9]{48})(\.[a-z0-9]+)?$/', $base, $m ) ) {
				continue;
			}
			if ( filemtime( $path ) > $threshold ) {
				continue;
			}
			if ( self::is_referenced( $m[1] ) ) {
				continue;
			}
			if ( unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Whether any order still references the token.
	 *
	 * @param string $token Stored token.
	 */
	public static function is_referenced( string $token ): bool {
		return ! empty( self::referencing_orders( $token ) );
	}

	/**
	 * Orders the meta index claims contain the token, verified against the
	 * actual item meta. Verification matters: a broken/absent meta query must
	 * never let cleanup delete a referenced file or authorize a stranger.
	 *
	 * @param string $token Stored token.
	 * @param array<string,mixed> $extra Extra wc_get_orders arguments.
	 * @return \WC_Order[]
	 */
	private static function referencing_orders( string $token, array $extra = [] ): array {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) || ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$orders = wc_get_orders(
			$extra + [
				'limit'      => 25,
				// Trashed orders are restorable, so their files must survive.
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), [ 'trash' ] ),
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_opf_upload_tokens',
						'value'   => $token,
						'compare' => 'LIKE',
					],
				],
			]
		);
		$out = [];
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order && self::order_has_token( $order, $token ) ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * Whether an order actually stores the token, on the order or on a line.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $token Stored token.
	 */
	public static function order_has_token( \WC_Order $order, string $token ): bool {
		$tokens = $order->get_meta( '_opf_upload_tokens', true );
		if ( is_array( $tokens ) && in_array( $token, $tokens, true ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			$stored = $item->get_meta( '_opf_files', true );
			if ( is_string( $stored ) && '' !== $stored ) {
				$stored = json_decode( $stored, true );
			}
			if ( is_array( $stored ) && self::files_have_token( $stored, $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan a gid/fid/entries structure for a token.
	 *
	 * @param array<mixed> $files Files.
	 * @param string       $token Stored token.
	 */
	private static function files_have_token( array $files, string $token ): bool {
		foreach ( $files as $group_files ) {
			foreach ( (array) $group_files as $entries ) {
				foreach ( (array) $entries as $entry ) {
					if ( is_array( $entry ) && isset( $entry['token'] ) && hash_equals( (string) $entry['token'], $token ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Whether the current request may download a token: shop managers always,
	 * customers only when their own order (or session cart) holds the file,
	 * and guests only through their order key (the same credential WooCommerce
	 * uses for guest order access).
	 *
	 * @param string   $token     Stored token.
	 * @param int|null $user_id   User id; null resolves the current user.
	 * @param string   $order_key Guest order key, when supplied.
	 */
	public static function viewer_can_access( string $token, ?int $user_id = null, string $order_key = '' ): bool {
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return false;
		}
		if ( null === $user_id ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		}
		if ( $user_id > 0 && function_exists( 'user_can' ) && user_can( $user_id, 'manage_woocommerce' ) ) {
			return true;
		}
		if ( '' !== $order_key && function_exists( 'wc_get_order_id_by_order_key' ) ) {
			$order_id = (int) wc_get_order_id_by_order_key( $order_key );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order instanceof \WC_Order && hash_equals( (string) $order->get_order_key(), $order_key ) && self::order_has_token( $order, $token ) ) {
				return true;
			}
		}
		if ( $user_id > 0 && function_exists( 'wc_get_orders' ) ) {
			$orders = self::referencing_orders( $token, [ 'customer_id' => $user_id ] );
			if ( ! empty( $orders ) ) {
				return true;
			}
		}
		return self::in_session_cart( $token );
	}

	/**
	 * Whether the active WooCommerce session cart holds the token.
	 *
	 * @param string $token Stored token.
	 */
	private static function in_session_cart( string $token ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			foreach ( (array) ( $cart_item['opf_files'] ?? [] ) as $files ) {
				foreach ( (array) $files as $entries ) {
					foreach ( (array) $entries as $entry ) {
						if ( is_array( $entry ) && hash_equals( (string) ( $entry['token'] ?? '' ), $token ) ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}
}
