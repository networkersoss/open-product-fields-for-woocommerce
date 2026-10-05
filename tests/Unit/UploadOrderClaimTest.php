<?php
namespace OPF\Tests\Unit;

use OPF\Service\Uploads;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UploadOrderClaimTest extends TestCase {
	private string $dir;
	private string $token;
	private array $field;

	protected function setUp(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/opf-test-content' );
		if ( ! defined( 'MB_IN_BYTES' ) ) define( 'MB_IN_BYTES', 1048576 );
		if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
		if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ) define( 'OPF_UPLOAD_PRIVATE_DIR', sys_get_temp_dir() . '/opf-claim-' . uniqid() );
		eval( 'class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data ) { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_data() { return $this->data; } }
		class WC_Order { public $id; public $status; public $total = 10.0; public $customer_id = 0;
			public function __construct( $id, $status ) { $this->id = $id; $this->status = $status; }
			public function get_id() { return $this->id; }
			public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
			public function needs_payment() { return $this->has_status( [ "pending", "failed" ] ) && $this->total > 0; }
			public function get_customer_id() { return $this->customer_id; } }
		class WC_Order_Item_Product { public $meta = []; public function add_meta_data( $k, $v, $u = false ) { $this->meta[ $k ] = $v; } public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ""; } }
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
		function wp_max_upload_size() { return 64 * 1048576; }
		function get_allowed_mime_types() { return [ "png" => "image/png" ]; }
		function sanitize_file_name( $n ) { return preg_replace( "/[^A-Za-z0-9._-]/", "_", $n ); }
		function wp_check_filetype_and_ext( $file, $name ) { return "png" === pathinfo( $name, PATHINFO_EXTENSION ) ? [ "ext" => "png", "type" => "image/png" ] : [ "ext" => false, "type" => false ]; }' );

		$GLOBALS['opf_wc']               = new class() { public $session; };
		$GLOBALS['opf_wc']->session      = new \OPF_Test_Session();
		$GLOBALS['opf_orders']           = [];
		$GLOBALS['opf_test_options']     = [];
		$this->dir                       = OPF_UPLOAD_PRIVATE_DIR;
		$this->token                     = str_repeat( 'a', 64 );
		$this->field                     = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'accepted_types' => [ 'png' ], 'max_size' => 1, 'multiple' => false ];
		mkdir( $this->dir, 0700, true );
		file_put_contents( $this->dir . '/' . $this->token . '.bin', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=' ) );
		chmod( $this->dir . '/' . $this->token . '.bin', 0600 );
		$this->store( 0 );
	}

	protected function tearDown(): void {
		$file = $this->dir . '/' . $this->token . '.bin';
		if ( is_file( $file ) ) unlink( $file );
		if ( is_dir( $this->dir ) ) rmdir( $this->dir );
	}

	private function store( int $order_id ): void {
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ] = [
			'name' => 'art.png', 'mime' => 'image/png', 'size' => filesize( $this->dir . '/' . $this->token . '.bin' ),
			'owner' => \OPF\Service\Uploads::owner(), 'product_id' => 7, 'group_id' => 'g', 'field_id' => 'art',
			'created' => time(), 'order_id' => $order_id, 'cart' => true,
		];
	}

	public function test_retryable_order_bindings_still_validate_for_their_owner(): void {
		$GLOBALS['opf_orders'][40] = new \WC_Order( 40, 'checkout-draft' );
		$GLOBALS['opf_orders'][41] = new \WC_Order( 41, 'pending' );
		$GLOBALS['opf_orders'][42] = new \WC_Order( 42, 'failed' );
		foreach ( [ 40, 41, 42 ] as $id ) {
			$this->store( $id );
			self::assertSame( [], Uploads::validate_tokens( $this->field, [ $this->token ], 7, 'g' ), "status {$GLOBALS['opf_orders'][ $id ]->status}" );
		}
	}

	public function test_completed_and_deleted_order_bindings_keep_their_claim(): void {
		$GLOBALS['opf_orders'][50] = new \WC_Order( 50, 'processing' );
		$this->store( 50 );
		self::assertNotSame( [], Uploads::validate_tokens( $this->field, [ $this->token ], 7, 'g' ) );
		$this->store( 99 );
		self::assertSame( [], Uploads::validate_tokens( $this->field, [ $this->token ], 7, 'g' ) );
	}

	public function test_persist_reemits_references_and_rebinds_retryable_orders(): void {
		$GLOBALS['opf_orders'][60] = new \WC_Order( 60, 'pending' );
		$this->store( 60 );
		$item = new \WC_Order_Item_Product();
		Uploads::persist( [ 'g' => [ 'art' => [ $this->token ] ] ], $item, $GLOBALS['opf_orders'][60] );
		self::assertSame( [ [ 'token' => $this->token, 'name' => 'art.png' ] ], $item->get_meta( '_opf_uploads' ) );

		$next = new \WC_Order( 61, 'checkout-draft' );
		$item = new \WC_Order_Item_Product();
		Uploads::persist( [ 'g' => [ 'art' => [ $this->token ] ] ], $item, $next );
		self::assertSame( 61, $GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['order_id'] );
		self::assertSame( [ [ 'token' => $this->token, 'name' => 'art.png' ] ], $item->get_meta( '_opf_uploads' ) );
	}

	public function test_persist_and_bind_order_never_steal_live_order_uploads(): void {
		$GLOBALS['opf_orders'][70] = new \WC_Order( 70, 'processing' );
		$this->store( 70 );
		$item = new \WC_Order_Item_Product();
		Uploads::persist( [ 'g' => [ 'art' => [ $this->token ] ] ], $item, new \WC_Order( 71, 'checkout-draft' ) );
		self::assertSame( 70, $GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['order_id'] );
		self::assertSame( '', $item->get_meta( '_opf_uploads' ) );

		$meta_item = new \WC_Order_Item_Product();
		$meta_item->add_meta_data( '_opf_uploads', [ [ 'token' => $this->token, 'name' => 'art.png' ] ] );
		Uploads::bind_order( 1, $meta_item, 72 );
		self::assertSame( 70, $GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['order_id'] );

		$GLOBALS['opf_orders'][70]->status = 'pending';
		Uploads::bind_order( 1, $meta_item, 73 );
		self::assertSame( 73, $GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['order_id'] );
	}

	public function test_mark_cart_refreshes_retryable_bindings_but_not_live_ones(): void {
		$GLOBALS['opf_orders'][80] = new \WC_Order( 80, 'pending' );
		$this->store( 80 );
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['cart']    = false;
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['created'] = time() - 3600;
		Uploads::mark_cart( [ 'g' => [ 'art' => [ $this->token ] ] ] );
		$record = $GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ];
		self::assertTrue( $record['cart'] );
		self::assertGreaterThan( time() - 60, $record['created'] );

		$GLOBALS['opf_orders'][81] = new \WC_Order( 81, 'processing' );
		$this->store( 81 );
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['cart']    = false;
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['created'] = time() - 3600;
		Uploads::mark_cart( [ 'g' => [ 'art' => [ $this->token ] ] ] );
		$record = $GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ];
		self::assertFalse( $record['cart'] );
	}
}
