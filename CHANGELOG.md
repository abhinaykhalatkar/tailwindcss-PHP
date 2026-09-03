# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Composer package skeleton: `abhinaykhalatkar/tailwindcss-php`, PSR-4 autoloading for the
  `TailwindPhp\` namespace, the `tailwind-php` console entry point, and a runtime
  requirement set limited to `php >=7.3`, `ext-json`, `psr/log` and
  `symfony/polyfill-php80`.
- PHPUnit test suites `Unit`, `Integration`, `Conformance`, `Security` and
  `Benchmark`, configured to run identically on PHPUnit 9.6, 10 and 11.
- PHPStan at level 8 analysing with PHP 7.3 semantics (`phpVersion: 70300`), so
  7.4+ syntax and functions are reported on the floor version.
- PSR-12 coding standard enforced by PHP-CS-Fixer (with `@PHP73Migration`) and
  PHP_CodeSniffer.
- Continuous integration matrix covering PHP 7.3 through 8.5, with lowest and
  highest dependency resolutions on the floor and the newest stable version, and a
  restricted shared-host job that runs the suite without `zlib`/`apcu` and with the
  process functions disabled for the test process.
- Security policy with private vulnerability reporting through GitHub Security
  Advisories and a 90-day coordinated disclosure window.

<!-- Resolves once v1.0.0 is tagged; the repository is not yet published. -->
[Unreleased]: https://github.com/abhinaykhalatkar/tailwindcss-PHP/compare/v1.0.0...HEAD
