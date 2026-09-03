# tailwindcss-php

> **Pre-release — not yet published on Packagist; API may change before 1.0.**

**Tailwind CSS, compiled on demand from PHP.** No Node, no watcher, no build step. Edit a
template or stylesheet, reload the page, see the change — on any host that runs PHP 7.3+,
including shared hosting where `exec()` is disabled.

> This project is an independent implementation and is not affiliated with, sponsored by, or
> endorsed by Tailwind Labs Inc. "Tailwind" and "Tailwind CSS" are trademarks of Tailwind Labs Inc.

[![Packagist](https://img.shields.io/packagist/v/abhinaykhalatkar/tailwindcss-php)](https://packagist.org/packages/abhinaykhalatkar/tailwindcss-php)
[![PHP](https://img.shields.io/packagist/php-v/abhinaykhalatkar/tailwindcss-php)](composer.json)
[![CI](https://github.com/abhinaykhalatkar/tailwindcss-PHP/actions/workflows/ci.yml/badge.svg)](https://github.com/abhinaykhalatkar/tailwindcss-PHP/actions)

## How it works

Your stylesheet is a **virtual URL**. When the browser requests `/assets/styles.css`, your
front controller hands the request to this library, which:

1. checks whether any watched file changed since the last build (a few `stat` calls);
2. if only templates changed, re-scans just those files and checks whether the *set of class
   names* actually changed;
3. compiles only when needed — with the official Tailwind standalone CLI if your host allows
   it, or with the bundled pure-PHP Tailwind v4 engine otherwise;
4. writes the result atomically to a cache directory and streams it with proper caching
   headers, a content-hash fingerprinted URL, ETag/304 support, and precompressed gzip.

A warm request costs about a millisecond. There is nothing to run in the background.

## Install

```bash
composer require abhinaykhalatkar/tailwindcss-php
```

Requirements: PHP ≥ 7.3, `ext-json`. Optional: `ext-zlib` (gzip artefacts), `ext-apcu`,
`scssphp/scssphp` (SCSS entry files), `twig/twig` (Twig helper).

## Quick start

**1. Create an entry stylesheet** — `assets/styles/main.css`:
```css
@import "tailwindcss";

@theme {
  --color-brand: oklch(62% 0.2 250);
}
```

**2. Create a config** — `config/tailwind.php`:
```php
<?php
return [
    'debug'        => false,             // true while developing: rebuild every request, show errors on the page
    'input'        => 'assets/styles/main.css',
    'sources'      => ['templates'],     // directories scanned for class names
    'public_route' => 'assets/styles.css',
];
```

**3. Wire it into your front controller** — first thing in `index.php`:
```php
$tailwind = TailwindPhp\Tailwind::fromArray(require __DIR__ . '/../config/tailwind.php');

$route = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
if ($tailwind->respond($route, $_SERVER)) {
    exit;
}
```

**4. Make sure the virtual URL reaches PHP.** With Apache, this `.htaccess` is enough:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]
RewriteRule ^ index.php [L]
```
nginx: `try_files $uri /index.php$is_args$args;`. See `docs/integrations.md` for details.

**5. Link the stylesheet** using the fingerprinted URL helper:
```php
<link rel="stylesheet" href="/<?= TailwindPhp\url() ?>">
```
```twig
{# with the Twig extension #}
<link rel="stylesheet" href="/{{ tailwind_url() }}">
```

**6. Check your host:**
```bash
vendor/bin/tailwind-php doctor
```
`doctor` tells you which compiler will be used and why, whether the cache directory is
writable, and what to fix if something is off.

## Compilers

| Driver | When | Fidelity |
|--------|------|----------|
| `cli` | `proc_open` available and the official standalone binary installed (`vendor/bin/tailwind-php install-cli`, checksum-verified) | 100 % — it *is* Tailwind |
| `php` | Always available. Pure PHP, no process, no network | Tailwind v4 semantics; conformance measured against the official CLI on every commit — see `docs/compat/KNOWN-DIFFERENCES.md` |

`driver: auto` (default) picks `cli` when it is safe and available, else `php`.

The PHP engine supports CSS-first Tailwind v4: `@theme`, `@source`, `@utility`,
`@custom-variant`, `@apply`, responsive/state/group/peer/arbitrary variants, arbitrary
values and properties, and the utility catalogue in `docs/compat/`. It does not execute
JavaScript, so `@plugin` and `@config` require the `cli` driver.

## Configuration

All options with defaults: `docs/configuration.md`. The ones you will actually touch:

```php
return [
    'debug'          => false,
    'input'          => 'assets/styles/main.css',
    'sources'        => ['templates', 'assets/scripts'],
    'public_route'   => 'assets/styles.css',
    'cache_dir'      => 'cache/tailwind',
    'driver'         => 'auto',           // auto | php | cli
    'freeze'         => false,            // true: never check at request time; rebuild via `build`
    'check_interval' => 0,                // seconds between staleness checks (0 = every request)
    'safelist'       => [],               // classes that only appear in database content
    'preprocessor'   => null,             // 'scss' to use an .scss entry file
];
```

## Console

```
vendor/bin/tailwind-php build        # compile now (no-op if fresh; --force to rebuild)
vendor/bin/tailwind-php doctor       # explain the environment
vendor/bin/tailwind-php install-cli  # download the official binary (verified) — optional
vendor/bin/tailwind-php url          # print the current fingerprinted URL
vendor/bin/tailwind-php candidates --unknown   # "why is my class missing?"
vendor/bin/tailwind-php clear
vendor/bin/tailwind-php diff         # PHP engine vs CLI on your own project
```

## Deploying

- **Shared hosting (FTP, no shell):** defaults are right. The first request after an upload
  compiles; later requests are ~1 ms. Set `check_interval => 5` on busy sites.
- **VPS / CI deploys:** `vendor/bin/tailwind-php build` in your deploy script and
  `'freeze' => true`. Zero request-time checks; a missing cache still self-heals.
- **Cron warming:** `*/5 * * * * php vendor/bin/tailwind-php build -q`.

Hosting notes for IONOS, Strato, Hetzner, Docker and read-only filesystems: `docs/hosting.md`.

## Security

- All paths are confined to your project root; the cache holds only CSS and JSON.
- No shell is ever spawned; the CLI binary is checksum-verified against a pinned table.
- Debug mode is a code-level setting and cannot be enabled by a request.
- Production errors never expose paths or traces; the last good stylesheet keeps being served.

Full threat model in `docs/security.md`. Report vulnerabilities privately via GitHub
Security Advisories (see `SECURITY.md`).

## Compatibility

| | Supported |
|---|---|
| PHP | 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 (tested in CI) |
| Tailwind | v4 CSS-first semantics; goldens from CLI v4.1.x |
| Web servers | Apache (mod_rewrite), nginx, PHP built-in server |
| OS | Linux, macOS; Windows best-effort |

PHP 7.3 and 7.4 are end-of-life upstream. This library runs on them so that you are not
blocked by your host, but please upgrade PHP when you can — the library cannot make an old
PHP safe.

## Migrating from a scssphp-style pipeline

If you have a `StyleSheet`-like class with `getContent()`/`getContentType()`, swap it for
`TailwindPhp\Bridge\LegacyHandler($debug, 'assets/styles')` and keep everything else. Then
move to `respond()` and `tailwind_url()` when convenient. Details: `docs/integrations.md`.

## Contributing

See `CONTRIBUTING.md` for the development loop, the PHP 7.3 rules and the test
conventions. Compatibility with Tailwind is measured, not claimed: run `composer test`
and look at the compatibility report in `docs/compat/KNOWN-DIFFERENCES.md`.

## License

MIT. Preflight and default theme data are derived from Tailwind CSS (MIT, © Tailwind Labs Inc.);
see `NOTICE`.
