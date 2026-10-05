<?php
/**
 * Isolated fake WooCommerce Subscriptions contract for SubscriptionIntegration
 * tests. Never loaded outside child processes (RunTestsInSeparateProcesses).
 *
 * `WC_Subscriptions_Product` is intentionally NOT defined here — tests that
 * need the subscriptions API load `subscription-api.php` so the absent-plugin
 * (fail-closed) path stays exercisable.
 */

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public int $id;
		public float $price;
		public string $type;
		public function __construct( int $id, float $price, string $type = 'simple' ) {
			$this->id    = $id;
			$this->price = $price;
			$this->type  = $type;
		}
		public function get_id(): int { return $this->id; }
		public function get_price( string $context = 'view' ): float { return $this->price; }
		public function get_type(): string { return $this->type; }
	}
}

function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void {
	$GLOBALS['opf_subs_hooks'][ $name ][ $priority ][] = [ $callback, $accepted ];
}
function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void {
	add_filter( $name, $callback, $priority, $accepted );
}
function apply_filters( $name, $value, ...$args ) {
	$hooks = $GLOBALS['opf_subs_hooks'][ $name ] ?? [];
	ksort( $hooks );
	foreach ( $hooks as $callbacks ) {
		foreach ( $callbacks as [ $callback, $accepted ] ) {
			$value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted ) );
		}
	}
	return $value;
}
function do_action( $name, ...$args ): void {
	$hooks = $GLOBALS['opf_subs_hooks'][ $name ] ?? [];
	ksort( $hooks );
	foreach ( $hooks as $callbacks ) {
		foreach ( $callbacks as [ $callback, $accepted ] ) {
			$callback( ...array_slice( $args, 0, $accepted ) );
		}
	}
}
