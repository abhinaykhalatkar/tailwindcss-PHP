<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate;

use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\VarValue;

/**
 * Candidate string → its interpretations (design doc 07 §Phase 3, ENG-A3,
 * ENG-A4, ENG-A13).
 *
 *     candidate := [variant ":"]* ["!"] ["-"] utility ["/" modifier] ["!"]
 *
 * {@see self::parse()} returns every interpretation the root set allows, in
 * the order the compiler must try them:
 *
 * 1. the modifier reading first (`w-1/2` → value `1`, modifier `2`), then —
 *    when both the value and the modifier are plain names — the full-value
 *    reading (`w-1/2` → value `1/2`, a fraction);
 * 2. within each reading, one candidate per matching root, longest root
 *    first (`border-t-red-500` → `border-t`+`red-500`, then
 *    `border`+`t-red-500`), each with the value taken from its own tail.
 *
 * An empty list means "not a utility"; the parser emits no diagnostics and
 * never throws (T-06) — `W_UNKNOWN_UTILITY` is the compiler's call.
 *
 * Bounds before any scanning (T-05, NFR-SEC-6): at most {@see self::MAX_LENGTH}
 * bytes, no byte below 0x20, no `;` `{` `}` or comment opener outside brackets,
 * at most {@see self::MAX_VARIANTS} variants. Every later step is one
 * left-to-right pass over the string (or a part of it); nothing rescans. No
 * regex anywhere (T-12): byte scanning only.
 *
 * `prefix` (design doc 07 §Layers, `@import "tailwindcss" prefix(tw)`):
 * Tailwind 4.1 writes the prefix as the *first variant* — `tw:hover:flex`,
 * never `hover:tw:flex`. A candidate whose first `:`-segment is not exactly the
 * prefix, or that is only the prefix, has no interpretation.
 */
final class Parser
{
    /**
     * Longest candidate considered (design doc 07 §Complexity and limits).
     */
    public const MAX_LENGTH = 256;

    /**
     * Most variants one candidate may carry.
     */
    public const MAX_VARIANTS = 8;

    /**
     * Bytes that never belong in a plain name, value or modifier: brackets,
     * parentheses, quotes and escapes only occur in the bracketed forms.
     */
    private const NOT_PLAIN = "[]()\"'\\";

    /**
     * @var RootSetInterface
     */
    private $roots;

    /**
     * @var string|null
     */
    private $prefix;

    /**
     * @var RootSetInterface|null
     */
    private $variantRoots;

    /**
     * @param string|null $prefix The `prefix(…)` of the Tailwind import, without the colon.
     * @param RootSetInterface|null $variantRoots Functional variant roots (`group`, `aria`, …)
     *                                            used to split `name-value` variants; without
     *                                            it a plain variant keeps its whole text as name.
     */
    public function __construct(
        RootSetInterface $roots,
        ?string $prefix = null,
        ?RootSetInterface $variantRoots = null
    ) {
        $this->roots = $roots;
        $this->prefix = $prefix;
        $this->variantRoots = $variantRoots;
    }

    /**
     * Every interpretation of $raw, in the order to try them.
     *
     * @return list<Candidate>
     */
    public function parse(string $raw): array
    {
        if (!self::passesBounds($raw)) {
            return [];
        }

        // Step 2: variants. segment() is depth-aware, so `[&:hover]` and
        // `[content:'a:b']` stay whole.
        $segments = self::segment($raw, ':');

        if ($this->prefix !== null) {
            if (\count($segments) < 2 || $segments[0] !== $this->prefix) {
                return [];
            }

            \array_shift($segments);
        }

        $base = (string) \array_pop($segments);

        if (\count($segments) > self::MAX_VARIANTS) {
            return [];
        }

        $variants = [];

        foreach ($segments as $text) {
            $variant = $this->variant($text);

            if ($variant === null) {
                return [];
            }

            $variants[] = $variant;
        }

        // Step 3: important, suffix (v4) or prefix (legacy) — not both.
        $important = false;

        if ($base !== '' && $base[\strlen($base) - 1] === '!') {
            $important = true;
            $base = (string) \substr($base, 0, -1);
        } elseif ($base !== '' && $base[0] === '!') {
            $important = true;
            $base = (string) \substr($base, 1);
        }

        // Step 6 (done here so the modifier split sees the utility only).
        $negative = false;

        if ($base !== '' && $base[0] === '-') {
            $negative = true;
            $base = (string) \substr($base, 1);
        }

        // Step 4: at most one depth-0 `/`.
        $parts = self::segment($base, '/');

        if (\count($parts) > 2) {
            return [];
        }

        $modifier = null;
        $utility = $parts[0];

        if (\count($parts) === 2) {
            $modifier = Modifier::parse($parts[1]);

            if ($modifier === null) {
                return [];
            }
        }

        if ($utility === '') {
            return [];
        }

        // Step 5: arbitrary property.
        if ($utility[0] === '[') {
            if ($negative) {
                return [];
            }

            $candidate = self::arbitraryProperty($raw, $variants, $important, $utility, $modifier);

            return $candidate === null ? [] : [$candidate];
        }

        // Step 7: roots — modifier reading, then the full-value reading.
        $candidates = [];
        $this->functional($raw, $variants, $important, $negative, $utility, $modifier, $candidates);

        if ($modifier !== null && $modifier->kind === Modifier::NAMED) {
            $this->named($raw, $variants, $important, $negative, $base, null, $candidates);
        }

        return $candidates;
    }

