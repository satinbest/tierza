<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Delete test products
$product_ids = get_posts( [
	'post_type'      => 'product',
	'title'          => 'Tierza E2E Test Product',
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
$user = get_user_by( 'login', 'e2e_b2b_user' );
if ( $user ) {
	$wpdb->delete( $wpdb->prefix . 'tierza_user_groups', [ 'user_id' => $user->ID ] );
	wp_delete_user( $user->ID );
}

// Clean test groups
$wpdb->query( "DELETE FROM {$wpdb->prefix}tierza_rules WHERE target_type = 'group' AND active_key LIKE 'group:%'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}tierza_groups WHERE slug LIKE 'e2e-b2b-group%'" );

echo 'TEARDOWN_OK';
