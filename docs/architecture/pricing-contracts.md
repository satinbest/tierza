# Tierza — Pricing Contracts & Domain Interfaces (TRZ-005)

**Document Version:** 1.0.0  
**Status:** Approved Specification  
**Namespace:** `Tierza\Domain\Pricing`  
**License:** GPL-2.0-or-later

---

## 1. Overview and Objective

The pricing engine in Tierza is designed around deterministic, testable, and isolated domain contracts. The domain layer remains strictly agnostic of WordPress and WooCommerce globals (such as `get_current_user_id()`, `WC()->cart`, or database tables), enabling 100% pure unit testing and isolated evaluation.

TRZ-005 establishes the authoritative types, value objects, and interfaces that govern:
1. **Context Representation**: `PricingContext`
2. **Deterministic Arithmetic**: `Money` and `DecimalMath`
3. **Rule Resolution Contracts**: `RuleResolverInterface` and `ResolutionResult`
4. **Authoritative Calculation Contracts**: `PriceCalculatorInterface` and `PricingResult`
5. **Precedence Hierarchy & Machine-Readable Audit Codes**: `PrecedenceLevel`, `PricingSource`, and `PricingReasonCode`

These contracts serve as the baseline for:
- **TRZ-006**: Rule Resolver
- **TRZ-007**: Price Calculator
- **TRZ-008**: Pricing Engine Unit Test Matrix
- **TRZ-009**: Multi-level High-Performance Pricing Cache
- **TRZ-010 to TRZ-013**: WooCommerce Integrations (Product, Cart, Order, Admin)

---

## 2. Object Relationships & Architecture

```mermaid
flowchart TD
    subgraph Input ["Domain Input"]
        CTX["PricingContext\n(Immutable)"]
        MONEY["Money (Decimal)\n+ Currency"]
        CTX --> MONEY
    end

    subgraph Phase1 ["1. Resolution Phase (TRZ-006)"]
        RR["RuleResolverInterface\nresolve(context)"]
        RES["ResolutionResult\n(Matched Rule or Fallback)"]
        CTX --> RR
        RR --> RES
    end

    subgraph Phase2 ["2. Calculation Phase (TRZ-007)"]
        PC["PriceCalculatorInterface\ncalculate(context, resolution)"]
        OUT["PricingResult\n(Immutable Final Price)"]
        CTX --> PC
        RES --> PC
        PC --> OUT
    end

    subgraph Consumers ["Consumers (TRZ-010+)"]
        WC["WooCommerce Filters\n(wc_price, add_to_cart)"]
        REST["REST API / Simulator"]
        OUT --> WC
        OUT --> REST
    end
```

---

## 3. Money and Decimal Precision Strategy

### 3.1 Binary Floating-Point Imprecision Prevention
IEEE-754 binary floating-point arithmetic (native PHP `float`) is explicitly rejected for authoritative price calculation. Binary floats introduce well-known rounding anomalies (e.g., `0.1 + 0.2 = 0.30000000000000004`), which lead to off-by-one errors in invoice calculations, cart totals, and tax reporting.

### 3.2 Arbitrary-Precision String Decimal Arithmetic
- All monetary operations use fixed-scale decimal strings (`numeric-string`).
- **Internal Scale**: 6 decimal places (`0.000000`), matching database storage `DECIMAL(20,6)`.
- **Rounding Mode**: `PHP_ROUND_HALF_UP` (standard financial and e-commerce convention).
- **Format / Display**: Clean formatting to 2, 4, or store-configured decimals without floating-point conversion.
- **Precision Policy**: `DecimalMath` accepts arbitrary decimal strings with any number of fractional digits without truncation during intermediate arithmetic. Intermediate calculations (addition, subtraction, multiplication, division) preserve full precision of operands and format the final result to the requested `$scale` (defaulting to 6).
- **Exact Mathematical Zero Detection**: Division-by-zero checking in `DecimalMath::div()` uses `DecimalMath::is_zero()` which checks exact mathematical zero across all digits, independently of output `$scale`. A nonzero sub-scale divisor (such as `0.0000001` or `0.0000000001`) is correctly recognized as nonzero and evaluates to the accurate mathematical quotient rather than falsely triggering a division-by-zero error.
- **Argument Scale Bounds Validation**: All public arithmetic methods (`normalize`, `comp`, `add`, `sub`, `mul`, `div`, `round`) validate that `$scale` and `$precision` are integers between 0 and `MAX_SCALE = 20`. Negative scales or excessive scales (> 20) strictly throw `InvalidArgumentException`. Unsupported rounding modes in `round()` strictly throw `InvalidArgumentException`.
- **Boundary Restriction**: `Money` restricts monetary amounts to 6 fractional decimal digits at the domain boundary upon instantiation via `DecimalMath::normalize( $amount, self::DEFAULT_SCALE )`.

