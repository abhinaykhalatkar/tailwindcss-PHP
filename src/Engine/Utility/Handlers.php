<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Theme\ThemeRegistry;

/**
 * Utilities whose output is not "property := resolved value" (design doc 07
 * §Definition schema, `handler`). A row names a handler; the compiler calls
 * {@see self::run()} with the definition, the candidate interpretation and
 * the theme, and gets `[nodes, usedVars]` back — or null when the handler
 * does not take this candidate (the next definition is tried).
 *
 * Every handler name design doc 07 lists exists so rows can reference it; the
 * ones a later family needs are stubs returning null until that family lands
 * (TODO M2-wave4). Handlers never read raw candidate text (T-15): only the
 * parsed value objects, the definition and the theme.
 */
final class Handlers
{
    /**
     * Handler name => whether it takes a value (so its root also matches
     * `<root>-…`). The set of names a row may use.
     *
     * @var array<string, bool>
     */
    public const TAKES_VALUE = [
        'container' => false,
        'aspect' => true,
        // Stubs for the wave-4 families (TODO M2-wave4).
        'fontSize' => true,
        'space' => true,
        'divide' => true,
        'ring' => true,
        'shadow' => true,
        'gradient' => true,
        'transform' => true,
        'truncate' => false,
        'lineClamp' => true,
        'size' => true,
        'gridCols' => true,
        'content' => true,
        'transition' => true,
        'animate' => true,
        'filter' => true,
        'backdropFilter' => true,
        'outline' => true,
        'border' => true,
    ];

    /**
     * Handlers implemented so far; the others are stubs.
     *
     * @var list<string>
     */
    public const IMPLEMENTED = ['container', 'aspect'];

    /**
     * @var ValueResolver
     */
    private $resolver;

    public function __construct(ValueResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Run handler $name.
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     *
     * @throws \InvalidArgumentException When $name is not a handler.
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        switch ($name) {
            case 'container':
                return $this->container($candidate, $theme);
            case 'aspect':
                return $this->aspect($definition, $candidate, $theme);
            default:
                if (!isset(self::TAKES_VALUE[$name])) {
                    throw new \InvalidArgumentException(\sprintf('Unknown utility handler "%s".', $name));
                }

                // TODO M2-wave4: the family that needs this handler implements it.
                return null;
        }
    }

    /**
     * `container`: `width: 100%`, then one `@media (width >= <bp>) {
     * max-width: <bp> }` per theme breakpoint, smallest first — the CLI's
     * shape (memory tailwind-4-1-18-output-facts). The breakpoint values are
     * written as such, not as variables, as the CLI does.
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function container(Candidate $candidate, ThemeRegistry $theme): ?array
    {
        if ($candidate->kind !== Candidate::STATIC || $candidate->negative || $candidate->modifier !== null) {
            return null;
        }

        $breakpoints = [];

        foreach ($theme->namespaceKeys('breakpoint') as $key) {
            $value = $theme->resolve('breakpoint', $key);

            if ($value !== null) {
                $breakpoints[] = $value->value();
            }
        }

        $nodes = [new Declaration('width', '100%')];

        foreach (self::ascending($breakpoints) as $breakpoint) {
            $maxWidth = new Declaration('max-width', $breakpoint);
            $nodes[] = new AtRule('media', '(width >= ' . $breakpoint . ')', [$maxWidth]);
        }

        return [$nodes, []];
    }

    /**
     * `aspect`: a fraction is a ratio written as is (`aspect-16/9` →
     * `aspect-ratio: 16/9`, not a percentage); everything else resolves like
     * a data row (`auto`, `square`, `--aspect-*`, arbitrary).
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function aspect(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $value = $candidate->value;

        if ($candidate->kind !== Candidate::FUNCTIONAL || $value === null || $candidate->negative) {
            return null;
        }

        if ($value instanceof NamedValue && $value->fraction) {
            $slash = (int) \strpos($value->name, '/');
            $ratio = ValueResolver::isInteger((string) \substr($value->name, 0, $slash))
                && ValueResolver::isInteger((string) \substr($value->name, $slash + 1));

            return $ratio ? [self::declarations($definition, $value->name), []] : null;
        }

        $resolved = $this->resolver->resolve($definition, $value, $theme, false);

        return $resolved === null ? null : [self::declarations($definition, $resolved[0]), $resolved[1]];
    }

    /**
     * @return list<Node>
     */
    private static function declarations(Definition $definition, string $value): array
    {
        $nodes = [];

        foreach ($definition->props() as $property) {
            $nodes[] = new Declaration($property, $value);
        }

        return $nodes;
    }

    /**
     * Breakpoint values smallest first when they share a unit (v4
     * `compareBreakpoints`); values that cannot be compared keep theme order.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function ascending(array $values): array
    {
        $keyed = [];

        foreach ($values as $index => $value) {
            $digits = (int) \strspn($value, '0123456789.');
            $keyed[] = [(float) \substr($value, 0, $digits), (string) \substr($value, $digits), $index, $value];
        }

        \usort($keyed, static function (array $a, array $b): int {
            if ($a[1] === $b[1] && $a[1] !== '') {
                return $a[0] <=> $b[0] ?: $a[2] <=> $b[2];
            }

            return $a[2] <=> $b[2];
        });

        $sorted = [];

        foreach ($keyed as $entry) {
            $sorted[] = $entry[3];
        }

        return $sorted;
    }
}
