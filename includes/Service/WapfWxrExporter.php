<?php
/**
 * Export interoperable WAPF global groups in WordPress WXR format.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class WapfWxrExporter {

	private const WP_NS = 'http://wordpress.org/export/1.2/';
	private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
	private const DC_NS = 'http://purl.org/dc/elements/1.1/';
	private const EXCERPT_NS = 'http://wordpress.org/export/1.2/excerpt/';

	/**
	 * Build a WXR 1.2 document containing WAPF-compatible global groups.
	 * WAPF stores global groups as `wapf_product` posts with serialized content.
	 *
	 * @param array<int,array<string,mixed>> $groups OPF groups with post metadata.
	 * @param array{site_url:string,site_title:string,language?:string} $site Channel metadata.
	 */
	public static function build_document( array $groups, array $site ): string {
		$site_url = rtrim( (string) ( $site['site_url'] ?? '' ), '/' );
		if ( '' === $site_url ) {
			throw new \InvalidArgumentException( 'WAPF WXR export requires the source site URL.' );
		}
		$title = (string) ( $site['site_title'] ?? '' );
		$now = gmdate( 'D, d M Y H:i:s +0000' );
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= '<rss version="2.0" xmlns:excerpt="' . self::EXCERPT_NS . '" xmlns:content="' . self::CONTENT_NS . '" xmlns:dc="' . self::DC_NS . '" xmlns:wp="' . self::WP_NS . '"><channel>';
		$xml .= self::element( 'title', $title );
		$xml .= self::element( 'link', $site_url );
		$xml .= self::element( 'description', '' );
		$xml .= self::element( 'pubDate', $now );
		$xml .= self::element( 'language', (string) ( $site['language'] ?? 'en-US' ) );
		$xml .= '<wp:wxr_version>1.2</wp:wxr_version>';
		$xml .= self::element( 'wp:base_site_url', $site_url );
		$xml .= self::element( 'wp:base_blog_url', $site_url );

		foreach ( $groups as $entry ) {
			if ( ! is_array( $entry['data'] ?? null ) || ! is_numeric( $entry['id'] ?? null ) || (int) $entry['id'] < 1 ) {
				throw new \InvalidArgumentException( 'WAPF WXR export encountered a group without valid OPF data and source ID.' );
			}
			$payload = WapfExporter::build_payload( $entry['data'] );
			$serialized = serialize( self::serialized_field_group( $payload, (int) $entry['id'] ) );
			$id = (int) $entry['id'];
			$date = (string) ( $entry['date'] ?? '1970-01-01 00:00:00' );
			$date_gmt = (string) ( $entry['date_gmt'] ?? $date );
			$slug = (string) ( $entry['slug'] ?? 'opf-field-group-' . $id );
			$status = (string) ( $entry['status'] ?? 'draft' );
			$permalink = $site_url . '/?post_type=wapf_product&p=' . $id;

			$xml .= '<item>';
			$xml .= self::element( 'title', (string) ( $entry['title'] ?? '' ) );
			$xml .= self::element( 'link', $permalink );
			$xml .= self::element( 'pubDate', $now );
			$xml .= self::cdata_element( 'dc:creator', (string) ( $entry['author'] ?? '' ) );
			$xml .= self::element( 'guid', $permalink, [ 'isPermaLink' => 'false' ] );
			$xml .= self::cdata_element( 'description', '' );
			$xml .= self::cdata_element( 'content:encoded', $serialized );
			$xml .= self::cdata_element( 'excerpt:encoded', '' );
			$xml .= self::element( 'wp:post_id', (string) $id );
			$xml .= self::cdata_element( 'wp:post_date', $date );
			$xml .= self::cdata_element( 'wp:post_date_gmt', $date_gmt );
			$xml .= self::element( 'wp:comment_status', 'closed' );
			$xml .= self::element( 'wp:ping_status', 'closed' );
			$xml .= self::cdata_element( 'wp:post_name', $slug );
			$xml .= self::element( 'wp:status', $status );
			$xml .= self::element( 'wp:post_parent', '0' );
			$xml .= self::element( 'wp:menu_order', (string) (int) ( $entry['menu_order'] ?? 0 ) );
			$xml .= self::element( 'wp:post_type', 'wapf_product' );
			$xml .= self::cdata_element( 'wp:post_password', '' );
			$xml .= self::element( 'wp:is_sticky', '0' );
			$xml .= '</item>';
		}

		return $xml . "</channel></rss>\n";
	}

	/** Convert WAPF's raw JSON-import shape into its serialized FieldGroup model. */
	private static function serialized_field_group( array $payload, int $source_id ): array {
		$fields = [];
		// Flattened Tools keys that belong inside the stored field's `options`
		// bucket (mirrors Field_Groups::raw_json_to_field_group handling).
		$option_keys = [
			'choices', 'placeholder', 'default', 'message', 'p_content', 'image',
			'attachment', 'minimum', 'maximum', 'min_choices', 'max_choices',
			'large_image', 'label_pos', 'layout', 'size', 'grid_layout',
			'item_width', 'items_per_row', 'items_per_row_tablet',
			'items_per_row_mobile', 'minlength', 'maxlength', 'disabled_days',
			// Date-field options serialized by WapfExporter::map_date_settings().
			'disable_past', 'disable_future', 'min_date', 'max_date',
			'disabled_dates', 'disable_today_after',
			'hide_cart', 'hide_checkout', 'hide_order',
			// Linked products (`products` type) and file upload options.
			'product_selection', 'product_query', 'qty_method', 'display',
			'slot_1', 'slot_2', 'slot_3', 'incl_img', 'incl_desc', 'img_fit',
			'multiple', 'accept', 'maxsize',
			'columns',
			// WAPF Extended `calc` options.
			'calc_type', 'formula', 'result_format', 'result_text',
		];
		foreach ( (array) ( $payload['fields'] ?? [] ) as $field ) {
			$options = [];
			foreach ( $option_keys as $key ) {
				if ( array_key_exists( $key, $field ) ) {
					$options[ $key ] = $field[ $key ];
				}
			}
			$serialized = [
				'id' => (string) $field['id'],
				'label' => (string) ( $field['label'] ?? '' ),
				'description' => (string) ( $field['description'] ?? '' ),
				'type' => (string) $field['type'],
				'required' => (bool) ( $field['required'] ?? false ),
				'class' => (string) ( $field['class'] ?? '' ),
				'width' => (int) ( $field['width'] ?? 100 ),
				'options' => $options,
				'conditionals' => array_values( (array) ( $field['conditionals'] ?? [] ) ),
				'clone' => [ 'enabled' => false ],
				'pricing' => [
					'type' => (string) ( $field['pricing']['type'] ?? 'none' ),
					// Formula pricing stores an expression, not a numeric amount.
					// The JSON exporter already validates its WAPF representation.
					'amount' => $field['pricing']['amount'] ?? 0,
					'enabled' => (bool) ( $field['pricing']['enabled'] ?? false ),
				],
			];
			if ( isset( $field['subtype'] ) ) {
				$serialized['subtype'] = (string) $field['subtype'];
			}
			$fields[] = $serialized;
		}

		$rule_groups = [];
		foreach ( (array) ( $payload['conditions'] ?? [] ) as $group ) {
			$rules = [];
			foreach ( (array) ( $group['rules'] ?? [] ) as $rule ) {
				$rules[] = [
					'value' => $rule['value'] ?? '',
					'condition' => (string) ( $rule['condition'] ?? '' ),
					'subject' => (string) ( $rule['subject'] ?? '' ),
				];
			}
			$rule_groups[] = [ 'rules' => $rules ];
		}

		return [
			'id' => $source_id,
			'type' => 'wapf_product',
			'layout' => (array) ( $payload['layout'] ?? [] ),
			'fields' => $fields,
			'rule_groups' => $rule_groups,
			'variables' => array_values( (array) ( $payload['variables'] ?? [] ) ),
		];
	}

	private static function element( string $name, string $value, array $attributes = [] ): string {
		$xml = '<' . $name;
		foreach ( $attributes as $attribute => $attribute_value ) {
			$xml .= ' ' . $attribute . '="' . htmlspecialchars( (string) $attribute_value, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '"';
		}
		return $xml . '>' . htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</' . $name . '>';
	}

	private static function cdata_element( string $name, string $value ): string {
		return '<' . $name . '><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $value ) . ']]></' . $name . '>';
	}
}
