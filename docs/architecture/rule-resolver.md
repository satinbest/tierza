# Tierza — Deterministic Rule Resolver (TRZ-006)

**Document Version:** 1.1.0  
**Status:** Approved Specification  
**Namespace:** `Tierza\Application\Pricing`  
**License:** GPL-2.0-or-later

---

## 1. Overview and Objective

The `DeterministicRuleResolver` is the core pricing rule selection engine for Tierza. It evaluates an immutable `PricingContext` (containing product, customer, group, and category data) and deterministically selects exactly **one** winning pricing rule from the repository, or returns an explicit fallback result when no applicable rule exists.

In accordance with Tierza Product Requirements Document (PRD v1), rule resolution is completely decoupled from price calculation. The resolver's sole responsibility is identifying *which rule applies* based on the strict 9-level precedence hierarchy and tie-breaking policies. Price evaluation (discounts, fixed prices, min/max limits, rounding) is deferred to the calculation engine in TRZ-007.

Key architectural characteristics:
- **Zero Floating-Point Operations**: Preserves domain purity and exact numerical guarantees.
- **Fail-Closed Database Failure Policy**: Database read errors are never silently converted into fallback prices. Dedicated exceptions preserve the causal error chain.
- **Fail-Closed Group Eligibility Policy**: Never assumes group activation from a positive numeric ID alone. Requires either a trusted entity in context or repository verification.
- **Strict Deterministic Category-Depth Validation**: Multi-category tie-breaking strictly mandates depth metadata for all competing categories without defaulting unknown depths to zero.
- **Single Batch Query Optimization**: Executes at most 1 database query for rule lookups and 0 queries for anonymous users without groups.
- **Pure Dependency Injection**: Operates on `RuleRepositoryInterface` and `GroupRepositoryInterface`, requiring no WordPress or WooCommerce runtime during evaluation.
- **100% Deterministic**: Identical inputs always produce the identical winning rule regardless of database row order or retrieval sequence.
- **Defensive Safeguards**: Detects corrupted data, negative amounts, out-of-bounds percentages, and missing category depths with explicit domain exceptions.

---

## 2. Precedence Hierarchy

Tierza v1 implements a strict, deterministic 9-level precedence hierarchy without rule stacking or compounding:

| Priority | Precedence Level | Target Type | Scope Type | Scope Target | Description |
|:---:|:---|:---:|:---:|:---:|:---|
| **1** | `CUSTOMER_VARIATION` | Customer (`user`) | `variation` | Specific Variation ID | Rule created for a specific user on a specific product variation. |
| **2** | `CUSTOMER_PRODUCT` | Customer (`user`) | `product` | Product ID | Rule created for a specific user on a product (or variation's parent product). |
| **3** | `CUSTOMER_CATEGORY` | Customer (`user`) | `category` | Product Category ID | Rule created for a specific user on a product's assigned category. |
| **4** | `CUSTOMER_GLOBAL` | Customer (`user`) | `global` | All Store Products (0) | Storewide rule created for a specific user. |
| **5** | `GROUP_VARIATION` | Group (`group`) | `variation` | Specific Variation ID | Rule created for a customer's group on a specific variation. |
| **6** | `GROUP_PRODUCT` | Group (`group`) | `product` | Product ID | Rule created for a customer's group on a product (or parent product). |
| **7** | `GROUP_CATEGORY` | Group (`group`) | `category` | Product Category ID | Rule created for a customer's group on a product's category. |
| **8** | `GROUP_GLOBAL` | Group (`group`) | `global` | All Store Products (0) | Storewide rule created for a customer's group. |
| **9** | `WOOCOMMERCE_FALLBACK` | None | None | None | Standard WooCommerce base price applies (no B2B rule matches). |

### Strict Precedence Invariants:
1. **Higher Level Always Wins**: A rule at Priority 1 always beats Priority 2..9, regardless of discount depth or pricing value.
2. **Customer Overrides Group**: Customer rules (1..4) strictly supersede Group rules (5..8).
3. **Specific Scope Overrides Broad Scope**: Variation overrides Product, which overrides Category, which overrides Global.
4. **No Stacking in v1**: Exactly one rule wins. Rules are never combined or compounded.
5. **Fallback Default**: If no active rule matches in priorities 1..8, priority 9 (`WOOCOMMERCE_FALLBACK`) is returned with reason `NO_MATCHING_RULE`.

---

## 3. Database Failure Handling vs. Successful Fallback

### 3.1 The Fail-Closed Mandate
A critical failure mode in e-commerce pricing engines is silently interpreting a failed database query as an empty result set (`[]`). When this occurs, a customer with an active B2B discount (e.g., 40% wholesale discount) is silently charged retail price (`WOOCOMMERCE_FALLBACK`), or conversely, private pricing data could be bypassed.

Tierza strictly enforces a **fail-closed** architecture:
- **Successful Empty Result**: When `$wpdb` successfully executes the query and returns 0 matching rows (`is_array($rows) && empty($rows)` and `$wpdb->last_error === ''`), the resolver returns an authoritative `ResolutionResult::fallback()` with reason `FALLBACK_NO_MATCHING_RULE` or `FALLBACK_ANONYMOUS_NO_RULE`.
- **Database Query Failure**: When `$wpdb` encounters an error (syntax error, table lock, connection drop, server crash, or `! empty($wpdb->last_error)`), the infrastructure layer immediately throws `DatabaseQueryException`.
- **Exception Propagation**: `DeterministicRuleResolver` catches any repository-level `Throwable`, wraps it into `RuleResolutionException`, and preserves the original exception as its `$previous` causal chain.
- **Sanitized Public Messages**: Error messages in `DatabaseQueryException` and `RuleResolutionException` describe the operational failure (e.g., `"Database query failed while retrieving active rules for targets."`) without leaking raw SQL queries, internal database credentials, or customer identifiers into public responses.

### 3.2 Later WooCommerce Adapter Responsibility
In upcoming tasks (TRZ-010 to TRZ-013), external WooCommerce hooks (e.g., `woocommerce_product_get_price`) invoke the pricing engine:
- If `RuleResolutionException` or `DatabaseQueryException` is raised during price resolution, the WooCommerce adapter MUST log the incident with administrative audit trails.
- The adapter must handle the exception according to merchant security policies (e.g., preventing checkout or displaying a localized friendly error notice), rather than silently overwriting prices or ignoring errors.

---

## 4. Group Verification & Trust Boundaries

### 4.1 Elimination of Unverified Numeric Group Assumptions
Earlier iterations evaluated `$context->group_id() > 0` and defaulted to active if no repository was injected. This violated fail-closed security. In TRZ-006 v1.1.0:
- **Rule 1**: The resolver **never** infers group activation from a positive numeric ID alone.
- **Rule 2**: When an explicitly trusted, server-side validated `PricingGroup` entity is attached to `$context->group()`, the resolver honors `$group->is_active()` without an additional database query.
- **Rule 3**: If only a numeric `$context->group_id()` is provided, the resolver **must** verify the group's existence and status via `GroupRepositoryInterface::find_by_id($group_id)`.
- **Rule 4**: If neither a trusted entity nor a group repository is provided, the resolver immediately raises `RuleResolutionException`.
- **Rule 5**: A verified inactive group (`status = 'inactive'`) never contributes group pricing rules (Tiers 5..8 are completely omitted from the resolution targets).
- **Rule 6**: If a context references a group ID that does not exist in the database (e.g. deleted group), the resolver throws an explicit `RuleResolutionException` to prevent silent referential corruption.

### 4.2 Security Trust Boundary
- Authentication status (`customer_id`) and group assignment must originate exclusively from server-side WordPress user authentication and trusted user metadata (`wp_tierza_user_groups`).
- User-supplied group IDs from `$_GET`, `$_POST`, cookies, or REST request bodies are strictly rejected at the adapter layer.
- Cached group entities (introduced in TRZ-009) are subject to automated invalidation upon any group status update or deletion.

---

## 5. Category Hierarchy Tie-Breaking Policy

When multiple category rules match at Level 3 (`CUSTOMER_CATEGORY`) or Level 7 (`GROUP_CATEGORY`), Tierza applies a deterministic 3-step tie-breaking procedure:

### 5.1 Deterministic 3-Step Algorithm
1. **Step 1 — Greatest Hierarchy Depth**:
   Rules assigned to deeper child categories in the taxonomy tree take precedence over broader ancestor categories.
   - Example: Rule on Category *Laptops* (depth 3) defeats Rule on Category *Electronics* (depth 1).
2. **Step 2 — Highest Percentage Discount (Lowest Final Price)**:
   If two candidate category rules reside at the exact same depth level, the rule offering the greatest percentage discount (lowest final price for the B2B customer) wins.
   - Example: 25% discount on *Casual Shirts* (depth 2) defeats 15% discount on *Summer Apparel* (depth 2).
3. **Step 3 — Smallest Category ID (`category_id ASC`)**:
   If both category rules reside at identical depth and offer the identical discount percentage, the category with the lower numeric taxonomy term ID wins.
   - Example: Category ID 15 wins over Category ID 40.

### 5.2 Strict Category-Depth Metadata Validation
- **Multi-Category Requirement**: When two or more distinct category rules compete, valid depth metadata **must** exist in `$context->category_depths()` for every competing category.
- **No Incomplete Maps**: A completely missing depth map (`[]`) or a partially populated map (missing any competing category) throws `RuleResolutionException`.
- **Preservation of Depth Zero**: Root categories possess valid depth `0` (`is_int($depth) && $depth >= 0`). Depth zero is explicitly preserved and compared via `array_key_exists()`, never falsely treated as missing.
- **No Arbitrary Defaulting**: Unknown category depths are **never** defaulted to zero during multi-category resolution.
- **Single Non-Competing Rule**: If only 1 category rule matches, no competition or tie-breaking exists; the rule is returned directly without requiring depth metadata.

---

## 6. Performance Verification & Database Query Counts

### 6.1 Query Count Breakdown
The resolver explicitly distinguishes between rule-lookup queries and group-verification queries:

| Customer Context | Group Verification Queries | Pricing Rule Queries | Total Queries per Resolution |
|:---|:---:|:---:|:---:|
| **Anonymous Guest (No Group)** | 0 | 0 | **0 queries** |
| **Authenticated (with Trusted Group Entity)** | 0 | 1 (`find_active_for_targets`) | **1 query** |
| **Authenticated (Numeric Group ID only)** | 1 (`find_by_id`) | 1 (`find_active_for_targets`) | **2 queries** |
| **Product Listing Archive ($N$ items, Trusted Entity)** | 0 | $N$ | **$N$ queries** (optimized in TRZ-009) |

### 6.2 Benchmark Fixtures (MariaDB Environment)
Verified in development Docker test environment (`tests/Live/DatabaseIntegrationLiveTest.php`):
- **Fixtures Seeded**: 10 pricing groups, 100 WooCommerce products, 500 pricing rules in `wp_tierza_rules`.
- **Listing Resolution (100 products)**:
  - Total queries: Exactly 100 queries (1 indexed batch query per product, 0 group queries).
  - Total execution time: ~120ms total (< 1.5ms per resolution).

---

## 7. Critical Invariants & Defensive Handling

1. **Explicit 0% Discount & 0.00 Fixed Price**:
   - Resolves to `PrecedenceLevel::CUSTOMER_*` or `PrecedenceLevel::GROUP_*`.
   - Emits `PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE` for explicit 0% rules, distinguishing intended zero discounts from unconfigured fallbacks.
2. **Defensive Candidate Validation**:
   - Inactive rules returned by faulty repositories are rejected with `RuleResolutionException`.
   - Duplicate active rules for the same logical key (`target:scope`) throw `RuleResolutionException`.
   - Fixed-price rules appearing at category or global scope throw `RuleResolutionException`.
3. **Variation Parent Product Inheritance**:
   - For variable products, if no variation-specific rule exists (Tiers 1 & 5), the variation inherits parent product rules at Tiers 2 (`CUSTOMER_PRODUCT`) and 6 (`GROUP_PRODUCT`).
   - Customer product rule (Tier 2) strictly overrides Group variation rule (Tier 5).

---

## 8. Verification Matrix

The deterministic rule resolver is verified by:
1. **73 Isolated Unit Tests** (`DeterministicRuleResolverTest`):
   - All 8 precedence tiers tested individually.
   - Database read failure exception wrapping and causal chain tests.
   - Group verification tests: trusted entity, repository lookup, inactive group, deleted group, unverified ID exception, and guest group matching.
   - Category depth validation tests: missing depth map, partial depth map, valid depth 0 preservation, parent vs child, tie-break rules, and randomized ordering.
   - Invariant defensive tests: corrupted inactive rules, duplicate logical rules, fixed price at category scope, explicit zero rules.
   - 28-pair precedence matrix test comparing every higher-priority tier against every lower-priority tier.
2. **5 Live MariaDB Integration Tests** (`DatabaseIntegrationLiveTest`):
   - Real MariaDB stored rules resolution.
   - Real MariaDB category taxonomy hierarchy resolution.
   - Real MariaDB explicit 0% and inactive rules filtering.
   - Real MariaDB intentionally invalid SQL query failure producing `DatabaseQueryException` and `RuleResolutionException` (distinguished from fallback).
   - Real MariaDB query count breakdown across all 4 scenarios.
   - Real MariaDB benchmark fixtures test (10 groups, 100 products, 500 rules).
