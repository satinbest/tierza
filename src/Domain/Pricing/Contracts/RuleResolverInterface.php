<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing\Contracts;

use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;

/**
 * Domain contract for the deterministic Rule Resolver (TRZ-006).
 *
 * Precedence model:
 * 1. Customer-specific variation rule
 * 2. Customer-specific product rule
 * 3. Customer-specific category rule
 * 4. Customer-specific global rule
 * 5. Group-specific variation rule
 * 6. Group-specific product rule
 * 7. Group-specific category rule
 * 8. Group-specific global rule
 * 9. Original WooCommerce price fallback
 */
interface RuleResolverInterface {

	/**
	 * Resolves the single highest-precedence active rule matching the given context.
	 *
	 * Rules do not stack in v1.
	 * Explicit 0% rules are resolved with precedence over lower levels.
	 *
	 * @param PricingContext $context
	 * @return ResolutionResult
	 * @throws RuleResolutionException If an inconsistent or unrecoverable resolution state occurs.
	 */
	public function resolve( PricingContext $context ): ResolutionResult;
}
