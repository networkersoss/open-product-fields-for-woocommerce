<?php
namespace OPF\Tests\Unit;

use OPF\Service\CartIntegration;
use OPF\Service\UploadReissue;
use OPF\Service\Uploads;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UploadReissueTest extends TestCase {
	private string $dir;
	private string $token;
	private string $png;

	protected function setUp(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/opf-test-content' );
		if ( ! defined( 'MB_IN_BYTES' ) ) define( 'MB_IN_BYTES', 1048576 );
		if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
		if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ) define( 'OPF_UPLOAD_PRIVATE_DIR', sys_get_temp_dir() . '/opf-reissue-' . uniqid() );
		eval( 'class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data ) { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_data() { return $this->data; } }
		class WC_Order { public $id; public $status; public $total = 10.0; public $customer_id = 0;
			public function __construct( $id, $status ) { $this->id = $id; $this->status = $status; }
			public function get_id() { return $this->id; }
			public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
			public function needs_payment() { return $this->has_status( [ "pending", "failed" ] ) && $this->total > 0; }
			public function get_customer_id() { return $this->customer_id; } }
		class WC_Order_Item_Product { public $meta = []; public $product_id = 7;
			public function add_meta_data( $k, $v, $u = false ) { $this->meta[ $k ] = $v; }
			public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ""; }
			public function get_product_id() { return $this->product_id; } }
		class OPF_Test_Session { public $customer = "sess-owner"; public $data = [];
			public function get_customer_id() { return $this->customer; }
			public function get( $k ) { return $this->data[ $k ] ?? null; }
			public function set( $k, $v ) { $this->data[ $k ] = $v; }
			public function set_customer_session_cookie( $b ) {} }
		function WC() { return $GLOBALS["opf_wc"]; }
		function wc_get_order( $id ) { return $GLOBALS["opf_orders"][ $id ] ?? false; }
		function wc_load_cart() {}
		function wp_salt( $s ) { return "test-salt"; }
		function is_wp_error( $v ) { return $v instanceof WP_Error; }
		function update_option( $k, $v, $a = null ) { $GLOBALS["opf_test_options"][ $k ] = $v; return true; }
		function add_option( $k, $v, $d = "", $a = null ) { $GLOBALS["opf_test_options"][ $k ] = $v; return true; }
		function delete_option( $k ) { unset( $GLOBALS["opf_test_options"][ $k ] ); return true; }
		function apply_filters( $n, $v, ...$a ) { return $v; }
		function current_user_can( $cap ) { return ! empty( $GLOBALS["opf_can"][ $cap ] ); }
		function get_current_user_id() { return $GLOBALS["opf_uid"] ?? 0; }
		function wp_max_upload_size() { return 64 * 1048576; }
		function get_allowed_mime_types() { return [ "png" => "image/png" ]; }
		function sanitize_file_name( $n ) { return preg_replace( "/[^A-Za-z0-9._-]/", "_", $n ); }
		function wp_check_filetype_and_ext( $file, $name ) { return "png" === pathinfo( $name, PATHINFO_EXTENSION ) ? [ "ext" => "png", "type" => "image/png" ] : [ "ext" => false, "type" => false ]; }
		function maybe_unserialize( $v ) { return $v; }
		define( "ARRAY_A", "ARRAY_A" );
		class OPF_Test_WPDB { public $options = "wp_options";
			public function prepare( $q, ...$a ) { return $q; }
			public function esc_like( $s ) { return $s; }
			public function get_results( $q, $f = null ) { $rows = []; foreach ( $GLOBALS["opf_test_options"] as $k => $v ) { $rows[] = [ "option_name" => $k, "option_value" => $v ]; } return $rows; } }' );

		$GLOBALS['wpdb']             = new \OPF_Test_WPDB();
		$GLOBALS['opf_wc']           = new class() { public $session; };
		$GLOBALS['opf_wc']->session  = new \OPF_Test_Session();
		$GLOBALS['opf_orders']       = [];
		$GLOBALS['opf_test_options'] = [];
		$GLOBALS['opf_can']          = [];
		$GLOBALS['opf_uid']          = 0;
		$this->dir                   = OPF_UPLOAD_PRIVATE_DIR;
		$this->token                 = str_repeat( 'a', 64 );
		$this->png                   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' );
		mkdir( $this->dir, 0700, true );
		file_put_contents( $this->dir . '/' . $this->token . '.bin', $this->png );
		chmod( $this->dir . '/' . $this->token . '.bin', 0600 );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*.bin' ) ?: [] as $file ) {
			unlink( $file );
		}
		foreach ( glob( $this->dir . '/.upload.lock' ) ?: [] as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) rmdir( $this->dir );
	}

	private function store( int $order_id, array $overrides = [] ): void {
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ] = $overrides + [
			'name' => 'art.png', 'mime' => 'image/png', 'size' => strlen( $this->png ),
			'owner' => Uploads::owner(), 'product_id' => 7, 'group_id' => 'g', 'field_id' => 'art',
			'created' => time(), 'order_id' => $order_id, 'cart' => true,
		];
	}

	private function cart_data( $value ): array {
		return [ CartIntegration::ITEM_KEY => [ 'g' => [ 'art' => $value ] ] ];
	}

	private function order_again( array $cart_item_data, \WC_Order $order, int $product_id = 7 ): array {
		$GLOBALS['opf_orders'][ $order->get_id() ] = $order; // A reordered order always exists.
		$item = new \WC_Order_Item_Product();
		$item->product_id = $product_id;
		return UploadReissue::reissue( $cart_item_data, $item, $order );
	}

	public function test_completed_order_token_is_reissued_for_the_same_session(): void {
		$order = new \WC_Order( 40, 'completed' );
		$this->store( 40 );

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		$new  = $data[ CartIntegration::ITEM_KEY ]['g']['art'];
		self::assertCount( 1, $new );
		self::assertNotSame( $this->token, $new[0] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $new[0] );

		$record = Uploads::record( $new[0] );
		self::assertSame( Uploads::owner(), $record['owner'] );
		self::assertSame( 0, $record['order_id'] );
		self::assertFalse( $record['cart'] );
		self::assertSame( [ 7, 'g', 'art', 'art.png', 'image/png', strlen( $this->png ), $this->token ],
			[ $record['product_id'], $record['group_id'], $record['field_id'], $record['name'], $record['mime'], $record['size'], $record['reissued_from'] ] );
		self::assertSame( $this->png, file_get_contents( $this->dir . '/' . $new[0] . '.bin' ) );

		// The source record is untouched: still claimed by the completed order.
		$source = Uploads::record( $this->token );
		self::assertSame( 40, $source['order_id'] );
		self::assertArrayNotHasKey( 'reissued_from', $source );

		// The reissue satisfies the same validation a fresh upload passes.
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'accepted_types' => [ 'png' ], 'max_size' => 1, 'multiple' => false ];
		self::assertSame( [], Uploads::validate_tokens( $field, $new, 7, 'g' ) );
		// The source token still cannot seed a cart.
		self::assertNotSame( [], Uploads::validate_tokens( $field, [ $this->token ], 7, 'g' ) );
	}

	public function test_reissued_token_binds_to_the_new_order_only(): void {
		$old = new \WC_Order( 41, 'completed' );
		$this->store( 41 );
		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $old );
		$new_token = $data[ CartIntegration::ITEM_KEY ]['g']['art'][0];

		$new_order = new \WC_Order( 42, 'checkout-draft' );
		$GLOBALS['opf_orders'][42] = $new_order;
		$item = new \WC_Order_Item_Product();
		Uploads::persist( [ 'g' => [ 'art' => [ $new_token ] ] ], $item, $new_order );
		$record = Uploads::record( $new_token );
		self::assertSame( 42, $record['order_id'] );
		self::assertSame( [ [ 'token' => $new_token, 'name' => 'art.png' ] ], $item->get_meta( '_opf_uploads' ) );

		// Once the reorder completes, the reissued token is claimed too.
		$new_order->status = 'processing';
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'accepted_types' => [ 'png' ], 'max_size' => 1, 'multiple' => false ];
		self::assertNotSame( [], Uploads::validate_tokens( $field, [ $new_token ], 7, 'g' ) );
		// The source token never moved off the original order.
		self::assertSame( 41, Uploads::record( $this->token )['order_id'] );
	}

	public function test_foreign_session_cannot_reissue_a_customers_file(): void {
		$order = new \WC_Order( 43, 'completed' );
		$order->customer_id = 55;
		$this->store( 43 );
		$GLOBALS['opf_wc']->session->customer = 'intruder-session';
		$GLOBALS['opf_uid'] = 77; // Logged in, but neither customer nor manager.

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertSame( [ $this->token ], $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
		self::assertCount( 1, glob( $this->dir . '/*.bin' ) );
	}

	public function test_order_customer_can_reissue_from_a_new_session(): void {
		$order = new \WC_Order( 44, 'completed' );
		$order->customer_id = 55;
		$this->store( 44 );
		$GLOBALS['opf_wc']->session->customer = 'new-device-session';
		$GLOBALS['opf_uid'] = 55;

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		$new  = $data[ CartIntegration::ITEM_KEY ]['g']['art'];
		self::assertNotSame( $this->token, $new[0] );
		$record = Uploads::record( $new[0] );
		self::assertSame( hash_hmac( 'sha256', 'new-device-session', 'test-salt' ), $record['owner'] );
	}

	public function test_shop_manager_can_reissue_for_fulfilment(): void {
		$order = new \WC_Order( 45, 'completed' );
		$order->customer_id = 55;
		$this->store( 45 );
		$GLOBALS['opf_wc']->session->customer = 'manager-session';
		$GLOBALS['opf_uid'] = 1;
		$GLOBALS['opf_can'] = [ 'manage_woocommerce' => true ];

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertNotSame( $this->token, $data[ CartIntegration::ITEM_KEY ]['g']['art'][0] );
	}

	public function test_guest_session_of_anonymous_order_cannot_reissue(): void {
		$order = new \WC_Order( 46, 'completed' );
		$order->customer_id = 0;
		$this->store( 46 );
		$GLOBALS['opf_wc']->session->customer = 'other-guest-session';
		$GLOBALS['opf_uid'] = 0;

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertSame( [ $this->token ], $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
	}

	public function test_retryable_order_keeps_the_still_valid_token(): void {
		$order = new \WC_Order( 47, 'pending' );
		$GLOBALS['opf_orders'][47] = $order;
		$this->store( 47 );

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertSame( [ $this->token ], $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
		self::assertCount( 1, glob( $this->dir . '/*.bin' ) );
	}

	public function test_token_bound_to_a_different_order_is_untouched(): void {
		$order = new \WC_Order( 48, 'completed' );
		$this->store( 99 );

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertSame( [ $this->token ], $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
	}

	public function test_token_with_wrong_scope_is_untouched(): void {
		$order = new \WC_Order( 49, 'completed' );
		$this->store( 49, [ 'field_id' => 'other' ] );
		self::assertSame( [ $this->token ], $this->order_again( $this->cart_data( [ $this->token ] ), $order )[ CartIntegration::ITEM_KEY ]['g']['art'] );

		$this->store( 49, [ 'group_id' => 'other-group' ] );
		self::assertSame( [ $this->token ], $this->order_again( $this->cart_data( [ $this->token ] ), $order )[ CartIntegration::ITEM_KEY ]['g']['art'] );

		$this->store( 49 );
		self::assertSame( [ $this->token ], $this->order_again( $this->cart_data( [ $this->token ] ), $order, 8 )[ CartIntegration::ITEM_KEY ]['g']['art'] );
	}

	public function test_unknown_and_malformed_tokens_pass_through(): void {
		$order = new \WC_Order( 50, 'completed' );
		$data  = $this->order_again( $this->cart_data( [ 'invalid', str_repeat( 'b', 64 ), $this->token ] ), $order );
		$out   = $data[ CartIntegration::ITEM_KEY ]['g']['art'];
		self::assertSame( 'invalid', $out[0] );
		self::assertSame( str_repeat( 'b', 64 ), $out[1] );
		$this->store( 50 );
		$data = $this->order_again( $this->cart_data( [ 'invalid', $this->token ] ), $order );
		$out  = $data[ CartIntegration::ITEM_KEY ]['g']['art'];
		self::assertSame( 'invalid', $out[0] );
		self::assertNotSame( $this->token, $out[1] );
	}

	public function test_scalar_and_nested_values_are_not_rewritten(): void {
		$order = new \WC_Order( 51, 'completed' );
		$this->store( 51 );
		$data = $this->order_again( [ CartIntegration::ITEM_KEY => [ 'g' => [ 'art' => 'plain text', 'nested' => [ [ $this->token ] ] ] ] ], $order );
		self::assertSame( 'plain text', $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
		self::assertSame( [ [ $this->token ] ], $data[ CartIntegration::ITEM_KEY ]['g']['nested'] );
	}

	public function test_second_reissue_reuses_the_live_copy(): void {
		$order = new \WC_Order( 52, 'completed' );
		$this->store( 52 );

		$first  = $this->order_again( $this->cart_data( [ $this->token ] ), $order )[ CartIntegration::ITEM_KEY ]['g']['art'][0];
		$second = $this->order_again( $this->cart_data( [ $this->token ] ), $order )[ CartIntegration::ITEM_KEY ]['g']['art'][0];
		self::assertSame( $first, $second );
		self::assertCount( 2, glob( $this->dir . '/*.bin' ) );

		// Once the reissue is claimed by a new completed order, a further
		// reorder of the original order mints another copy.
		$claimed = new \WC_Order( 53, 'processing' );
		$GLOBALS['opf_orders'][53] = $claimed;
		$record = Uploads::record( $first );
		$record['order_id'] = 53;
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $first ] = $record;
		$third = $this->order_again( $this->cart_data( [ $this->token ] ), $order )[ CartIntegration::ITEM_KEY ]['g']['art'][0];
		self::assertNotSame( $first, $third );
		self::assertCount( 3, glob( $this->dir . '/*.bin' ) );
	}

	public function test_site_file_cap_blocks_reissue_and_fails_closed(): void {
		$order = new \WC_Order( 54, 'completed' );
		$this->store( 54 );
		define( 'OPF_UPLOAD_MAX_FILES', 1 );

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertSame( [ $this->token ], $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
		self::assertCount( 1, glob( $this->dir . '/*.bin' ) );
	}

	public function test_missing_source_bytes_block_reissue(): void {
		$order = new \WC_Order( 56, 'completed' );
		$this->store( 56 );
		unlink( $this->dir . '/' . $this->token . '.bin' );

		$data = $this->order_again( $this->cart_data( [ $this->token ] ), $order );
		self::assertSame( [ $this->token ], $data[ CartIntegration::ITEM_KEY ]['g']['art'] );
	}

	public function test_cart_data_without_values_or_foreign_items_is_untouched(): void {
		$order = new \WC_Order( 57, 'completed' );
		$this->store( 57 );
		$item = new \WC_Order_Item_Product();

		self::assertSame( [], UploadReissue::reissue( [], $item, $order ) );
		self::assertSame( [ 'key' => 'x' ], UploadReissue::reissue( [ 'key' => 'x' ], $item, $order ) );
		self::assertSame( [], UploadReissue::reissue( [], $item, new \stdClass() ) );
	}
}
