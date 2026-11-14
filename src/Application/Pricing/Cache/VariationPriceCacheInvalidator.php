<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing\Cache;

use Tierza\Domain\Entities\PricingRule;
use wpdb;

/**
 * Cross-request invalidation and versioning manager for WooCommerce variation price caches.
 *
 * WooCommerce caches variation price ranges in transients ('wc_var_prices_{$product_id}').
 * This service ensures that when Tierza pricing rules, groups, or customer assignments change:
 * 1. An atomic version counter is incremented, altering the hash in 'woocommerce_get_variation_prices_hash'.
 * 2. Associated WooCommerce transients are actively purged to avoid unbounded database bloat.
 * 3. Works seamlessly with both standard MySQL transients and persistent object caches (Redis/Memcached).
 */
class VariationPriceCacheInvalidator {

	public const OPTION_NAME = 'tierza_var_price_version';

	/**
	 * Runtime in-memory cached version for the current request.
	 */
	private ?int $cached_version = null;

	private wpdb $db;

	public function __construct( ?wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $GLOBALS['wpdb'];
	}

	/**
	 * Retrieve current pricing version counter.
	 *
	 * Cached in-memory per request to avoid redundant get_option() database lookups.
	 *
	 * @return int Positive integer version.
	 */
	public function get_version(): int {
		if ( null !== $this->cached_version ) {
			return $this->cached_version;
		}

		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION_NAME, 1 );
			$val = is_numeric( $opt ) ? (int) $opt : 1;
		} else {
			$val = 1;
		}

		$this->cached_version = max( 1, $val );
		return $this->cached_version;
	}

	/**
	 * Increment pricing version counter and flush runtime cache.
	 *
	 * @return int New incremented version.
	 */
	public function increment_version(): int {
		$current = $this->get_version();
		$new_ver = $current + 1;

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_NAME, $new_ver, false );
		}

		$this->cached_version = $new_ver;
		return $new_ver;
	}

	/**
	 * Invalidate variation price caches affected by a rule mutation.
	 *
	 * @param PricingRule $rule Mutated rule entity.
	 */
	public function invalidate_for_rule( PricingRule $rule ): void {
		$this->increment_version();

		$scope = $rule->scope();
		if ( $scope->is_product() ) {
			$this->clean_product_transient( $scope->id() );
		} elseif ( $scope->is_variation() ) {
			$var_id    = $scope->id();
			$parent_id = function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $var_id ) : 0;
			if ( $parent_id > 0 ) {
				$this->clean_product_transient( $parent_id );
			}
		} else {
			// Global or Category rule affects multiple products: clean all variation price transients.
			$this->clean_all_variation_transients();
		}
	}

	/**
	 * Invalidate variation price caches affected by group changes.
	 *
	 * @param int $group_id Group ID.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function invalidate_for_group( int $group_id ): void {
		$this->increment_version();
		$this->clean_all_variation_transients();
	}

	/**
	 * Invalidate variation price caches affected by customer group reassignment.
	 *
	 * @param int $customer_id Customer user ID.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function invalidate_for_customer( int $customer_id ): void {
		$this->increment_version();
		$this->clean_all_variation_transients();
	}

	/**
	 * Clean WooCommerce variation prices transient for a specific product.
	 *
	 * @param int $product_id Product ID.
	 */
	public function clean_product_transient( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'wc_var_prices_' . $product_id );
		}

		// Also clean native WooCommerce data store price cache if available.
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( null !== $product && $product->is_type( 'variable' ) ) {
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product_id );
				}
			}
		}
	}

	/**
	 * Delete all WooCommerce variation price transients in database.
	 *
	 * Prevents unbounded persistent cache buildup after rule or group updates.
	 */
	public function clean_all_variation_transients(): void {
		if ( isset( $this->db->options ) && ! empty( $this->db->options ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->query(
				"DELETE FROM {$this->db->options} WHERE option_name LIKE '_transient_wc_var_prices_%' OR option_name LIKE '_transient_timeout_wc_var_prices_%'"
			);
		}
	}

	/**
	 * Reset in-memory cached version.
	 */
	public function reset_memory(): void {
		$this->cached_version = null;
	}
}
