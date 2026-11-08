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
- Entity reference validation (`EntityReferenceValidator`) verifying users, groups, products, variations, and categories (TRZ-004).
- 21 live database integration tests executed on WordPress & MariaDB Docker environment (TRZ-004).
