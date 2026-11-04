<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\ValueInterface;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The handlers of the flexbox and grid family (`Data\Utilities\FlexboxGrid`),
 * TASK-0036:
 *
 * - `gridCols`: `grid-cols-3` → `repeat(3, minmax(0, 1fr))`, and for the
 *   `col-span` / `row-span` rows (their property is `grid-column` or
 *   `grid-row`) `col-span-3` → `span 3 / span 3`; a row's own literals
 *   (`none`, `subgrid`, `full`) and arbitrary values and variables resolve as
 *   in a data row.
 * - `content`: `content-none` → `--tw-content: none; content: none`, and an
 *   arbitrary value or variable → `--tw-content: <v>; content: var(--tw-content)`.
 *
 * Both read only the parsed value, the definition and the theme (T-15): the
 * text that reaches a declaration is a row literal, a checked integer or the
 * validated arbitrary value, never the raw candidate.
 */
final class FlexboxGrid implements FamilyHandlerInterface
{
    /**
     * @var ValueResolver
     */
    private $resolver;

    public function __construct(ValueResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function names(): array
    {
        return ['gridCols', 'content'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $value = $candidate->value;

        if ($candidate->kind !== Candidate::FUNCTIONAL || $value === null || $candidate->negative) {
            return null;
        }

        switch ($name) {
            case 'gridCols':
                return $this->gridCols($definition, $value, $theme);
            case 'content':
                return $this->content($definition, $value, $theme);
            default:
                return null;
        }
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function gridCols(Definition $definition, ValueInterface $value, ThemeRegistry $theme): ?array
    {
        $span = self::isSpan($definition);

        if ($value instanceof NamedValue) {
            // A fraction (`1/2`) is neither a literal of the row nor an integer.
            $literal = $definition->named()[$value->name] ?? null;

            // A track count is at least 1 (`grid-cols-0` is refused); a span may be 0, as the CLI has it.
            if ($literal === null && ValueResolver::isInteger($value->name) && ($span || $value->name !== '0')) {
                $literal = $span
                    ? 'span ' . $value->name . ' / span ' . $value->name
                    : 'repeat(' . $value->name . ', minmax(0, 1fr))';
            }

            return $literal === null ? null : [self::declarations($definition, $literal), []];
        }

        $resolved = $this->resolver->resolve($definition, $value, $theme, false);

        if ($resolved === null) {
            return null;
        }

        $css = $span ? 'span ' . $resolved[0] . ' / span ' . $resolved[0] : $resolved[0];

        return [self::declarations($definition, $css), $resolved[1]];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function content(Definition $definition, ValueInterface $value, ThemeRegistry $theme): ?array
    {
        if ($value instanceof NamedValue) {
            $literal = $definition->named()[$value->name] ?? null;

            // A literal (`none`) is written to both.
            return $literal === null
                ? null
                : [[new Declaration('--tw-content', $literal), new Declaration('content', $literal)], []];
        }

        if (!$value instanceof ArbitraryValue && !$value instanceof VarValue) {
            return null;
        }

        $resolved = $this->resolver->resolve($definition, $value, $theme, false);

        if ($resolved === null) {
            return null;
        }

        return [
            [new Declaration('--tw-content', $resolved[0]), new Declaration('content', 'var(--tw-content)')],
            $resolved[1],
        ];
    }

    /**
     * Whether the row writes a `span` (`col-span`, `row-span`) rather than a
     * track list: by the property it sets.
     */
    private static function isSpan(Definition $definition): bool
    {
        $props = $definition->props();

        return isset($props[0]) && ($props[0] === 'grid-column' || $props[0] === 'grid-row');
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
}
