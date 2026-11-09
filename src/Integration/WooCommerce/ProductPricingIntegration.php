<?php

declare(strict_types=1);

namespace Tierza\Integration\WooCommerce;

use Throwable;
use WC_Product;
use WP_Post;
use WP_Query;

/**
 * WooCommerce hook integration coordinator for Tierza B2B product pricing.
 *
 * Enforces:
 * 1. Strict reentrancy guards to prevent infinite price filter recursion.
 * 2. Scope boundaries protecting admin product editing, imports, and background syncs.
 * 3. Cache isolation for variable products via variation prices hash.
 * 4. Automatic loop batch prefetching for high-performance archive listings.
 * 5. Production error handling preventing price leaks or $0 crashes.
 */
class ProductPricingIntegration {

	/**
	 * Active product IDs currently undergoing price resolution (reentrancy guard).
	 *
	 * @var array<int, bool>
	 */
	private array $active_resolutions = [];

	/**
	 * Whether pricing should be applied during CLI execution (e.g. for testing).
	 */
	private bool $allow_cli_pricing = true;

	/**
	 * Constructor.
	 *
	 * @param ProductPricingAdapter $adapter Pricing adapter service.
	 */
	public function __construct(
		private readonly ProductPricingAdapter $adapter
	) {}

	/**
	 * Register all WooCommerce pricing hooks.
	 */
	public function register(): void {
		// Single & variable product price filters.
		add_filter( 'woocommerce_product_get_price', [ $this, 'filter_product_price' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', [ $this, 'filter_product_price' ], 10, 2 );

		// Variation prices array calculation inside variable product data stores.
		add_filter( 'woocommerce_variation_prices_price', [ $this, 'filter_variation_prices_price' ], 10, 3 );

		// Variable product transient cache key hash isolation.
		add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'filter_variation_prices_hash' ], 10, 3 );

		// Product price HTML formatting.
		add_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html' ], 10, 2 );

		// High-performance batch prefetch hooks for archive/catalog loops.
		add_action( 'woocommerce_product_loop_start', [ $this, 'on_product_loop_start' ], 10, 0 );
		add_filter( 'the_posts', [ $this, 'on_the_posts' ], 10, 2 );
	}

