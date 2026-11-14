# Tierza Architecture — WooCommerce Cart & Checkout Pricing Integration (TRZ-011)

## 1. Architectural Context & Design Invariants

**TRZ-011 connects Tierza's authoritative pricing engine directly with WooCommerce's classic cart and checkout lifecycle (`woocommerce_before_calculate_totals`, `woocommerce_after_checkout_validation`, `woocommerce_cart_item_price`, `woocommerce_cart_item_subtotal`).**

Before TRZ-011, Tierza provided deterministic catalog and product single view pricing (TRZ-010). TRZ-011 ensures that negotiated B2B prices, quantity scales, variation-level discounts, and user group rates persist accurately throughout the customer's shopping cart and order checkout stages with zero discrepancies or price drift.

### Core Architectural Invariants:
1. **Zero Database Pollution:** Stored WooCommerce base product prices (`_price`, `_regular_price`, `_sale_price`) in `wp_posts` and `wp_postmeta` are never mutated by cart calculations or checkout validations.
2. **Strict Idempotency:** Repeated cart total calculations (e.g. standard WooCommerce multi-pass totals calculation, coupon applications, tax recalculations, shipping selection updates) evaluate strictly against the authoritative unfiltered base price. Tierza discounts never stack exponentially across successive `calculate_totals` executions.
3. **Mandatory Checkout Invariant:** Unresolved pricing failures, calculation exceptions, or price discrepancies between the cart line item and live database rules strictly block order placement at checkout boundary validation.
4. **Trusted Customer Identity:** Pricing contexts are constructed using verified WordPress customer IDs. Guest sessions are strictly isolated; client query parameters or request headers cannot spoof group identities.
5. **Reentrancy Protection:** Mutex locks prevent recursive calculation loops if WooCommerce or third-party plugins invoke `calculate_totals()` during totals evaluation.
6. **Isolated Scope Guards:** Cart pricing hooks only execute in frontend cart/checkout and AJAX requests; administrative product edits (`post.php`, `post-new.php`) and bulk imports are guarded against unintentional execution.

---

## 2. Cart Calculation Lifecycle & Hook Integration

Tierza integrates into WooCommerce's classic cart lifecycle via dedicated action and filter hooks managed by `CartPricingIntegration`:

```mermaid
flowchart TD
    subgraph CartLifecycle["WooCommerce Cart Lifecycle"]
        A[Customer visits Cart / Checkout / Updates Quantity] --> B[woocommerce_before_calculate_totals]
        B --> C[CartPricingIntegration::on_before_calculate_totals]
        C --> D{Reentrancy Guard / Admin Check}
        D -- Blocked --> E[Bypass Calculation]
        D -- Allowed --> F[CartPricingAdapter::prefetch_cart]
        F --> G[Single Batch SQL Query for all Cart Items]
        G --> H[Loop Cart Items: apply_cart_item_pricing]
        H --> I[Product::set_price with Formatted Decimal]
        I --> J[Attach Immutable tierza_pricing Metadata]
        J --> K[WooCommerce Computes Line Subtotals & Taxes]
    end

    subgraph DisplayFormatting["Cart Item Display"]
        L[woocommerce_cart_item_price] --> M[CartPricingIntegration::filter_cart_item_price]
        M --> N[Catalog Aligned Strikethrough HTML]
        O[woocommerce_cart_item_subtotal] --> P[CartPricingIntegration::filter_cart_item_subtotal]
        P --> Q[Strikethrough Subtotal HTML]
    end

    subgraph CheckoutBoundary["Checkout Validation Gate"]
        R[Customer Submits Order] --> S[woocommerce_after_checkout_validation]
        S --> T[CartPricingAdapter::revalidate_checkout]
        T --> U{Unresolved Failures or Discrepancies?}
        U -- Yes --> V[Add WP_Error: tierza_checkout_pricing_error]
        V --> W[Block Order Placement & Display Notice]
        U -- No --> X[Allow Order Processing to Proceed]
    end
```

### Registered Hooks:
- `woocommerce_before_calculate_totals` (Priority 10): Authoritative calculation and price assignment before WooCommerce sums line totals.
- `woocommerce_cart_item_price` (Priority 10): Unit price display markup with catalog-matching strikethrough.
- `woocommerce_cart_item_subtotal` (Priority 10): Line item subtotal markup with regular price strikethrough.
- `woocommerce_after_checkout_validation` (Priority 10): Final release gate validating time-of-check price consistency and failure tracker status.
- `woocommerce_check_cart_items` (Priority 10): Surfaces cart-level warning notices if pricing failures exist.
- `wp_login` & `wp_logout` (Priority 10): Resets session pricing caches and failure state upon customer authentication transitions.
- `woocommerce_get_cart_item_from_session` (Priority 10): Restores `tierza_pricing` line item audit metadata across HTTP sessions.

