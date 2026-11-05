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
- The request pipeline: a stylesheet served as a virtual URL, rebuilt only when a watched file
  or the set of class names changed, written atomically to a cache directory and served with a
  content-hash URL, `ETag`/`304` support and a precompressed copy.
- Configuration (`Tailwind::fromArray()` and a `tailwind.php` file) with validation, every path
  confined to the project root, and several stylesheet bundles per project.
- The `cli` driver: the official standalone Tailwind binary, started without a shell and only
  after its checksum matched a pinned release, with the `php` driver as the fallback.
- A candidate scanner for templates, with optional Twig and PHP pre-passes, and an optional SCSS
  stage in front of the stylesheet.
- The `tailwind-php` commands `build`, `clear`, `url`, `candidates`, `doctor` and `install-cli`
  (a verified download of the official binary).
- The pure-PHP Tailwind CSS v4.1 engine as the `php` driver. It compiles the Tier-1 utilities
  and variants and the CSS-first directives: `@import "tailwindcss"` (preflight and default
  theme), `@theme`, `@layer`, `@source`, static `@utility`, `@custom-variant` in its shorthand form
  (a selector, `@media`, `@supports`, `@container` or `(@starting-style)`) and `@apply`. A project
  that needs `@plugin`, `@config`, a functional `@utility` or the block form of `@custom-variant`
  gets a diagnostic from the engine and should use the `cli` driver.
- A conformance suite that compares the engine with the official CLI (4.1.18) on a generated
  corpus of 5 739 utility and variant candidates: 99.7 % identical, every difference listed with its
  reason in `docs/compat/KNOWN-DIFFERENCES.md`. `ConformanceTest` fails on a difference nobody listed and on
  a listed difference that no longer exists.
- `tools/compare-drivers.php <project-root>`: compiles one project with both drivers and prints
  every difference.
- An engine benchmark (`composer bench`) and `tools/profile-compile.php`, which shows where a
  compile spends its time.

### Changed

- `PhpEngineCompiler` is the real engine; the fixed-CSS stub of the earlier milestone is gone. It
  reports the compatibility stamp `compat-4.1.18.0`, which is part of every cache fingerprint, so
  a change in generated CSS invalidates the caches of the previous engine.

### Security

- The engine never touches the filesystem, the clock or a random source (a static check keeps it
  so); imports are read only through a resolver confined to the project's allowed roots.
- Every size the engine reads or writes is bounded: candidates per compile (30 000 by default,
  with a warning when the list is cut), parsed nodes, output bytes, the work of `@apply` chains
  and import depth. A hostile stylesheet or template can make a compile fail, not run away.
- Arbitrary values (`w-[...]`) and custom-variant selectors are validated before they reach a
  declaration: a value that could end a rule early, open a comment or carry a script URL is
  refused, and no diagnostic echoes more than a short excerpt of it.

<!-- Resolves once v1.0.0 is tagged; the repository is not yet published. -->
[Unreleased]: https://github.com/abhinaykhalatkar/tailwindcss-PHP/compare/v1.0.0...HEAD