	/**
	 * Unregister all WooCommerce pricing hooks.
	 */
	public function unregister(): void {
		remove_filter( 'woocommerce_product_get_price', [ $this, 'filter_product_price' ], 10 );
		remove_filter( 'woocommerce_product_variation_get_price', [ $this, 'filter_product_price' ], 10 );
		remove_filter( 'woocommerce_variation_prices_price', [ $this, 'filter_variation_prices_price' ], 10 );
		remove_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'filter_variation_prices_hash' ], 10 );
		remove_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html' ], 10 );
		remove_action( 'woocommerce_product_loop_start', [ $this, 'on_product_loop_start' ], 10 );
		remove_filter( 'the_posts', [ $this, 'on_the_posts' ], 10 );
	}

	/**
	 * Filter product active price.
	 *
	 * Hooked to 'woocommerce_product_get_price' and 'woocommerce_product_variation_get_price'.
	 *
	 * @param mixed $price   Incoming product price.
	 * @param mixed $product WC_Product instance.
	 * @return string Adjusted price string.
	 */
	public function filter_product_price( mixed $price, mixed $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return (string) $price;
		}

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return (string) $price;
		}

		// Reentrancy check: prevent infinite filter recursion if get_price() is called internally.
		if ( isset( $this->active_resolutions[ $product_id ] ) ) {
			return (string) $price;
		}

		// Scope check: do not modify prices during admin editing, imports, or admin REST operations.
		if ( ! $this->should_apply_pricing( $product ) ) {
			return (string) $price;
		}

		$this->active_resolutions[ $product_id ] = true;

		try {
			return $this->adapter->get_adjusted_price( $product );
		} catch ( Throwable $e ) {
			$this->log_error( 'Failed to calculate Tierza price for product #' . $product_id, $e );
			return (string) $price;
		} finally {
			unset( $this->active_resolutions[ $product_id ] );
		}
	}

	/**
	 * Filter individual variation price when variable product generates variation prices array.
	 *
	 * Hooked to 'woocommerce_variation_prices_price'.
	 *
	 * @param mixed $price            Incoming variation price.
	 * @param mixed $variation        WC_Product_Variation instance.
	 * @param mixed $variable_product Parent WC_Product_Variable instance.
	 * @return string Adjusted variation price.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function filter_variation_prices_price( mixed $price, mixed $variation, mixed $variable_product ): string {
		if ( ! $variation instanceof WC_Product ) {
			return (string) $price;
		}
		return $this->filter_product_price( $price, $variation );
	}

	/**
	 * Augment variation prices cache hash with customer and group identity.
	 *
	 * Hooked to 'woocommerce_get_variation_prices_hash'.
	 * Ensures WooCommerce's transient cache ('wc_var_prices_{$id}') never serves
	 * Customer A's personalized price range to Customer B or guest users.
	 *
	 * @param array<mixed> $price_hash  Original WooCommerce price hash data.
	 * @param mixed        $product     Variable product instance.
	 * @param bool         $for_display Whether prices are formatted for display.
	 * @return array<mixed> Augmented hash data.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function filter_variation_prices_hash( array $price_hash, mixed $product, bool $for_display = false ): array {
		$identity_data = $this->adapter->get_variation_prices_hash_data();

		$price_hash['tierza'] = $identity_data;

		return $price_hash;
	}

	/**
	 * Filter product price HTML.
	 *
	 * Hooked to 'woocommerce_get_price_html'.
	 *
	 * @param mixed $price_html Original price HTML.
	 * @param mixed $product    WC_Product instance.
	 * @return string Formatted price HTML.
	 */
	public function filter_price_html( mixed $price_html, mixed $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return (string) $price_html;
		}

		if ( ! $this->should_apply_pricing( $product ) ) {
			return (string) $price_html;
		}

		try {
			return $this->adapter->get_price_html( (string) $price_html, $product );
		} catch ( Throwable $e ) {
			$this->log_error( 'Failed to format price HTML for product #' . $product->get_id(), $e );
			return (string) $price_html;
		}
	}

	/**
	 * Automatically prefetch pricing rules before entering WooCommerce product loop.
	 *
	 * Hooked to 'woocommerce_product_loop_start'.
	 */
	public function on_product_loop_start(): void {
		global $wp_query;

		if ( ! isset( $wp_query ) || ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
			return;
		}

		$products = [];
		foreach ( $wp_query->posts as $post ) {
			if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
				if ( $product instanceof WC_Product ) {
					$products[] = $product;
				}
			}
		}

		if ( ! empty( $products ) ) {
			$this->adapter->prefetch_products( $products );
		}
	}

	/**
	 * Intercept queried posts to prefetch candidate rules early.
	 *
	 * Hooked to 'the_posts'.
	 *
	 * @param mixed $posts Query posts.
	 * @param mixed $query WP_Query instance.
	 * @return mixed Untouched posts.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function on_the_posts( mixed $posts, mixed $query ): mixed {
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}

		// Only prefetch on frontend queries or storefront AJAX.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $posts;
		}

		$products = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && ( 'product' === $post->post_type || 'product_variation' === $post->post_type ) ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
				if ( $product instanceof WC_Product ) {
					$products[] = $product;
				}
			}
		}

		if ( ! empty( $products ) ) {
			$this->adapter->prefetch_products( $products );
		}

		return $posts;
	}

	/**
	 * Verify if pricing should be applied in current execution context.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return bool True if pricing should be applied.
	 */
	public function should_apply_pricing( WC_Product $product ): bool {
		// 1. Prevent recursion.
		if ( isset( $this->active_resolutions[ (int) $product->get_id() ] ) ) {
			return false;
		}

		// 2. Prevent interference during WooCommerce product import or export.
		if ( defined( 'WC_DOING_PRODUCT_IMPORT' ) && WC_DOING_PRODUCT_IMPORT ) {
			return false;
		}

		// 3. CLI execution boundaries.
		if ( defined( 'WP_CLI' ) && WP_CLI && ! $this->allow_cli_pricing ) {
			return false;
		}

		// 4. WordPress Admin screen boundaries.
		if ( is_admin() ) {
			// Frontend AJAX requests require pricing (e.g. variation selections, cart fragments).
			if ( wp_doing_ajax() ) {
				// Admin AJAX saving actions must not filter prices.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$raw_action = $_REQUEST['action'] ?? '';
				if ( is_string( $raw_action ) && '' !== $raw_action ) {
					$admin_actions = [
						'woocommerce_save_variations',
						'woocommerce_add_variation',
						'inline-save',
						'editpost',
					];
					$unslashed     = wp_unslash( $raw_action );
					if ( is_string( $unslashed ) ) {
						$action = sanitize_text_field( $unslashed );
						if ( in_array( $action, $admin_actions, true ) ) {
							return false;
						}
					}
				}
				return true;
			}

			// Admin editing screens (post.php, post-new.php, edit.php for product post type) must preserve base prices.
			global $pagenow;
			if ( in_array( $pagenow, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
				return false;
			}

			return false;
		}

		// 5. REST API context boundaries.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// If request context is 'edit' and caller is an admin, preserve database prices.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.Capabilities.Unknown
			if ( isset( $_GET['context'] ) && 'edit' === $_GET['context'] && current_user_can( 'manage_woocommerce' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Configure whether CLI execution should apply pricing (for testing/integration).
	 *
	 * @param bool $allow Whether to allow CLI pricing.
	 */
	public function set_allow_cli_pricing( bool $allow ): void {
		$this->allow_cli_pricing = $allow;
	}

	/**
	 * Safely log error without leaking sensitive SQL or internals.
	 *
	 * @param string    $message Safe log message.
	 * @param Throwable $e       Caught exception.
	 */
	private function log_error( string $message, Throwable $e ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( method_exists( $logger, 'error' ) ) {
				$logger->error(
					sprintf( '%s: %s', $message, $e->getMessage() ),
					[ 'source' => 'tierza-pricing' ]
				);
			}
		}
	}
}
