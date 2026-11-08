<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing\Contracts;

use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;

/**
 * Domain contract for authoritative Price Calculation (TRZ-007).
 *
 * Supports:
 * - Percentage discounts applied to active base price.
 * - Fixed prices for product and variation scopes.
 * - Fallback to active WooCommerce base price when no rule applies.
 * - Clamping at zero to guarantee nonnegative prices.
 */
interface PriceCalculatorInterface {

	/**
	 * Calculates the final authoritative price from the pricing context and resolution outcome.
	 *
	 * @param PricingContext   $context
	 * @param ResolutionResult $resolution
	 * @return PricingResult
	 * @throws CurrencyMismatchException
	 * @throws InvalidMoneyException
	 */
	public function calculate( PricingContext $context, ResolutionResult $resolution ): PricingResult;
}
