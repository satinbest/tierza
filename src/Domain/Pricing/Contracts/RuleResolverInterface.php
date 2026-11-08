<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing\Contracts;

use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;

/**
 * Domain contract for the deterministic Rule Resolver (TRZ-006).
 *
 * Precedence model (PRD Policy):
 * 1. Customer-specific rules override group rules.
 * 2. Variation overrides product.
 * 3. Product overrides category.
 * 4. Category overrides global.
 * 5. Within category rules, deeper category hierarchy wins.
 * 6. For equal-depth category matches, the rule producing the lowest final price wins (highest discount percentage in v1).
 * 7. If the final prices are equal, the smaller category ID wins (category_id ASC).
 *
 * Level Hierarchy (1 to 9):
 * 1. Customer-specific variation rule
 * 2. Customer-specific product rule
 * 3. Customer-specific category rule (evaluated via steps 5-7)
 * 4. Customer-specific global rule
 * 5. Group-specific variation rule
 * 6. Group-specific product rule
 * 7. Group-specific category rule (evaluated via steps 5-7)
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
