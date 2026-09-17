# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0](https://github.com/Grazulex/laravel-api-idempotency/releases/tag/v1.1.0) (2026-09-17)

### Added

- Laravel 13 support (`illuminate/*` `^12.0|^13.0`)

### Changed

- PHP 8.3 remains the minimum version; PHP 8.4 is now part of the test matrix
- Dev dependencies updated: Orchestra Testbench `^10.0|^11.0`, Pest `^3.8|^4.0`, Pest Laravel plugin `^3.2|^4.0`
- CI matrix now runs PHP 8.3 / 8.4 against Laravel 12 / 13 with `prefer-lowest` and `prefer-stable` dependency sets
- PHPStan configuration no longer fails on unmatched ignore patterns across supported Larastan versions
- Code style aligned with the current Laravel Pint preset (imports only, no behaviour change)

### Removed

- Laravel 11 support (end of life)

## [1.0.0](https://github.com/Grazulex/laravel-api-idempotency/releases/tag/v1.0.0) (2026-02-04)

### Features

- initial implementation of Laravel API Idempotency package ([fa5925f](https://github.com/Grazulex/laravel-api-idempotency/commit/fa5925f432f7c9ee82736809e2b73c3a6fd5d13d))

### Documentation

- simplify README to match laravel-apiroute style ([3a44c94](https://github.com/Grazulex/laravel-api-idempotency/commit/3a44c94b573673f7793f44a2e92d7b3b203863c7))
