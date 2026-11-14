<?php

// Ensure WordPress is loaded
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$prod = new WC_Product_Simple();
$prod->set_name( 'Tierza E2E Test Product' );
$prod->set_status( 'publish' );
$prod->set_regular_price( '100.00' );
$prod->set_price( '100.00' );
$prod->save();
$pid = $prod->get_id();

// Create user if not exists
$existing_user = get_user_by( 'login', 'e2e_b2b_user' );
if ( $existing_user ) {
	wp_delete_user( $existing_user->ID );
}
$uid = wp_create_user( 'e2e_b2b_user', 'password123', 'e2e_b2b@tierza.test' );

global $wpdb;
$table_groups      = $wpdb->prefix . 'tierza_groups';
$table_user_groups = $wpdb->prefix . 'tierza_user_groups';
$table_rules       = $wpdb->prefix . 'tierza_rules';
$now               = current_time( 'mysql', true );

$wpdb->insert(
	$table_groups,
	[
		'name'       => 'E2E B2B Group',
		'slug'       => 'e2e-b2b-group-' . time(),
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

$active_key = sprintf( 'group:%d|global:0', $gid );
$wpdb->insert(
	$table_rules,
	[
		'target_type'  => 'group',
		'target_id'    => $gid,
		'scope_type'   => 'global',
		'scope_id'     => 0,
		'pricing_type' => 'percentage',
		'value'        => '25.000000',
		'status'       => 'active',
		'active_key'   => $active_key,
		'created_at'   => $now,
		'updated_at'   => $now,
	]
);
$rid = (int) $wpdb->insert_id;

echo wp_json_encode( [
	'product_id'  => $pid,
	'product_url' => get_permalink( $pid ),
	'user_id'     => $uid,
	'group_id'    => $gid,
	'rule_id'     => $rid,
] );
