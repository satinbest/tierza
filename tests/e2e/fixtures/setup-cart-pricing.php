<?php
/**
 * Setup fixture for Playwright Cart Pricing E2E tests (TRZ-011).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Create simple product ($100.00)
$prod1 = new WC_Product_Simple();
$prod1->set_name( 'Tierza E2E Cart Simple Product' );
$prod1->set_status( 'publish' );
$prod1->set_regular_price( '100.00' );
$prod1->set_price( '100.00' );
$prod1->save();
$p1_id = $prod1->get_id();

// 2. Create variable product
$prod2 = new WC_Product_Variable();
$prod2->set_name( 'Tierza E2E Cart Variable Product' );
$prod2->set_status( 'publish' );
$prod2->save();
$p2_id = $prod2->get_id();

// Variation 1
$var1 = new WC_Product_Variation();
$var1->set_parent_id( $p2_id );
$var1->set_regular_price( '120.00' );
$var1->set_price( '120.00' );
$var1->set_status( 'publish' );
$var1->save();
$v1_id = $var1->get_id();

// 3. Create test user
$existing_user = get_user_by( 'login', 'e2e_cart_b2b_user' );
if ( $existing_user ) {
	wp_delete_user( $existing_user->ID );
}
$uid = wp_create_user( 'e2e_cart_b2b_user', 'password123', 'e2e_cart_b2b@tierza.test' );

// 4. Create group and assign user
global $wpdb;
$table_groups      = $wpdb->prefix . 'tierza_groups';
$table_user_groups = $wpdb->prefix . 'tierza_user_groups';
$table_rules       = $wpdb->prefix . 'tierza_rules';
$now               = current_time( 'mysql', true );

$wpdb->insert(
	$table_groups,
	[
		'name'       => 'E2E Cart B2B Group',
		'slug'       => 'e2e-cart-b2b-group-' . time(),
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

// 5. Pricing Rule: 25% discount on Product 1 for Group
$active_key = sprintf( 'group:%d|product:%d', $gid, $p1_id );
$wpdb->insert(
	$table_rules,
	[
		'target_type'  => 'group',
		'target_id'    => $gid,
		'scope_type'   => 'product',
		'scope_id'     => $p1_id,
		'pricing_type' => 'percentage',
		'value'        => '25.000000',
		'status'       => 'active',
		'active_key'   => $active_key,
		'created_at'   => $now,
		'updated_at'   => $now,
	]
);
$r1_id = (int) $wpdb->insert_id;

// Pricing Rule: 20% discount on Variation 1 for Group
$active_key_var = sprintf( 'group:%d|variation:%d', $gid, $v1_id );
$wpdb->insert(
	$table_rules,
	[
		'target_type'  => 'group',
		'target_id'    => $gid,
		'scope_type'   => 'variation',
		'scope_id'     => $v1_id,
		'pricing_type' => 'percentage',
		'value'        => '20.000000',
		'status'       => 'active',
		'active_key'   => $active_key_var,
		'created_at'   => $now,
		'updated_at'   => $now,
	]
);
$r2_id = (int) $wpdb->insert_id;

// 6. Ensure cart and checkout pages use classic templates [woocommerce_cart] and [woocommerce_checkout]
$cart_pid = wc_get_page_id( 'cart' );
if ( $cart_pid > 0 ) {
	$orig_cart = get_post_field( 'post_content', $cart_pid );
	if ( ! get_post_meta( $cart_pid, '_tierza_e2e_orig_content', true ) ) {
		update_post_meta( $cart_pid, '_tierza_e2e_orig_content', $orig_cart );
	}
	wp_update_post( [
		'ID'           => $cart_pid,
		'post_content' => '[woocommerce_cart]',
	] );
}

$checkout_pid = wc_get_page_id( 'checkout' );
if ( $checkout_pid > 0 ) {
	$orig_checkout = get_post_field( 'post_content', $checkout_pid );
	if ( ! get_post_meta( $checkout_pid, '_tierza_e2e_orig_content', true ) ) {
		update_post_meta( $checkout_pid, '_tierza_e2e_orig_content', $orig_checkout );
	}
	wp_update_post( [
		'ID'           => $checkout_pid,
		'post_content' => '[woocommerce_checkout]',
	] );
}

echo wp_json_encode( [
	'simple_id'     => $p1_id,
	'simple_url'    => get_permalink( $p1_id ),
	'variable_id'   => $p2_id,
	'variation_id'  => $v1_id,
	'user_id'       => $uid,
	'group_id'      => $gid,
	'cart_url'      => wc_get_cart_url(),
	'checkout_url'  => wc_get_checkout_url(),
] );
