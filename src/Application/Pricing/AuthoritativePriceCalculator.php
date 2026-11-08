<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing;

use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Contracts\PriceCalculatorInterface;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\Money;

/**
 * Authoritative price calculation service (TRZ-007).
 *
 * Implements deterministic monetary arithmetic on trusted pricing contexts and
 * already resolved rules, strictly separating calculation from rule resolution.
 *
 * Guarantees:
 * - Pure arbitrary-precision string arithmetic via DecimalMath.
 * - Zero float operations in authoritative calculations.
 * - Half-up rounding exclusively at the documented currency boundary.
 * - Fail-closed error handling (errors never silently become fallback prices).
 * - Immutability of domain entities and WooCommerce product data.
 */
final class AuthoritativePriceCalculator implements PriceCalculatorInterface {

	public const DEFAULT_CURRENCY_DECIMALS = 2;
	public const MAX_CURRENCY_DECIMALS     = 8;

	private readonly int $default_currency_decimals;
	private readonly ?string $math_driver;

	/**
	 * @param int         $default_currency_decimals Default currency decimal precision (0 to 8).
	 * @param string|null $math_driver               Optional explicit driver ('bcmath'|'php'|null).
	 */
	public function __construct(
		int $default_currency_decimals = self::DEFAULT_CURRENCY_DECIMALS,
		?string $math_driver = null
	) {
		if ( $default_currency_decimals < 0 || $default_currency_decimals > self::MAX_CURRENCY_DECIMALS ) {
			throw new InvalidPricingContextException(
				sprintf( 'Default currency decimals must be between 0 and %d, got %d.', self::MAX_CURRENCY_DECIMALS, $default_currency_decimals )
			);
		}

		$this->default_currency_decimals = $default_currency_decimals;
		$this->math_driver               = $math_driver;
	}

