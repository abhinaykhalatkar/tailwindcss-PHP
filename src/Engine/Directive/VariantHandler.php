<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Variant\Definition;
use TailwindPhp\Engine\Variant\Registry;

/**
 * `@custom-variant` in its shorthand form (design doc 08 §Directives, T1):
 *
 * - `@custom-variant name (&:where(…));` → a selector variant (one or more
 *   comma-separated selectors, each with `&`);
 * - `@custom-variant name (@media …);` (also `@supports`, `@container`) → an
 *   at-rule variant.
 *
 * The inner text is checked with {@see ArbitraryValue::isSafe()} before it
 * becomes a variant (T-15) — the CSS-text check of `validate()` without its
 * candidate-only underscore decoding, which would rewrite a selector such as
 * `.my_class`. The block form `{ @slot }` is T2 (M3) and reported as
 * unsupported. A custom variant gets the next order after every existing one.
 */
final class VariantHandler
{
    /**
     * The name and definition added, or null when the directive was refused.
     *
     * @return array{0: string, 1: Definition}|null
     */
    public static function handle(AtRule $variant, Registry $registry, DiagnosticBag $bag): ?array
    {
        $params = \trim($variant->params);
        $nameLength = (int) \strspn($params, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-');
        $name = (string) \substr($params, 0, $nameLength);
        $rest = \trim((string) \substr($params, $nameLength));

        if ($variant->nodes !== null) {
            self::report(
                $variant,
                Diagnostic::E_UNSUPPORTED_DIRECTIVE,
                \sprintf(
                    'The block form of @custom-variant %s is not supported by the PHP engine yet (M3); '
                    . 'use the shorthand or the CLI driver.',
                    Processor::echo($name)
                ),
                $bag
            );

            return null;
        }

        $length = \strlen($rest);

        if ($name === '' || $length < 2 || $rest[0] !== '(' || $rest[$length - 1] !== ')') {
            $message = \sprintf('@custom-variant %s needs a (selector) or (@at-rule).', Processor::echo($params));
            self::report($variant, Diagnostic::E_CSS_SYNTAX, $message, $bag);

            return null;
        }

        $inner = \trim((string) \substr($rest, 1, $length - 2));
        $row = self::row($inner);

        if ($row === null || !ArbitraryValue::isSafe($inner)) {
            $message = \sprintf('@custom-variant %s has an invalid selector or at-rule.', Processor::echo($name));
            self::report($variant, Diagnostic::E_CSS_SYNTAX, $message, $bag);

            return null;
        }

        $row['order'] = $registry->nextOrder();

        try {
            $definition = Definition::fromRow($name, $row);
            $registry->addCustom($name, $definition);
        } catch (\InvalidArgumentException $exception) {
            $message = \sprintf('@custom-variant %s is not a valid variant.', Processor::echo($name));
            self::report($variant, Diagnostic::E_CSS_SYNTAX, $message, $bag);

            return null;
        }

        return [$name, $definition];
    }

    /**
     * The variant row for the inner text of the shorthand, or null.
     *
     * @return array<string, mixed>|null
     */
    private static function row(string $inner): ?array
    {
        if ($inner === '') {
            return null;
        }

        if ($inner[0] === '@') {
            $space = \strcspn($inner, " \t");
            $keyword = \strtolower((string) \substr($inner, 1, $space - 1));
            $query = \trim((string) \substr($inner, $space));

            // `@starting-style` has no query; the other at-rules need one.
            if ($keyword === 'starting-style') {
                return $query === '' ? ['starting-style' => true] : null;
            }

            if ($query === '' || !\in_array($keyword, ['media', 'supports', 'container'], true)) {
                return null;
            }

            return [$keyword => $query];
        }

        $selectors = [];

        foreach (self::splitTopLevel($inner) as $selector) {
            $selector = \trim($selector);

            if ($selector === '' || \strpos($selector, '&') === false) {
                return null;
            }

            $selectors[] = $selector;
        }

        return ['selector' => \count($selectors) === 1 ? $selectors[0] : $selectors];
    }

    /**
     * Split at commas outside brackets and strings.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $text): array
    {
        $parts = [];
        $depth = 0;
        $quote = '';
        $start = 0;
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $c = $text[$i];

            if ($quote !== '') {
                if ($c === '\\') {
                    ++$i;
                } elseif ($c === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                ++$depth;
            } elseif ($c === ')' || $c === ']') {
                --$depth;
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = (string) \substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $parts[] = (string) \substr($text, $start);

        return $parts;
    }

    private static function report(AtRule $variant, string $code, string $message, DiagnosticBag $bag): void
    {
        $bag->add(Processor::diagnostic($code, $message, $variant->line));
    }
}
