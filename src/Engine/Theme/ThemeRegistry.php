<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Theme;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Data\ThemeNamespaces;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The theme: every variable the stylesheet and the defaults declare, and which
 * of them the output needs (design doc 07 §Phase 2, doc 13 #20).
 *
 * **Names.** A theme variable is a CSS custom property whose name starts with a
 * namespace: `--color-red-500` is key `red-500` in namespace `color`. Callers
 * pass the namespace without dashes (`'color'`). The one variable with no key is
 * the namespace itself, so `resolve('spacing', '')` is `--spacing`. A
 * *companion* is a variable that hangs off another by a second `--`:
 * `--text-xl--line-height` is `companion('text', 'xl', 'line-height')`.
 *
 * **Merging.** `merge()` applies one `@theme` block at a time. Later definitions
 * win and keep their original position. A `default` block never overrides a
 * non-default definition, whichever came first. The value `initial` removes a
 * variable, and `--<ns>-*: initial` removes a namespace — except for the
 * sub-namespaces Tailwind protects (`--font-*` leaves `--font-weight-*`,
 * `--inset-*` leaves `--inset-shadow-*`, `--text-*` leaves `--text-shadow-*` and
 * its siblings), which the pinned 4.1.18 CLI was checked to do. The lookups
 * ({@see self::has()}, {@see self::resolve()}, {@see self::companion()},
 * {@see self::namespaceKeys()}) skip those same sub-namespaces
 * ({@see ThemeNamespaces}, v4's `ignoredThemeKeyMap`): `resolve('font',
 * 'weight-bold')` is null, `resolve('font-weight', 'bold')` is the variable.
 *
 * **What is emitted** ({@see self::emittable()}), as verified against the
 * `theme-flags` golden of the pinned CLI:
 *
 * - a variable that is `static`, or that a utility marked used, is emitted;
 * - a `reference` variable is never emitted, however it is used;
 * - an `inline` variable is not special here — a utility that substitutes its
 *   value simply never marks it used, so it is not emitted unless it is also
 *   `static` or another emitted variable's value refers to it;
 * - variables referenced by `var(--x)` inside an emitted value are emitted too,
 *   transitively (that is how `--font-sans` follows `--default-font-family`);
 * - emission order is definition order, never the order variables were used.
 *
 * Storage is one flat array, `name => [value, bits]`, with a single `isset()` per
 * lookup; no object exists for a variable until `resolve()` returns one. Pure:
 * no I/O, no clock, no randomness. Values are stored verbatim and never
 * evaluated (T-15).
 */
final class ThemeRegistry
{
    private const INLINE = 1;
    private const REFERENCE = 2;
    private const DEFAULT_BLOCK = 4;
    private const STATIC_BLOCK = 8;

    /**
     * Sub-namespaces that survive a wildcard reset of their parent, as in
     * Tailwind v4's `theme.ts` and confirmed against the pinned CLI.
     *
     * @var array<string, list<string>>
     */
    private const KEPT_ON_RESET = [
        '--font' => ['--font-weight', '--font-size'],
        '--inset' => ['--inset-shadow', '--inset-ring'],
        '--text' => [
            '--text-color',
            '--text-decoration-color',
            '--text-decoration-thickness',
            '--text-indent',
            '--text-shadow',
            '--text-underline-offset',
        ],
    ];

    /**
     * The two default variables v4 writes as `--theme(--x, initial)`.
     *
     * @var array<string, true>
     */
    private const UNSET_DEFAULTS = ['--default-font-family' => true, '--default-mono-font-family' => true];

    private const SPACE = " \t\r\n\f";

    /**
     * @var array<string, array{0: string, 1: int}>
     */
    private $vars = [];

    /**
     * @var array<string, true>
     */
    private $used = [];

    /**
     * @var array<string, array{0: AtRule|string, 1: bool}>
     */
    private $keyframes = [];

    /**
     * @var bool
     */
    private $static = false;

    /**
     * @var array<string, list<string>>
     */
    private $keys = [];

    /**
     * A registry seeded with the default theme (what `@import "tailwindcss"` and
     * `theme.css` bring in), flagged `default` so any authored `@theme` wins.
     *
     * @param array<string, string> $vars
     * @param array<string, AtRule|string> $keyframes Whole `@keyframes` blocks;
     *                                                strings are parsed on first use.
     */
    public static function fromDefault(array $vars, array $keyframes): self
    {
        $registry = new self();

        $registry->merge($vars, new ThemeFlags(false, false, false, true), $keyframes);

        return $registry;
    }

    /**
     * Whether a declared name is a namespace reset such as `--color-*`.
     */
    public static function isWildcard(string $name): bool
    {
        return $name === '--*' || (\strlen($name) > 4 && \strncmp($name, '--', 2) === 0 && \substr($name, -2) === '-*');
    }

    /**
     * Apply one `@theme` block.
     *
     * @param array<string, string> $vars Name => value, in source order.
     * @param array<string, AtRule|string> $keyframes Name => `@keyframes` block.
     */
    public function merge(array $vars, ThemeFlags $flags, array $keyframes = []): void
    {
        $this->keys = [];

        if ($flags->isStatic()) {
            $this->static = true;
        }

        $bits = ($flags->isInline() ? self::INLINE : 0)
            | ($flags->isReference() ? self::REFERENCE : 0)
            | ($flags->isDefault() ? self::DEFAULT_BLOCK : 0)
            | ($flags->isStatic() ? self::STATIC_BLOCK : 0);

        foreach ($vars as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;

            if (self::isWildcard($name)) {
                if ($value === 'initial') {
                    $this->clearNamespace($name, $flags->isDefault());
                }

                continue;
            }

            if ($value === 'initial') {
                // Removing what is not there is not an error. A default block
                // cannot remove what a non-default block defined.
                if (isset($this->vars[$name]) && (!$flags->isDefault() || $this->isDefaultVar($name))) {
                    unset($this->vars[$name]);
                }

                continue;
            }

            if ($flags->isDefault() && isset($this->vars[$name]) && !$this->isDefaultVar($name)) {
                continue;
            }

            $this->vars[$name] = [$value, $bits];
        }

        foreach ($keyframes as $name => $block) {
            $name = (string) $name;

            if ($flags->isDefault() && isset($this->keyframes[$name]) && !$this->keyframes[$name][1]) {
                continue;
            }

            $this->keyframes[$name] = [$block, $flags->isDefault()];
        }
    }

    /**
     * Whether `--<namespace>-<key>` is defined (references included).
     */
    public function has(string $namespace, string $key = ''): bool
    {
        return isset($this->vars[self::name($namespace, $key)]) && !ThemeNamespaces::isIgnored($namespace, $key);
    }

    /**
     * The variable `--<namespace>-<key>`, or `--<namespace>` for an empty key.
     */
    public function resolve(string $namespace, string $key): ?ThemeValue
    {
        return ThemeNamespaces::isIgnored($namespace, $key) ? null : $this->get(self::name($namespace, $key));
    }

    /**
     * A companion variable: `--<namespace>-<key>--<suffix>`, such as
     * `--text-xl--line-height`.
     */
    public function companion(string $namespace, string $key, string $suffix): ?ThemeValue
    {
        if (ThemeNamespaces::isIgnored($namespace, $key)) {
            return null;
        }

        return $this->get(self::name($namespace, $key) . '--' . $suffix);
    }

    /**
     * A variable by its full name.
     */
    public function get(string $name): ?ThemeValue
    {
        if (!isset($this->vars[$name])) {
            return null;
        }

        [$value, $bits] = $this->vars[$name];

        return new ThemeValue(
            $name,
            $value,
            ($bits & self::INLINE) !== 0,
            ($bits & self::REFERENCE) !== 0
        );
    }

    /**
     * The keys of a namespace in definition order: `breakpoint` gives `sm`, `md`,
     * … Companions (`xl--line-height`), the bare namespace variable and the keys
     * of an ignored sub-namespace (`font` has no `weight-bold`) are not keys.
     * Reference variables are listed, because they resolve.
     *
     * @return list<string>
     */
    public function namespaceKeys(string $namespace): array
    {
        if (isset($this->keys[$namespace])) {
            return $this->keys[$namespace];
        }

        $prefix = '--' . $namespace . '-';
        $length = \strlen($prefix);
        $keys = [];

        foreach ($this->vars as $name => $entry) {
            $name = (string) $name;

            if (\strncmp($name, $prefix, $length) !== 0 || \strlen($name) === $length) {
                continue;
            }

            $key = (string) \substr($name, $length);

            if (\strpos($key, '--') === false && !ThemeNamespaces::isIgnored($namespace, $key)) {
                $keys[] = $key;
            }
        }

        $this->keys[$namespace] = $keys;

        return $keys;
    }

    /**
     * The `@keyframes` block recorded under a name, parsed on first use when it
     * was supplied as CSS text.
     */
    public function keyframes(string $name): ?AtRule
    {
        if (!isset($this->keyframes[$name])) {
            return null;
        }

        $block = $this->keyframes[$name][0];

        if ($block instanceof AtRule) {
            return $block;
        }

        $parsed = self::parseKeyframes($block);

        if ($parsed === null) {
            unset($this->keyframes[$name]);

            return null;
        }

        $this->keyframes[$name][0] = $parsed;

        return $parsed;
    }

    /**
     * Record that the output needs a variable. Unknown names are ignored: a
     * utility may ask for a variable the theme does not have.
     */
    public function markUsed(string $name): void
    {
        if (isset($this->vars[$name])) {
            $this->used[$name] = true;
        }
    }

    /**
     * Variables marked used, in definition order.
     *
     * @return array<string, string>
     */
    public function usedVars(): array
    {
        $used = [];

        foreach ($this->vars as $name => $entry) {
            if (isset($this->used[$name])) {
                $used[(string) $name] = $entry[0];
            }
        }

        return $used;
    }

    /**
     * Every variable, in definition order.
     *
     * @return array<string, string>
     */
    public function allVars(): array
    {
        $all = [];

        foreach ($this->vars as $name => $entry) {
            $all[(string) $name] = $entry[0];
        }

        return $all;
    }

    /**
     * Whether any merged block was `static`.
     */
    public function isStatic(): bool
    {
        return $this->static;
    }

    /**
     * The variables to write into the theme layer, in definition order. See the
     * class comment for the rules.
     *
     * @return array<string, string>
     */
    public function emittable(): array
    {
        $emit = [];
        $queue = [];

        foreach ($this->vars as $name => $entry) {
            $name = (string) $name;

            if (($entry[1] & self::REFERENCE) !== 0 || $this->isUnsetDefault($name)) {
                continue;
            }

            if (($entry[1] & self::STATIC_BLOCK) !== 0 || isset($this->used[$name])) {
                $emit[$name] = true;
                $queue[] = $name;
            }
        }

        // Follow `var(--x)` references inside emitted values. Each name enters
        // the queue at most once, so a cycle (`--a: var(--b); --b: var(--a)`)
        // ends after one pass over it.
        for ($i = 0; $i < \count($queue); ++$i) {
            foreach (self::references($this->vars[$queue[$i]][0]) as $reference) {
                if (
                    isset($this->vars[$reference])
                    && !isset($emit[$reference])
                    && ($this->vars[$reference][1] & self::REFERENCE) === 0
                    && !$this->isUnsetDefault($reference)
                ) {
                    $emit[$reference] = true;
                    $queue[] = $reference;
                }
            }
        }

        $result = [];

        foreach ($this->vars as $name => $entry) {
            if (isset($emit[$name])) {
                $result[(string) $name] = $entry[0];
            }
        }

        return $result;
    }

    // ------------------------------------------------------------- internals

    private static function name(string $namespace, string $key): string
    {
        return '--' . $namespace . ($key === '' ? '' : '-' . $key);
    }

    private function isDefaultVar(string $name): bool
    {
        return isset($this->vars[$name]) && ($this->vars[$name][1] & self::DEFAULT_BLOCK) !== 0;
    }

    /**
     * A default variable whose whole value is one `var(--x)` of a variable that
     * is not defined: v4 writes those as `--theme(--x, initial)`
     * (`--default-font-family`, `--default-mono-font-family`), so once `--x` is
     * reset the variable is `initial` — not emitted, and not followed. Only
     * those two names qualify: any other variable, in a default block or not,
     * is emitted as written whatever it reads.
     */
    private function isUnsetDefault(string $name): bool
    {
        if (!isset(self::UNSET_DEFAULTS[$name]) || !$this->isDefaultVar($name)) {
            return false;
        }

        $value = \trim($this->vars[$name][0]);

        if (\strncmp($value, 'var(', 4) !== 0 || \substr($value, -1) !== ')') {
            return false;
        }

        $reference = \trim((string) \substr($value, 4, -1));

        return \strncmp($reference, '--', 2) === 0
            && \strcspn($reference, " \t\n,()") === \strlen($reference)
            && !isset($this->vars[$reference]);
    }

    /**
     * Remove a namespace for `--<ns>-*: initial`.
     *
     * Matched by prefix on the namespace without its trailing `-*`, as Tailwind
     * does. From a `default` block only default variables go.
     */
    private function clearNamespace(string $wildcard, bool $onlyDefault): void
    {
        $namespace = $wildcard === '--*' ? '' : (string) \substr($wildcard, 0, -2);
        $protected = self::KEPT_ON_RESET[$namespace] ?? [];

        foreach (\array_keys($this->vars) as $name) {
            $name = (string) $name;

            if ($namespace !== '' && \strncmp($name, $namespace, \strlen($namespace)) !== 0) {
                continue;
            }

            if ($onlyDefault && !$this->isDefaultVar($name)) {
                continue;
            }

            foreach ($protected as $keep) {
                if (\strncmp($name, $keep, \strlen($keep)) === 0) {
                    continue 2;
                }
            }

            unset($this->vars[$name]);
        }
    }

    /**
     * The custom properties a value reads through `var(--name…)`.
     *
     * @return list<string>
     */
    private static function references(string $value): array
    {
        $found = [];
        $length = \strlen($value);
        $offset = 0;

        while (($at = \strpos($value, 'var(', $offset)) !== false) {
            $i = $at + 4;

            while ($i < $length && \strpos(self::SPACE, $value[$i]) !== false) {
                ++$i;
            }

            if ($i + 1 < $length && $value[$i] === '-' && $value[$i + 1] === '-') {
                $start = $i;

                while ($i < $length && \strpos(self::SPACE . ',)', $value[$i]) === false) {
                    ++$i;
                }

                $found[] = (string) \substr($value, $start, $i - $start);
            }

            $offset = $at + 4;
        }

        return $found;
    }

    private static function parseKeyframes(string $css): ?AtRule
    {
        $diagnostics = new DiagnosticBag();
        $sheet = Parser::parse($css, $diagnostics);
        $node = $sheet->nodes === [] ? null : $sheet->nodes[0];

        // One clean `@keyframes` block or nothing: text that parses with a
        // diagnostic is not trusted to mean what it appears to.
        if (
            !$diagnostics->isEmpty()
            || \count($sheet->nodes) !== 1
            || !$node instanceof AtRule
            || \strtolower($node->name) !== 'keyframes'
        ) {
            return null;
        }

        return $node;
    }
}
