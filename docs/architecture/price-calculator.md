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
[ Intermediate Multiplication ] ──> Scale: 12 decimal places (exact: 6 + 6 digits)
             │
             ▼
[ Intermediate Division by 100] ──> Scale: 14 decimal places (exact: 12 + 2 digits)
             │
             ▼
[ Boundary Rounding ]           ──> Scale: currency_decimals (0 to 6, default 2)
                                      Mode:  PHP_ROUND_HALF_UP (WooCommerce standard)
             │
             ▼
[ Normalized Storage ]           ──> Scale: 6 decimal places (Money::DEFAULT_SCALE / DECIMAL(20,6))
             │
             ▼
[ Display / API Output ]         ──> Formatted string with currency_decimals places
```

1. **Zero Binary Floating-Point:** All arithmetic operations (`add`, `sub`, `mul`, `div`, `round`, `comp`) execute via `DecimalMath` on decimal strings. Native binary floats (`float`/`double`) are never used in domain monetary calculations.
2. **Mathematically Justified Intermediate Precision:**
   - Both `base_price` and `discount_percent` have at most 6 decimal places ($10^{-6}$).
   - The product $B \times (100 - P)$ has at most $6 + 6 = 12$ exact decimal places without any lost guard digits.
   - Dividing by $100$ only shifts the decimal point 2 positions left to at most $12 + 2 = 14$ exact decimal places.
   - Because $100 = 10^2$, dividing by 100 never produces an infinite repeating fraction. Scale 14 captures the mathematically exact fraction.
3. **One and Only One Output Rounding:** Half-up rounding (`PHP_ROUND_HALF_UP`) occurs **exclusively at the calculation output boundary**, rounding the exact 14-decimal value to the store currency's configured decimal places.
4. **Supported Precision Bound (0 to 6 Decimals):**
   - Tierza v1 enforces a strict maximum currency precision of 6 decimal places (`Money::DEFAULT_SCALE = 6`), perfectly matching the database schema (`tierza_rules.value DECIMAL(20,6)`).
   - Higher currency decimals (such as 7 or 8) are explicitly rejected with `InvalidPricingContextException` to prevent silent truncation.
   - Zero-decimal currencies (IRR, JPY, KRW, Toman) are fully supported.
5. **Prevention of Silent Truncation:** Both `Money` and `PricingValue` validate that input values do not contain non-zero digits exceeding 6 decimal places, throwing `InvalidMoneyException` or `InvalidRuleException` rather than silently cutting off data.
6. **No Automatic Currency Conversions:** No implicit multiplying or dividing by 10 between IRR and Toman. Currencies are treated as distinct ISO codes.
7. **Large Monetary Values:** Safely handles arbitrary-length values (e.g. `999,999,999,999,999.99`) without native 64-bit integer overflow.
8. **BCMath & Pure-PHP Parity:** Guaranteed 100% identical behavior whether `ext-bcmath` is active or the pure-PHP fallback is utilized.

---

## 4. Sale-Price Metadata Policy

WooCommerce products may have an active promotional sale price in addition to their regular price.

1. **Active Base Price is Authoritative:** The calculation always applies discounts against `PricingContext::base_price()`, which WooCommerce provides as the current active price.
2. **No False Sale Assumptions:** Numerical equality between `base_price` and `sale_price` alone does NOT establish that a sale is active (e.g. expired sale schedule, identical regular and sale values).
3. **Explicit Context Confirmation (`is_on_sale`):**
   - When the store environment confirms an active sale, it passes `is_on_sale: true`. The diagnostic metadata records `'confirmed_sale_price'`.
   - When confirmed regular, it passes `is_on_sale: false`. The diagnostic metadata records `'confirmed_regular_price'`.
   - When unconfirmed (`is_on_sale: null`), `is_sale_active()` returns `false` and diagnostic metadata records `'active_base_price'`, never falsely asserting a confirmed promotional sale.
4. **No Modification of Core WooCommerce Prices:** Tierza never mutates or overwrites WooCommerce's stored regular or sale price postmeta.

---

## 5. Independent Calculation Oracle

To provide an authoritative mathematical baseline independent of the production `DecimalMath` implementation, Tierza includes `ExactRationalPricingOracle` (`tests/Unit/Application/Pricing/ExactRationalPricingOracle.php`).

* Uses pure integer-rational arithmetic and grade-school string big-integer multiplication and division.
* Verifies boundary conditions, exact halfway values, values immediately above and below rounding thresholds, very small micro-prices, large numbers, and currencies with 0, 2, 3, and 6 decimal places.
* 100% of test vectors match `AuthoritativePriceCalculator` exactly.

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
