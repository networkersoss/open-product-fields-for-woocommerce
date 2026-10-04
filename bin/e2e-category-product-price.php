<?php
/** Category-query fixed/none pricing proof. Fresh disposable clone only. */

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\FieldGroups;
use OPF\Service\WapfExporter;
use OPF\Service\WapfWxrExporter;

if ( '1' !== getenv( 'OPF_CATEGORY_PRICE_ALLOW' ) || '/tmp/opf-category-price-wp' !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Use the fresh /tmp/opf-category-price-wp clone with explicit opt-in.' );
}
$dir = getenv( 'OPF_CATEGORY_PRICE_ARTIFACTS' ) ?: '/tmp/opf-category-price-evidence';
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0700, true ); }
$checks = [];
$check = static function ( string $label, bool $ok ) use ( &$checks ): void {
	$checks[] = [ 'label' => $label, 'pass' => $ok ];
	if ( ! $ok ) { throw new RuntimeException( $label ); }
};
$phase = getenv( 'OPF_CATEGORY_PRICE_PHASE' ) ?: 'setup';
$state = get_option( 'opf_category_price_state', [] );

if ( 'setup' === $phase ) {
	$check( 'fresh fixture namespace', [] === $state );
	$term = wp_insert_term( 'Category price proof', 'product_cat', [ 'slug' => 'category-price-proof' ] );
	$check( 'category created', ! is_wp_error( $term ) );
	$make = static function ( string $name, float $price, bool $child ) use ( $term ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( (string) $price );
		$p->set_virtual( true );
		if ( $child ) { $p->set_category_ids( [ $term['term_id'] ] ); }
		return $p->save();
	};
	$parent = $make( 'Category price parent', 20, false );
	$alpha = $make( 'Category price alpha', 8, true );
	$beta = $make( 'Category price beta', 12, true );
	$fields = [];
	foreach ( [ 'fixed_card', 'none_card', 'fixed_qty', 'none_qty' ] as $id ) {
		$fields[] = [
			'id' => $id, 'label' => $id, 'type' => 'products',
			'subtype' => str_ends_with( $id, 'qty' ) ? 'card-qty' : 'card',
			'product_selection' => 'category', 'qty_method' => 'parent',
			'product_query' => [ 'query_id' => $term['term_id'], 'query_label' => 'Category price proof', 'limit' => 50, 'sort' => 'name_asc', 'pricing_type' => str_starts_with( $id, 'none' ) ? 'none' : 'fixed' ],
			'incl_img' => false, 'slot_1' => 'price',
		];
	}
	$data = FieldGroup::normalize( [ 'fields' => $fields, 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent ] ] ] ] ] ] );
	$gid = FieldGroups::save( 0, $data, [ 'title' => 'Category pricing proof', 'status' => 'publish' ] );
	$check( 'four category pricing modes persisted', $gid > 0 && $data === FieldGroups::group_from_post( get_post( $gid ) )->data );
	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Category proof checkout', 'post_name' => 'category-proof-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	update_option( 'woocommerce_checkout_page_id', $checkout );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_default_country', 'US:CA' );
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	update_option( 'woocommerce_coming_soon', 'no' );
	$state = [ 'group' => $gid, 'parent' => $parent, 'alpha' => $alpha, 'beta' => $beta, 'category' => (int) $term['term_id'], 'checkout' => $checkout ];
	update_option( 'opf_category_price_state', $state, false );
	file_put_contents( $dir . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
} elseif ( 'reference' === $phase ) {
	$check( 'fixture state exists', ! empty( $state['group'] ) );
	$source = rtrim( (string) getenv( 'OPF_WAPF_SOURCE_PATH' ), '/' );
	$check( 'installed WAPF reference source exists', is_file( $source . '/includes/controllers/class-linked-products-controller.php' ) );
	// Load reference classes only. No WAPF bootstrap or global Woo hooks run.
	spl_autoload_register( static function ( $class ) use ( $source ): void {
		if ( 0 !== strpos( $class, 'SW_WAPF_PRO\\' ) ) { return; }
		$parts = explode( '\\', substr( $class, 12 ) );
		$name = array_pop( $parts );
		$file = $source . '/' . implode( '/', array_map( 'strtolower', $parts ) ) . '/class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_file( $file ) ) { require_once $file; }
	} );
	$controller = ( new ReflectionClass( SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller::class ) )->newInstanceWithoutConstructor();
	add_filter( 'wapf/field_types', [ $controller, 'add_products_field_type' ] );
	add_action( 'wapf/admin/sanitize_field', [ $controller, 'sanitize_field_data' ], 10, 2 );
	$native_type = new ReflectionMethod( $controller, 'get_product_price_type' );
	$native_resolve = new ReflectionMethod( $controller, 'build_cache_data' );
	$native_expand = new ReflectionMethod( $controller, 'expand_product_choice' );
	$registry = new ReflectionMethod( OPF\Service\Renderer::class, 'registry' );
	foreach ( [ 'fixed', 'none' ] as $price ) {
		foreach ( FieldGroup::PRODUCTS_SUBTYPES as $subtype ) {
			$data = FieldGroup::normalize( [ 'fields' => [ [
				'id' => 'extras', 'label' => 'Extras', 'type' => 'products', 'subtype' => $subtype,
				'product_selection' => 'category', 'qty_method' => 'parent',
				'product_query' => [ 'query_id' => $state['category'], 'query_label' => 'Category price proof', 'limit' => 50, 'sort' => 'name_asc', 'pricing_type' => $price ],
			] ] ] );
			$label = "$price/$subtype";
			$payload = WapfExporter::build_payload( $data ) + [ 'id' => 77, 'type' => 'wapf_product' ];
			$model = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( $payload );
			$check( "$label native Tools model preserves query", $data['fields'][0]['product_query'] === $model->fields[0]->options['product_query'] );
			$mapped = WapfMapper::map( $model->to_array() );
			$check( "$label native Tools roundtrip preserves query", ! $mapped['needs_review'] && $data['fields'][0]['product_query'] === $mapped['group']['fields'][0]['product_query'] );
			$xml = WapfWxrExporter::build_document( [ [ 'id' => 77, 'title' => 'Category reference', 'data' => $data ] ], [ 'site_url' => home_url(), 'site_title' => 'Category reference' ] );
			$doc = new DOMDocument();
			$doc->loadXML( $xml );
			$xpath = new DOMXPath( $doc );
			$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
			$wxr = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent );
			$mapped = WapfMapper::map( $wxr->to_array() );
			$check( "$label native WXR roundtrip preserves query", ! $mapped['needs_review'] && $data['fields'][0]['product_query'] === $mapped['group']['fields'][0]['product_query'] );
			// WAPF's admin parser creates an unhydrated field. Runtime reads the
			// stored model through process_data(), which attaches subtype metadata.
			$runtime = SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $model->to_array() );
			$check( "$label actual reference runtime price type agrees", OPF\Service\LinkedProducts::price_type( $data['fields'][0], $price ) === $native_type->invoke( $controller, $runtime->fields[0], [ 'pricing_type' => $price ] ) );
			$raw_value = OPF\Service\LinkedProducts::is_qty_subtype( $data['fields'][0] ) ? [ $state['alpha'] => 3 ] : [ $state['alpha'] ];
			$legacy = $native_resolve->invoke( $controller, $runtime->fields[0], $raw_value, 2, $state['parent'], 0 );
			$opf_value = OPF\Service\LinkedProducts::sanitize_value( $data['fields'][0], $raw_value );
			$resolved = OPF\Service\LinkedProducts::resolve( $data['fields'][0], $opf_value, 2, $state['parent'] );
			$check( "$label actual reference selected child quantity agrees", $legacy['choices'][ $state['alpha'] ]['qty'] === $resolved['choices'][ $state['alpha'] ]['qty'] );
			$check( "$label actual reference selected child propagation agrees", $legacy['qty_method'] === $resolved['qty_type'] );
			$child = wc_get_product( $state['alpha'] );
			$expanded = $native_expand->invoke( $controller, [ 'pricing_type' => $price ], $runtime->fields[0], $child );
			$opf_choices = OPF\Service\LinkedProducts::product_choices( $data['fields'][0], wc_get_product( $state['parent'] ) );
			$check( "$label actual reference zero/catalog amount agrees", (float) $expanded['pricing_amount'] === (float) $opf_choices[0]['pricing_amount'] );
			$client = $registry->invoke( null, [ [ 'id' => 77, 'group' => new FieldGroup( $data ) ] ], wc_get_product( $state['parent'] ) );
			$client_field = $client[77]['extras'];
			$check( "$label live registry contains two queried products", 2 === count( $client_field['choices'] ) );
			$check( "$label live registry source price and type preserved", $client_field['choices'][0]['child_price'] === (float) $expanded['pricing_amount'] && $client_field['choices'][0]['child_price_type'] === $expanded['pricing_type'] );
			$check( "$label live registry contains no product object", ! isset( $client_field['choices'][0]['product'] ) );
			// Persist through the real importers, then re-read stored groups.
			// These source posts exist only in the disposable database; cleanup
			// covers both sides even if an assertion fails.
			foreach ( [ 'Tools' => serialize( $model->to_array() ), 'WXR' => $xpath->query( '/rss/channel/item/content:encoded' )->item( 0 )->textContent ] as $format => $content ) {
				$source_id = wp_insert_post( [ 'post_type' => 'wapf_product', 'post_status' => 'publish', 'post_title' => "$label $format import proof", 'post_content' => wp_slash( $content ) ] );
				$imported_id = 0;
				try {
					$report = OPF\Service\Importer::run( true );
					foreach ( $report['groups'] as $result ) {
						if ( (int) ( $result['source'] ?? 0 ) === $source_id ) { $imported_id = (int) ( $result['opf_id'] ?? 0 ); break; }
					}
					$check( "$label $format real import persisted", $imported_id > 0 );
					$stored = FieldGroups::group_from_post( get_post( $imported_id ) );
					$check( "$label $format persisted subtype and query roundtrip", $stored->data['fields'][0]['subtype'] === $subtype && $stored->data['fields'][0]['product_query'] === $data['fields'][0]['product_query'] );
				} finally {
					if ( $imported_id ) { wp_delete_post( $imported_id, true ); }
					wp_delete_post( $source_id, true );
				}
				$check( "$label $format owned import records removed", null === get_post( $source_id ) && null === get_post( $imported_id ) );
			}
			$package = OPF\Service\Exporter::build_package( [ [ 'id' => 77, 'title' => "$label Archive import proof", 'status' => 'publish', 'data' => $data ] ], [ 'site' => home_url() ] );
			$report = OPF\Service\ArchiveImporter::import( $package, true );
			$archive_id = (int) ( $report['groups'][0]['opf_id'] ?? 0 );
			try {
				$check( "$label Archive real import persisted", $archive_id > 0 );
				$stored = FieldGroups::group_from_post( get_post( $archive_id ) );
				$check( "$label Archive persisted subtype and query roundtrip", $stored->data === $data );
				$check( "$label Archive site-local query requires review", 'draft' === get_post_status( $archive_id ) && ! empty( get_post_meta( $archive_id, '_opf_needs_review', true ) ) );
			} finally {
				if ( $archive_id ) { wp_delete_post( $archive_id, true ); }
			}
			$check( "$label Archive owned import record removed", null === get_post( $archive_id ) );
		}
	}
} elseif ( 'verify' === $phase ) {
	$check( 'fixture state exists', ! empty( $state['group'] ) );
	if ( ! WC()->cart ) { wc_load_cart(); }
	$orders = json_decode( file_get_contents( $dir . '/orders.json' ), true );
	foreach ( $orders as $transport => $order_id ) {
		$order = wc_get_order( $order_id );
		$check( "$transport checkout-generated order", $order instanceof WC_Order );
		$items = $order->get_items();
		$check( "$transport parent and four children persisted", 5 === count( $items ) );
		$check( "$transport total is 40+16+0+36+0=92", 92.0 === (float) $order->get_total() );
		$cart = [];
		foreach ( $items as $item ) {
			$marker = $item->get_meta( '_opf_child_full' );
			if ( is_array( $marker ) ) {
				$free = str_starts_with( $marker['field'], 'none' );
				$check( "$transport {$marker['field']} order price preserved", ( $free ? 0.0 : ( 'fixed_qty' === $marker['field'] ? 36.0 : 16.0 ) ) === (float) $item->get_total() );
				$check( "$transport {$marker['field']} order pricing marker preserved", ( $free ? 'none' : ( 'fixed_qty' === $marker['field'] ? 'nr' : 'qt' ) ) === $marker['price_type'] );
			}
			$data = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
			$pid = $item->get_product_id();
			$qty = $item->get_quantity();
			$check( "$transport order-again validates item $pid", apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, $qty, 0, [], $data ) );
			$key = WC()->cart->generate_cart_id( $pid, 0, [], $data );
			$product = wc_get_product( $pid );
			$cart[ $key ] = apply_filters( 'woocommerce_add_order_again_cart_item', $data + [ 'key' => $key, 'product_id' => $pid, 'variation_id' => 0, 'variation' => [], 'quantity' => $qty, 'data' => $product, 'data_hash' => wc_get_cart_item_data_hash( $product ) ], $key );
		}
		do_action_ref_array( 'woocommerce_ordered_again', [ $order_id, $items, &$cart ] );
		WC()->cart->cart_contents = $cart;
		WC()->cart->calculate_totals();
		$check( "$transport order-again keeps five lines and total", 5 === count( WC()->cart->get_cart() ) && 92.0 === (float) WC()->cart->get_total( 'edit' ) );
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( isset( $line['_opf_child'] ) ) {
				$check( "$transport order-again parent link restored", isset( WC()->cart->cart_contents[ $line['_opf_child']['parent'] ] ) );
			}
		}
		WC()->cart->empty_cart();
	}
} elseif ( 'reorder-setup' === $phase ) {
	$check( 'fixture state exists', ! empty( $state['group'] ) );
	foreach ( [ 'account' => '[woocommerce_my_account]', 'cart' => '[woocommerce_cart]' ] as $key => $content ) {
		$check( "fresh $key page fixture", empty( $state[ $key ] ) );
		$state[ $key ] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Category proof ' . $key, 'post_content' => $content ] );
		update_option( 'woocommerce_' . ( 'account' === $key ? 'myaccount' : 'cart' ) . '_page_id', $state[ $key ] );
	}
	$admin = get_user_by( 'login', 'parity-admin' );
	$orders = json_decode( file_get_contents( $dir . '/orders.json' ), true );
	foreach ( $orders as $transport => $id ) {
		$order = wc_get_order( $id );
		$order->set_customer_id( $admin->ID );
		$order->set_status( 'completed' );
		$order->save();
		$check( "$transport fixture owned/completed for actual reorder", (int) $admin->ID === $order->get_customer_id() && $order->has_status( 'completed' ) );
	}
	update_option( 'opf_category_price_state', $state, false );
	file_put_contents( $dir . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
} elseif ( 'stock-refund' === $phase ) {
	$check( 'fixture state exists', ! empty( $state['group'] ) );
	$orders = json_decode( file_get_contents( $dir . '/orders.json' ), true );
	foreach ( $orders as $transport => $id ) {
		$order = wc_get_order( $id );
		$check( "$transport fixture order has no prior refunds", $order instanceof WC_Order && [] === $order->get_refunds() );
		foreach ( [ $state['alpha'], $state['beta'] ] as $pid ) {
			$p = wc_get_product( $pid );
			$p->set_manage_stock( true );
			$p->set_stock_quantity( 50 );
			$p->save();
		}
		// These checkout orders were created with unmanaged stock. Enable stock
		// only on this fixture's products, then exercise Woo's actual order
		// reduction and refund/restock lifecycle with their persisted lines.
		$order->get_data_store()->set_stock_reduced( $id, false );
		wc_reduce_stock_levels( $id );
		$check( "$transport paid+free alpha quantities both reduce stock", 44 === (int) wc_get_product( $state['alpha'] )->get_stock_quantity() );
		$check( "$transport paid+free beta quantities both reduce stock", 45 === (int) wc_get_product( $state['beta'] )->get_stock_quantity() );
		$lines = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item->get_meta( '_opf_child_full' ) ) {
				$lines[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => (float) $item->get_total(), 'refund_tax' => [] ];
			}
		}
		$refund = wc_create_refund( [ 'order_id' => $id, 'amount' => 52, 'reason' => 'Category price fixture', 'line_items' => $lines, 'refund_payment' => false, 'restock_items' => true ] );
		$check( "$transport paid and zero-price child refund persists", $refund instanceof WC_Order_Refund && 4 === count( $refund->get_items() ) && 52.0 === (float) $refund->get_amount() );
		$check( "$transport refund restores alpha stock including free child", 50 === (int) wc_get_product( $state['alpha'] )->get_stock_quantity() );
		$check( "$transport refund restores beta stock including free child", 50 === (int) wc_get_product( $state['beta'] )->get_stock_quantity() );
	}
} elseif ( 'cleanup' === $phase ) {
	$check( 'fixture state exists', ! empty( $state['group'] ) );
	$ids = is_file( $dir . '/owned-orders.json' ) ? json_decode( file_get_contents( $dir . '/owned-orders.json' ), true ) : [];
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); }
			$order->delete( true );
		}
		$check( "owned order $id removed", false === wc_get_order( $id ) );
	}
	foreach ( [ 'parent', 'alpha', 'beta', 'group', 'checkout', 'account', 'cart' ] as $key ) {
		if ( empty( $state[ $key ] ) ) { continue; }
		wp_delete_post( (int) $state[ $key ], true );
		$check( "owned $key removed", null === get_post( $state[ $key ] ) );
	}
	wp_delete_term( $state['category'], 'product_cat' );
	$check( 'owned category removed', null === term_exists( $state['category'], 'product_cat' ) );
	delete_option( 'opf_category_price_state' );
	FieldGroups::flush_cache();
} else {
	throw new RuntimeException( 'Unknown phase.' );
}
file_put_contents( $dir . '/' . $phase . '-checks.json', wp_json_encode( [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'checks' => $checks ], JSON_PRETTY_PRINT ) );
echo count( $checks ) . " checks passed ($phase).\n";
