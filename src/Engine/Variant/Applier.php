<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Variant;

use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Variant;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\SelectorList;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\Str;

/**
 * Wraps a compiled utility's nodes in its variants (design doc 07 §Phase 3c
 * §Application).
 *
 * Variants apply **right to left**: the rightmost, innermost, first, and each
 * wraps what the one before produced, so in `md:hover:flex` the media query for
 * `md` ends up outside the `:hover` rule. A variant is a selector transform
 * (`&:hover`, `&::before`), an at-rule wrapper (`@media`, `@supports`,
 * `@container`) or both. A row with both nests the at-rule *inside* the
 * selector rule, as v4 does; `inject` declarations (`content` for `before`) go in
 * front of the nodes inside the selector rule. Every selector keeps its `&`: the
 * whole thing sits in one rule whose selector is the candidate's class selector,
 * and `Css\Nesting::flatten` substitutes it, which gives the flat selectors the
 * CLI prints (checked against every golden in `VariantGoldenTest`).
 *
 * **Arbitrary variants** are the candidate's already-validated text, checked
 * again here (`ArbitraryValue::isSafe`): a `[@media …]` / `[@supports …]` /
 * `[@container …]` / `[@starting-style]` text is an at-rule wrapper, any other
 * at-rule is refused; everything else is a selector, wrapped in `&:is(…)` when it
 * has no `&` (as v4 does for `[.x]`). `min-[…]` and `max-[…]` fill the `min` and
 * `max` row templates.
 *
 * **Compound variants** (`group-hover`, `peer-checked`, named `group-hover/name`)
 * re-run the inner variant's selector against the compound template; a name puts
 * `.group\/name` in place of `.group`. Only a named modifier is accepted.
 *
 * A candidate whose variants cannot all be applied gives null and one warning
 * that carries the variant name and nothing the author typed after it (T-06):
 * `W_UNKNOWN_VARIANT`, `W_INVALID_ARBITRARY`, or `W_CANDIDATE_LIMIT` past eight
 * variants. Linear in the number of variants times the size of the nodes.
 */
final class Applier
{
    /**
     * The most variants one candidate may carry (the candidate parser already
     * enforces it; this is a second line).
     */
    public const MAX_VARIANTS = 8;

    /**
     * The at-rules an arbitrary variant may open: grouping rules that hold style
     * rules. `@import`, `@font-face`, `@keyframes`, `@layer`, `@property` and the
     * rest are not variants.
     */
    private const AT_RULES = [
        'media' => true,
        'supports' => true,
        'container' => true,
        'starting-style' => true,
    ];

    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    private const AT_RULE_NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-';

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var DiagnosticBag
     */
    private $diagnostics;

