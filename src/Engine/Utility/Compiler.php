<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Data\PropertyOrder;
use TailwindPhp\Engine\Theme\ThemeRegistry;

/**
 * Turns a parsed candidate into the body of its utility (design doc 07 §Phase
 * 3b): the root's definitions are tried in order, each through
 * {@see ValueResolver} or its {@see Handlers} method; the first that takes
 * the candidate wins. Null means no definition does — the caller reports
 * `W_UNKNOWN_UTILITY` (with the candidate text, at most 64 bytes, T-06).
 *
 * The parser returns several interpretations of one string in try-order
 * (memory engine-candidate-contract); {@see self::compileReadings()} tries
 * them in that order and memoises the answer per candidate string for the
 * lifetime of this instance — the engine compiler (TASK-0034) creates one
 * per compile, so the memo is the per-compile cache of doc 13 #17.
 *
 * Nothing here marks theme variables used: the result lists them and the
 * caller marks them when it emits the utility.
 */
final class Compiler
{
    /**
     * Longest value, prelude or selector the emission guard accepts (64 KiB:
     * far beyond any real theme value, and a single pass of the scanner).
     */
    private const MAX_TEXT = 65536;

    /**
     * Bytes a custom property name may hold after its `--`.
     */
    private const CUSTOM_NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';

    /**
     * Bytes the emission guard's fast path accepts without the full reading.
     */
    private const PLAIN_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 %.,-+*/()#:<>=_';

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var ValueResolver
     */
    private $resolver;

    /**
     * @var Handlers
     */
    private $handlers;

    /**
     * @var array<string, CompiledUtility|false> Candidate string => result.
     */
    private $memo = [];

    public function __construct(Registry $registry, ?ValueResolver $resolver = null)
    {
        $this->registry = $registry;
        $this->resolver = $resolver ?? new ValueResolver();
        $this->handlers = new Handlers($this->resolver);
    }

    /**
     * The first interpretation of one candidate string that compiles, tried in
     * the order the candidate parser returned them; memoised by $raw.
     *
     * The memo is keyed by the raw text only, so the caller must keep to its
     * contract: one instance per compile and per theme (a second theme needs
     * a second instance), and $readings always the parser's full list for
     * $raw — a later call with the same text returns the first answer
     * whatever readings or theme it passes.
     *
     * @param list<Candidate> $readings
     */
    public function compileReadings(string $raw, array $readings, ThemeRegistry $theme): ?CompiledUtility
    {
        if (isset($this->memo[$raw])) {
            return $this->memo[$raw] === false ? null : $this->memo[$raw];
        }

        $result = null;

        foreach ($readings as $candidate) {
            $result = $this->compile($candidate, $theme);

            if ($result !== null) {
                break;
            }
        }

        $this->memo[$raw] = $result ?? false;

        return $result;
    }

