# Authoritative Price Calculator Architecture (TRZ-007)

## 1. Overview

The `AuthoritativePriceCalculator` (`Tierza\Application\Pricing\AuthoritativePriceCalculator`) implements Tierza's authoritative price calculation service as defined by `PriceCalculatorInterface`.

The service receives a strongly-typed, immutable `PricingContext` and an already resolved `ResolutionResult`, then computes an immutable `PricingResult`.

### Core Architectural Separation

```
[ WooCommerce / Application Layer ]
                │
                ▼
      ┌──────────────────┐
      │  PricingContext  │
      └─────────┬────────┘
                │
                ├───────────────────────────────────────┐
                ▼                                       ▼
    ┌───────────────────────┐               ┌───────────────────────┐
    │ Deterministic Rule    │               │ Authoritative Price   │
    │ Resolver (TRZ-006)    │               │ Calculator (TRZ-007)  │
    └───────────┬───────────┘               └───────────▲───────────┘
                │ ResolutionResult                      │
                └───────────────────────────────────────┘
                                                        │
                                                        ▼
                                            ┌───────────────────────┐
                                            │     PricingResult     │
                                            └───────────────────────┘
```

1. **Resolution Phase (TRZ-006):** Evaluates user, group, and category rules against the 9-level precedence hierarchy and selects the single winning rule (or explicit fallback).
2. **Calculation Phase (TRZ-007):** Performs deterministic arbitrary-precision arithmetic without database access, WooCommerce global state, or price filtering hooks.

---

## 2. Calculation Formulas

### A. Percentage Discount

For rules with `pricing_type = 'percentage'` and value `P` (where `0 <= P <= 100`):

$$\text{remaining\_percent} = 100 - P$$

$$\text{raw\_final\_price} = \frac{\text{active\_base\_price} \times \text{remaining\_percent}}{100}$$

$$\text{final\_price} = \text{round\_half\_up}(\text{raw\_final\_price}, \text{currency\_decimals})$$

#### Special Percentage Cases:
* **0% Discount:** Explicitly applied (`is_zero_percentage = true`, `is_rule_applied = true`, `calculation_type = 'percentage_zero'`). Returns base price.
* **100% Discount:** Explicitly produces exact zero (`Money::zero($currency)`, `is_rule_applied = true`, `calculation_type = 'percentage_hundred'`).
* **Percentages Outside 0..100:** Strictly rejected with `InvalidRuleException`. Values are never clamped.
* **No Rule Stacking:** Exactly one rule is evaluated; compounding or stacking multiple percentage rules is strictly forbidden.

### B. Fixed Price

For rules with `pricing_type = 'fixed'` and value `F` (where `F >= 0`):

$$\text{final\_price} = \text{round\_half\_up}(F, \text{currency\_decimals})$$

#### Requirements & Invariants:
* **Fixed Zero ($0.00):** Valid and supported (`Money::zero($currency)`).
* **Scope Restriction:** Fixed-price rules are only valid at `product` or `variation` scope. Category and global scopes are rejected with `InvalidRuleException`.
* **Surcharges / Higher Prices:** Fixed price may legally exceed the original base price. When `final_price > original_price`, `is_discounted()` returns `false`, `discount_amount()` returns zero, and diagnostic metadata records `calculation_type = 'fixed_surcharge'`.
* **Fixed Equal:** When `final_price == original_price`, `is_discounted()` is `false`, and metadata records `calculation_type = 'fixed_equal'`.
* **Fixed Discount:** When `final_price < original_price`, `is_discounted()` is `true`, and metadata records `calculation_type = 'fixed_discount'`.

### C. Fallback (No Matching Rule)

When the Resolver returns a fallback (`$resolution->is_fallback() == true`):

$$\text{final\_price} = \text{active\_base\_price}$$

* The active WooCommerce base price is returned **without alteration or re-rounding**.
* `is_rule_applied = false`.
* `applied_rule_id = null`.
* `pricing_type = null`.
* `scope_type = null`.
* `source = PricingSource::WOOCOMMERCE_FALLBACK`.
* `reason_code = $resolution->reason_code()`.

---

## 3. Money Precision Policy & Rounding Boundaries

```
[ Raw Base Price & Percentage ]
             │
             ▼
[ Intermediate Calculation ] ──> Scale: 8 decimal places (via DecimalMath)
             │
             ▼
[ Boundary Rounding ]       ──> Scale: currency_decimals (0-8, default 2)
                                  Mode:  PHP_ROUND_HALF_UP (WooCommerce standard)
             │
             ▼
[ Normalized Storage ]       ──> Scale: 6 decimal places (Money::DEFAULT_SCALE)
             │
             ▼
[ Display / API Output ]     ──> Formatted string with currency_decimals places
```

1. **Zero Binary Floating-Point:** All arithmetic operations (`add`, `sub`, `mul`, `div`, `round`, `comp`) execute via `DecimalMath` on decimal strings. Native binary floats (`float`/`double`) are never used in domain monetary calculations.
2. **Intermediate Precision (Scale 8):** Intermediate percentage divisions and products use scale 8 to prevent truncation drift.
3. **Output Rounding Boundary:** Half-up rounding (`PHP_ROUND_HALF_UP`) occurs **exclusively at the calculation output boundary**, respecting the store currency's configured decimal places.
4. **Normalized Monetary Object:** The rounded result is stored in `Money` normalized to canonical 6 decimal places (e.g. `"15.000000"`).
5. **Zero-Decimal Currencies:** Fully supports 0-decimal currencies (such as IRR, JPY, KRW, Toman) by configuring `currency_decimals = 0`.
6. **No Automatic Currency Conversions:** No implicit multiplying or dividing by 10 between IRR and Toman. Currencies are treated as distinct ISO codes.
7. **Large Monetary Values:** Safely handles arbitrary-length values (e.g. `999,999,999,999,999.99`) without native 64-bit integer overflow.
8. **BCMath & Pure-PHP Parity:** Guaranteed identical behavior whether `ext-bcmath` is active or the pure-PHP fallback is utilized.

