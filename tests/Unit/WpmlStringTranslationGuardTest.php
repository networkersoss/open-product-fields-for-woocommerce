<?php
/**
 * D1 guard: OPF must not emit string-package registration when WPML's String
 * Translation package API is not consuming the hooks (outdated stand-in or no
 * registration handler), and must warn the administrator once instead.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Tests\Unit {

	use OPF\Service\WpmlIntegration;
	use PHPUnit\Framework\TestCase;

	final class WpmlStringTranslationGuardTest extends TestCase {

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/fixtures/wpml-st-guard-contract.php';
			unset( $GLOBALS['WPML_String_Translation'] );
			$GLOBALS['opf_wpml_filters'] = [];
			$GLOBALS['opf_wpml_actions'] = [];
			$GLOBALS['opf_woocs_meta']    = [];
			$GLOBALS['opf_woocs_hooks']   = [];
			$GLOBALS['opf_has_action']    = [];
			$GLOBALS['opf_can']           = [ 'manage_woocommerce' => true ];
			$GLOBALS['opf_is_admin']      = true;
			$this->setNoticeQueued( false );
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['WPML_String_Translation'],
				$GLOBALS['opf_wpml_filters'],
				$GLOBALS['opf_wpml_actions'],
				$GLOBALS['opf_woocs_meta'],
				$GLOBALS['opf_woocs_hooks'],
				$GLOBALS['opf_has_action'],
				$GLOBALS['opf_can'],
				$GLOBALS['opf_is_admin']
			);
			$this->setNoticeQueued( false );
		}

		/** A published field group whose display-string count is known to be 7. */
		private function post(): object {
			return (object) [
				'post_type'    => 'opf_field_group',
				'post_status'  => 'publish',
				'post_title'   => 'Gift',
				'post_content' => json_encode( [
					'schema' => 1,
					'fields' => [
						[
							'id'          => 'gift',
							'type'        => 'select',
							'label'       => 'Gift wrap',
							'description' => 'Choose wrapping',
							'placeholder' => 'Select an option',
							'choices'     => [
								[ 'slug' => 'red', 'label' => 'Red' ],
								[ 'slug' => 'blue', 'label' => 'Blue' ],
							],
						],
						[ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ],
						[ 'id' => 'info', 'type' => 'paragraph', 'content' => '<b>Info</b>', 'content_format' => 'html' ],
					],
					'rule_groups' => [],
				] ),
			];
		}

		private function renderedNotice(): string {
			ob_start();
			WpmlIntegration::render_unavailable_notice();
			return (string) ob_get_clean();
		}

		private function setNoticeQueued( bool $queued ): void {
			$property = new \ReflectionProperty( WpmlIntegration::class, 'unavailable_notice_queued' );
			$property->setValue( null, $queued );
		}

		private function noticeIsQueued(): bool {
			$property = new \ReflectionProperty( WpmlIntegration::class, 'unavailable_notice_queued' );
			return (bool) $property->getValue( null );
		}

		/** Drive the private queue directly to prove it is idempotent. */
		private function queueNotice(): bool {
			$method = new \ReflectionMethod( WpmlIntegration::class, 'queue_unavailable_notice' );
			return (bool) $method->invoke( null );
		}

		public function test_available_package_api_emits_the_registration_contract_unchanged(): void {
			$GLOBALS['WPML_String_Translation'] = new \stdClass();
			$GLOBALS['opf_has_action']          = [ 'wpml_register_string' => 10 ];

			WpmlIntegration::init();
			$this->assertFalse( $this->noticeIsQueued() );
			$this->assertArrayNotHasKey( 'admin_notices', $GLOBALS['opf_woocs_hooks'] );

			WpmlIntegration::register_post( 7, $this->post() );
			$actions = $GLOBALS['opf_wpml_actions'];
			$this->assertSame( 'wpml_start_string_package_registration', $actions[0][0] );
			$this->assertSame( 'Gift (#7)', $actions[0][1][0]['title'] );
			$this->assertSame( 'wpml_delete_unused_package_strings', end( $actions )[0] );
			$registered = array_values( array_filter( $actions, static fn( array $action ): bool => 'wpml_register_string' === $action[0] ) );
			$this->assertCount( 7, $registered );
			$this->assertSame(
				[ 'field:gift:label', 'field:gift:description', 'field:gift:placeholder', 'field:gift:choice:red', 'field:gift:choice:blue', 'field:note:label', 'field:info:content' ],
				array_map( static fn( array $action ): string => $action[1][1], $registered )
			);
		}

		public function test_missing_registration_handler_skips_emission_and_queues_one_notice(): void {
			$GLOBALS['WPML_String_Translation'] = new \stdClass(); // WPML present, no handler.
			$GLOBALS['opf_has_action']          = [];

			WpmlIntegration::init();
			$this->assertTrue( $this->noticeIsQueued() );
			$this->assertSame(
				[ WpmlIntegration::class, 'render_unavailable_notice' ],
				$GLOBALS['opf_woocs_hooks']['admin_notices'][0]
			);
			// Queued once: the guard refuses a second queue.
			$this->assertFalse( $this->queueNotice() );

			WpmlIntegration::register_post( 7, $this->post() );
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
		}

		public function test_outdated_string_translation_stand_in_disables_registration_even_with_a_subscribed_handler(): void {
			$GLOBALS['WPML_String_Translation'] = new \WPML_ST_Outdated_Stand_In();
			$GLOBALS['opf_has_action']          = [
				'wpml_register_string'                    => 10,
				'wpml_start_string_package_registration' => 10,
			];

			WpmlIntegration::init();
			$this->assertTrue( $this->noticeIsQueued() );

			WpmlIntegration::register_post( 7, $this->post() );
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
		}

		public function test_absent_wpml_keeps_emitting_and_never_queues_a_notice(): void {
			WpmlIntegration::init();
			$this->assertFalse( $this->noticeIsQueued() );
			$this->assertArrayNotHasKey( 'admin_notices', $GLOBALS['opf_woocs_hooks'] );

			WpmlIntegration::register_post( 7, $this->post() );
			$this->assertCount( 9, $GLOBALS['opf_wpml_actions'] );
		}

		public function test_notice_is_capability_checked_escaped_and_front_end_silent(): void {
			$GLOBALS['WPML_String_Translation'] = new \stdClass();
			WpmlIntegration::init();

			$GLOBALS['opf_is_admin'] = false;
			$GLOBALS['opf_can']      = [ 'manage_woocommerce' => true ];
			$this->assertSame( '', $this->renderedNotice(), 'Front end must stay silent.' );

			$GLOBALS['opf_is_admin'] = true;
			$GLOBALS['opf_can']      = [];
			$this->assertSame( '', $this->renderedNotice(), 'Users who cannot act must not be warned.' );

			$GLOBALS['opf_can'] = [ 'manage_woocommerce' => true ];
			$notice             = $this->renderedNotice();
			$this->assertStringContainsString( 'class="notice notice-warning"', $notice );
			$this->assertStringContainsString( 'WPML String Translation is unavailable or incompatible', $notice );
			$this->assertStringNotContainsString( '<script', $notice );
		}

		public function test_available_api_renders_no_notice_even_when_the_hook_is_queued(): void {
			$GLOBALS['WPML_String_Translation'] = new \stdClass();
			$GLOBALS['opf_has_action']          = [ 'wpml_register_string' => 10 ];
			$this->setNoticeQueued( true );

			$this->assertSame( '', $this->renderedNotice() );
		}
	}
}
