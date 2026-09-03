# Contributing

Thanks for helping out. This library has one unusual constraint that shapes almost
every rule below: **it must run unchanged on PHP 7.3**, because that is what a
meaningful share of its users' shared hosts still run. Most of what follows falls out
of that, plus a deliberate refusal to grow dependencies.

## Local development loop

```bash
composer install && composer tools:install
composer cs:fix && composer stan && composer test      # before every push
```

`composer tools:install` is a separate step because PHP-CS-Fixer cannot run on the
PHP 7.3 floor and therefore lives in its own manifest under `tools/php-cs-fixer`
rather than in `require-dev`; `composer cs` and `composer cs:fix` call it from there.

Occasionally — and always before you claim something works on the floor version —
run the suite inside a PHP 7.3 container:

```bash
docker run --rm -v $PWD:/app -w /app php:7.3-cli sh -c 'composer install && composer test'
```

The library ships no `composer.lock`, so CI resolves dependencies per matrix job and
uses `--prefer-lowest` on the floor to catch dependency floors. If you develop on
PHP 8.x and want to check that resolution still works for 7.3, use the container
above rather than committing a `platform` config.

`composer goldens` regenerates the conformance goldens. It needs the official
standalone binary and is only relevant when you touch the conformance corpus or the
utility tables.

### A note for Windows contributors

`composer lint:73` is a `find … | xargs … php -l` pipeline and needs a POSIX shell.
On Windows run it from **Git Bash**, not from PowerShell or `cmd.exe`, whose `find`
is a different program entirely. Everything else in the loop works from any shell.

## PHP 7.3 is the floor

`"php": ">=7.3"`, with no upper bound. Do not use anything newer than 7.3 syntax in
`src/`, `tests/`, `tools/` or `bin/`:

| Do not use | Since | Use instead |
|------------|-------|-------------|
| Typed properties `private string $x;` | 7.4 | Untyped property + `/** @var string */`, validate in the constructor |
| Arrow functions `fn($x) => …` | 7.4 | `function ($x) use (…) { return …; }` |
| `??=` | 7.4 | `if ($a === null) { $a = $b; }` or `$a = $a ?? $b` |
| Numeric literal separators `1_000` | 7.4 | `1000` |
| `proc_open` with an array command | 7.4 | Branch on `PHP_VERSION_ID`; escape and join on 7.3 |
| Union types `int\|string`, `mixed`, `static` return | 8.0 | Docblock the type; check at runtime where it matters |
| Nullsafe `$a?->b()` | 8.0 | `$a !== null ? $a->b() : null` |
| Named arguments | 8.0 | Positional arguments; an options array when there are many |
| `match` | 8.0 | `switch` with `break`, or a lookup array |
| Constructor property promotion | 8.0 | Explicit properties and assignments |
| Attributes `#[…]` | 8.0 | Docblock annotations |
| Trailing comma in *parameter* lists | 8.0 | None — a trailing comma in a *call* is fine on 7.3 |
| `catch (Throwable)` without a variable | 8.0 | `catch (\Throwable $e)` |
| Enums | 8.1 | `final class` with constants and a validation method |
| Readonly properties | 8.1 | Private property with a getter and no setter |
| First-class callables `f(...)` | 8.1 | `[$obj, 'method']` or `Closure::fromCallable` |
| `never` return type | 8.1 | `void` plus `@return never` in the docblock |
| Typed class constants, `json_validate`, property hooks | 8.3 / 8.4 | Avoid |

Every file starts with `declare(strict_types=1);`. Docblocks carry the types PHPStan
needs at level 8, since the signatures cannot.

**Polyfill rule.** `str_contains`, `str_starts_with`, `str_ends_with`,
`get_debug_type`, `fdiv` and `preg_last_error_msg` *are* allowed — they come from
`symfony/polyfill-php80`, which is a required runtime dependency. Do not hand-roll
them, and do not reach for a different polyfill package.

Three gates enforce this: `php -l` on a real 7.3 runtime, PHPStan with
`phpVersion: 70300`, and PHP-CS-Fixer with `@PHP73Migration`. The last two run on
8.3 because the tools themselves need a newer PHP.

## Dependencies are frozen

Runtime requirements are `php`, `ext-json`, `psr/log` (interfaces only) and
`symfony/polyfill-php80`. That is the whole list, and it is a deliberate architectural
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

## Tests

| Suite | Directory | What it is for |
|-------|-----------|----------------|
| Unit | `tests/Unit` | Pure logic. No filesystem, network, clock or randomness — inject the interfaces instead. |
| Integration | `tests/Integration` | Real temp directories: cache writes, staleness, locking, the full request lifecycle. |
| Conformance | `tests/Conformance` | The PHP engine's output against goldens generated from the official CLI. |
| Security | `tests/Security` | One test class per threat in the threat model. |
| Benchmark | `tests/Benchmark` | Performance budgets. Runs on 8.3 with opcache; not part of `composer test`. |

`composer test` runs the first four. `composer bench` runs the last.

Conventions, all of which exist so the suite passes on PHPUnit 9.6, 10 and 11 alike:

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
