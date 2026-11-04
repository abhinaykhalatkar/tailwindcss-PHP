<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\VarValue;
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
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
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

    /**
     * @var array<string, CompiledUtility|false> Utility part => result (T-05, see {@see self::compile()}).
     */
    private $bodies = [];

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
     *
     * The answer, a refusal included, is memoised by the candidate's utility
     * part, everything but its variants (kind, root, value, modifier, negative,
     * important, property): every variant prefix of one utility builds its body
     * once. The raw-text memo of {@see self::compileReadings()} is not enough
     * for that: `[&_.x1]:u0`, `[&_.x2]:u0` … are distinct texts of one utility,
     * and a body that is built and then refused by the emission guard (an
     * `@apply` chain spliced past the depth limit) was walked again for each of
     * them — 53 ms apiece, 27 minutes over a default candidate cap (T-05). The
     * same contract as the raw memo holds: one instance per compile and per theme.
     */
    public function compile(Candidate $candidate, ThemeRegistry $theme): ?CompiledUtility
    {
        $key = self::utilityKey($candidate);

        if ($key === null) {
            return $this->build($candidate, $theme);
        }

        if (isset($this->bodies[$key])) {
            return $this->bodies[$key] === false ? null : $this->bodies[$key];
        }

        $result = $this->build($candidate, $theme);
        $this->bodies[$key] = $result ?? false;

        return $result;
    }

    /**
     * The key of a candidate's utility part, or null when it cannot be told from another by its fields (a
     * value of a class this compiler does not know), in which case nothing is memoised. Every text is length
     * prefixed, so no two different parts share a key.
     */
    private static function utilityKey(Candidate $candidate): ?string
    {
        $value = $candidate->value;

        if ($value === null) {
            $valueKey = '-';
        } elseif ($value instanceof NamedValue) {
            $valueKey = 'n' . ($value->fraction ? '1' : '0') . self::part($value->name);
        } elseif ($value instanceof VarValue) {
            $valueKey = 'v' . self::part($value->name) . self::part((string) $value->dataType);
        } elseif ($value instanceof ArbitraryValue) {
            $valueKey = 'a' . self::part($value->raw) . self::part($value->normalised)
                . self::part((string) $value->dataType);
        } else {
            return null;
        }

        $modifier = $candidate->modifier === null
            ? '-'
            : self::part($candidate->modifier->kind) . self::part($candidate->modifier->value);

        return self::part($candidate->kind) . self::part((string) $candidate->root)
            . ($candidate->negative ? '1' : '0') . ($candidate->important ? '1' : '0')
            . self::part((string) $candidate->property) . $valueKey . $modifier;
    }

    private static function part(string $text): string
    {
        return \strlen($text) . ':' . $text;
    }

    private function build(Candidate $candidate, ThemeRegistry $theme): ?CompiledUtility
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
                return $this->finish(
                    $body[0],
                    $body[1],
                    // The handler's registrations first: the CLI registers in the order a
                    // utility's own AST names them (`divide-x` registers its reverse flag
                    // before the border style its row lists).
                    \array_merge($body[2] ?? [], $definition->properties()),
                    $definition->order(),
                    $candidate->important
                );
            }

            // A value one kind of the root claims is not offered to the later kinds just because a modifier
            // makes that kind refuse it: v4 picks the kind by the value's type first (`bg-[10px]/50` is a
            // background size, which takes no opacity, so it is nothing — not a colour mixed with `10px`).
            // Cost: one extra body build for every modifier-less kind the loop passes before one takes the
            // candidate (`bg-red-500/50` passes seven), only for a candidate that carries a modifier; measured
            // at about 3 % on 4 488 `bg-<colour>-<shade>/<n>` candidates.
            if (
                $candidate->modifier !== null
                && $definition->modifier() === null
                && $this->claims($definition, $candidate, $theme)
            ) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether $definition, which takes no modifier, would have taken the candidate without its modifier.
     */
    private function claims(Definition $definition, Candidate $candidate, ThemeRegistry $theme): bool
    {
        $bare = new Candidate(
            $candidate->raw,
            $candidate->variants,
            $candidate->important,
            $candidate->negative,
            $candidate->kind,
            $candidate->root,
            $candidate->value,
            null,
            $candidate->property
        );

        return $this->body($definition, $bare, $theme) !== null;
    }

    /**
     * `[nodes, usedVars]`, or `[nodes, usedVars, properties]` from a handler
     * that names `--tw-*` properties to register beyond the ones it declares.
     *
     * @return array{0: list<Node>, 1: list<string>, 2?: list<string>}|null
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
     *   colour unmixed; a theme colour that is itself `currentcolor` or one
     *   `var()` falls back to its reference, see {@see self::isUnmixable()} — and
     *   the oklab form under `@supports (color: color-mix(in lab, red, red))`.
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
            if (self::isUnmixable($variable->value(), true)) {
                // A theme colour that is itself `currentcolor` or one variable: the reference is the fallback.
                $fallback = $color[0];
            } elseif (\strncmp($opacity[0], 'var(', 4) === 0) {
                $fallback = $variable->value();
            } else {
                $fallback = 'color-mix(in srgb, ' . $variable->value() . ' ' . $opacity[0] . ', transparent)';
            }
        } elseif (self::isUnmixable($color[0])) {
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
     * Whether a colour cannot be mixed ahead of time, so an opacity on it needs a fallback line: it is
     * `currentcolor` (any case) or reads a variable. For a colour written in the candidate any `var(` counts
     * (`rgb(var(--x) 0 0)`); for the value of a theme colour ($wholeValue) only a value that is `currentcolor`
     * or starts with `var(` does: the CLI mixes `rgb(var(--x) 0 0)` ahead of time when it comes from the theme.
     */
    public static function isUnmixable(string $color, bool $wholeValue = false): bool
    {
        if (\strtolower($color) === 'currentcolor') {
            return true;
        }

        return $wholeValue ? \strncmp($color, 'var(', 4) === 0 : \strpos($color, 'var(') !== false;
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
     * `$properties` are the `--tw-*` custom properties the row or handler asks
     * to register with `@property` — exactly what the CLI registers for the
     * utility, listed explicitly: registrations cannot be derived from the
     * declarations (`outline-solid` declares `--tw-outline-style` but the CLI
     * registers nothing for it, while `outline-2` registers it). A name that is
     * not a `--tw-*` custom property is a programming error in a row or
     * handler, so it throws rather than being refused quietly.
     *
     * @param list<Node> $nodes
     * @param list<string> $used
     * @param list<string> $properties
     *
     * @throws \LogicException When a property to register is not a `--tw-*` custom property.
     */
    private function finish(
        array $nodes,
        array $used,
        array $properties,
        ?string $order,
        bool $important
    ): ?CompiledUtility {
        if (!self::emittable($nodes, 0)) {
            return null;
        }

        foreach ($properties as $property) {
            if (!Definition::isRegistrableProperty($property)) {
                throw new \LogicException(\sprintf(
                    'A utility asked to register "%s", which is not a --tw-* custom property.',
                    $property
                ));
            }
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
            \array_values(\array_unique($properties)),
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
     * `-` or `_` (a custom property), or an optional vendor `-`, an ASCII
     * letter, then ASCII letters, digits and `-` (the CLI writes `COLOR` as it
     * is written; letters only, so nothing that could end a declaration).
     */
    public static function isPropertyName(string $name): bool
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
            && \strspn($name[$start], self::LETTERS) === 1
            && \strspn($name, self::LETTERS . '0123456789-', $start) === $length - $start;
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
     * $value without a trailing `!important` (any case; CSS allows blanks
     * before the `!` and between it and the word): the flag carries it, so it
     * is never written twice.
     */
    private static function withoutImportant(string $value): string
    {
        $trimmed = \rtrim($value, " \t");

        if (\strlen($trimmed) < 9 || \strtolower((string) \substr($trimmed, -9)) !== 'important') {
            return $value;
        }

        $head = \rtrim((string) \substr($trimmed, 0, -9), " \t");

        if (\substr($head, -1) !== '!') {
            return $value;
        }

        return \rtrim((string) \substr($head, 0, -1), " \t");
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
