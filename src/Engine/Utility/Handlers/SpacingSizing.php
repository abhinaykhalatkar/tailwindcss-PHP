<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The handlers of the spacing and sizing family (`Data\Utilities\SpacingSizing`),
 * TASK-0036: `space` and `spaceReverse`, the utilities whose output is a nested rule;
 * `size` (TASK-0042), the `screen-<breakpoint>` names of `max-w`.
 *
 * `space-x-4` and `space-y-4` write, for the children of the element, the
 * margins of one axis scaled by `--tw-space-<axis>-reverse` (the CLI's shape,
 * so `space-x-reverse` can flip them); `space-x-reverse` alone only sets that
 * variable to 1. The value is resolved like a data row's (`calc(var(--spacing)
 * * 4)`, `1px`, an arbitrary value, a variable, negative where allowed), so
 * what reaches a declaration is a row literal, a checked number or the
 * validated arbitrary value, never the raw candidate (T-15).
 *
 * `max-w-screen-md` is `max-width: var(--breakpoint-md)`: a theme breakpoint
 * named with a `screen-` prefix, which a data row cannot say (a prefix is not
 * a theme namespace). The `size` handler, on the one sub-definition of `max-w`
 * that has it, answers only those names and leaves every other value to the
 * next sub-definition. Only `max-w` has them: the CLI refuses `w-screen-md`
 * and `min-w-screen-md`.
 */
final class SpacingSizing implements FamilyHandlerInterface
{
    private const CHILDREN = ':where(& > :not(:last-child))';

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
        return ['space', 'spaceReverse', 'size'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        if ($candidate->root === null) {
            return null;
        }

        $inline = \strncmp($candidate->root, 'space-x', 7) === 0;
        $reverse = $inline ? '--tw-space-x-reverse' : '--tw-space-y-reverse';

        switch ($name) {
            case 'space':
                return $this->space($definition, $candidate, $theme, $inline, $reverse);
            case 'spaceReverse':
                return $this->reverse($candidate, $reverse);
            case 'size':
                return $this->screen($definition, $candidate, $theme);
            default:
                return null;
        }
    }

    /**
     * `max-w-screen-<breakpoint>`: the theme breakpoint as the value
     * (`max-width: var(--breakpoint-md)`, which it reports as used). Any other
     * name, a modifier, a negative or an unknown breakpoint is not this
     * sub-definition's: null, and the next one tries.
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function screen(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $value = $candidate->value;

        if (
            $candidate->kind !== Candidate::FUNCTIONAL
            || !$value instanceof NamedValue
            || $value->fraction
            || $candidate->negative
            || \strlen($value->name) <= 7
            || \strncmp($value->name, 'screen-', 7) !== 0
        ) {
            return null;
        }

        $breakpoint = $theme->resolve('breakpoint', (string) \substr($value->name, 7));

        if ($breakpoint === null) {
            return null;
        }

        [$css, $used] = ValueResolver::reference($breakpoint);
        $nodes = [];

        foreach ($definition->props() as $property) {
            $nodes[] = new Declaration($property, $css);
        }

        return [$nodes, $used];
    }

    /**
     * `space-x-reverse` / `space-y-reverse`: the flag alone. The CLI registers
     * the flag with `@property` for the reverse utility as for the scale.
     *
     * @return array{0: list<Node>, 1: list<string>, 2: list<string>}|null
     */
    private function reverse(Candidate $candidate, string $reverse): ?array
    {
        if ($candidate->kind !== Candidate::STATIC || $candidate->negative || $candidate->modifier !== null) {
            return null;
        }

        return [[new Rule(self::CHILDREN, [new Declaration($reverse, '1')])], [], [$reverse]];
    }

    /**
     * `space-x-4` and the rest: both margins of the axis scaled by the flag,
     * which the CLI registers with `@property`.
     *
     * @return array{0: list<Node>, 1: list<string>, 2: list<string>}|null
     */
    private function space(
        Definition $definition,
        Candidate $candidate,
        ThemeRegistry $theme,
        bool $inline,
        string $reverse
    ): ?array {
        if ($candidate->kind !== Candidate::FUNCTIONAL || $candidate->value === null) {
            return null;
        }

        $resolved = $this->resolver->resolve($definition, $candidate->value, $theme, $candidate->negative);

        if ($resolved === null) {
            return null;
        }

        $sides = $inline ? ['margin-inline-start', 'margin-inline-end'] : ['margin-block-start', 'margin-block-end'];

        /** @var list<Node> $nodes */
        $nodes = [
            new Declaration($reverse, '0'),
            new Declaration($sides[0], 'calc(' . $resolved[0] . ' * var(' . $reverse . '))'),
            new Declaration($sides[1], 'calc(' . $resolved[0] . ' * calc(1 - var(' . $reverse . ')))'),
        ];

        return [[new Rule(self::CHILDREN, $nodes)], $resolved[1], [$reverse]];
    }
}
