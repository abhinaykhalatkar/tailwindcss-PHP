<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Variant;

use TailwindPhp\Engine\Candidate\RootSetInterface;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Css\SelectorList;

/**
 * Every variant the engine knows (design doc 07 §Phase 3c): the rows of
 * `Data\Variants::table()`, the breakpoint variants built from the theme, the
 * compound `group-*` / `peer-*` variants expanded for every pseudo-class row, and
 * the custom variants a stylesheet adds.
 *
 * **Breakpoints.** The theme's `--breakpoint-*` variables arrive as a plain
 * `name => value` array (`md => 48rem`) so this class does not depend on the theme
 * registry. Each gives `md` (`(width >= 48rem)`), `min-md` (the same) and `max-md`
 * (`(width < 48rem)`). Their order follows value, ascending for `md`, descending
 * for `max-md` (so the narrowest `max-*` comes last, as in v4); values in
 * different units keep the theme's order between units, because `40rem` and `600px`
 * cannot be compared without a font size. The arbitrary `min-[…]` / `max-[…]`
 * forms keep the order of the `min` and `max` rows, before the named ones; the
 * exact place of an arbitrary value among the named ones is the sort key's job.
 * An entry whose name is not made of letters, digits, `-` and `_`, or whose value
 * is not a safe CSS value, is skipped: it would otherwise reach a selector or a
 * media query.
 *
 * **Compound variants.** `group-hover` is the `group` template applied to the
 * `hover` row: the row's selector with `&` replaced by `:where(.group)`, put into
 * `&:is({inner} *)`; its media query, if any, is kept. Only rows with a single
 * `&:pseudo-class` selector are compoundable, which is exactly what the CLI accepts
 * (`group-before`, `group-dark` and `group-marker` are not variants). The order is
 * the template's plus the inner row's order divided by 1000.
 *
 * **Root split.** The registry is the candidate parser's variant root set: a
 * whole name that is a variant of its own is a root (`hover`, `md`); `group-hover`,
 * `peer-checked`, `max-md` and `min-lg` are not, so they split into the root
 * (`group`, `peer`, `max`, `min`) and a value, and the applier looks the whole name
 * up again.
 *
 * A bad table row throws `\InvalidArgumentException` when the registry is built.
 */
final class Registry implements RootSetInterface
{
    /**
     * Where the named `max-*` breakpoints start, and the `min-*` / plain ones.
     */
    private const MAX_NAMED_BASE = 138000;

    private const MIN_NAMED_BASE = 146000;

    /**
     * Arbitrary variants sort after every built-in one: selectors, then at-rules.
     */
    public const ARBITRARY_SELECTOR_ORDER = 171000;

    public const ARBITRARY_AT_RULE_ORDER = 174000;

    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * @var array<string, Definition> Concrete variants, in registration order.
     */
    private $definitions = [];

    /**
     * @var array<string, true> Names that are a variant root on their own.
     */
    private $exact = [];

    /**
     * @var array<string, Definition> Functional rows by root (`min`, `max`).
     */
    private $functional = [];

    /**
     * @var array<string, Definition> Compound templates by name (`group`, `peer`).
     */
    private $compounds = [];

    /**
     * @param array<array-key, array<array-key, mixed>> $table `Data\Variants::table()`.
     * @param array<string, string> $breakpoints The theme's breakpoints, `name => value`, in theme order.
     *
     * @throws \InvalidArgumentException When a table row is malformed.
     */
    public function __construct(array $table, array $breakpoints = [])
    {
        $plain = [];

        foreach ($table as $name => $row) {
            $definition = Definition::fromRow((string) $name, $row);

            if ($definition->compound() !== null && $definition->inner() === null) {
                $this->assertTemplate($definition);
                $this->compounds[$definition->compound()] = $definition;

                continue;
            }

            if ($definition->isFunctional()) {
                $this->functional[(string) $definition->functional()] = $definition;

                continue;
            }

            $plain[] = $definition;
            $this->definitions[$definition->name()] = $definition;
            $this->exact[$definition->name()] = true;
        }

        foreach ($plain as $definition) {
            $this->expand($definition);
        }

        $this->addBreakpoints($breakpoints);
    }

    public function get(string $name): ?Definition
    {
        return $this->definitions[$name] ?? null;
    }

    /**
     * @throws \InvalidArgumentException When no such variant exists.
     */
    public function order(string $name): int
    {
        $definition = $this->get($name);

        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown variant.');
        }