    /**
     * Split $input on $separator outside brackets, parentheses, braces and
     * quoted strings, honouring backslash escapes — a port of v4's
     * `segment()`. Always returns at least one part.
     *
     * @return list<string>
     */
    public static function segment(string $input, string $separator): array
    {
        $parts = [];
        $closers = [];
        $length = \strlen($input);
        $last = 0;
        $stops = $separator . "\\\"'([{)]}";

        for ($i = 0; $i < $length; ++$i) {
            $i += (int) \strcspn($input, $stops, $i);

            if ($i >= $length) {
                break;
            }

            $c = $input[$i];

            if ($c === $separator && $closers === []) {
                $parts[] = (string) \substr($input, $last, $i - $last);
                $last = $i + 1;

                continue;
            }

            switch ($c) {
                case '\\':
                    ++$i;

                    break;
                case '"':
                case "'":
                    while (++$i < $length) {
                        if ($input[$i] === '\\') {
                            ++$i;
                        } elseif ($input[$i] === $c) {
                            break;
                        }
                    }

                    break;
                case '(':
                    $closers[] = ')';

                    break;
                case '[':
                    $closers[] = ']';

                    break;
                case '{':
                    $closers[] = '}';

                    break;
                case ')':
                case ']':
                case '}':
                    if ($closers !== [] && $closers[\count($closers) - 1] === $c) {
                        \array_pop($closers);
                    }

                    break;
            }
        }

        $parts[] = (string) \substr($input, $last);

        return $parts;
    }

    /**
     * A name, value or modifier written without brackets: non-empty and free
     * of brackets, parentheses, quotes and backslashes.
     */
    public static function isPlainName(string $text): bool
    {
        return $text !== '' && \strcspn($text, self::NOT_PLAIN) === \strlen($text);
    }

    /**
     * Step 1 (NFR-SEC-6): length, control bytes, and `;` `{` `}` or a
     * comment opener outside brackets.
     */
    private static function passesBounds(string $raw): bool
    {
        $length = \strlen($raw);

        if ($length === 0 || $length > self::MAX_LENGTH) {
            return false;
        }

        $depth = 0;

        for ($i = 0; $i < $length; ++$i) {
            $c = $raw[$i];

            if (\ord($c) < 0x20) {
                return false;
            }

            if ($c === '[') {
                ++$depth;
            } elseif ($c === ']') {
                $depth = $depth > 0 ? $depth - 1 : 0;
            } elseif ($depth === 0) {
                if ($c === ';' || $c === '{' || $c === '}') {
                    return false;
                }

                if ($c === '/' && $i + 1 < $length && $raw[$i + 1] === '*') {
                    return false;
                }
            }
        }

        return true;
    }

    private function variant(string $raw): ?Variant
    {
        $parts = self::segment($raw, '/');

        if (\count($parts) > 2) {
            return null;
        }

        $modifier = null;
        $text = $parts[0];

        if (\count($parts) === 2) {
            $modifier = Modifier::parse($parts[1]);

            if ($modifier === null) {
                return null;
            }
        }

        if ($text === '') {
            return null;
        }

        $last = $text[\strlen($text) - 1];

        // `[…]`: an arbitrary selector or at-rule.
        if ($text[0] === '[') {
            $content = $last === ']' ? ArbitraryValue::validate((string) \substr($text, 1, -1)) : null;

            if ($content === null || \trim($content) === '') {
                return null;
            }

            // v4: an at-rule variant cannot also be a selector.
            if ($content[0] === '@' && \strpos($content, '&') !== false) {
                return null;
            }

            return new Variant('', null, $content, $raw, $modifier);
        }

        // `name-[…]`.
        if ($last === ']') {
            $open = \strpos($text, '-[');

            if ($open === false || $open === 0) {
                return null;
            }

            $content = ArbitraryValue::validate((string) \substr($text, $open + 2, -1));

            if ($content === null || \trim($content) === '') {
                return null;
            }

            return new Variant((string) \substr($text, 0, $open), null, $content, $raw, $modifier);
        }

        // `name-(--var)`.
        if ($last === ')') {
            $open = \strpos($text, '-(');

            if ($open === false || $open === 0) {
                return null;
            }

            $var = VarValue::parse((string) \substr($text, $open + 2, -1));

            if ($var === null || $var->dataType !== null) {
                return null;
            }

            return new Variant((string) \substr($text, 0, $open), null, 'var(' . $var->name . ')', $raw, $modifier);
        }

        if (!self::isPlainName($text)) {
            return null;
        }

        if ($this->variantRoots !== null) {
            foreach ($this->variantRoots->matchRoots($text) as $root) {
                $rest = (string) \substr($text, \strlen($root));

                if ($rest === '') {
                    return new Variant($root, null, null, $raw, $modifier);
                }

                if ($rest[0] === '-' && \strlen($rest) > 1) {
                    return new Variant($root, (string) \substr($rest, 1), null, $raw, $modifier);
                }
            }
        }

        return new Variant($text, null, null, $raw, $modifier);
    }