    public function __construct(Registry $registry, DiagnosticBag $diagnostics)
    {
        $this->registry = $registry;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @param list<Node> $nodes The utility's declarations (and nested rules).
     * @param list<Variant> $variants In written order, leftmost first.
     * @param string $classSelector `.` + the escaped candidate, e.g. `.md\:hover\:flex`.
     */
    public function wrap(array $nodes, array $variants, string $classSelector): ?Wrapped
    {
        $variants = \array_values($variants);
        $count = \count($variants);

        if ($count > self::MAX_VARIANTS) {
            $this->warn(
                Diagnostic::W_CANDIDATE_LIMIT,
                'A candidate may carry at most ' . self::MAX_VARIANTS . ' variants.',
                null
            );

            return null;
        }

        $current = $nodes;
        $properties = [];
        $orders = \array_fill(0, $count, 0);

        for ($i = $count - 1; $i >= 0; --$i) {
            $step = $this->apply($variants[$i], $current);

            if ($step === null) {
                return null;
            }

            $current = $step['nodes'];
            $orders[$i] = $step['order'];

            foreach ($step['properties'] as $property) {
                $properties[$property] = true;
            }
        }

        return new Wrapped([new Rule($classSelector, $current)], \array_keys($properties), \array_values($orders));
    }

    /**
     * @param list<Node> $current
     *
     * @return array{nodes: list<Node>, properties: list<string>, order: int}|null
     */
    private function apply(Variant $variant, array $current): ?array
    {
        if ($variant->arbitrary !== null) {
            return $variant->name === ''
                ? $this->arbitraryVariant($variant, $current)
                : $this->functionalVariant($variant, $current);
        }

        $name = $variant->value === null ? $variant->name : $variant->name . '-' . $variant->value;
        $definition = $this->registry->get($name);

        if ($definition === null) {
            return $this->unknown($variant);
        }

        $modifier = $variant->modifier;

        if ($modifier !== null && ($definition->compound() === null || $modifier->kind !== Modifier::NAMED)) {
            return $this->unknown($variant);
        }

        $selectors = $definition->selectors();

        if ($definition->compound() !== null) {
            $selectors = [$this->compoundSelector($definition, $modifier)];
        }

        return $this->build(
            $current,
            $selectors,
            $definition->media(),
            $definition->supports(),
            $definition->container(),
            $definition->startingStyle(),
            $definition->inject(),
            $definition->order()
        );
    }

    /**
     * `min-[400px]`, `max-[600px]`: a functional row's template with the value in.
     *
     * @param list<Node> $current
     *
     * @return array{nodes: list<Node>, properties: list<string>, order: int}|null
     */
    private function functionalVariant(Variant $variant, array $current): ?array
    {
        $row = $this->registry->functional($variant->name);

        if ($row === null || $variant->modifier !== null) {
            return $this->unknown($variant);
        }

        $value = (string) $variant->arbitrary;

        if (\trim($value) === '' || !ArbitraryValue::isSafe($value)) {
            return $this->invalid($variant->name);
        }

        $fill = static function (?string $template) use ($value): ?string {
            return $template === null ? null : \str_replace('{value}', $value, $template);
        };
        $selectors = [];

        foreach ($row->selectors() as $selector) {
            $selectors[] = (string) $fill($selector);
        }

        return $this->build(
            $current,
            $selectors,
            $fill($row->media()),
            $fill($row->supports()),
            $fill($row->container()),
            $row->startingStyle(),
            $row->inject(),
            $row->order()
        );
    }

    /**
     * `[&>*]`, `[.parent_&]`, `[@media(…)]`.
     *
     * @param list<Node> $current
     *
     * @return array{nodes: list<Node>, properties: list<string>, order: int}|null
     */
    private function arbitraryVariant(Variant $variant, array $current): ?array
    {
        $content = \trim((string) $variant->arbitrary);

        if ($content === '' || $variant->modifier !== null || !ArbitraryValue::isSafe($content)) {
            return $this->invalid('');
        }

        if ($content[0] === '@') {
            return $this->arbitraryAtRule($content, $current);
        }

        $selector = SelectorList::containsAmpersand($content) ? $content : '&:is(' . $content . ')';

        return [
            'nodes' => [new Rule($selector, $current)],
            'properties' => [],
            'order' => Registry::ARBITRARY_SELECTOR_ORDER,
        ];
    }

    /**
     * @param list<Node> $current
     *
     * @return array{nodes: list<Node>, properties: list<string>, order: int}|null
     */
    private function arbitraryAtRule(string $content, array $current): ?array
    {
        $length = (int) \strspn($content, self::AT_RULE_NAME_BYTES, 1);
        $name = (string) \substr($content, 1, $length);
        $params = \trim((string) \substr($content, 1 + $length));

        if (
            !isset(self::AT_RULES[$name])
            || ($params === '' && $name !== 'starting-style')
            || ($params !== '' && $name === 'starting-style')
            || SelectorList::containsAmpersand($content)
        ) {
            return $this->invalid('');
        }

        return [
            'nodes' => [new AtRule($name, $params, $current)],
            'properties' => [],
            'order' => Registry::ARBITRARY_AT_RULE_ORDER,
        ];
    }

    /**
     * The selector of `group-hover` / `peer-checked`, with a name in place of
     * `.group` when the variant carries one.
     */
    private function compoundSelector(Definition $definition, ?Modifier $modifier): string
    {
        $unnamed = $definition->selectors()[0];
        $template = $this->registry->compound((string) $definition->compound());

        if ($modifier === null || $template === null || $definition->inner() === null) {
            return $unnamed;
        }

        $class = '.' . Escape::identifier($definition->compound() . '/' . $modifier->value);
        $inner = SelectorList::replaceAmpersand($definition->inner(), ':where(' . $class . ')');

        return \str_replace('{inner}', $inner, $template->selectors()[0]);
    }

    /**
     * Wrap `$current`: inject first, then the at-rules innermost to outermost
     * (`@starting-style` last of them), then one rule per selector.
     *
     * @param list<Node> $current
     * @param list<string> $selectors
     * @param array<string, string> $inject
     *
     * @return array{nodes: list<Node>, properties: list<string>, order: int}
     */
    private function build(
        array $current,
        array $selectors,
        ?string $media,
        ?string $supports,
        ?string $container,
        bool $startingStyle,
        array $inject,
        int $order
    ): array {
        $inner = $current;
        $properties = [];

        if ($inject !== []) {
            $declarations = [];

            foreach ($inject as $property => $value) {
                $declarations[] = new Declaration((string) $property, $value);

                foreach (self::tailwindProperties($value) as $name) {
                    $properties[] = $name;
                }
            }

            $inner = \array_merge($declarations, $inner);
        }

        foreach (['media' => $media, 'supports' => $supports, 'container' => $container] as $name => $params) {
            if ($params !== null) {
                $inner = [new AtRule($name, $params, $inner)];
            }
        }

        // `@starting-style` has no query; it sits at the same place in the nesting as the at-rules above.
        if ($startingStyle) {
            $inner = [new AtRule('starting-style', '', $inner)];
        }

        if ($selectors === []) {
            return ['nodes' => $inner, 'properties' => $properties, 'order' => $order];
        }

        $nodes = [];

        foreach ($selectors as $index => $selector) {
            $nodes[] = new Rule($selector, $index === 0 ? $inner : self::copy($inner));
        }

        return ['nodes' => $nodes, 'properties' => $properties, 'order' => $order];
    }

    /**
     * The `--tw-*` custom properties a declaration value reads.
     *
     * @return list<string>
     */
    private static function tailwindProperties(string $value): array
    {
        $names = [];
        $needle = 'var(--tw-';
        $offset = 0;

        while (($at = \strpos($value, $needle, $offset)) !== false) {
            $start = $at + 4;
            $length = (int) \strspn($value, self::NAME_BYTES, $start);
            $names[] = (string) \substr($value, $start, $length);
            $offset = $start + $length;
        }

        return $names;
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function copy(array $nodes): array
    {
        $copies = [];

        foreach ($nodes as $node) {
            $copies[] = self::copyNode($node);
        }

        return $copies;
    }

    private static function copyNode(Node $node): Node
    {
        if ($node instanceof Rule) {
            return new Rule($node->selector, self::copy($node->nodes), $node->line, $node->col);
        }

        if ($node instanceof AtRule) {
            return new AtRule(
                $node->name,
                $node->params,
                $node->nodes === null ? null : self::copy($node->nodes),
                $node->line,
                $node->col
            );
        }

        if ($node instanceof Declaration) {
            return new Declaration($node->property, $node->value, $node->important, $node->line, $node->col);
        }

        if ($node instanceof Comment) {
            return new Comment($node->text, $node->line, $node->col);
        }

        return $node;
    }

    /**
     * @return null
     */
    private function unknown(Variant $variant)
    {
        $name = Str::truncateBytes($variant->name === '' ? 'arbitrary' : $variant->name, 40);

        $this->warn(Diagnostic::W_UNKNOWN_VARIANT, 'Unknown variant "' . $name . '".', $name);

        return null;
    }

    /**
     * @return null
     */
    private function invalid(string $name)
    {
        $name = Str::truncateBytes($name, 40);

        $this->warn(
            Diagnostic::W_INVALID_ARBITRARY,
            $name === '' ? 'Invalid arbitrary variant.' : 'Invalid arbitrary value for variant "' . $name . '".',
            $name === '' ? null : $name
        );

        return null;
    }

    private function warn(string $code, string $message, ?string $candidate): void
    {
        $this->diagnostics->add(new Diagnostic(Diagnostic::WARNING, $code, $message, null, null, $candidate));
    }
}
