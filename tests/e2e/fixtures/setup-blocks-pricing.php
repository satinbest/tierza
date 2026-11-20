<?php
/**
 * Setup fixture for Playwright WooCommerce Blocks & Store API Pricing E2E tests (TRZ-012).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 0. Clean any previous test products, orders, and draft orders
$old_prods = wc_get_products( [
	'limit' => -1,
	's'     => 'Tierza Blocks E2E',
] );
foreach ( $old_prods as $old_p ) {
	$old_p->delete( true );
}

$old_orders = wc_get_orders( [
	'limit'         => -1,
	'billing_email' => 'e2e_blocks_b2b@tierza.test',
] );
foreach ( $old_orders as $old_o ) {
	$old_o->delete( true );
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status = 'wc-checkout-draft'" );

// 1. Create simple product ($100.00)
$prod1 = new WC_Product_Simple();
$prod1->set_name( 'Tierza Blocks E2E Simple Product' );
$prod1->set_status( 'publish' );
$prod1->set_regular_price( '100.00' );
$prod1->set_price( '100.00' );
$prod1->save();
$p1_id = $prod1->get_id();

// 2. Create variable product
$prod2 = new WC_Product_Variable();
$prod2->set_name( 'Tierza Blocks E2E Variable Product' );
$prod2->set_status( 'publish' );
$prod2->save();
$p2_id = $prod2->get_id();

// Variation 1
$var1 = new WC_Product_Variation();
$var1->set_parent_id( $p2_id );
$var1->set_regular_price( '150.00' );
$var1->set_price( '150.00' );
$var1->set_status( 'publish' );
$var1->save();
$v1_id = $var1->get_id();

// 3. Create test user
$existing_user = get_user_by( 'login', 'e2e_blocks_b2b_user' );
if ( $existing_user ) {
	wp_delete_user( $existing_user->ID );
}
$uid = wp_create_user( 'e2e_blocks_b2b_user', 'password123', 'e2e_blocks_b2b@tierza.test' );

// 4. Create group and assign user
global $wpdb;
$table_groups      = $wpdb->prefix . 'tierza_groups';
$table_user_groups = $wpdb->prefix . 'tierza_user_groups';
$table_rules       = $wpdb->prefix . 'tierza_rules';
$now               = current_time( 'mysql', true );

$wpdb->insert(
	$table_groups,
	[
		'name'       => 'E2E Blocks B2B Group',
		'slug'       => 'e2e-blocks-b2b-group-' . time(),
		'status'     => 'active',
		'created_at' => $now,
		'updated_at' => $now,
	]
);
$gid = (int) $wpdb->insert_id;

$wpdb->insert(
	$table_user_groups,
	[
		'user_id'    => $uid,
		'group_id'   => $gid,
		'created_at' => $now,
		'updated_at' => $now,
	]
);

// 5. Pricing Rule: 20% discount on Product 1 for Group ($100.00 -> $80.00)
$active_key = sprintf( 'group:%d|product:%d', $gid, $p1_id );
$wpdb->insert(
	$table_rules,
	[
		'target_type'  => 'group',
		'target_id'    => $gid,
		'scope_type'   => 'product',
		'scope_id'     => $p1_id,
		'pricing_type' => 'percentage',
		'value'        => '20.000000',
		'status'       => 'active',
		'active_key'   => $active_key,
		'created_at'   => $now,
		'updated_at'   => $now,
	]
);
$r1_id = (int) $wpdb->insert_id;

// Pricing Rule: 10% discount on Variation 1 for Group ($150.00 -> $135.00)
$active_key_var = sprintf( 'group:%d|variation:%d', $gid, $v1_id );
$wpdb->insert(
	$table_rules,
	[
		'target_type'  => 'group',
		'target_id'    => $gid,
		'scope_type'   => 'variation',
		'scope_id'     => $v1_id,
		'pricing_type' => 'percentage',
		'value'        => '10.000000',
		'status'       => 'active',
		'active_key'   => $active_key_var,
		'created_at'   => $now,
		'updated_at'   => $now,
	]
);
$r2_id = (int) $wpdb->insert_id;

// 6. Ensure cart and checkout pages have Gutenberg Blocks content
$cart_pid = wc_get_page_id( 'cart' );
if ( $cart_pid > 0 ) {
	$current_cart = (string) get_post_field( 'post_content', $cart_pid );
	if ( ! str_contains( $current_cart, 'wp:woocommerce/cart' ) ) {
		wp_update_post( [
			'ID'           => $cart_pid,
			'post_content' => "<!-- wp:woocommerce/cart -->\n<div class=\"wp-block-woocommerce-cart alignwide\"></div>\n<!-- /wp:woocommerce/cart -->",
		] );
	}
}

$checkout_pid = wc_get_page_id( 'checkout' );
if ( $checkout_pid > 0 ) {
	$current_checkout = (string) get_post_field( 'post_content', $checkout_pid );
	if ( ! str_contains( $current_checkout, 'wp:woocommerce/checkout' ) ) {
		wp_update_post( [
			'ID'           => $checkout_pid,
			'post_content' => "<!-- wp:woocommerce/checkout -->\n<div class=\"wp-block-woocommerce-checkout alignwide\"></div>\n<!-- /wp:woocommerce/checkout -->",
		] );
	}
}

// Ensure pretty permalinks are enabled for REST API /wp-json/
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->flush_rules( true );
if ( function_exists( 'save_mod_rewrite_rules' ) ) {
	save_mod_rewrite_rules();
}

// Ensure BACS is enabled for testing checkout
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes' ] );

// Clear all pricing cache
\Tierza\Plugin::instance()->get_pricing_cache()->clear();

echo wp_json_encode( [
	'simple_id'         => $p1_id,
	'simple_url'        => get_permalink( $p1_id ),
	'variable_id'       => $p2_id,
	'variation_id'      => $v1_id,
	'user_id'           => $uid,
	'group_id'          => $gid,
	'cart_url'          => wc_get_cart_url(),
	'checkout_url'      => wc_get_checkout_url(),
	'rest_cart_url'     => get_rest_url( null, 'wc/store/v1/cart' ),
	'rest_checkout_url' => get_rest_url( null, 'wc/store/v1/checkout' ),
] );
