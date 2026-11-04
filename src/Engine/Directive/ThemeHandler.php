<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeParser;
use TailwindPhp\Engine\Theme\ThemeRegistry;

/**
 * `@theme` and the `tailwindcss/theme.css` bundle (design doc 07 §Phase 2).
 *
 * A block is read with {@see ThemeParser} and merged with its flags. Before
 * the merge every value is checked (T-15, the TASK-0032 and TASK-0033
 * reviews) with the same scanner as candidate values and emitted CSS,
 * {@see ArbitraryValue::isSafeText()}, at up to {@see self::MAX_VALUE} bytes:
 * no control byte or DEL (also not after a `\`, nor as an escape that decodes
 * to one), no `{` or `}`, no `;` or comment outside strings, strings
 * terminated, brackets balanced, no script URL, no `expression(`. A value
 * that fails — such as one the parser kept after recovering from broken CSS —
 * is skipped with `W_INVALID_THEME_VALUE` and never reaches the registry, so
 * it cannot reach a selector, an `@media` prelude or the theme layer.
 *
 * Line breaks and tabs are folded to single spaces first (a value written
 * across lines is legitimate CSS; the CLI prints such values joined the same
 * way) — except after a `\`, where folding would turn the escape into an
 * escaped space and change the value's meaning: such a value is refused.
 */
final class ThemeHandler
{
    /**
     * Longest theme value accepted (the utility compiler's emission limit).
     */
    public const MAX_VALUE = 65536;

    /**
     * What a breakpoint name may hold (the variant registry's rule).
     */
    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * Merge one `@theme` block.
     */
    public static function apply(AtRule $block, ThemeRegistry $theme, DiagnosticBag $bag): void
    {
        $parsed = ThemeParser::parse($block, $bag);
        $vars = [];

        foreach ($parsed['vars'] as $name => $value) {
            $name = (string) $name;
            $usable = !self::escapesWhitespace($value);
            $value = self::fold($value);

            if (!$usable || !self::isUsable($value)) {
                $bag->add(Processor::diagnostic(
                    Diagnostic::W_INVALID_THEME_VALUE,
                    \sprintf('The value of %s cannot be used; the variable is skipped.', Processor::echo($name)),
                    $block->line
                ));

                continue;
            }

            $vars[$name] = $value;
        }

        $theme->merge($vars, $parsed['flags'], $parsed['keyframes']);
    }

    /**
     * The theme bundle: the default theme as a `default` block, with the
     * import's `theme(…)` words as further flags.
     *
     * @param list<string> $words
     */
    public static function seedDefault(ThemeRegistry $theme, array $words): void
    {
        $theme->merge(
            DefaultTheme::vars(),
            new ThemeFlags(
                \in_array('inline', $words, true),
                \in_array('static', $words, true),
                \in_array('reference', $words, true),
                true
            ),
            DefaultTheme::keyframes()
        );
    }

    /**
     * The theme's breakpoints the variant registry can use, `name => value` in
     * theme order. An unusable one (a name that is not letters, digits, `-`
     * and `_`, or a value that is empty or unsafe) is reported with
     * `W_INVALID_THEME_VALUE` here, because the registry skips it silently.
     *
     * @return array<string, string>
     */
    public static function breakpoints(ThemeRegistry $theme, DiagnosticBag $bag): array
    {
        $usable = [];

        foreach ($theme->namespaceKeys('breakpoint') as $key) {
            $value = $theme->resolve('breakpoint', $key);

            if ($value === null) {
                continue;
            }

            $name = (string) $key;
            $text = $value->value();
            $nameOk = $name !== '' && \strspn($name, self::NAME_BYTES) === \strlen($name);

            if (!$nameOk || \trim($text) === '' || !ArbitraryValue::isSafe($text)) {
                $bag->add(Processor::diagnostic(
                    Diagnostic::W_INVALID_THEME_VALUE,
                    \sprintf(
                        'The breakpoint %s cannot be used as a variant; it is skipped.',
                        Processor::echo('--breakpoint-' . $name)
                    ),
                    null
                ));

                continue;
            }

            $usable[$name] = $text;
        }

        return $usable;
    }

    /**
     * Whether a (folded) value may be merged: the shared CSS-text scanner,
     * {@see ArbitraryValue::isSafeText()}, at up to {@see self::MAX_VALUE} bytes.
     */
    public static function isUsable(string $value): bool
    {
        return \strlen($value) <= self::MAX_VALUE && ArbitraryValue::isSafeText($value, self::MAX_VALUE);
    }

    /**
     * Whether a `\` in the raw value is followed by a line break, tab or form
     * feed — an escape that folding would turn into an escaped space.
     */
    private static function escapesWhitespace(string $value): bool
    {
        $offset = 0;

        while (($slash = \strpos($value, '\\', $offset)) !== false) {
            $next = $value[$slash + 1] ?? '';

            if ($next === "\n" || $next === "\r" || $next === "\t" || $next === "\f") {
                return true;
            }

            // An escaped backslash is one unit: skip both.
            $offset = $slash + ($next === '\\' ? 2 : 1);
        }

        return false;
    }

    /**
     * Line breaks and tabs (with the spaces around them) become one space.
     */
    private static function fold(string $value): string
    {
        if (\strcspn($value, "\r\n\t") === \strlen($value)) {
            return $value;
        }

        $out = '';
        $length = \strlen($value);
        $i = 0;

        while ($i < $length) {
            $run = (int) \strspn($value, " \r\n\t", $i);

            if ($run > 0) {
                $chunk = (string) \substr($value, $i, $run);
                $out .= \strcspn($chunk, "\r\n\t") === $run ? $chunk : ' ';
                $i += $run;

                continue;
            }

            $next = (int) \strcspn($value, " \r\n\t", $i);
            $out .= (string) \substr($value, $i, $next);
            $i += $next;
        }

        return \trim($out);
    }
}