	/**
	 * Calculates the final authoritative price from the pricing context and resolution outcome.
	 *
	 * @param PricingContext   $context
	 * @param ResolutionResult $resolution
	 * @return PricingResult
	 * @throws CurrencyMismatchException
	 * @throws InvalidMoneyException
	 * @throws InvalidRuleException
	 * @throws PricingCalculationException
	 * @throws RuleResolutionException
	 */
	public function calculate( PricingContext $context, ResolutionResult $resolution ): PricingResult {
		$currency          = $context->currency();
		$base_price        = $context->base_price();
		$currency_decimals = $context->currency_decimals();

		if ( $currency_decimals < 0 || $currency_decimals > self::MAX_CURRENCY_DECIMALS ) {
			$currency_decimals = $this->default_currency_decimals;
		}

		// Validate base price is non-negative. Deliberately zero-priced products are valid.
		if ( DecimalMath::comp( $base_price->amount(), '0.000000', 6, $this->math_driver ) < 0 ) {
			throw new InvalidMoneyException(
				sprintf( 'Base price cannot be negative, got "%s".', $base_price->amount() )
			);
		}

		// Handle Fallback outcome: return active WooCommerce base price without alteration.
		if ( $resolution->is_fallback() || ! $resolution->has_rule() ) {
			if ( PrecedenceLevel::WOOCOMMERCE_FALLBACK !== $resolution->precedence_level() ) {
				throw new RuleResolutionException( 'Fallback resolution must have WOOCOMMERCE_FALLBACK precedence.' );
			}
			if ( PricingSource::WOOCOMMERCE_FALLBACK !== $resolution->source() ) {
				throw new RuleResolutionException( 'Fallback resolution must have WOOCOMMERCE_FALLBACK pricing source.' );
			}

			$diagnostics = array_merge(
				$resolution->diagnostic_metadata(),
				[
					'calculation_type'      => 'fallback_no_rule',
					'currency_decimals'     => $currency_decimals,
					'internal_precision'    => Money::DEFAULT_SCALE,
					'raw_calculated_amount' => $base_price->amount(),
					'precedence_level'      => PrecedenceLevel::WOOCOMMERCE_FALLBACK->value,
					'precedence_name'       => PrecedenceLevel::WOOCOMMERCE_FALLBACK->name,
					'base_price_type'       => $context->is_sale_active() ? 'sale_price' : 'regular_price',
					'math_driver'           => DecimalMath::resolve_driver( $this->math_driver ),
				]
			);

			return new PricingResult(
				original_price:      $base_price,
				final_price:         $base_price,
				applied_rule_id:     null,
				pricing_type:        null,
				scope_type:          null,
				source:              PricingSource::WOOCOMMERCE_FALLBACK,
				is_rule_applied:     false,
				is_zero_percentage:  false,
				reason_code:         $resolution->reason_code(),
				diagnostic_metadata: $diagnostics
			);
		}

		// Matched rule path: validate input integrity and rule invariants.
		$rule = $resolution->rule();
		if ( null === $rule ) {
			throw new RuleResolutionException( 'Matched resolution result must contain a non-null rule.' );
		}

		if ( ! $rule->is_active() ) {
			throw new RuleResolutionException(
				sprintf( 'Cannot calculate price with an inactive rule (rule ID %s has status "%s").', (string) $rule->id(), $rule->status()->value() )
			);
		}

		// Verify precedence and source consistency.
		if ( $resolution->precedence_level()->is_customer() ) {
			if ( PricingSource::CUSTOMER !== $resolution->source() ) {
				throw new RuleResolutionException( 'Customer precedence level must have CUSTOMER pricing source.' );
			}
			if ( ! $rule->target()->is_user() ) {
				throw new RuleResolutionException( 'Customer precedence level requires a user/customer-targeted rule.' );
			}
		} elseif ( $resolution->precedence_level()->is_group() ) {
			if ( PricingSource::GROUP !== $resolution->source() ) {
				throw new RuleResolutionException( 'Group precedence level must have GROUP pricing source.' );
			}
			if ( ! $rule->target()->is_group() ) {
				throw new RuleResolutionException( 'Group precedence level requires a group-targeted rule.' );
			}
		} else {
			throw new RuleResolutionException( 'Matched rule cannot have fallback precedence.' );
		}

		// Enforce target boundaries: guest users must never receive individual customer pricing.
		if ( $rule->target()->is_user() ) {
			if ( $context->is_anonymous() ) {
				throw new PricingCalculationException( 'Guest users cannot receive customer-specific pricing.' );
			}
			if ( $rule->target()->id() !== $context->customer_id() ) {
				throw new PricingCalculationException(
					sprintf( 'Customer pricing rule target ID (%d) does not match context customer ID (%d).', $rule->target()->id(), $context->customer_id() )
				);
			}
		} elseif ( $rule->target()->is_group() ) {
			if ( ! $context->has_group() ) {
				throw new PricingCalculationException( 'Group pricing rule cannot be applied to context without an assigned group.' );
			}
			if ( $rule->target()->id() !== $context->group_id() ) {
				throw new PricingCalculationException(
					sprintf( 'Group pricing rule target ID (%d) does not match context group ID (%d).', $rule->target()->id(), (int) $context->group_id() )
				);
			}
		}

		// Enforce scope boundaries.
		if ( $rule->scope()->is_variation() ) {
			if ( ! $context->is_variation() || $rule->scope()->id() !== $context->variation_id() ) {
				throw new PricingCalculationException(
					sprintf( 'Variation-scoped rule (ID %d) does not match context variation ID (%s).', $rule->scope()->id(), (string) $context->variation_id() )
				);
			}
		} elseif ( $rule->scope()->is_product() ) {
			if ( $context->product_id() !== $rule->scope()->id() ) {
				throw new PricingCalculationException(
					sprintf( 'Product-scoped rule (ID %d) does not match context product ID (%d).', $rule->scope()->id(), $context->product_id() )
				);
			}
		} elseif ( $rule->scope()->is_category() ) {
			if ( ! in_array( $rule->scope()->id(), $context->category_ids(), true ) ) {
				throw new PricingCalculationException(
					sprintf( 'Category-scoped rule (ID %d) is not present in context product category IDs.', $rule->scope()->id() )
				);
			}
		} elseif ( ! $rule->scope()->is_global() ) {
			throw new PricingCalculationException(
				sprintf( 'Unknown or unsupported rule scope "%s".', $rule->scope()->type() )
			);
		}

		// Enforce v1 business rule: fixed-price rules are only valid at product or variation scope.
		$pricing_val = $rule->pricing_value();
		if ( $pricing_val->is_fixed() && ! $rule->scope()->is_product() && ! $rule->scope()->is_variation() ) {
			throw new InvalidRuleException(
				sprintf( 'Fixed-price rules are restricted to product or variation scopes in v1, got scope "%s".', $rule->scope()->type() )
			);
		}

		// Enforce value non-negativity.
		$raw_val = $pricing_val->value();
		if ( DecimalMath::comp( $raw_val, '0.000000', 6, $this->math_driver ) < 0 ) {
			throw new InvalidRuleException(
				sprintf( 'Pricing value cannot be negative, got "%s".', $raw_val )
			);
		}

		// Execute authoritative arithmetic.
		$is_zero_percentage = false;
		$calculation_type   = '';
		$raw_calculated     = '';

		if ( $pricing_val->is_percentage() ) {
			// Reject percentage values outside 0..100 (do not clamp).
			if ( DecimalMath::comp( $raw_val, '100.000000', 6, $this->math_driver ) > 0 ) {
				throw new InvalidRuleException(
					sprintf( 'Discount percentage cannot exceed 100%%, got "%s".', $raw_val )
				);
			}

			if ( DecimalMath::comp( $raw_val, '0.000000', 6, $this->math_driver ) === 0 ) {
				// Explicit 0% discount: valid and applied, produces base price.
				$is_zero_percentage = true;
				$calculation_type   = 'percentage_zero';
				$raw_calculated     = $base_price->amount();
				$rounded            = DecimalMath::round( $raw_calculated, $currency_decimals, PHP_ROUND_HALF_UP, $this->math_driver );
				$final_price        = new Money( $rounded, $currency );
			} elseif ( DecimalMath::comp( $raw_val, '100.000000', 6, $this->math_driver ) === 0 ) {
				// 100% discount produces exact zero.
				$is_zero_percentage = false;
				$calculation_type   = 'percentage_hundred';
				$raw_calculated     = '0.000000';
				$final_price        = Money::zero( $currency );
			} else {
				// Standard percentage calculation: active_base_price × (100 - discount_percent) / 100.
				$is_zero_percentage = false;
				$calculation_type   = 'percentage_standard';

				$remaining_percent = DecimalMath::sub( '100.000000', $raw_val, 6, $this->math_driver );
				$product           = DecimalMath::mul( $base_price->amount(), $remaining_percent, 8, $this->math_driver );
				$raw_calculated    = DecimalMath::div( $product, '100.000000', 8, $this->math_driver );

				// Guarantee nonnegative price.
				if ( DecimalMath::comp( $raw_calculated, '0.000000', 8, $this->math_driver ) < 0 ) {
					$raw_calculated = '0.000000';
				}

				$rounded     = DecimalMath::round( $raw_calculated, $currency_decimals, PHP_ROUND_HALF_UP, $this->math_driver );
				$final_price = new Money( $rounded, $currency );
			}
		} elseif ( $pricing_val->is_fixed() ) {
			$raw_calculated = $raw_val;

			if ( DecimalMath::comp( $raw_val, '0.000000', 6, $this->math_driver ) === 0 ) {
				// Fixed zero price is valid.
				$calculation_type = 'fixed_zero';
				$final_price      = Money::zero( $currency );
			} else {
				$rounded     = DecimalMath::round( $raw_calculated, $currency_decimals, PHP_ROUND_HALF_UP, $this->math_driver );
				$final_price = new Money( $rounded, $currency );

				$comparison = DecimalMath::comp( $final_price->amount(), $base_price->amount(), 6, $this->math_driver );
				if ( $comparison > 0 ) {
					$calculation_type = 'fixed_surcharge';
				} elseif ( $comparison < 0 ) {
					$calculation_type = 'fixed_discount';
				} else {
					$calculation_type = 'fixed_equal';
				}
			}
		} else {
			throw new InvalidRuleException(
				sprintf( 'Unsupported pricing type "%s".', $pricing_val->type() )
			);
		}

		$diagnostics = array_merge(
			$resolution->diagnostic_metadata(),
			[
				'calculation_type'      => $calculation_type,
				'currency_decimals'     => $currency_decimals,
				'internal_precision'    => Money::DEFAULT_SCALE,
				'raw_calculated_amount' => $raw_calculated,
				'precedence_level'      => $resolution->precedence_level()->value,
				'precedence_name'       => $resolution->precedence_level()->name,
				'base_price_type'       => $context->is_sale_active() ? 'sale_price' : 'regular_price',
				'math_driver'           => DecimalMath::resolve_driver( $this->math_driver ),
			]
		);

		return new PricingResult(
			original_price:      $base_price,
			final_price:         $final_price,
			applied_rule_id:     $rule->id(),
			pricing_type:        $pricing_val->type(),
			scope_type:          $rule->scope()->type(),
			source:              $resolution->source(),
			is_rule_applied:     true,
			is_zero_percentage:  $is_zero_percentage,
			reason_code:         $resolution->reason_code(),
			diagnostic_metadata: $diagnostics
		);
	}
}