---

## 3. Idempotency & Reentrancy Guards

In WooCommerce, `WC_Cart::calculate_totals()` is called multiple times per request (e.g. once during shipping estimation, once during tax calculation, and once before order creation). If a pricing plugin derives discounts from `$product->get_price()` without referencing the authoritative base price, repeated calculations result in exponential double-discounting (e.g. $100 -> $80 -> $64 -> $51.20).

### Idempotency Mechanism:
1. **Base Price Derivation (`get_unfiltered_base_price`):**
   - Retrieves original regular price via `$product->get_regular_price('edit')`.
   - Inspects scheduled promotional sale validity via `ProductPricingAdapter::is_product_on_sale()`. If an active scheduled sale exists, `$product->get_sale_price('edit')` serves as the authoritative starting price.
   - For custom items, reads `$cart_item['tierza_pricing']['base_price']`.
   - Never reads `$product->get_price()` as the starting price after it has already been set.
2. **Reentrancy Mutex:**
   - A boolean flag `$is_calculating` wraps `on_before_calculate_totals()`.
   - If an internal hook or third-party listener triggers `calculate_totals()` recursively, the reentrancy guard immediately returns, preventing stack overflows.

---

## 4. Context Construction & Line Item Metadata Preservation

`CartPricingAdapter::get_cart_item_pricing_context()` constructs a strongly typed `PricingContext` for each cart item:
- **Product & Variation Hierarchy:** Properly links variation ID and parent product ID for `WC_Product_Variation`.
- **Line Quantity:** Incorporates `$cart_item['quantity']` for quantity-tier rules.
- **Taxonomy Categories:** Recursively extracts product categories and ancestor depth hierarchy.
- **Trusted Customer Identity:** Derived via `ProductPricingAdapter::resolve_customer_id()`.

### Immutable Audit Metadata:
Upon applying pricing, `CartPricingAdapter` attaches audit metadata to `$cart_item['tierza_pricing']`:
```php
$cart_item['tierza_pricing'] = [
    'base_price'       => $context->base_price()->amount(),
    'calculated_price' => $final_price,
    'quantity'         => $context->quantity(),
    'rule_id'          => $pricing_result->applied_rule_id(),
    'customer_id'      => $context->customer_id(),
    'group_id'         => $context->group()?->id() ?? 0,
    'is_fallback'      => ! $pricing_result->is_rule_applied(),
    'precedence_level' => $pricing_result->precedence_level()?->value ?? '',
    'timestamp'        => time(),
];
```
This metadata is preserved across session deserialization via `woocommerce_get_cart_item_from_session`.

---

## 5. Customer Identity Resolution & Guest Session Isolation

- **Production Execution:** In production storefront requests, customer identity is strictly resolved from `get_current_user_id()`. Query string parameters (`?user_id=X`), POST parameters, or headers are never trusted to impersonate customer groups.
- **Guest Session Isolation:** Unauthenticated visitors (user ID 0) are resolved with null group. Even if a guest user possesses a cookie or cart from a prior session, prices fall back cleanly to catalog prices.
- **Login / Logout Transitions:**
  - Upon `wp_login`, customer group assignments are resolved and request caches are cleared. The saved cart recalculates to reflect the newly authenticated customer's B2B discounts.
  - Upon `wp_logout`, the cart recalculates back to guest catalog prices.

---

## 6. Catalog Alignment & Display Price Markup

Cart display prices match catalog formatting:
- When a line item receives a discount relative to its regular price, `format_cart_item_price_html()` outputs standard WooCommerce sale markup: `<del aria-hidden="true">$100.00</del> <ins>$80.00</ins>`.
- Subtotals in `filter_cart_item_subtotal()` scale the strikethrough regular price by the item quantity (e.g. `<del aria-hidden="true">$200.00</del> <ins>$160.00</ins>` for quantity 2).
- When no rule is applied (fallback price) or when a fixed rule equals the regular price, standard clean price HTML is rendered without strike-through.

---

## 7. Quantity Scaling & Subtotal Computation

- Line prices applied via `$product->set_price()` represent the **unit price** of the product formatted to store decimals (`wc_get_price_decimals()`).
- WooCommerce natively handles line subtotal multiplication (`unit_price * quantity`), tax calculation (inclusive/exclusive), and standard coupon deductions based on the authoritative unit price.
- Changing cart line item quantities triggers `woocommerce_before_calculate_totals`, re-evaluating quantity-based pricing tiers deterministically.

---

## 8. Checkout Boundary Validation & Invariant Enforcement

