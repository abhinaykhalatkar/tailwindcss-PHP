<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Theme;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\Str;

/**
 * Reads one `@theme { … }` rule into variables, keyframes and flags
 * (design doc 07 §Phase 2).
 *
 * It works on the AST the directive processor hands over, never on CSS text, and
 * it stores values exactly as written — nothing is evaluated (T-15). What a
 * `@theme` block may hold:
 *
 * - custom-property declarations, `--color-brand: #f00`;
 * - the wildcard reset `--color-*: initial`, kept verbatim for the registry;
 * - `@keyframes name { … }`, recorded by name.
 *
 * Anything else is `E_THEME_INVALID` and skipped, and the rest of the block is
 * still read. Diagnostics name the variable or option and never a path (T-06).
 */
final class ThemeParser
{
    /**
     * Longest variable name or keyframes name echoed back in a diagnostic.
     */
    private const MAX_ECHO_BYTES = 80;

    /**
     * What a `@keyframes` name may be made of. Quotes and spaces are not allowed
     * here: Tailwind names animations with plain identifiers.
     */
    private const IDENTIFIER_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * Read an `@theme` rule.
     *
     * The result is the `ThemeBlock` of the plan: plain arrays, in source order.
     *
     * @return array{vars: array<string, string>, keyframes: array<string, AtRule>, flags: ThemeFlags}
     */
    public static function parse(AtRule $theme, DiagnosticBag $diagnostics): array
    {
        $flags = ThemeFlags::parse($theme->params, $diagnostics);
        $vars = [];
        $keyframes = [];

        foreach ($theme->nodes ?? [] as $node) {
            if ($node instanceof Comment) {
                continue;
            }

            if ($node instanceof Declaration) {
                self::variable($node, $vars, $diagnostics);

                continue;
            }

            if ($node instanceof AtRule && \strtolower($node->name) === 'keyframes') {
                self::keyframes($node, $keyframes, $diagnostics);

                continue;
            }

            self::invalid(
                $node,
                $diagnostics,
                'Only custom properties and @keyframes may appear inside @theme.'
            );
        }

        return ['vars' => $vars, 'keyframes' => $keyframes, 'flags' => $flags];
    }

    /**
     * @param array<string, string> $vars
     */
    private static function variable(Declaration $declaration, array &$vars, DiagnosticBag $diagnostics): void
    {
        $name = $declaration->property;
        $value = \trim($declaration->value);

        if (\strncmp($name, '--', 2) !== 0 || \strlen($name) < 3) {
            self::invalid(
                $declaration,
                $diagnostics,
                \sprintf(
                    'Only custom properties (--name) may be declared inside @theme; found "%s".',
                    Str::truncateBytes($name, self::MAX_ECHO_BYTES)
                )
            );

            return;
        }

        if ($declaration->important) {
            self::invalid(
                $declaration,
                $diagnostics,
                \sprintf(
                    'The theme variable "%s" cannot be !important.',
                    Str::truncateBytes($name, self::MAX_ECHO_BYTES)
                )
            );

            return;
        }

        if (ThemeRegistry::isWildcard($name) && $value !== 'initial') {
            self::invalid(
                $declaration,
                $diagnostics,
                \sprintf(
                    'The namespace wildcard "%s" can only be set to initial.',
                    Str::truncateBytes($name, self::MAX_ECHO_BYTES)
                )
            );

            return;
        }

        $vars[$name] = $value;
    }

    /**
     * @param array<string, AtRule> $keyframes
     */
    private static function keyframes(AtRule $rule, array &$keyframes, DiagnosticBag $diagnostics): void
    {
        $name = \trim($rule->params);

        if ($rule->nodes === null || $name === '' || \strspn($name, self::IDENTIFIER_BYTES) !== \strlen($name)) {
            self::invalid(
                $rule,
                $diagnostics,
                \sprintf(
                    'A @keyframes inside @theme needs a plain name and a body; found "%s".',
                    Str::truncateBytes($name, self::MAX_ECHO_BYTES)
                )
            );

            return;
        }

        $keyframes[$name] = $rule;
    }

    private static function invalid(Node $node, DiagnosticBag $diagnostics, string $message): void
    {
        $line = $node instanceof Declaration || $node instanceof AtRule || $node instanceof Rule ? $node->line : null;

        $diagnostics->add(new Diagnostic(Diagnostic::ERROR, Diagnostic::E_THEME_INVALID, $message, null, $line));
    }
}
