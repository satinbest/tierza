<?php
/**
 * Teardown fixture for Playwright Cart Pricing E2E tests (TRZ-011).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Delete test products
$product_ids = get_posts( [
	'post_type'      => [ 'product', 'product_variation' ],
	'title'          => [ 'Tierza E2E Cart Simple Product', 'Tierza E2E Cart Variable Product' ],
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

foreach ( $product_ids as $pid ) {
	$p = wc_get_product( $pid );
	if ( $p ) {
		$p->delete( true );
	}
}

// Delete test user
$user = get_user_by( 'login', 'e2e_cart_b2b_user' );
if ( $user ) {
	$wpdb->delete( $wpdb->prefix . 'tierza_user_groups', [ 'user_id' => $user->ID ] );
	wp_delete_user( $user->ID );
}

// Clean test groups & rules
$wpdb->query( "DELETE FROM {$wpdb->prefix}tierza_rules WHERE active_key LIKE 'group:%'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}tierza_groups WHERE slug LIKE 'e2e-cart-b2b-group%'" );

// Restore original cart & checkout page content
$cart_pid = wc_get_page_id( 'cart' );
if ( $cart_pid > 0 ) {
	$orig_cart = get_post_meta( $cart_pid, '_tierza_e2e_orig_content', true );
	if ( $orig_cart ) {
		wp_update_post( [
			'ID'           => $cart_pid,
			'post_content' => $orig_cart,
		] );
		delete_post_meta( $cart_pid, '_tierza_e2e_orig_content' );
	}
}

$checkout_pid = wc_get_page_id( 'checkout' );
if ( $checkout_pid > 0 ) {
	$orig_checkout = get_post_meta( $checkout_pid, '_tierza_e2e_orig_content', true );
	if ( $orig_checkout ) {
		wp_update_post( [
			'ID'           => $checkout_pid,
			'post_content' => $orig_checkout,
		] );
		delete_post_meta( $checkout_pid, '_tierza_e2e_orig_content' );
	}
}

echo 'TEARDOWN_OK';
