<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The handlers of the tables, transitions and transforms family
 * (`Data\Utilities\TablesTransitionsTransforms`), TASK-0039:
 *
 * - `transform`: the utilities that set one `--tw-*` variable per axis and
 *   compose them into a property — `translate`, `scale` and `border-spacing`
 *   with their `-x` / `-y` forms (the vocabulary has no name of its own for
 *   the axis pairs, so they share this one). `translate-4` sets both axes,
 *   `translate-x-4` one; the property is always `var(x) var(y)`. `none` is
 *   the property alone. `scale-[0.5]` and `scale-(--s)` skip the variables
 *   (`scale: 0.5`), as the CLI does. The CLI registers the variables of the
 *   pair with `@property` whichever axis is used, and so does the third
 *   element returned here.
 * - `transition`: the property list (the default one for `transition`, a
 *   named list, an arbitrary value or a variable), then the timing function
 *   and duration read from `--tw-ease` / `--tw-duration`; `none` is the
 *   property alone; `discrete` and `normal` set `transition-behavior`.
 *
 * Both read only the parsed value, the definition and the theme (T-15): the
 * text that reaches a declaration is a row literal, a checked number, a
 * theme value or the validated arbitrary value, never the raw candidate.
 */
final class TablesTransitionsTransforms implements FamilyHandlerInterface
{
    /**
     * How a value reaches the property: through the axis variables (never directly), directly when it is
     * not a named one (`scale-[0.5]`, `scale-(--s)`), or always (`rotate`, which has no variables).
     */
    private const NEVER = 0;

    private const UNNAMED = 1;

    private const ALWAYS = 2;

    /**
     * Root => [composed property, variable prefix, axes it sets, axes the property composes, axes the CLI
     * registers, how a value reaches the property].
     */
    private const SHAPES = [
        'translate' => ['translate', '--tw-translate-', ['x', 'y'], ['x', 'y'], ['x', 'y', 'z'], self::NEVER],
        'translate-x' => ['translate', '--tw-translate-', ['x'], ['x', 'y'], ['x', 'y', 'z'], self::NEVER],
        'translate-y' => ['translate', '--tw-translate-', ['y'], ['x', 'y'], ['x', 'y', 'z'], self::NEVER],
        'scale' => ['scale', '--tw-scale-', ['x', 'y', 'z'], ['x', 'y'], ['x', 'y', 'z'], self::UNNAMED],
        'scale-x' => ['scale', '--tw-scale-', ['x'], ['x', 'y'], ['x', 'y', 'z'], self::NEVER],
        'scale-y' => ['scale', '--tw-scale-', ['y'], ['x', 'y'], ['x', 'y', 'z'], self::NEVER],
        'rotate' => ['rotate', '', [], [], [], self::ALWAYS],
        'border-spacing' => [
            'border-spacing', '--tw-border-spacing-', ['x', 'y'], ['x', 'y'], ['x', 'y'], self::NEVER,
        ],
        'border-spacing-x' => [
            'border-spacing', '--tw-border-spacing-', ['x'], ['x', 'y'], ['x', 'y'], self::NEVER,
        ],
        'border-spacing-y' => [
            'border-spacing', '--tw-border-spacing-', ['y'], ['x', 'y'], ['x', 'y'], self::NEVER,
        ],
    ];

    private const TIMING = 'var(--tw-ease, var(--default-transition-timing-function))';

    private const DURATION = 'var(--tw-duration, var(--default-transition-duration))';

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
        return ['transform', 'transition'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        switch ($name) {
            case 'transform':
                return $this->transform($definition, $candidate, $theme);
            case 'transition':
                return $this->transition($definition, $candidate, $theme);
            default:
                return null;
        }
    }

    /**
     * @return array{0: list<Node>, 1: list<string>, 2?: list<string>}|null
     */
    private function transform(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $shape = $candidate->root === null ? null : (self::SHAPES[$candidate->root] ?? null);
        $value = $candidate->value;

        if ($shape === null || $candidate->kind !== Candidate::FUNCTIONAL || $value === null) {
            return null;
        }

        [$property, $prefix, $sets, $composed, $registered, $direct] = $shape;

        if ($value instanceof NamedValue && !$value->fraction && $value->name === 'none') {
            return isset($definition->named()['none']) && !$candidate->negative
                ? [[new Declaration($property, 'none')], []]
                : null;
        }

        // Angles and percentages are whole numbers here (`scale-1.5`, `rotate-1.5` are refused), unlike the
        // `opacity` percentage; the shared bare kinds accept decimals, so the handler narrows them.
        $bare = $definition->bare();

        if (
            $value instanceof NamedValue
            && ($bare === 'percentage' || $bare === 'angle')
            && !ValueResolver::isInteger($value->name)
        ) {
            return null;
        }

        $resolved = $this->resolver->resolve($definition, $value, $theme, $candidate->negative);

        if ($resolved === null) {
            return null;
        }

        if ($direct === self::ALWAYS || ($direct === self::UNNAMED && !$value instanceof NamedValue)) {
            return [[new Declaration($property, $resolved[0])], $resolved[1]];
        }

        $nodes = [];

        foreach ($sets as $axis) {
            $nodes[] = new Declaration($prefix . $axis, $resolved[0]);
        }

        $parts = [];

        foreach ($composed as $axis) {
            $parts[] = 'var(' . $prefix . $axis . ')';
        }

        $nodes[] = new Declaration($property, \implode(' ', $parts));

        $properties = [];

        foreach ($registered as $axis) {
            $properties[] = $prefix . $axis;
        }

        return [$nodes, $resolved[1], $properties];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function transition(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        if ($candidate->negative) {
            return null;
        }

        $value = $candidate->value;
        $used = [];

        if ($candidate->kind === Candidate::STATIC) {
            $list = $definition->defaultValue();
        } elseif ($candidate->kind !== Candidate::FUNCTIONAL || $value === null) {
            return null;
        } elseif ($value instanceof NamedValue) {
            $list = $value->fraction ? null : ($definition->named()[$value->name] ?? null);

            if ($value->name === 'none' && $list !== null) {
                return [[new Declaration('transition-property', 'none')], []];
            }

            if ($value->name === 'discrete' || $value->name === 'normal') {
                return $list === null ? null : [[new Declaration('transition-behavior', $list)], []];
            }
        } else {
            $resolved = $this->resolver->resolve($definition, $value, $theme, false);
            $list = $resolved === null ? null : $resolved[0];
        }

        if ($list === null) {
            return null;
        }

        foreach (['--default-transition-timing-function', '--default-transition-duration'] as $variable) {
            if ($theme->get($variable) !== null) {
                $used[] = $variable;
            }
        }

        return [
            [
                new Declaration('transition-property', $list),
                new Declaration('transition-timing-function', self::TIMING),
                new Declaration('transition-duration', self::DURATION),
            ],
            $used,
        ];
    }
}
