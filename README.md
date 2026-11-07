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
- [x] **TRZ-004 (Database Schema & Persistence Layer)**: Production-ready MariaDB/MySQL database schema (`tierza_groups`, `tierza_rules`, `tierza_user_groups`), domain entities, value objects, repository implementations, uniqueness policies, and real database integration tests.

### Planned Milestones:
- [ ] **TRZ-005**: Deterministic Rule Resolution Engine & Authority Calculations
- [ ] **TRZ-006+**: Category/product tier pricing, customer-specific pricing, REST API controllers, and React admin SPA.

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
