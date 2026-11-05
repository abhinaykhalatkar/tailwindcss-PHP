# Contributing

Thanks for helping out. This library has one unusual constraint that shapes almost
every rule below: **it must run on the shared hosts its users actually have**, on every
PHP from 8.2 up, often with process functions disabled. Most of what follows falls out
of that, plus a deliberate refusal to grow dependencies.

## Local development loop

```bash
composer install && composer tools:install
composer cs:fix && composer stan && composer test      # before every push
```

`composer tools:install` is a separate step because PHP-CS-Fixer lives in its own
manifest under `tools/php-cs-fixer` rather than in `require-dev`, so its large
dependency tree never mixes with the library's; `composer cs` and `composer cs:fix`
call it from there.

Occasionally — and always before you claim something works on the floor version —
run the suite on PHP 8.2 itself: the local PHP matrix below does that for every
supported version, or use a container:

```bash
docker run --rm -v $PWD:/app -w /app php:8.2-cli sh -c 'composer install && composer test'
```

The library ships no `composer.lock`, so CI resolves dependencies per matrix job and
uses `--prefer-lowest` on the floor and on 8.4 to catch dependency floors. If you
develop on a newer PHP and want to check that resolution still works for 8.2, use the
matrix or the container above rather than committing a `platform` config.

`composer goldens` regenerates the conformance goldens. It needs the official
standalone binary and is only relevant when you touch the conformance corpus or the
utility tables.

### A note for Windows contributors

`composer lint:82` is a `find … | xargs … php -l` pipeline and needs a POSIX shell.
On Windows run it from **Git Bash**, not from PowerShell or `cmd.exe`, whose `find`
is a different program entirely. Everything else in the loop works from any shell.

### Local PHP matrix

`tools/php-matrix.php` runs every supported PHP minor on your machine, the same way the CI
matrix does. The supported versions come from `require.php` in `composer.json`. An installed
version below that floor is listed as "unsupported, skipped" and never run. Each version
resolves its own dependency set with the highest versions. As in CI, the floor's minor and 8.4
also get a cell with the lowest (`--prefer-lowest --prefer-stable`). Composer's advisory
filter stays on everywhere.

Then each cell runs `php -l` over `src/`, `tools/` and `bin/`, the Unit, Integration,
Conformance and Security suites, and `tools/compare-drivers.php` on
`tests/Fixtures/project-basic`. PHPStan, with both configurations, runs on the 8.3 cell only,
as in CI. The whole run prints one table and exits 0 when every cell passes, 1 when one
fails, and 2 on a usage or environment problem. Each cell's findings also print as soon as
that cell ends.

The tool finds the versions installed with [scoop](https://scoop.sh) (Windows):

```bash
scoop bucket add versions
scoop install versions/php82 versions/php83 versions/php84 versions/php85
```

Installing a version moves scoop's `php` shim (and, for some versions, the user-level
`PHP_INI_SCAN_DIR` variable) to that version. Run `scoop reset php<NN>` afterwards to put
back the one you work with. The matrix itself never uses the shim. It calls each
`apps\php<NN>\current\php.exe` directly with a php.ini it generates.

```bash
php tools/php-matrix.php --list                 # what was found, and what is unsupported
php tools/php-matrix.php                        # the whole matrix (allow an hour or more)
php tools/php-matrix.php --only=8.2,8.5         # some minors
php tools/php-matrix.php --no-lowest            # the highest cells only
php tools/php-matrix.php --only=8.2 --skip-install   # today's vendor/, no resolution
php tools/php-matrix.php --kit=<project dir>    # also compare-drivers on a real project
php tools/php-matrix.php --restore              # put vendor/ back after an interrupted run
```

While the matrix runs, your `vendor/` is moved to `.matrix/vendor-current/`, and it is moved
back at the end, even after a failure. Don't run other tests in the same checkout meanwhile:
give the matrix its own worktree if you need to keep working. Each cell's dependency set and
lock stay under `.matrix/php<NN>/` or `.matrix/php<NN>-lowest/` (gitignored). A run, and
`--restore`, holds `.matrix/lock`; a second run stops with exit 2 instead of moving `vendor/`
twice. If the run is killed, the next run refuses to start: delete `.matrix/lock` once no
`php.exe` of the run is left, then run `--restore`. A time limit ends only the step's own
process, so check for orphaned `php.exe` after a step reports "[stopped after N seconds]".
`vendor/` and `.matrix/` must be plain directories; the tool refuses a link or junction there.
Composer runs with `--no-plugins --no-scripts`. The `--kit` project can also
be set with the `TAILWINDPHP_MATRIX_KIT` environment variable. It is only read, but
compare-drivers loads its `config/tailwind.php` exactly as the library would, so point it
only at a project you trust.

## PHP 8.2 is the floor

`"php": ">=8.2"`, with no upper bound. PHP 7.3 to 8.1 are no longer supported: they
carry published security advisories, and the development tooling that still runs on
them cannot be kept advisory-free.

The code base was written for the old 7.3 floor and has not been modernised. It uses
no typed properties, arrow functions, `match`, enums, readonly properties or
constructor promotion, and it keeps its types in docblocks. Moving it to 8.2 idioms is
a separate, deliberate change, not something to do in passing. Until then:

