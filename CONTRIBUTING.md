# Contributing to Tierza

Thank you for your interest in contributing to Tierza! This project aims to deliver a modern, modular, open-source B2B pricing system for WooCommerce stores.

---

## 1. Code of Conduct

Please maintain a collaborative, respectful, and professional environment. All contributors are expected to adhere to standard open-source best practices.

---

## 2. Branching Strategy

- **`main`**: The primary, stable integration branch. All code must pass CI before merging into `main`.
- **`task/TRZ-xxx`**: Dedicated branches for specific roadmap tasks (e.g. `task/TRZ-004-pricing-schema`).
- **`feature/*`** or **`fix/*`**: Feature branches and bug fixes branched off `main`.

Direct commits to `main` are restricted. All changes must be submitted via Pull Requests.

---

## 3. Development Workflow

1. **Fork and Clone**:
   ```bash
   git clone https://github.com/<your-username>/tierza.git
   cd tierza
   ```
2. **Install Dependencies**:
   ```bash
   composer install
   npm install
   ```
3. **Compile Assets & Translations**:
   ```bash
   php bin/compile-mo.php
   npm run build
   ```
4. **Local Verification**:
   Before submitting any changes, verify all quality suites pass:
   ```bash
   composer test         # PHPUnit tests
   composer phpstan      # PHPStan static analysis (Level 8)
   composer phpcs        # WordPress Coding Standards
   npm run type-check    # TypeScript compiler check
   npm run lint:js       # ESLint
   composer build:zip    # Distribution packaging check
   ```

---

## 4. Coding Standards

- **PHP**: Adhere to official WordPress Coding Standards (`WordPress-Core`, `WordPress-Extra`). Keep code strictly typed (`declare(strict_types=1);`).
- **Architecture**: Respect the clean layered architecture. Domain logic must remain isolated from WordPress presentation hooks.
- **JavaScript/TypeScript**: Follow `@wordpress/scripts` and `@wordpress/eslint-plugin` rules.
- **Localization**: All user-facing strings must use gettext functions (`__()`, `esc_html__()`, etc.) with domain `'tierza'` and have English source text.

---

## 5. Pull Request Checklist

When opening a Pull Request:
- [ ] PR title references the task or issue (e.g., `TRZ-004: Customer Pricing Groups Schema`).
- [ ] All automated CI checks pass.
- [ ] No unrelated modifications or breaking changes.
- [ ] Any new strings are added to `languages/tierza.pot` and translated in `languages/tierza-fa_IR.po`.
- [ ] New functionality includes automated PHPUnit and/or Playwright E2E tests.
