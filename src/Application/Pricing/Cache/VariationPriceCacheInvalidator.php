<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing\Cache;

use Tierza\Domain\Entities\PricingRule;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;
use wpdb;

/**
 * Cross-request invalidation and versioning manager for WooCommerce variation price caches.
 *
 * WooCommerce caches variation price ranges in transients ('wc_var_prices_{$product_id}').
 * This service ensures that when Tierza pricing rules, groups, or customer assignments change:
 * 1. An atomic, monotonic version counter in MariaDB/MySQL is incremented to prevent race conditions.
 * 2. Invalidation hooks through WC_Cache_Helper and delete_transient purge external object caches (Redis/Memcached).
 * 3. Database transients are actively purged to avoid unbounded database bloat.
 * 4. Stale cache versions in memory are reset, ensuring runtime consistency.
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
	 * Cached in-memory per request to avoid redundant database lookups.
	 *
	 * @return int Positive integer version.
	 * @throws DatabaseQueryException If database read fails.
	 */
	public function get_version(): int {
		if ( null !== $this->cached_version ) {
			return $this->cached_version;
		}

		if ( ! empty( $this->db->options ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$raw = $this->db->get_var(
				$this->db->prepare(
					"SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
					self::OPTION_NAME
				)
			);

			if ( ! empty( $this->db->last_error ) ) {
				throw new DatabaseQueryException(
					sprintf( 'Failed to read variation cache version from database: %s', $this->db->last_error )
				);
			}

			if ( null !== $raw && '' !== $raw && is_numeric( $raw ) ) {
				$this->cached_version = max( 1, (int) $raw );
				return $this->cached_version;
			}

			// Clean query finding 0 rows: fresh installation where version row does not exist yet.
			$this->cached_version = 1;
			return 1;
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
	 * Atomically increment pricing version counter using a monotonic database upsert.
	 *
	 * Concurrency-safe: Executes a single atomic INSERT ... ON DUPLICATE KEY UPDATE
	 * with row-level locks on UNIQUE KEY option_name, eliminating lost updates when multiple
	 * administrators modify pricing rules simultaneously.
	 *
	 * @return int Authoritative incremented version.
	 * @throws DatabaseQueryException If database write or verification fails.
	 */
	public function increment_version(): int {
		if ( ! empty( $this->db->options ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $this->db->prepare(
				"INSERT INTO {$this->db->options} (option_name, option_value, autoload)
				 VALUES (%s, '2', 'no')
				 ON DUPLICATE KEY UPDATE option_value = CAST(COALESCE(NULLIF(option_value, ''), '1') AS UNSIGNED) + 1",
				self::OPTION_NAME
			);

			$result = $this->db->query( $sql );
			if ( false === $result || ! empty( $this->db->last_error ) ) {
				$err_msg = ! empty( $this->db->last_error ) ? $this->db->last_error : 'Unknown database error';
				throw new DatabaseQueryException(
					sprintf( 'Failed to increment variation cache version: %s', $err_msg )
				);
			}

			// Read back newly updated authoritative version.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$new_ver_raw = $this->db->get_var(
				$this->db->prepare(
					"SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
					self::OPTION_NAME
				)
			);

			if ( null === $new_ver_raw || ! is_numeric( $new_ver_raw ) ) {
				throw new DatabaseQueryException( 'Failed to verify updated variation cache version from database.' );
			}

			$new_version = max( 1, (int) $new_ver_raw );
		} else {
			// Fallback for non-table environments or testing stubs.
			$new_version = $this->get_version() + 1;
			if ( function_exists( 'update_option' ) ) {
				$updated = update_option( self::OPTION_NAME, $new_version, false );
				if ( false === $updated && $this->get_version() !== $new_version ) {
					throw new DatabaseQueryException( 'Failed to update variation cache version option.' );
				}
			}
		}

		// Ensure WordPress object caches and options caches are cleared to maintain coherence.
		if ( function_exists( 'clean_option_cache' ) ) {
			clean_option_cache( self::OPTION_NAME );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		$this->cached_version = $new_version;
		return $new_version;
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
	 * Supports persistent object cache through delete_transient() and native WooCommerce data stores.
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
	 * Invalidate all WooCommerce variation price transients across database and persistent caches.
	 *
	 * 1. Signals WooCommerce core via WC_Cache_Helper::get_transient_version('product', true)
	 *    to invalidate product caches in persistent object stores (Redis/Memcached).
	 * 2. Purges database transients from wp_options to prevent disk storage buildup.
	 *
	 * @throws DatabaseQueryException If database transient purge query fails.
	 */
	public function clean_all_variation_transients(): void {
		// Invalidate WooCommerce persistent object cache group if available.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'product', true );
		}

		// Purge transients in database.
		if ( ! empty( $this->db->options ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $this->db->query(
				"DELETE FROM {$this->db->options} WHERE option_name LIKE '_transient_wc_var_prices_%' OR option_name LIKE '_transient_timeout_wc_var_prices_%'"
			);
			if ( false === $result || ! empty( $this->db->last_error ) ) {
				$err_msg = ! empty( $this->db->last_error ) ? $this->db->last_error : 'Unknown database error';
				throw new DatabaseQueryException(
					sprintf( 'Failed to clean variation transients from database: %s', $err_msg )
				);
			}
		}
	}

	/**
	 * Reset in-memory cached version.
	 */
	public function reset_memory(): void {
		$this->cached_version = null;
	}
}