---

## 4. Active Sale Price Behavior

WooCommerce products may have an active promotional sale price in addition to their regular price.

1. **Active Price as Calculation Base:** When a product has an active sale price, WooCommerce sets the active price (`$product->get_price()`) to that sale price. `PricingContext::base_price()` receives this active price.
2. **Percentage Applied to Active Price:** Percentage discounts are calculated against the active base price, NOT the regular price.
   * Example: Regular price = `$100.00`, Active sale price = `$80.00`.
   * A 20% Tierza rule calculates: `$80.00 × (100 - 20) / 100 = $64.00` (NOT `$80.00`).
3. **No Modification of WooCommerce Prices:** Tierza never mutates or overwrites WooCommerce's stored regular or sale price postmeta.
4. **Diagnostics Tracking:** Diagnostic metadata tracks `'base_price_type' => 'sale_price'` if `$context->is_sale_active()` is true, otherwise `'regular_price'`.

---

## 5. Input Integrity & Validation Rules

The calculator verifies that input preconditions and invariant guarantees hold:

| Invariant | Condition | Failure Outcome |
| :--- | :--- | :--- |
| **Missing Base Price** | `null === $base_price` | `MissingBasePriceException` |
| **Negative Base Price** | `$base_price < 0` | `InvalidMoneyException` |
| **Deliberately Zero Base Price** | `$base_price == 0` | Allowed (valid zero-priced item) |
| **Inactive Rule** | `$rule->is_active() == false` | `RuleResolutionException` |
| **Guest User Rule Violation** | `$context->is_anonymous()` & customer rule | `PricingCalculationException` |
| **Customer ID Mismatch** | `$rule->target()->id() !== $context->customer_id()` | `PricingCalculationException` |
| **Missing Group in Context** | Group rule & `! $context->has_group()` | `PricingCalculationException` |
| **Group ID Mismatch** | `$rule->target()->id() !== $context->group_id()` | `PricingCalculationException` |
| **Variation Scope Mismatch** | Variation rule & mismatched variation ID | `PricingCalculationException` |
| **Product Scope Mismatch** | Product rule & mismatched product ID | `PricingCalculationException` |
| **Category Scope Mismatch** | Category rule & category ID not in context | `PricingCalculationException` |
| **Fixed Scope Restriction** | Fixed rule with category or global scope | `InvalidRuleException` |
| **Percentage Bounds** | Percentage `< 0` or `> 100` | `InvalidRuleException` (no clamping) |
| **Currency Mismatch** | Currency discrepancy in context prices | `CurrencyMismatchException` |

**Fail-Closed Rule:** Calculation errors **never** silently degrade to fallback prices. Fallback is valid **only** when the Rule Resolver explicitly outputs a `WOOCOMMERCE_FALLBACK` resolution result.

---

## 6. PricingResult Contract & Metadata

The immutable `PricingResult` exposes:

```php
$result->original_price();          // Money (e.g. 100.000000 USD)
$result->final_price();             // Money (e.g. 80.000000 USD)
$result->currency();                // string (e.g. "USD")
$result->applied_rule_id();         // ?int (e.g. 101 or null)
$result->pricing_type();            // ?string ("percentage"|"fixed"|null)
$result->scope_type();              // ?string ("product"|"variation"|"category"|"global"|null)
$result->source();                  // PricingSource (CUSTOMER|GROUP|WOOCOMMERCE_FALLBACK)
$result->precedence_level();        // ?PrecedenceLevel (CUSTOMER_PRODUCT, etc.)
$result->is_rule_applied();         // bool (true if rule applied, false if fallback)
$result->is_zero_percentage();      // bool (true ONLY for explicit 0% discount)
$result->is_discounted();           // bool (true if final_price < original_price)
$result->discount_amount();         // Money (difference, or Money::zero())
$result->savings_percentage();      // string (e.g. "20.000000")
$result->reason_code();             // string (machine-readable reason code)
$result->formatted_final_price();   // string (e.g. "80.00")
$result->formatted_original_price();// string (e.g. "100.00")
$result->diagnostic_metadata();     // array<string, mixed>
```

### Diagnostic Metadata Fields

* `calculation_type`: `'fallback_no_rule'`, `'percentage_zero'`, `'percentage_hundred'`, `'percentage_standard'`, `'fixed_zero'`, `'fixed_discount'`, `'fixed_surcharge'`, `'fixed_equal'`.
* `currency_decimals`: Configured decimal scale (e.g. 2).
* `internal_precision`: `6` (`Money::DEFAULT_SCALE`).
* `raw_calculated_amount`: Unrounded decimal string before currency boundary rounding.
* `precedence_level`: Integer precedence level (1 to 9).
* `precedence_name`: Precedence enum name.
* `base_price_type`: `'regular_price'` or `'sale_price'`.
* `math_driver`: `'bcmath'` or `'php'`.

---

## 7. Test Verification

* **39 Unit Tests** in `tests/Unit/Application/Pricing/AuthoritativePriceCalculatorTest.php` (302 assertions).
* **331 Total Unit & Domain Tests** across the entire PHPUnit suite (1531 assertions).
* **38 Live MariaDB Integration Tests** in `tests/Live/DatabaseIntegrationLiveTest.php` (250 assertions) verifying end-to-end data flow:
  `Real MariaDB Rule Record -> Rule Resolver -> Price Calculator -> PricingResult`.
