# Changelog

All notable changes to the Tierza project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-dev] - 2026-11-08

### Added
- Initial project scaffold and layered architecture structure (TRZ-001).
- WordPress plugin main entry point (`tierza.php`) with standard metadata and security headers.
- PSR-4 autoloading with dual composer and lightweight built-in fallback autoloader.
- Environment compatibility checker for PHP (>= 8.1), WordPress (>= 6.5), and WooCommerce (>= 8.5).
- Graceful degradation and bilingual admin notices when WooCommerce is inactive.
- Tierza top-level admin menu and Persian/RTL dashboard template with English fallback.
- Localization infrastructure with `tierza.pot`, `tierza-fa_IR.po`, and compiled `tierza-fa_IR.mo`.
- Automated test suite and static analysis configuration (PHPUnit & PHPStan Level 8).
- WordPress Coding Standards (WPCS) with PHPCS configuration.
- Reproducible development environment with `@wordpress/env` on Docker (WordPress 6.5.5 & WooCommerce 8.5.2) (TRZ-002).
- Playwright E2E browser smoke tests verified on live WordPress instance (TRZ-002).
- GitHub Actions CI pipeline (`.github/workflows/ci.yml`) covering PHP matrix (8.1, 8.4), JS linting, live WordPress integration, Playwright E2E, and production ZIP packaging (TRZ-003).
- Comprehensive CI pipeline guide (`docs/ci-pipeline.md`) (TRZ-003).
- Build packaging script for standalone distribution ZIP (`dist/tierza.zip`).
- Production-ready MariaDB/MySQL database schema (`tierza_groups`, `tierza_rules`, `tierza_user_groups`) using WordPress `dbDelta()` (TRZ-004).
- Domain entities, value objects, and repository implementations for Groups, Rules, and User-Group assignments (TRZ-004).
- Concurrency-safe active rule uniqueness key (`active_key` with `uq_active_rule` unique index) preventing duplicate active rules (TRZ-004).
- Logical conflict resolution ensuring only one active rule exists per `target_type + target_id + scope_type + scope_id` regardless of pricing type (TRZ-004).
- Failure-safe idempotent schema migrations with verification checks and `SchemaMigrationException` (TRZ-004).
- Resumable pre-migration backfill algorithm detecting legacy active rule conflicts without data mutation (TRZ-004).
- Hardened schema verification verifying index uniqueness and exact column sequences (TRZ-004).
- Entity reference validation (`EntityReferenceValidator`) verifying users, groups, products, variations, and categories (TRZ-004).
- 31 live database integration and legacy upgrade tests executed on WordPress & MariaDB Docker environment (TRZ-004).
- Strongly typed immutable PricingContext representing customer, group, product, variation, category hierarchy, quantity, and store currency outside WP globals (TRZ-005).
- Deterministic arbitrary-precision decimal monetary value objects (Money and DecimalMath) with 6-decimal internal scale and pure-PHP fallback (TRZ-005).
- Rule resolver domain interface (RuleResolverInterface) with strict 9-level precedence hierarchy and deterministic category tie-breaking specification (TRZ-005).
- Authoritative price calculation contract (PriceCalculatorInterface) supporting percentage discounts and fixed pricing (TRZ-005).
- Immutable PricingResult domain contract with machine-readable reason codes, explicit 0% rule vs no-rule distinction, and safe audit metadata (TRZ-005).
- Comprehensive pricing architecture specification (docs/architecture/pricing-contracts.md) (TRZ-005).
- Fixed DecimalMath sign preservation on negative fractions (e.g. -0.5, -0.125) and canonical unsigned zero across BCMath and pure-PHP fallback (TRZ-005).
- Comprehensive differential test suite (DecimalMathDifferentialTest) verifying 100% parity between BCMath and pure-PHP fallback drivers (TRZ-005).
- Hardened Money construction to strictly accept string|int only, rejecting binary float and scientific notation (TRZ-005).
- Reconciled category tie-breaking specification with 7-step PRD policy: deeper hierarchy depth wins; equal depth resolved by lowest final price; equal prices resolved by smaller category ID (TRZ-005).
- Enforced strict PricingContext consistency (group entity vs group_id, category depth mapping validation, and anonymous customer isolation) (TRZ-005).
- Hardened ResolutionResult validation with invariant checks preventing inconsistent or corrupted result states (TRZ-005).
- Expanded PHPUnit unit test suite to 209 tests with 810 assertions passing at 100% (TRZ-005).
- Deterministic pricing rule selection engine (`DeterministicRuleResolver`) implementing strict 9-level precedence hierarchy and 100% pure unit testing (TRZ-006).
- High-performance single batch query method (`RuleRepositoryInterface::find_active_for_targets()`) utilizing composite index `idx_target` (TRZ-006).
- Guaranteed zero database queries for unauthenticated/anonymous guests without assigned groups (TRZ-006).
- Fully deterministic 3-step category hierarchy tie-breaking engine (deepest taxonomy depth, lowest final price/highest percentage, smallest category ID) (TRZ-006).
- Strict referential safeguards for inactive rules, inactive groups, and deleted group references with domain exceptions (TRZ-006).
- Full support for explicit 0% discount and fixed zero price rules with dedicated reason codes (`RULE_APPLIED_ZERO_PERCENTAGE`) (TRZ-006).
- Variation rule inheritance allowing product-level rules to cascade to variations when no variation-specific rule matches (TRZ-006).
- In-memory mock repositories (`InMemoryRuleRepository` and `InMemoryGroupRepository`) for isolated high-speed testing (TRZ-006).
- Complete 53-test resolver unit test suite including 28-pair precedence matrix and 50-iteration randomized order invariance tests (TRZ-006).
- Live MariaDB resolver integration tests verifying end-to-end repository queries, category taxonomy matching, and query counts (TRZ-006).
- Comprehensive Rule Resolver architecture document (`docs/architecture/rule-resolver.md`) (TRZ-006).
- Hardened database read failure handling with `DatabaseQueryException` ensuring failed SQL queries never silently produce fallback prices (TRZ-006).
- Enforced fail-closed group eligibility policy requiring trusted entities or repository verification rather than assuming active status (TRZ-006).
- Enforced strict category-depth metadata validation for competing category rules, preserving valid depth zero (TRZ-006).
- Expanded PHPUnit test suite to 288 tests with 1,215 assertions and live MariaDB test suite to 37 tests with 228 assertions (TRZ-006).
- Implementation of Authoritative Price Calculator (`AuthoritativePriceCalculator`) implementing `PriceCalculatorInterface` (TRZ-007).
- Deterministic monetary calculation for percentage discounts and fixed product/variation pricing with arbitrary-precision decimal strings (TRZ-007).
- Full support for explicit 0% discount, 100% discount to zero, fixed zero price, and fixed price surcharges exceeding base price (TRZ-007).
- Output boundary half-up rounding respecting store currency decimal configuration (0 to 6 decimals) with support for zero-decimal currencies (TRZ-007).
- Active promotional sale price awareness: uses active WooCommerce price as discount base while preserving regular and sale prices untouched (TRZ-007).
- Hardened sale-price metadata policy: added explicit optional `is_on_sale` context flag, ensuring price equality alone never falsely claims a confirmed promotional sale (TRZ-007).
- Enforced uniform 0..6 currency decimal precision bound matching `DECIMAL(20,6)` database storage, explicitly rejecting unsupported 7+ decimal places with `InvalidPricingContextException` (TRZ-007).
- Prevented silent truncation across `Money` and `PricingValue` by strictly rejecting non-zero fractional digits exceeding scale 6 with `InvalidMoneyException` and `InvalidRuleException` (TRZ-007).
- Hardened intermediate calculation sequence with scale 12 multiplication and scale 14 division, guaranteeing 100% mathematical precision with zero premature rounding or lost guard digits (TRZ-007).
- Implemented independent ground-truth calculation oracle (`ExactRationalPricingOracle`) using exact integer-rational arithmetic across boundary values, halfway points, large numbers, and micro-prices (TRZ-007).
- Strict fail-closed calculation invariant verification with `PricingCalculationException`: rejects guest customer rules, scope mismatches, and invalid percentages (TRZ-007).
- Comprehensive unit test suite expanded to 371 PHPUnit tests (1,585 assertions) and 38 live MariaDB tests (250 assertions) verifying BCMath/pure-PHP parity, rounding boundaries, and immutability (TRZ-007).
- Authoritative Price Calculator architecture document (`docs/architecture/price-calculator.md`) (TRZ-007).
