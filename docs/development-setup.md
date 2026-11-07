# Tierza — Development Environment & Tooling Guide

This guide details how to set up, run, and test Tierza in local development and continuous integration environments.

---

## 1. Prerequisites

### Required Software
- **PHP**: `8.1` or higher (verified with PHP 8.1 & 8.4)
  - Required extensions: `curl`, `mbstring`, `zip`, `openssl`, `pdo_sqlite`, `pdo_mysql`, `dom`, `libxml`.
- **Composer**: `2.x`
- **Node.js**: `v20.x` or higher (tested with `v24.18.x`)
- **npm**: `10.x` or higher (tested with `12.x`)
- **Git**: `2.x`

### Containerization (For Live WordPress Test Environment)
- **Docker Desktop**: Required to run the official `@wordpress/env` stack.
  - Windows: WSL2 backend enabled.
  - Linux/macOS: Docker Engine with Docker Compose v2.

---

## 2. Quick Setup

```bash
# 1. Clone repository
git clone https://github.com/tierza/tierza.git
cd tierza

# 2. Install PHP dependencies (including PHPUnit, PHPStan, WPCS)
composer install

# 3. Install JavaScript / React / tooling dependencies
npm install

# 4. Compile translations
php bin/compile-mo.php

# 5. Build distribution ZIP
composer build:zip
```

---

## 3. WordPress Test Environment (`@wordpress/env`)

Tierza uses `@wordpress/env` with a pinned environment configuration defined in `.wp-env.json`.

### Environment Specifications
- **WordPress Core**: `6.5.5` (WordPress/WordPress#6.5.5)
- **WooCommerce**: `8.5.2` (downloaded from official WordPress.org releases)
- **Tierza**: Mounted live from the root repository (`"."`)
- **Database**: MariaDB / MySQL (managed by Docker)
- **Port Mapping**:
  - Main WordPress: `http://localhost:8888`
  - Tests WordPress: `http://localhost:8889`
- **Development Language**: Persian (`WPLANG: fa_IR`) with RTL enabled.
- **Test Language**: English fallback (`WPLANG: ""`).
- **Debugging**: `WP_DEBUG: true`, `WP_DEBUG_LOG: true`, `SCRIPT_DEBUG: true`.

### Environment Management Commands
```bash
# Start the local WordPress + WooCommerce environment
npm run env:start

# Stop the environment
npm run env:stop

# Clean and reinitialize database
npm run env:clean
```

### Default Credentials
| User Role | Username | Password |
| :--- | :--- | :--- |
| Administrator | `admin` | `password` |

---

## 4. Development & Testing Commands

| Task | Command | Description |
| :--- | :--- | :--- |
| **Unit Tests** | `composer test` | Runs PHPUnit test suites (Unit & Integration). |
| **PHP Coding Standards** | `composer phpcs` | Validates against WordPress Coding Standards (Core & Extra). |
| **Auto-fix PHP Styles** | `composer phpcbf` | Automatically fixes sniff violations. |
| **PHP Static Analysis** | `composer phpstan` | Runs PHPStan at Level 8. |
| **Build Assets** | `npm run build` | Compiles JavaScript/TypeScript assets via `@wordpress/scripts`. |
| **Watch Assets** | `npm run start` | Watches and rebuilds development assets. |
| **Lint JavaScript** | `npm run lint:js` | Runs ESLint with WordPress standards. |
| **Format JavaScript** | `npm run format:js` | Formats JavaScript with Prettier. |
| **E2E Smoke Tests** | `npm run test:e2e` | Runs Playwright tests against running WordPress. |
| **Build Release ZIP** | `composer build:zip` | Compiles translations and packages clean `dist/tierza.zip`. |

---

## 5. Troubleshooting & Environment Limitations

### Docker Not Available Blocker
If Docker is not running or not installed on the host machine:
- `npm run env:start` will output `Docker is not running` or command not found.
- In environments without Docker, run the unit and mocked integration tests via:
  ```bash
  composer test
  composer phpstan
  composer phpcs
  ```
- To test standalone extraction and unzipped execution without Composer:
  ```bash
  php bin/verify-standalone.php
  ```

### Windows Line Endings (CRLF / LF)
- Git on Windows may convert LF to CRLF. `.gitattributes` or git config `core.autocrlf true` is recommended.

### Translation Changes
- After updating strings in PHP, recompile binary MO files:
  ```bash
  php bin/compile-mo.php
  ```
