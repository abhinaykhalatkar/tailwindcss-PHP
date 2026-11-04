<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\Classifier;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\ValueInterface;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Data\Colors;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Theme\ThemeValue;

/**
 * The value resolution rules of design doc 07 §Phase 3b, in their order, for
 * one functional definition and one candidate value.
 *
 * A result is `[css, usedVars]`: the CSS value text and the theme variables it
 * reads (to be marked used only if the utility is emitted). Null means "this
 * definition does not take this value" — the compiler then tries the next one.
 *
 * Only these sources reach CSS (T-15): the definition's own `named` literals,
 * theme values, numbers this class has checked byte by byte, and the
 * `normalised` text of an {@see ArbitraryValue} / the name of a
 * {@see VarValue}, both validated by the candidate parser. Raw candidate text
 * is never read. Numbers follow v4: canonical decimal forms only (`01`, `1.0`,
 * `1e3`, `+1` are not numbers), spacing multipliers in steps of 0.25.
 */
final class ValueResolver
{
    /**
     * Longest integer part accepted, so every accepted number is exact in a
     * double (v4 compares `String(Number(v))` with `v`).
     */
    private const MAX_INTEGER_DIGITS = 15;

    /**
     * Resolve $value for $definition, negated when $negative.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function resolve(Definition $definition, ValueInterface $value, ThemeRegistry $theme, bool $negative): ?array
    {
        if ($negative && !$definition->negative()) {
            return null;
        }

        if ($value instanceof NamedValue) {
            return $this->named($definition, $value, $theme, $negative);
        }

        if ($value instanceof ArbitraryValue) {
            if (!self::typeAccepted($definition, $value->dataType, $value->normalised)) {
                return null;
            }

            return [$negative ? self::negate($value->normalised) : $value->normalised, []];
        }

        if ($value instanceof VarValue) {
            if (!self::typeAccepted($definition, $value->dataType, null)) {
                return null;
            }

            $css = 'var(' . $value->name . ')';

            return [$negative ? self::negate($css) : $css, []];
        }

        return null;
    }

    /**
     * The value a definition gives its root used alone (`rounded`, `border`):
     * resolved like a named value.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function resolveDefault(Definition $definition, ThemeRegistry $theme, bool $negative): ?array
    {
        $default = $definition->defaultValue();

        if ($default === null || ($negative && !$definition->negative())) {
            return null;
        }

        return [$negative ? self::negate($default) : $default, []];
    }

    /**
     * The percentage an `opacity` modifier stands for (rule 7), as the 4.1.18
     * CLI reads it: `/50` → `50%` (a canonical number in steps of 0.25),
     * `/[0.33]` and `/[.5]` → `33%` / `50%` (any decimal, times 100), any
     * other arbitrary text as written (`/[50%]`, even `/[red]`), `/(--o)` →
     * `var(--o)`, a theme `--opacity-<name>` → its reference. Null for a
     * named modifier that is none of these.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function opacity(Modifier $modifier, ThemeRegistry $theme): ?array
    {
        if ($modifier->kind === Modifier::VAR) {
            return ['var(' . $modifier->value . ')', []];
        }

        if ($modifier->kind === Modifier::ARBITRARY) {
            $text = $modifier->value;

            return [self::isDecimal($text) ? self::times100($text) . '%' : $text, []];
        }

        $themed = $theme->resolve('opacity', $modifier->value);

        if ($themed !== null) {
            return self::reference($themed);
        }

        return self::isNumber($modifier->value) && self::isMultipleOfQuarter($modifier->value)
            ? [$modifier->value . '%', []]
            : null;
    }

    /**
     * How a theme variable is written into a utility (memory
     * theme-emission-rule-and-resets): an inline variable's value as is, a
     * reference variable as `var(--x, <value>)`, otherwise `var(--x)`; every
     * non-inline variable is reported as used.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function reference(ThemeValue $value): array
    {
        if ($value->isInline()) {
            return [$value->value(), []];
        }

        if ($value->isReference()) {
            return ['var(' . $value->name() . ', ' . $value->value() . ')', [$value->name()]];
        }

        return ['var(' . $value->name() . ')', [$value->name()]];
    }

    /**
     * Rule 8: `calc(<value> * -1)`.
     */
    public static function negate(string $value): string
    {
        return 'calc(' . $value . ' * -1)';
    }

    /**
     * A canonical non-negative decimal: `0`, `4`, `1.5`, `0.25` — not `01`,
     * `1.0`, `.5`, `1e3`, `+1` (v4 `String(Number(v)) === v`).
     */
    public static function isNumber(string $text): bool
    {
        $length = \strlen($text);
        $integer = (int) \strspn($text, '0123456789');

        if ($integer === 0 || $integer > self::MAX_INTEGER_DIGITS || ($integer > 1 && $text[0] === '0')) {
            return false;
        }

        if ($integer === $length) {
            return true;
        }

        if ($text[$integer] !== '.') {
            return false;
        }

        $fraction = (int) \strspn($text, '0123456789', $integer + 1);

        return $fraction > 0 && $integer + 1 + $fraction === $length && $text[$length - 1] !== '0';
    }

