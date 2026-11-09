# Tierza — Advanced B2B Pricing for WooCommerce

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version: 0.1.0-dev](https://img.shields.io/badge/Version-0.1.0--dev-orange.svg)](#)
[![PHP: >=8.1](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4.svg)](https://www.php.net/)
[![WordPress: >=6.5](https://img.shields.io/badge/WordPress-%3E%3D6.5-21759B.svg)](https://wordpress.org/)
[![WooCommerce: >=8.5](https://img.shields.io/badge/WooCommerce-%3E%3D8.5-96588a.svg)](https://woocommerce.com/)

**Tierza** is a free and open-source, modular B2B pricing plugin for WooCommerce, specifically engineered for Iranian wholesale, corporate, and multi-tier merchant workflows.

[**Persian Documentation (مستندات فارسی)**](README.fa.md) | **English Documentation**

---

## 🚀 Overview

Tierza provides clean, authoritative, server-side pricing rules for wholesale and tier customers without destructively overwriting WooCommerce's core `regular_price` or `sale_price` values. It is engineered with Clean Architecture principles, ensuring seamless compatibility with WooCommerce standard checkout, Cart & Checkout Blocks, Store API, and HPOS.

---

## 🚦 Development Status

> [!IMPORTANT]
> Tierza is currently at version **`0.1.0-dev`**.  
> The core bootstrap, localization, dev tooling, and CI pipeline are fully completed. **Pricing calculation engines, customer group assignments, and database schemas are under active development in upcoming tasks.**

### Completed Milestones:
- [x] **TRZ-001 (Plugin Bootstrap)**: Layered architecture (Domain, Application, Infrastructure, Integration, Rest, Admin), PSR-4 autoloading, graceful degradation when WooCommerce is inactive.
- [x] **Internationalization**: Full Persian RTL support, compiled `.mo` translation files, and English fallback strings.
- [x] **TRZ-002 (Development Environment & Tooling)**: `@wordpress/env` Docker environment (WordPress 6.5.5, WooCommerce 8.5.2, MariaDB), WordPress Coding Standards (PHPCS), PHPStan Level 8, ESLint, TypeScript, and Playwright E2E browser tests.
- [x] **TRZ-003 (CI Pipeline)**: GitHub Actions continuous integration testing PHP 8.1 & 8.4 matrix, JS quality, containerized integration, Playwright E2E, and production ZIP artifact validation.
- [x] **TRZ-004 (Database Schema & Persistence Layer)**: Production-ready MariaDB/MySQL database schema (`tierza_groups`, `tierza_rules`, `tierza_user_groups`), database-enforced active rule uniqueness (`uq_active_rule`), domain entities, repositories, failure-safe idempotent migrations, entity reference validation, and 31 live database integration and legacy upgrade tests.
- [x] **TRZ-005 (Pricing Contracts & Domain Interfaces)**: Strongly typed immutable PricingContext, arbitrary-precision deterministic decimal value objects (Money, DecimalMath) with zero float imprecision and pure-PHP fallback (no mandatory undocumented extensions), strict 9-level precedence hierarchy contracts (RuleResolverInterface), calculation contracts (PriceCalculatorInterface), immutable PricingResult, machine-readable reason codes, and 209 unit tests (810 assertions).
- [x] **TRZ-006 (Deterministic Rule Resolver)**: High-performance deterministic rule selection engine (`DeterministicRuleResolver`), single batch database query optimization (`find_active_for_targets()`), guaranteed 0 DB queries for guest users, deterministic 3-step category hierarchy tie-breaking, variation rule inheritance, explicit 0% and zero-price rule support, referential safeguards, 53 pure unit tests, and 4 live MariaDB integration tests (288 total unit tests, 1215 assertions, 37 live DB tests).
- [x] **TRZ-007 (Authoritative Price Calculator)**: Authoritative price calculation engine (`AuthoritativePriceCalculator`), arbitrary-precision percentage discounts and fixed product/variation pricing, output boundary half-up rounding, currency precision support (0-6 decimals), promotional sale price awareness, fail-closed invariant validation (`PricingCalculationException`), 39 unit tests, and end-to-end live MariaDB integration data flow tests.
- [x] **TRZ-008 (Comprehensive Pricing Tests & Reliability Hardening)**: Full-coverage 8-tier pricing decision matrix (`PricingDecisionMatrixTest`), customer/group boundary isolation (`CustomerGroupIsolationTest`), product/variation hierarchy resolution (`ProductVariationHierarchyTest`), independent integer-rational monetary oracle comparison (`ComprehensiveMonetaryOracleTest`), deterministic property-based metamorphic tests with reproducible seed (`PropertyBasedPricingTest`), storage precision boundary audit (`PrecisionPersistenceBoundaryTest` enforcing `DECIMAL(20,6)` 14-integer-digit storage capacity and anti-overflow/scientific-notation validation), reliability fault injection (`ReliabilityErrorInjectionTest`), full-chain MariaDB integration with database immutability verification, and documented performance baseline (459 PHPUnit tests, 3,007 assertions, 40 live MariaDB tests, 269 assertions).
- [x] **TRZ-009 (Pricing Cache & Batch Prefetching)**: Authoritative request-scoped multi-tier caching layer (`PricingCacheInterface`, `RequestPricingCache`, `PricingCacheKeyGenerator`, `PricingBatchPrefetcher`, `CachedRuleRepository`), high-performance batch loading API (`resolve_batch`), 99% database query reduction for 100 products (1 SQL query vs 100 in baseline, 6.2x faster), 99.8% reduction for 500 products (1 SQL query vs 500 in baseline, 35.3x faster), 100% cache hits on repeated lookups, strict customer and group isolation with zero state-leaking, exact boundary customer invalidation (`cust:auth:<id>#`), bounded group-status caching (500 entries default, FIFO eviction, miss-requery), heterogeneous batch prefetching (1 SQL query for multi-customer batches), automatic cache invalidation hooks on rule/group/user-group mutations, memory bounds with FIFO eviction (100 candidate sets, 2000 resolutions, 2000 calculations, 500 group statuses), 508 PHPUnit tests (5,384 assertions), and 46 live MariaDB tests (605 assertions).

### Planned Milestones:
- [ ] **TRZ-010**: WooCommerce Price Hooks & Cart/Checkout Integration
- [ ] **TRZ-011+**: REST API and React admin SPA.

---

## ⚙️ Minimum Requirements

- **PHP**: 8.1 or newer
- **WordPress**: 6.5 or newer
- **WooCommerce**: 8.5 or newer
- **Database**: MySQL 5.7+ or MariaDB 10.4+

---

## 📦 Installation

### From Release ZIP
1. Download `tierza.zip` from repository releases or artifact builds.
2. Log in to your WordPress Admin dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Select `tierza.zip` and click **Install Now**, then **Activate Plugin**.

### Local Development Setup
```bash
# Clone the repository
git clone https://github.com/satinbest/tierza.git
cd tierza

# Install PHP dependencies
composer install

# Install JS/tooling dependencies
npm install

# Compile translation binary files
php bin/compile-mo.php

# Build frontend assets
npm run build

# Start live WordPress development environment (Requires Docker)
npm run env:start
```

---

## 🧪 Testing & Quality Commands

| Command | Description |
| :--- | :--- |
| `composer test` | Runs PHPUnit unit and integration test suites |
| `composer phpstan` | Runs PHPStan static analysis at Level 8 |
| `composer phpcs` | Validates code against WordPress Coding Standards |
| `npm run type-check` | Verifies TypeScript types with `tsc --noEmit` |
| `npm run lint:js` | Lints JavaScript and TypeScript files with ESLint |
| `npm run test:e2e` | Runs Playwright browser tests against live WordPress |
| `composer build:zip` | Packages the production-ready installable ZIP (`dist/tierza.zip`) |

---

## 🤝 Contribution Guidelines

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on code standards, branch policies, and pull request procedures.

---

## 📄 License

Tierza is licensed under the **GNU General Public License v2.0 or later** ([GPL-2.0-or-later](LICENSE)).
