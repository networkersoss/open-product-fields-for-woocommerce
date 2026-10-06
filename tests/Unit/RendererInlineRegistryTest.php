<?php
/**
 * Per-group inline registry (`data-opf-registry`).
 *
 * A quick-view modal injects the product markup through Ajax. Themes sanitize
 * that fragment before inserting it (Astra Pro: DOMPurify + innerHTML), which
 * drops the inline `window.OPF_FIELDS` script the fragment also carries, so the
 * group element has to carry its own registry — the same metadata plus the
 * OPF-native image rules — for `window.OPF_FRONTEND.reinit( root )` to price a
 * modal whose page never rendered fields.
 */

namespace {
	require_once __DIR__ . '/RendererImageSwatchTest.php';
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class RendererInlineRegistryTest extends TestCase {

		/**
		 * Group with one priced select, one checkbox and two image rules.
		 *
		 * @param bool $with_rules Include the OPF-native image rules.
		 */
		private function group( bool $with_rules = true ): FieldGroup {
			return new FieldGroup(
				[
					'fields'      => [
						[
							'id'       => 'finish',
							'label'    => 'Finish',
							'type'     => 'select',
							'choices'  => [
								[ 'slug' => 'none', 'label' => 'None', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
								[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 15 ] ],
							],
							'conditionals' => [],
						],
						[
							'id'       => 'extras',
							'label'    => 'Extras',
							'type'     => 'checkbox',
							'choices'  => [ [ 'slug' => 'gift', 'label' => 'Gift', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ] ],
							'conditionals' => [],
						],
					],
					'image_rules' => $with_rules ? [
						[ 'target_url' => 'https://shop.test/rule-a.png', 'conditions' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
					] : [],
					'image_rule_mode' => 'last',
				]
			);
		}

		/**
		 * @return array<string,mixed> Decoded `data-opf-registry` payload.
		 */
		private function payload( string $html ): array {
			$this->assertMatchesRegularExpression( '/data-opf-registry="/', $html, 'the group element must carry its inline registry' );
			preg_match( '/data-opf-registry="([^"]*)"/', $html, $match );
			$registry = json_decode( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), true );
			$this->assertIsArray( $registry );
			return $registry;
		}

		private function render( ?array $registry = null, bool $with_rules = true ): string {
			$group = $this->group( $with_rules );
			ob_start();
			Renderer::render_group( '18', 'Options', $group, 10.0, null, null, 1, $registry );
			return (string) ob_get_clean();
		}

		public function test_group_carries_the_client_registry_the_frontend_reads(): void {
			$fields = $this->payload( $this->render() )['fields'];

			$this->assertSame( 'select', $fields['finish']['type'] );
			$this->assertSame( 'Gold', $fields['finish']['choices'][1]['label'] );
			$this->assertSame( 'gold', $fields['finish']['choices'][1]['slug'] );
			$this->assertSame( 15.0, (float) $fields['finish']['choices'][1]['pricing']['amount'] );
			$this->assertSame( 'checkbox', $fields['extras']['type'] );
			$this->assertSame( [ [ 'id' => 'finish', 'type' => 'select' ], [ 'id' => 'extras', 'type' => 'checkbox' ] ], $fields['__opf_formula_fields'] );
			$this->assertArrayHasKey( 'conditionals', $fields['finish'], 'the conditional evaluator reads the rule list from here' );
		}

		public function test_inline_payload_carries_the_native_image_rules_and_swap_mode(): void {
			$payload = $this->payload( $this->render() );

			$this->assertSame(
				[ [ 'target_url' => 'https://shop.test/rule-a.png', 'conditions' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ] ],
				$payload['image_rules']
			);
			$this->assertSame( 'last', $payload['image_rule_mode'] );
		}

		public function test_group_without_image_rules_emits_no_rule_keys(): void {
			$payload = $this->payload( $this->render( null, false ) );
			$this->assertArrayNotHasKey( 'image_rules', $payload );
			$this->assertArrayNotHasKey( 'image_rule_mode', $payload );
		}

		public function test_render_prints_the_registry_it_already_computed_for_the_globals(): void {
			// Renderer::render() computes the registry once (window.OPF_FIELDS)
			// and hands the same entry down to the markup: one computation per
			// request, and the two payloads cannot drift apart.
			$precomputed = [ 'finish' => [ 'type' => 'select', 'choices' => [] ] ];

			$this->assertSame( $precomputed, $this->payload( $this->render( $precomputed ) )['fields'] );
		}
	}
}