        return $definition->order();
    }

    /**
     * Every concrete variant by name; the functional rows and compound templates
     * are not variants of their own and are left out.
     *
     * @return array<string, Definition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * The functional row for a root such as `min`, or null.
     */
    public function functional(string $root): ?Definition
    {
        return $this->functional[$root] ?? null;
    }

    /**
     * The compound template named `group` or `peer`, or null.
     */
    public function compound(string $root): ?Definition
    {
        return $this->compounds[$root] ?? null;
    }

    /**
     * Add a variant from `@custom-variant`, replacing a built-in one of the
     * same name (`dark` is the usual case). The definition keeps its order.
     *
     * @throws \InvalidArgumentException When the name is not letters, digits, `-` and `_`.
     */
    public function addCustom(string $name, Definition $definition): void
    {
        if (!self::isName($name)) {
            throw new \InvalidArgumentException('A custom variant name may only contain letters, digits, - and _.');
        }

        $this->definitions[$name] = $definition->withNameAndOrder($name, $definition->order());
        $this->exact[$name] = true;
    }

    /**
     * An order past every existing variant, for a custom one.
     */
    public function nextOrder(): int
    {
        $highest = 0;

        foreach (\array_merge($this->definitions, $this->functional, $this->compounds) as $definition) {
            $highest = \max($highest, $definition->order());
        }

        return $highest + 1000;
    }

    /**
     * {@inheritdoc}
     */
    public function matchRoots(string $utility): array
    {
        $roots = [];

        if (isset($this->exact[$utility])) {
            $roots[] = $utility;
        }

        for ($i = \strlen($utility) - 1; $i > 0; --$i) {
            if ($utility[$i] !== '-') {
                continue;
            }

            $root = (string) \substr($utility, 0, $i);

            if (isset($this->functional[$root]) || isset($this->compounds[$root])) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    private function assertTemplate(Definition $template): void
    {
        $selectors = $template->selectors();

        if (\count($selectors) !== 1 || \strpos($selectors[0], '{inner}') === false) {
            throw new \InvalidArgumentException('A compound template needs one selector holding {inner}.');
        }
    }

    /**
     * Add `group-<name>`, `peer-<name>` for a compoundable row.
     */
    private function expand(Definition $row): void
    {
        $selectors = $row->selectors();

        if (
            $row->compound() !== null
            || $row->isFunctional()
            || \count($selectors) !== 1
            || $row->inject() !== []
            || \strncmp($selectors[0], '&:', 2) !== 0
            || \substr($selectors[0], 2, 1) === ':'
        ) {
            return;
        }

        foreach ($this->compounds as $compound => $template) {
            $inner = SelectorList::replaceAmpersand($selectors[0], ':where(.' . $compound . ')');
            $spec = [
                'compound' => $compound,
                'inner' => $selectors[0],
                'selector' => \str_replace('{inner}', $inner, $template->selectors()[0]),
                'order' => $template->order() + \intdiv($row->order(), 1000),
            ];

            if ($row->media() !== null) {
                $spec['media'] = $row->media();
            }

            $name = $compound . '-' . $row->name();
            $this->definitions[$name] = Definition::fromRow($name, $spec);
        }
    }

    /**
     * @param array<string, string> $breakpoints
     */
    private function addBreakpoints(array $breakpoints): void
    {
        $usable = [];
        $unitRank = [];

        foreach ($breakpoints as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;

            if (!self::isName($name) || \trim($value) === '' || !ArbitraryValue::isSafe($value)) {
                continue;
            }

            $length = (int) \strspn($value, '0123456789.');
            $unit = $length === 0 ? '' : \strtolower((string) \substr($value, $length));

            if (!isset($unitRank[$unit])) {
                $unitRank[$unit] = \count($unitRank);
            }

            $usable[] = [
                'name' => $name,
                'value' => $value,
                'unit' => $unitRank[$unit],
                'number' => $length === 0 ? 0.0 : (float) $value,
                'index' => \count($usable),
            ];
        }

        \usort($usable, static function (array $a, array $b): int {
            if ($a['unit'] !== $b['unit']) {
                return $a['unit'] <=> $b['unit'];
            }

            if ($a['number'] !== $b['number']) {
                return $a['number'] <=> $b['number'];
            }

            return $a['index'] <=> $b['index'];
        });

        $count = \count($usable);
        $step = \max(1, \min(10, \intdiv(999, \max(1, $count))));

        foreach ($usable as $position => $breakpoint) {
            $name = $breakpoint['name'];
            $value = $breakpoint['value'];
            $min = self::MIN_NAMED_BASE + $position * $step;
            $max = self::MAX_NAMED_BASE + ($count - 1 - $position) * $step;

            $this->addGenerated($name, ['media' => '(width >= ' . $value . ')', 'order' => $min], true);
            $this->addGenerated('min-' . $name, ['media' => '(width >= ' . $value . ')', 'order' => $min], false);
            $this->addGenerated('max-' . $name, ['media' => '(width < ' . $value . ')', 'order' => $max], false);
        }
    }

    /**
     * Add a generated variant unless a table row or earlier variant already
     * has the name: the table wins over the theme.
     *
     * @param array<string, mixed> $row
     */
    private function addGenerated(string $name, array $row, bool $exact): void
    {
        if (isset($this->definitions[$name])) {
            return;
        }

        $this->definitions[$name] = Definition::fromRow($name, $row);

        if ($exact) {
            $this->exact[$name] = true;
        }
    }

    private static function isName(string $name): bool
    {
        return $name !== '' && \strspn($name, self::NAME_BYTES) === \strlen($name);
    }
}