    /**
     * Compile one interpretation, or null when no definition of its root
     * takes it. Variants are not applied here (TASK-0031).
     */
    public function compile(Candidate $candidate, ThemeRegistry $theme): ?CompiledUtility
    {
        if ($candidate->kind === Candidate::ARBITRARY) {
            return $this->arbitraryProperty($candidate);
        }

        if ($candidate->root === null) {
            return null;
        }

        foreach ($this->registry->definitions($candidate->root) as $definition) {
            $body = $this->body($definition, $candidate, $theme);

            if ($body !== null) {
                return $this->finish($body[0], $body[1], $definition->order(), $candidate->important);
            }
        }

        return null;
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function body(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $custom = $definition->customNodes();

        if ($custom !== null) {
            return $candidate->kind === Candidate::STATIC && !$candidate->negative && $candidate->modifier === null
                ? [$custom, []]
                : null;
        }

        $static = $definition->staticDeclarations();

        if ($static !== null) {
            if ($candidate->kind !== Candidate::STATIC || $candidate->negative || $candidate->modifier !== null) {
                return null;
            }

            $nodes = [];

            foreach ($static as $property => $value) {
                $nodes[] = new Declaration((string) $property, $value);
            }

            return [$nodes, []];
        }

        // A modifier the definition does not take rejects the reading (the
        // parser then offers the next one, e.g. `w-1/2` as a fraction).
        if ($candidate->modifier !== null && $definition->modifier() === null) {
            return null;
        }

        $handler = $definition->handler();

        if ($handler !== null) {
            return $this->handlers->run($handler, $definition, $candidate, $theme);
        }

        if ($candidate->kind === Candidate::STATIC) {
            $resolved = $this->resolver->resolveDefault($definition, $theme, $candidate->negative);
        } elseif ($candidate->value !== null) {
            $resolved = $this->resolver->resolve($definition, $candidate->value, $theme, $candidate->negative);
        } else {
            $resolved = null;
        }

        if ($resolved === null) {
            return null;
        }

        if ($candidate->modifier !== null && $definition->modifier() === 'opacity') {
            return $this->withOpacity($definition, $resolved, $candidate->modifier, $theme);
        }

        if ($candidate->modifier !== null) {
            // `line-height` modifiers belong to the fontSize handler (wave 4).
            return null;
        }

        return [self::declare($definition, $resolved[0]), $resolved[1]];
    }

    /**
     * One declaration of $value per property of the definition.
     *
     * @return list<Node>
     */
    private static function declare(Definition $definition, string $value): array
    {
        $nodes = [];

        foreach ($definition->props() as $property) {
            $nodes[] = new Declaration($property, $value);
        }

        return $nodes;
    }

    /**
     * Rule 7, opacity, in the 4.1.18 CLI's shape:
     *
     * - `100%` leaves the colour as it is;
     * - a literal colour becomes `color-mix(in oklab, <color> <pct>, transparent)`;
     * - a colour the browser may not mix (a theme variable, `currentcolor`, any
     *   `var()`) gets a fallback first — the theme variable's value mixed in
     *   sRGB (its plain value when the percentage is a variable), otherwise the
     *   colour unmixed — and the oklab form under
     *   `@supports (color: color-mix(in lab, red, red))`.
     *
     * @param array{0: string, 1: list<string>} $color
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function withOpacity(Definition $definition, array $color, Modifier $modifier, ThemeRegistry $theme): ?array
    {
        $opacity = $this->resolver->opacity($modifier, $theme);

        if ($opacity === null) {
            return null;
        }

        $used = \array_values(\array_unique(\array_merge($color[1], $opacity[1])));

        if ($opacity[0] === '100%') {
            return [self::declare($definition, $color[0]), $used];
        }

        $mixed = 'color-mix(in oklab, ' . $color[0] . ' ' . $opacity[0] . ', transparent)';
        $variable = \strncmp($color[0], 'var(--', 6) === 0 && $color[1] !== [] ? $theme->get($color[1][0]) : null;

        if ($variable !== null) {
            $fallback = \strncmp($opacity[0], 'var(', 4) === 0
                ? $variable->value()
                : 'color-mix(in srgb, ' . $variable->value() . ' ' . $opacity[0] . ', transparent)';
        } elseif (\strpos($color[0], 'var(') !== false || \strtolower($color[0]) === 'currentcolor') {
            $fallback = $color[0];
        } else {
            return [self::declare($definition, $mixed), $used];
        }

        $nodes = [];
        $supported = [];

        foreach ($definition->props() as $property) {
            $nodes[] = new Declaration($property, $fallback);
            $supported[] = new Declaration($property, $mixed);
        }

        $nodes[] = new AtRule('supports', '(color: color-mix(in lab, red, red))', $supported);

        return [$nodes, $used];
    }

    /**
     * `[property:value]`: the validated value under the property as written.
     */
    private function arbitraryProperty(Candidate $candidate): ?CompiledUtility
    {
        $value = $candidate->value;

        if ($candidate->property === null || !$value instanceof ArbitraryValue || $candidate->modifier !== null) {
            return null;
        }

        return $this->finish(
            [new Declaration($candidate->property, $value->normalised)],
            [],
            null,
            $candidate->important
        );
    }

    /**
     * The result, or null when the body fails the emission guard.
     *
     * The guard (T-15, defence in depth) checks what is about to be emitted,
     * whatever built it: the parser is the gate for candidate text, but the
     * value classes have public constructors, `@apply` / `@utility` build
     * candidates and nodes too, and theme values come from author CSS. Every
     * path ends here — data rows, handlers, custom utilities, arbitrary
     * properties — so this is the one place that refuses a property that is
     * not a plain name, or a value, prelude or selector that could end a
     * declaration or a rule early, open a comment or carry a control byte.
     *
     * @param list<Node> $nodes
     * @param list<string> $used
     */
    private function finish(array $nodes, array $used, ?string $order, bool $important): ?CompiledUtility
    {
        if (!self::emittable($nodes, 0)) {
            return null;
        }

        if ($important) {
            $nodes = self::important($nodes);
        }

        $sortKey = [];

        if ($order !== null) {
            $sortKey[] = (int) PropertyOrder::index($order);
        } else {
            self::collectSort($nodes, $sortKey);
            \sort($sortKey);
        }

        return new CompiledUtility(
            $nodes,
            \array_values(\array_unique($used)),
            [],
            \array_values(\array_unique($sortKey))
        );
    }

    /**
     * Whether every node of a utility body may be emitted (see {@see self::finish()}).
     *
     * @param list<Node> $nodes
     */
    private static function emittable(array $nodes, int $depth): bool
    {
        if ($depth > Parser::MAX_DEPTH) {
            return false;
        }

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                if (!self::isPropertyName($node->property) || !self::isSafeText($node->value)) {
                    return false;
                }
            } elseif ($node instanceof AtRule) {
                if (!self::isPropertyName($node->name) || ($node->params !== '' && !self::isSafeText($node->params))) {
                    return false;
                }

                if ($node->nodes !== null && !self::emittable($node->nodes, $depth + 1)) {
                    return false;
                }
            } elseif ($node instanceof Rule) {
                if (!self::isSafeText($node->selector) || !self::emittable($node->nodes, $depth + 1)) {
                    return false;
                }
            } else {
                // Comments and anything else have no place in a utility body.
                return false;
            }
        }