### 3.3 Shared Hosting and BCMath Evaluation
- On production environments with `ext-bcmath` enabled, `DecimalMath` leverages native C-level routines (`bcadd`, `bcsub`, `bcmul`, `bcdiv`, `bccomp`) for optimal execution speed.
- In minimal shared hosting environments where `bcmath` is omitted, `DecimalMath` provides an algorithmic pure-PHP string arithmetic fallback.
- **Parity Verification**: 100% parity across BCMath and Pure-PHP drivers is verified via automated differential testing (`DecimalMathDifferentialTest`), including negative signs, canonical zero representation, sub-scale precision preservation, and edge cases.
- **Result**: Tierza introduces zero undocumented mandatory PHP extensions and guarantees 100% identical arithmetic behavior regardless of host configuration.

### 3.4 Multi-Currency, Explicit Store Currency & External Adapter Normalization
- Every `Money` instance is bound to an explicit currency code (e.g. `'USD'`, `'EUR'`, `'IRR'`, `'IRT'`).
- **Zero Automatic Conversion**: Tierza strictly does **not** assume or perform automatic currency conversions (e.g. 1 Toman = 10 Rials). The store's active currency is authoritative.
- Operations across different currencies trigger a domain `CurrencyMismatchException`.
- **Authoritative Types (`string|int`)**: `Money` strictly rejects binary floating-point numbers (`float`) and scientific notation (`1e5`) to prevent float-representation bugs in authoritative prices.
- **WooCommerce Adapter Normalization**: In future integration tasks (TRZ-010 to TRZ-013), external WooCommerce data (such as `$product->get_price()` which may return float or string) will be sanitized at the adapter boundary using `wc_format_decimal( (string) $raw_price, wc_get_price_decimals() )` before instantiating `Money`. This keeps all float conversions strictly outside the pure domain layer.

---

## 4. Rule Precedence Specification (PRD Policy)

Tierza v1 enforces a strict deterministic precedence policy:
1. **Customer overrides Group**: Customer-specific rules take precedence over group-specific rules.
2. **Variation overrides Product**: Specific variation rules take precedence over general product rules.
3. **Product overrides Category**: Product rules take precedence over category rules.
4. **Category overrides Global**: Category rules take precedence over storewide global rules.
5. **Deeper Hierarchy Wins**: Within category rules, a rule on a deeper (child) category takes precedence over an ancestor (parent) category.
6. **Lowest Final Price Wins (Equal Depth)**: When multiple category rules match at the same hierarchy depth, the rule producing the **lowest final price** wins (in v1, category rules are percentage-only, so the highest discount percentage wins).
7. **Smallest Category ID (Deterministic Sibling Tie-Break)**: If the calculated final prices are equal at the same depth, the rule associated with the smaller category ID wins (`category_id ASC`).

### Precedence Hierarchy Mapping:

| Precedence | Level Identifier | Description | Source |
| :---: | :--- | :--- | :--- |
| **1** | `CUSTOMER_VARIATION` | Customer-specific rule applied to a product variation | `customer` |
| **2** | `CUSTOMER_PRODUCT` | Customer-specific rule applied to a specific product | `customer` |
| **3** | `CUSTOMER_CATEGORY` | Customer-specific rule applied to a product category (evaluated via steps 5-7) | `customer` |
| **4** | `CUSTOMER_GLOBAL` | Customer-specific storewide rule | `customer` |
| **5** | `GROUP_VARIATION` | Group-specific rule applied to a product variation | `group` |
| **6** | `GROUP_PRODUCT` | Group-specific rule applied to a specific product | `group` |
| **7** | `GROUP_CATEGORY` | Group-specific rule applied to a product category (evaluated via steps 5-7) | `group` |
| **8** | `GROUP_GLOBAL` | Group-specific storewide rule | `group` |
| **9** | `WOOCOMMERCE_FALLBACK` | Default WooCommerce base / catalog price | `woocommerce` |