- write new code in the style of the code around it;
- PHPStan still analyses `src/` and `tools/` with `phpVersion: 70300`; that pin moves
  to `80200` together with the fixes the stricter analysis asks for;
- PHP-CS-Fixer runs `@PHP82Migration` with two rewrites switched off (the `0o` octal
  prefix and `??=`), so it leaves the existing code as it is.

Every file starts with `declare(strict_types=1);`. Docblocks carry the types PHPStan
needs at level 8.

`str_contains`, `str_starts_with`, `str_ends_with`, `get_debug_type`, `fdiv` and
`preg_last_error_msg` are native on the floor. `symfony/polyfill-php80` stays a
requirement while PHPStan is pinned to 70300, because it is what declares them to that
analysis; it goes together with the pin. Do not hand-roll them.

The gates: `php -l` on a real 8.2 runtime (the `lint-82` CI job), PHPStan and
PHP-CS-Fixer (on 8.3 in CI), and the test matrix on 8.2, 8.3, 8.4 and 8.5.

## Dependencies are frozen

Runtime requirements are `php`, `ext-json`, `psr/log` (interfaces only) and, until
the PHPStan pin moves to 8.2, `symfony/polyfill-php80`. That is the whole list, and it
is a deliberate architectural
decision, not an oversight — it is what makes the package installable on any host and
auditable in an afternoon.

- **Never add a runtime dependency.** A pull request that adds one will be asked to
  remove it. If you are convinced the design needs one, open an issue first; it
  requires a recorded architectural decision.
- Optional integrations (SCSS, Twig, PSR-7) are declared under `suggest` and probed
  with `class_exists()` at configuration time, throwing a configuration error that
  names the package to install.
- **Dev dependency changes need maintainer approval** — adding, removing or widening
  a `require-dev` constraint affects every matrix job, so raise it in the issue or PR
  description and wait for a maintainer to agree.
- **Known-vulnerable versions are blocked when dependencies resolve, and audited in CI.**
  `roave/security-advisories` in `require-dev` makes Composer refuse any dependency
  version with a published advisory, and Composer's own advisory filter does the same.
  roave also refuses a PHP patch release with an advisory, so resolve with a current
  patch of each minor. The Security job in CI runs `composer audit` on a fresh
  resolution as well; run the same command locally. This is also why the floor is 8.2:
  the end-of-life PHPs, and the dev tools that still support them, carry advisories of
  their own.

## Tests

| Suite | Directory | What it is for |
|-------|-----------|----------------|
| Unit | `tests/Unit` | Pure logic. No filesystem, network, clock or randomness — inject the interfaces instead. |
| Integration | `tests/Integration` | Real temp directories: cache writes, staleness, locking, the full request lifecycle. |
| Conformance | `tests/Conformance` | The PHP engine's output against goldens generated from the official CLI. |
| Security | `tests/Security` | One test class per threat in the threat model. |
| Benchmark | `tests/Benchmark` | Performance budgets. Runs on 8.3 with opcache; not part of `composer test`. |

`composer test` runs the first four. `composer bench` runs the last.

Conventions, all of which exist so the suite passes on PHPUnit 10 and 11 alike:

- **Docblock annotations only** — `/** @test */`, `@dataProvider`, and
  `@covers-requirement FR-…` to tie a test to a requirement. No PHPUnit attributes.
- **Data providers are `public static`** and return `array<string, array>` with
  descriptive string keys, so a failure reads like a sentence.
- `assertSame` rather than `assertEquals` for scalars; the modern assertion names
  (`assertMatchesRegularExpression`).
- No PHPUnit 10+-only APIs.
- Small hand-written fakes in `tests/Support` in preference to mock objects.
- One test class per production class, mirroring the path:
  `src/Support/Path.php` → `tests/Unit/Support/PathTest.php`.
- Fixtures never contain absolute paths.

Before writing a new helper, fake or fixture, look for an existing one in
`tests/Support` and `tests/Fixtures` — extend it rather than adding a near-duplicate.

## Commits and pull requests

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add @custom-variant support to the engine
fix: keep serving the last good stylesheet when the lock is held
```

Types in use: `feat`, `fix`, `perf`, `refactor`, `test`, `docs`, `chore`, `security`.
Imperative mood, **subject ≤ 72 characters**, and the body explains *why* rather than
restating the diff.

**No trailers of any kind.** Commits carry no `Co-Authored-By`, no `Signed-off-by`,
and no tool or assistant attribution. Please make sure your local tooling is not
adding them.

One logical change per pull request. The pull request template is filled in
automatically — work through its checklist, and complete the security section when
you touch path handling, the cache, the CLI driver, the HTTP layer, the scanner or
`install-cli`. Link the requirement IDs and the architectural decisions you relied on.

CI must be green before review. If your change alters documented behaviour, say so
explicitly in the pull request so the design documents can be updated alongside it.

## Compatibility with Tailwind

Compatibility is measured, not claimed. The conformance suite compares the pure-PHP
engine against goldens produced by the official CLI, and the differences we know
about are tracked in the compatibility report shipped under `docs/compat/`. If you
find a mismatch, a failing conformance case is the most useful possible bug report.