        return true;
    }

    /**
     * A plain property (or at-rule) name: `--` followed by letters, digits,
     * `-` or `_` (a custom property), or an optional vendor `-`, a lower-case
     * letter, then lower-case letters, digits and `-`.
     */
    private static function isPropertyName(string $name): bool
    {
        $length = \strlen($name);

        if ($length === 0 || $length > 256) {
            return false;
        }

        if (\strncmp($name, '--', 2) === 0) {
            return $length > 2
                && \strspn($name, self::CUSTOM_NAME_BYTES, 2) === $length - 2;
        }

        $start = $name[0] === '-' ? 1 : 0;

        return $start < $length
            && \strspn($name[$start], 'abcdefghijklmnopqrstuvwxyz') === 1
            && \strspn($name, 'abcdefghijklmnopqrstuvwxyz0123456789-', $start) === $length - $start;
    }

    /**
     * Text that cannot change the structure it is written into: exactly the
     * rules of {@see ArbitraryValue::isSafe()} — the same scanner, through
     * {@see ArbitraryValue::isSafeText()} — at any length up to
     * {@see self::MAX_TEXT} (theme values such as font stacks are longer than
     * a candidate may be). No byte below 0x20 and no DEL anywhere, no escape
     * that decodes to one, no `{` or `}`, no `;` or comment opener outside
     * strings, strings terminated, brackets balanced, url tokens read as CSS
     * reads them, no escaped function name, no `expression(`, no script URL.
     */
    private static function isSafeText(string $text): bool
    {
        $length = \strlen($text);

        if ($length > self::MAX_TEXT) {
            return false;
        }

        // Fast path, the common case (`calc(var(--spacing) * 4)`, `1 / 1`,
        // `(width >= 40rem)`): only bytes for which the full scanner has
        // nothing to check — no control byte, quote, backslash, bracket,
        // brace or `;` — no comment opener, no `url(`/`src(`/`expression(`
        // (the functions it reads specially) and parentheses that nest. Such
        // text passes the full scanner too, so the answer is the same.
        if (\strspn($text, self::PLAIN_BYTES) === $length && \strpos($text, '/*') === false) {
            $lower = \strtolower($text);
            $special = \strpos($lower, 'url(') !== false || \strpos($lower, 'src(') !== false
                || \strpos($lower, 'expression(') !== false;

            if (!$special && self::parenthesesNest($text)) {
                return true;
            }
        }

        return ArbitraryValue::isSafeText($text, self::MAX_TEXT);
    }

    /**
     * Whether every `(` is closed by a later `)` and no `)` comes first.
     */
    private static function parenthesesNest(string $text): bool
    {
        $depth = 0;
        $offset = 0;
        $length = \strlen($text);

        while ($offset < $length) {
            $next = \strcspn($text, '()', $offset);
            $offset += $next;

            if ($offset >= $length) {
                break;
            }

            $depth += $text[$offset] === '(' ? 1 : -1;

            if ($depth < 0) {
                return false;
            }

            ++$offset;
        }

        return $depth === 0;
    }

    /**
     * Copies of $nodes with every declaration `!important`, at any depth.
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function important(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $out[] = new Declaration(
                    $node->property,
                    self::withoutImportant($node->value),
                    true,
                    $node->line,
                    $node->col
                );
            } elseif ($node instanceof Rule) {
                $out[] = new Rule($node->selector, self::important($node->nodes), $node->line, $node->col);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $out[] = new AtRule($node->name, $node->params, self::important($node->nodes), $node->line, $node->col);
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * $value without a trailing `!important` (any case, any space before it):
     * the flag carries it, so it is never written twice.
     */
    private static function withoutImportant(string $value): string
    {
        $trimmed = \rtrim($value, " \t");
        $length = \strlen($trimmed);

        if ($length < 10 || \strtolower((string) \substr($trimmed, -10)) !== '!important') {
            return $value;
        }

        return \rtrim((string) \substr($trimmed, 0, $length - 10), " \t");
    }

    /**
     * v4 `getPropertySort`: the positions of every declared property, nested
     * blocks included; properties the order list does not have are ignored.
     *
     * @param list<Node> $nodes
     * @param list<int> $sortKey
     */
    private static function collectSort(array $nodes, array &$sortKey): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $index = PropertyOrder::index($node->property);

                if ($index !== null) {
                    $sortKey[] = $index;
                }
            } elseif (($node instanceof Rule || $node instanceof AtRule) && $node->nodes !== null) {
                self::collectSort($node->nodes, $sortKey);
            }
        }
    }
}
