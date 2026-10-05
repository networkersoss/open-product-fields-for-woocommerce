<?php
/** Site-wide formula variable administration. */

namespace OPF\Service\Admin;

use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class FormulaVariables {
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ], 61 );
	}

	public static function register_page(): void {
		add_management_page(
			__( 'OPF Formula Variables', 'open-product-fields-for-woocommerce' ),
			__( 'OPF Formula Variables', 'open-product-fields-for-woocommerce' ),
			'manage_woocommerce',
			'opf-formula-variables',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage formula variables.', 'open-product-fields-for-woocommerce' ) );
		}
		$message = '';
		if ( isset( $_POST['opf_formula_variables_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'opf_formula_variables_save', 'opf_formula_variables_nonce' );
			$raw = isset( $_POST['opf_formula_variables_json'] ) ? wp_unslash( (string) $_POST['opf_formula_variables_json'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) || ( '' !== trim( $raw ) && JSON_ERROR_NONE !== json_last_error() ) ) {
				$message = __( 'Invalid JSON. No changes were saved.', 'open-product-fields-for-woocommerce' );
			} else {
				update_option( 'opf_formula_variables', FieldGroup::normalize_formula_variables( $decoded ) );
				$message = __( 'Formula variables saved.', 'open-product-fields-for-woocommerce' );
			}
		}
		$variables = get_option( 'opf_formula_variables', [] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OPF Formula Variables', 'open-product-fields-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Create reusable numeric defaults and ordered conditional changes. Use [var_name] in a formula. Conditions refer to field IDs in the local group where the variable is used; the first matching change wins. A group-local definition overrides a site-wide variable with the same name.', 'open-product-fields-for-woocommerce' ); ?></p>
			<p><?php esc_html_e( 'Example: {"wrap_cost":{"default":1,"changes":[{"value":1.5,"logic":"all","rules":[{"field":"size","operator":"is","value":"large"}]}]}}', 'open-product-fields-for-woocommerce' ); ?></p>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'opf_formula_variables_save', 'opf_formula_variables_nonce' ); ?>
				<textarea name="opf_formula_variables_json" rows="16" class="large-text code"><?php echo esc_textarea( wp_json_encode( $variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
				<?php submit_button( __( 'Save formula variables', 'open-product-fields-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}
}