    /**
     * `[property:value]`, with the modifier already split off.
     *
     * @param list<Variant> $variants
     */
    private static function arbitraryProperty(
        string $raw,
        array $variants,
        bool $important,
        string $utility,
        ?Modifier $modifier
    ): ?Candidate {
        if ($utility[\strlen($utility) - 1] !== ']') {
            return null;
        }

        $inner = (string) \substr($utility, 1, -1);
        $colon = \strpos($inner, ':');

        if ($colon === false || $colon === 0 || $colon === \strlen($inner) - 1) {
            return null;
        }

        $property = (string) \substr($inner, 0, $colon);

        if (!self::isPropertyName($property)) {
            return null;
        }

        $valueText = (string) \substr($inner, $colon + 1);
        $normalised = ArbitraryValue::validate($valueText);

        if ($normalised === null || \trim($normalised) === '') {
            return null;
        }

        return new Candidate(
            $raw,
            $variants,
            $important,
            false,
            Candidate::ARBITRARY,
            null,
            new ArbitraryValue($valueText, $normalised),
            $modifier,
            $property
        );
    }

    /**
     * A property an arbitrary-property candidate may set: lower-case ASCII
     * letters, digits and `-`, starting with a letter after an optional
     * vendor `-` or custom-property `--`; a custom property may also use
     * upper case and `_` (names are case-sensitive there).
     */
    private static function isPropertyName(string $property): bool
    {
        $custom = \strncmp($property, '--', 2) === 0;
        $start = $custom ? 2 : ($property !== '' && $property[0] === '-' ? 1 : 0);
        $length = \strlen($property);

        if ($start >= $length) {
            return false;
        }

        $first = \ord($property[$start]);

        if (!$custom && !($first >= 97 && $first <= 122)) {
            return false;
        }

        $allowed = $custom
            ? 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_'
            : 'abcdefghijklmnopqrstuvwxyz0123456789-';

        return \strspn($property, $allowed, $start) === $length - $start;
    }

    /**
     * The modifier reading of a non-property utility: a bracketed or
     * parenthesised value with its single root, or the named roots.
     *
     * @param list<Variant> $variants
     * @param list<Candidate> $candidates
     */
    private function functional(
        string $raw,
        array $variants,
        bool $important,
        bool $negative,
        string $utility,
        ?Modifier $modifier,
        array &$candidates
    ): void {
        $last = $utility[\strlen($utility) - 1];

        if ($last !== ']' && $last !== ')') {
            $this->named($raw, $variants, $important, $negative, $utility, $modifier, $candidates);

            return;
        }

        // The root is everything before the first `-[` / `-(` (v4) and must
        // be one the root set offers for this utility.
        $open = \strpos($utility, $last === ']' ? '-[' : '-(');

        if ($open === false || $open === 0) {
            return;
        }

        $root = (string) \substr($utility, 0, $open);

        if (!\in_array($root, $this->roots->matchRoots($utility), true)) {
            return;
        }

        $inner = (string) \substr($utility, $open + 2, -1);
        $value = $last === ']' ? ArbitraryValue::parse($inner) : VarValue::parse($inner);

        if ($value !== null) {
            $candidates[] = new Candidate(
                $raw,
                $variants,
                $important,
                $negative,
                Candidate::FUNCTIONAL,
                $root,
                $value,
                $modifier
            );
        }
    }

    /**
     * One candidate per root the root set offers for $utility, longest first:
     * static for an exact match, functional with a named value otherwise.
     *
     * @param list<Variant> $variants
     * @param list<Candidate> $candidates
     */
    private function named(
        string $raw,
        array $variants,
        bool $important,
        bool $negative,
        string $utility,
        ?Modifier $modifier,
        array &$candidates
    ): void {
        foreach ($this->roots->matchRoots($utility) as $root) {
            $rest = (string) \substr($utility, \strlen($root));

            if ($rest === '') {
                $candidates[] = new Candidate(
                    $raw,
                    $variants,
                    $important,
                    $negative,
                    Candidate::STATIC,
                    $root,
                    null,
                    $modifier
                );

                continue;
            }

            if ($rest[0] !== '-' || \strncmp($utility, $root, \strlen($root)) !== 0) {
                continue;
            }

            $value = (string) \substr($rest, 1);

            // `bg-`: an empty value ends the search, as in v4.
            if ($value === '') {
                return;
            }

            if (!self::isPlainName($value)) {
                continue;
            }

            $candidates[] = new Candidate(
                $raw,
                $variants,
                $important,
                $negative,
                Candidate::FUNCTIONAL,
                $root,
                new NamedValue($value, NamedValue::isFraction($value)),
                $modifier
            );
        }
    }
}