    /**
     * A canonical non-negative integer (v4 `isPositiveInteger`).
     */
    public static function isInteger(string $text): bool
    {
        return self::isNumber($text) && \strpos($text, '.') === false;
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private function named(Definition $definition, NamedValue $value, ThemeRegistry $theme, bool $negative): ?array
    {
        $name = $value->name;

        // Rule 4 (a fraction never reads as a named or bare value).
        if ($value->fraction) {
            if (!$definition->fraction() || !self::isFraction($name)) {
                return null;
            }

            $css = 'calc(' . $name . ' * 100%)';

            return [$negative ? self::negate($css) : $css, []];
        }

        // Rule 1: a literal of the definition. A negative needs a number to
        // negate (`-inset-px` → `-1px`, `-inset-full` → `-100%`); `auto` and
        // other keywords have none (`-z-auto` is refused, as by the CLI).
        $named = $definition->named();

        if (isset($named[$name])) {
            if (!$negative) {
                return [$named[$name], []];
            }

            $literal = $named[$name];

            return $literal !== '' && \strspn($literal, '0123456789.') > 0 ? ['-' . $literal, []] : null;
        }

        // Rule 2: theme namespaces in order; colour keywords first.
        foreach ($definition->theme() as $namespace) {
            if ($namespace === 'color') {
                $special = Colors::special($name);

                if ($special !== null) {
                    return $negative ? null : [$special, []];
                }
            }

            $themed = $theme->resolve($namespace, $name);

            if ($themed !== null) {
                [$css, $used] = self::reference($themed);

                return [$negative ? self::negate($css) : $css, $used];
            }
        }

        // Rule 3: bare values.
        return $this->bare($definition->bare(), $name, $theme, $negative);
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private function bare(?string $kind, string $name, ThemeRegistry $theme, bool $negative): ?array
    {
        switch ($kind) {
            case 'spacing-multiplier':
                if (!self::isNumber($name) || !self::isMultipleOfQuarter($name)) {
                    return null;
                }

                $spacing = $theme->resolve('spacing', '');

                if ($spacing === null) {
                    return null;
                }

                [$css, $used] = self::reference($spacing);

                // v4 folds the sign into the multiplier: `* -4`, even `* -0`.
                return ['calc(' . $css . ' * ' . ($negative ? '-' : '') . $name . ')', $used];
            case 'integer':
            case 'line-count':
                return self::isInteger($name) ? self::signed($name, $negative) : null;
            case 'percentage':
                return self::isNumber($name) ? self::signed($name . '%', $negative) : null;
            case 'angle':
                return self::isNumber($name) ? self::signed($name . 'deg', $negative) : null;
            case 'duration':
                return self::isInteger($name) ? self::signed($name . 'ms', $negative) : null;
            default:
                return null;
        }
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function signed(string $css, bool $negative): array
    {
        return [$negative ? self::negate($css) : $css, []];
    }

    /**
     * Whether an arbitrary value (or a variable, $text null) of hint $hint
     * fits the definition's accepted data types.
     */
    private static function typeAccepted(Definition $definition, ?string $hint, ?string $text): bool
    {
        if (!$definition->acceptsArbitrary()) {
            return false;
        }

        $types = $definition->arbitraryTypes();

        if ($types === null) {
            // Any value: a hint is accepted and dropped (`top-[length:3px]`).
            return true;
        }

        if ($hint !== null) {
            return \in_array($hint, $types, true);
        }

        // A variable without a hint fits any type, as in v4.
        return $text === null || Classifier::infer($text, $types) !== null;
    }

    /**
     * `<integer>/<integer>`, both canonical (`1/0` is accepted, as by the
     * CLI; `01/2` and `1.5/2` are not).
     */
    private static function isFraction(string $name): bool
    {
        $slash = \strpos($name, '/');

        return $slash !== false
            && self::isInteger((string) \substr($name, 0, $slash))
            && self::isInteger((string) \substr($name, $slash + 1));
    }

    /**
     * A multiple of 0.25 (v4 spacing scale: `0.25`, `0.5`, `1.75`; not `0.3`).
     * Expects {@see self::isNumber()}.
     */
    private static function isMultipleOfQuarter(string $number): bool
    {
        $dot = \strpos($number, '.');

        if ($dot === false) {
            return true;
        }

        $fraction = (string) \substr($number, $dot + 1);

        return $fraction === '25' || $fraction === '5' || $fraction === '75';
    }

    /**
     * Digits with at most one `.` and at least one digit (`1`, `0.33`, `.5`,
     * `1.50`) — what JavaScript's `Number()` reads as a plain decimal.
     */
    private static function isDecimal(string $text): bool
    {
        $length = \strlen($text);
        $integer = (int) \strspn($text, '0123456789');

        if ($integer === $length) {
            return $length > 0 && $length <= self::MAX_INTEGER_DIGITS;
        }

        if ($text[$integer] !== '.' || $integer > self::MAX_INTEGER_DIGITS) {
            return false;
        }

        $fraction = (int) \strspn($text, '0123456789', $integer + 1);

        return $integer + 1 + $fraction === $length && $integer + $fraction > 0;
    }

    /**
     * $number × 100 as a canonical decimal, by moving the point (`0.33` →
     * `33`, `.5` → `50`, `1` → `100`, `0.125` → `12.5`). Expects
     * {@see self::isDecimal()}.
     */
    private static function times100(string $number): string
    {
        $dot = \strpos($number, '.');
        $integer = $dot === false ? $number : (string) \substr($number, 0, $dot);
        $fraction = $dot === false ? '' : (string) \substr($number, $dot + 1);
        $fraction = \str_pad($fraction, 2, '0');
        $whole = \ltrim($integer . \substr($fraction, 0, 2), '0');
        $rest = \rtrim((string) \substr($fraction, 2), '0');

        return ($whole === '' ? '0' : $whole) . ($rest === '' ? '' : '.' . $rest);
    }
}