The checkout boundary is a mandatory security gate executed during `woocommerce_after_checkout_validation`:
1. **Pre-flight Error Check:**
   - Inspects `PricingFailureTracker::has_any_failures()`. If any product in the current cart suffered a pricing calculation exception, database query timeout, or precision error, checkout submission is strictly rejected.
2. **Time-of-Check Price Discrepancy Verification (`revalidate_checkout`):**
   - Iterates all cart items and executes fresh rule resolution against live database state.
   - Compares current evaluated price against `$cart_item['data']->get_price()`.
   - If an administrator modified pricing rules, deactivated a customer group, or changed catalog prices between cart viewing and checkout submission, checkout is blocked with notice:
     *"The price of one or more items in your cart has changed. Your cart has been updated with current prices. Please review and place your order again."*
3. **Blocking Mechanism:** Adds error code `tierza_checkout_pricing_error` to the checkout `$errors` (`WP_Error`) object, halting order placement before order creation or payment gateway invocation.

---

## 9. Error Handling, Classification & Graceful Degradation

Tierza classifies all runtime exceptions using `PricingFailureTracker`:
- `CODE_REPOSITORY_UNAVAILABLE`: Database read failures or connectivity loss.
- `CODE_CALCULATION_FAILED`: Arithmetic or precision overflow exceptions.
- `CODE_INVALID_CONTEXT`: Malformed cart line item or unparsable product data.
- `CODE_UNEXPECTED`: Any other uncaught `Throwable`.

### Graceful Degradation Policy:
- In catalog views, failed products fall back safely to catalog regular price.
- In cart views, failures are logged with sanitized diagnostic details and tracked in `PricingFailureTracker`.
- At checkout, unresolved failures strictly halt checkout to prevent customers from purchasing items at incorrect prices.

---

## 10. Performance Architecture, Batch Prefetching & Complexity Analysis

Cart calculations operate with strict algorithmic efficiency:
- **Batch Prefetching (`prefetch_cart`):** Before iterating cart items, `CartPricingAdapter::prefetch_cart()` collects all product IDs, variation IDs, parent product IDs, and customer targets across all cart items.
- A single SQL query pre-warms all candidate rules in `RequestPricingCache`.
- **Complexity:**
  - Candidate Rule Retrieval: $\mathcal{O}(1)$ database query per cart calculation pass regardless of cart size (1 to 100 items).
  - Memory Usage: Bound within $\mathcal{O}(N)$ where $N$ is the number of distinct products in the cart.
  - Sub-50ms Latency: Cart calculation for 100 heterogeneous items completes in under 20ms on standard hosting.

---

## 11. Verification Matrix & Evidence

| Verification Dimension | Test Class / Suite | Coverage | Status |
| :--- | :--- | :--- | :--- |
| Cart Context & Quantity | `CartPricingAdapterTest` | 13 Unit Tests | PASS (100%) |
| Idempotency (3-Pass Recalc) | `CartPricingAdapterTest` & `DatabaseIntegrationLiveTest` | Unit & MariaDB Live | PASS (100%) |
| Customer Isolation & Login | `CartPricingIntegrationTest` & `DatabaseIntegrationLiveTest` | Unit & MariaDB Live | PASS (100%) |
| Checkout Validation Gate | `CartPricingAdapterTest` & `CartPricingIntegrationTest` | Unit & MariaDB Live | PASS (100%) |
| Zero-Price Products | `CartPricingAdapterTest` & `DatabaseIntegrationLiveTest` | Unit & MariaDB Live | PASS (100%) |
| Database Immutability | `DatabaseIntegrationLiveTest` | MariaDB Live PostMeta Check | PASS (100%) |
| Performance Benchmark | `DatabaseIntegrationLiveTest` | 1, 10, 50, 100 Items | PASS (100%) |
| End-to-End Storefront & Cart | Playwright (`cart-pricing.spec.ts`) | 5 E2E Browser Tests | PASS (100%) |

---

## 12. Scope Boundaries & Future Extension Points

### Scope Boundaries:
- **Included in TRZ-011:** Classic WooCommerce cart and checkout templates (`cart.php`, `checkout.php`), `WC_Cart` lifecycle, line item price adjustments, subtotal formatting, login/logout session transitions, checkout validation gates.
- **Excluded from TRZ-011 (Reserved for Future Tasks):**
  - **Store API / Cart & Checkout Blocks (TRZ-012):** REST endpoints (`/wp-json/wc/store/v1/cart`) and Gutenberg Block integration.
  - **Order Persistence & HPOS (TRZ-013):** High-Performance Order Storage order items metadata (`wc_order_product_lookup`).
  - **Admin Order Editing:** Backend manual order creation (`shop_order`).