### 4.1 Category Rule Constraints (v1)
- Category pricing rules are **percentage-based only** in v1 (fixed pricing on categories is disallowed by `PricingRule` invariants).
- Consequently, step 6 ("lowest final price wins") is directly determined by comparing discount percentages without requiring product price lookups during tier candidate sorting.

### 4.2 Explicit 0% Rules vs. Missing Rules
- An active rule with a 0% discount is valid and authoritative. If a customer has a 0% product rule (Level 2), it overrides any group-level discount (e.g., a 20% group rule at Level 6).
- `ResolutionResult::has_rule()` returns `true` for a 0% rule, with reason code `RULE_APPLIED_ZERO_PERCENTAGE`.
- Missing rules evaluate to `PrecedenceLevel::WOOCOMMERCE_FALLBACK` with `has_rule() === false`.

---

## 5. Type Definitions and Contract Interfaces

### 5.1 `PricingContext`
```php
final class PricingContext {
    public function __construct(
        int $product_id,
        Money $base_price,
        int $customer_id = 0,
        ?PricingGroup $group = null,
        ?int $variation_id = null,
        array $category_ids = [],
        array $category_depths = [],
        ?Money $regular_price = null,
        ?Money $sale_price = null,
        int $quantity = 1,
        ?int $group_id = null
    );
}
```

### 5.2 `RuleResolverInterface`
```php
namespace Tierza\Domain\Pricing\Contracts;

interface RuleResolverInterface {
    public function resolve( PricingContext $context ): ResolutionResult;
}
```

### 5.3 `PriceCalculatorInterface`
```php
namespace Tierza\Domain\Pricing\Contracts;

interface PriceCalculatorInterface {
    public function calculate( PricingContext $context, ResolutionResult $resolution ): PricingResult;
}
```

### 5.4 `PricingResult` Payload
```json
{
  "original_price": "100.000000",
  "final_price": "85.000000",
  "currency": "USD",
  "applied_rule_id": 42,
  "pricing_type": "percentage",
  "scope_type": "product",
  "source": "customer",
  "is_rule_applied": true,
  "is_zero_percentage": false,
  "is_discounted": true,
  "discount_amount": "15.000000",
  "savings_percentage": "15.000000",
  "reason_code": "RULE_APPLIED_CUSTOMER_PRODUCT"
}
```

---

## 6. Error Handling & Edge Cases

| Exception Class | Trigger Condition | Recoverable / Action |
| :--- | :--- | :--- |
| `InvalidMoneyException` | Negative price, non-numeric string, negative multiplier | Fatal for calculation. Prevents free product vulnerability. |
| `CurrencyMismatchException` | Mixing different currencies during calculation or in context | Fatal. Halt and log configuration error. |
| `InvalidPricingContextException` | `product_id <= 0`, `customer_id < 0`, `quantity < 1` | Fatal. Calling hook must provide valid entity references. |
| `InvalidProductVariationException` | `variation_id <= 0` or `variation_id === product_id` | Fatal. Inconsistent WooCommerce variation structure. |
| `MissingBasePriceException` | Product has no active price in WooCommerce | Recoverable fallback to standard WooCommerce display logic. |
| `RuleResolutionException` | Corrupted rule repository state or invalid resolution state | Logged; falls back safely to original WooCommerce price. |

---

## 7. Next Task Boundaries

- **TRZ-006 (Rule Resolver)**: Implements `RuleResolverInterface`, building candidate priority queues and category hierarchy evaluation algorithms.
- **TRZ-007 (Price Calculator)**: Implements `PriceCalculatorInterface`, clamping discounts, supporting fixed prices on products/variations, and formatting to WooCommerce decimals.
- **TRZ-008 (Unit Testing Matrix)**: Comprehensive boundary test suite for all combinations of rules, tiers, and currencies.
