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
- Build packaging script for standalone distribution ZIP (`dist/tierza.zip`).
