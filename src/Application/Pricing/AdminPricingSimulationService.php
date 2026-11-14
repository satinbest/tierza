<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing;

use Tierza\Domain\Contracts\PriceCalculatorInterface;
use Tierza\Domain\Contracts\RuleResolverInterface;
use Tierza\Domain\Entities\PricingResult;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Integration\WooCommerce\ProductPricingAdapter;
use WC_Product;

/**
 * Clearly authorized service for administrative customer pricing simulation.
 *
 * Strictly separated from storefront product price retrieval. Requires explicit
 * administrative capability checks ('manage_woocommerce') to prevent unauthorized
 * identity impersonation in production execution.
 */
class AdminPricingSimulationService {

	public function __construct(
		private ProductPricingAdapter $adapter
	) {}

	/**
	 * Simulate product price for a specific customer with strict authorization check.
	 *
	 * @param WC_Product $product     WooCommerce product instance.
	 * @param int        $customer_id Simulated customer user ID.
	 * @return string Calculated simulated price.
	 * @throws PricingCalculationException If user is unauthorized to simulate pricing.
	 */
	public function simulate_customer_price( WC_Product $product, int $customer_id ): string {
		$this->assert_authorized();

		$this->adapter->enable_test_mode( true );
		$this->adapter->set_test_customer_id( $customer_id );

		try {
			return $this->adapter->get_adjusted_price( $product, $customer_id );
		} finally {
			$this->adapter->set_test_customer_id( null );
			$this->adapter->enable_test_mode( false );
		}
	}

	/**
	 * Verify that current executing user holds administrative pricing simulation privileges.
	 *
	 * @throws PricingCalculationException If unauthorized.
	 */
	private function assert_authorized(): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			throw new PricingCalculationException( 'Unauthorized customer pricing simulation attempt.' );
		}
	}
}
