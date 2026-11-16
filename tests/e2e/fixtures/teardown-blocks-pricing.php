<?php
/**
 * Teardown fixture for Playwright WooCommerce Blocks & Store API Pricing E2E tests (TRZ-012).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// 1. Delete rules and user-group assignments
$table_groups      = $wpdb->prefix . 'tierza_groups';
$table_user_groups = $wpdb->prefix . 'tierza_user_groups';
$table_rules       = $wpdb->prefix . 'tierza_rules';

$wpdb->query( "DELETE FROM {$table_rules} WHERE active_key LIKE 'group:%'" );
$wpdb->query( "DELETE FROM {$table_user_groups}" );
$wpdb->query( "DELETE FROM {$table_groups} WHERE slug LIKE 'e2e-blocks-b2b-%'" );

// 2. Delete test user
$user = get_user_by( 'login', 'e2e_blocks_b2b_user' );
if ( $user ) {
	wp_delete_user( $user->ID );
}

// 3. Delete test products
$prods = wc_get_products( [
	'limit' => -1,
	's'     => 'Tierza Blocks E2E',
] );
foreach ( $prods as $p ) {
	$p->delete( true );
}

// 4. Delete test orders created during E2E
$orders = wc_get_orders( [
	'limit'          => -1,
	'billing_email'  => 'e2e_blocks_b2b@tierza.test',
] );
foreach ( $orders as $o ) {
	$o->delete( true );
}

// 5. Clear cart & pricing cache
if ( null !== WC()->cart ) {
	WC()->cart->empty_cart();
}
\Tierza\Plugin::instance()->get_pricing_cache()->clear();

echo wp_json_encode( [ 'status' => 'cleaned' ] );
