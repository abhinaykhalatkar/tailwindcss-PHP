<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\Classifier;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\Utilities\BackgroundsBorders as Rows;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The backgrounds and borders family's handlers (`Data\Utilities\BackgroundsBorders`),
 * each reproducing the 4.1.18 CLI's shape:
 *
 * - `gradient`: `bg-linear-to-*` (an `@supports` upgrade to `in oklab`),
 *   `bg-gradient-to-*` (always `in oklab`), and the `from` / `via` / `to`
 *   colour stops (the stop list rebuilt after the colour) and positions;
 * - `border`: a side's width with its `-style: var(--tw-border-style)`, and
 *   the style keywords of `border` itself;
 * - `divide`: the same inside `:where(& > :not(:last-child))`, widths split by
 *   `--tw-divide-*-reverse`; `divideReverse` sets that variable;
 * - `outline`: width with `outline-style: var(--tw-outline-style)`, the style
 *   keywords, and `outline-offset`; `outlineHidden` adds the forced-colours
 *   fallback;
 * - `ring`: the ring and ring-offset widths as `--tw-ring-*` shadows.
 *
 * A bare width is a canonical integer in `px` (`border-2` → `2px`, the rows'
 * `bare: pixel`). Every value comes from {@see ValueResolver} with the row's
 * flags or is a row literal, so only checked numbers, row literals, theme
 * references and validated arbitrary text reach the declarations (T-15).
 */
final class BackgroundsBorders implements FamilyHandlerInterface
{
    private const DIVIDE_SELECTOR = ':where(& > :not(:last-child))';

    private const STOPS = 'var(--tw-gradient-via-stops, var(--tw-gradient-position), '
        . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
        . 'var(--tw-gradient-to) var(--tw-gradient-to-position))';

    private const VIA_STOPS = 'var(--tw-gradient-position), '
        . 'var(--tw-gradient-from) var(--tw-gradient-from-position), '
        . 'var(--tw-gradient-via) var(--tw-gradient-via-position), '
        . 'var(--tw-gradient-to) var(--tw-gradient-to-position)';

    private const RING_BOX_SHADOW = 'var(--tw-inset-shadow), var(--tw-inset-ring-shadow), '
        . 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow)';

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
        return ['gradient', 'border', 'divide', 'divideReverse', 'outline', 'outlineHidden', 'ring'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        switch ($name) {
            case 'gradient':
                return $this->gradient($definition, $candidate, $theme);
            case 'border':
                return $this->border($definition, $candidate, $theme);
            case 'divide':
                return $this->divide($definition, $candidate, $theme);
            case 'divideReverse':
                return self::divideReverse($definition, $candidate);
            case 'outline':
                return $this->outline($definition, $candidate, $theme);
            case 'outlineHidden':
                return self::outlineHidden($candidate);
            case 'ring':
                return $this->ring($definition, $candidate, $theme);
            default:
                return null;
        }
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function gradient(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $root = $definition->root();

        if ($root === 'bg-linear-to' || $root === 'bg-gradient-to') {
            $direction = self::keyword($definition, $candidate);

            if ($direction === null) {
                return null;
            }

            $image = new Declaration('background-image', 'linear-gradient(var(--tw-gradient-stops))');

            if ($root === 'bg-gradient-to') {
                return [[new Declaration('--tw-gradient-position', $direction . ' in oklab'), $image], []];
            }

            return [[
                new Declaration('--tw-gradient-position', $direction),
                new AtRule('supports', '(background-image: linear-gradient(in lab, red, red))', [
                    new Declaration('--tw-gradient-position', $direction . ' in oklab'),
                ]),
                $image,
            ], []];
        }

        // A colour stop takes an opacity modifier; a position does not. The
        // last colour (no theme) takes what no position type claims.
        if ($definition->modifier() === 'opacity') {
            if ($definition->theme() === [] && self::typedAs($candidate, ['length', 'percentage'])) {
                return null;
            }

            $color = $this->color($definition, $candidate, $theme, ['--tw-gradient-' . $root]);

            if ($color === null) {
                return null;
            }

            [$nodes, $used] = $color;

            if ($root === 'via') {
                $nodes[] = new Declaration('--tw-gradient-via-stops', self::VIA_STOPS);
                $nodes[] = new Declaration('--tw-gradient-stops', 'var(--tw-gradient-via-stops)');
            } else {
                $nodes[] = new Declaration('--tw-gradient-stops', self::STOPS);
            }

            return [$nodes, $used];
        }

        $position = $this->stopPosition($definition, $candidate, $theme);

        return $position === null
            ? null
            : [[new Declaration('--tw-gradient-' . $root . '-position', $position[0])], $position[1]];
    }

    /**
     * A stop position: `N%` for a canonical integer N (`from-10%`), or a
     * validated arbitrary length or percentage.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function stopPosition(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $value = $candidate->value;

        if ($value === null || !self::takesValue($candidate)) {
            return null;
        }

        if ($value instanceof NamedValue) {
            $number = (string) \substr($value->name, 0, -1);

            return !$value->fraction && \substr($value->name, -1) === '%' && ValueResolver::isInteger($number)
                ? [$value->name, []]
                : null;
        }

        return $this->resolver->resolve($definition, $value, $theme, false);
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function border(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $prefix = Rows::BORDER_SIDES[(string) \substr($definition->root(), 7)] ?? null;

        if ($prefix === null) {
            return null;
        }

        if ($definition->named() !== []) {
            $style = self::keyword($definition, $candidate);

            return $style === null ? null : [[
                new Declaration('--tw-border-style', $style),
                new Declaration('border-style', $style),
            ], []];
        }

        if ($definition->modifier() === 'opacity') {
            return self::typedAs($candidate, ['line-width', 'length', 'number'])
                ? null
                : $this->color($definition, $candidate, $theme, [$prefix . '-color']);
        }

        $width = $this->width($definition, $candidate, $theme);

        return $width === null ? null : [[
            new Declaration($prefix . '-style', 'var(--tw-border-style)'),
            new Declaration($prefix . '-width', $width[0]),
        ], $width[1]];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function divide(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $root = $definition->root();

        if ($root === 'divide-x' || $root === 'divide-y') {
            $width = $this->width($definition, $candidate, $theme);

            if ($width === null) {
                return null;
            }

            $axis = (string) \substr($root, -1);
            $reverse = 'var(--tw-divide-' . $axis . '-reverse)';
            $nodes = [new Declaration('--tw-divide-' . $axis . '-reverse', '0')];

            if ($axis === 'x') {
                $nodes[] = new Declaration('border-inline-style', 'var(--tw-border-style)');
                $start = 'border-inline-start-width';
                $end = 'border-inline-end-width';
            } else {
                $nodes[] = new Declaration('border-bottom-style', 'var(--tw-border-style)');
                $nodes[] = new Declaration('border-top-style', 'var(--tw-border-style)');
                $start = 'border-top-width';
                $end = 'border-bottom-width';
            }

            $nodes[] = new Declaration($start, 'calc(' . $width[0] . ' * ' . $reverse . ')');
            $nodes[] = new Declaration($end, 'calc(' . $width[0] . ' * calc(1 - ' . $reverse . '))');

            return [[new Rule(self::DIVIDE_SELECTOR, $nodes)], $width[1]];
        }

        if ($definition->named() !== []) {
            $style = self::keyword($definition, $candidate);

            return $style === null ? null : [[new Rule(self::DIVIDE_SELECTOR, [
                new Declaration('--tw-border-style', $style),
                new Declaration('border-style', $style),
            ])], []];
        }

        $color = $this->color($definition, $candidate, $theme, ['border-color']);

        return $color === null ? null : [[new Rule(self::DIVIDE_SELECTOR, $color[0])], $color[1]];
    }

    /**
     * `divide-x-reverse` / `divide-y-reverse`.
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private static function divideReverse(Definition $definition, Candidate $candidate): ?array
    {
        if (!self::isPlain($candidate)) {
            return null;
        }

        $axis = $definition->root() === 'divide-y-reverse' ? 'y' : 'x';

        return [[new Rule(self::DIVIDE_SELECTOR, [new Declaration('--tw-divide-' . $axis . '-reverse', '1')])], []];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function outline(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        if ($definition->root() === 'outline-offset') {
            $offset = $this->width($definition, $candidate, $theme);

            return $offset === null ? null : [[new Declaration('outline-offset', $offset[0])], $offset[1]];
        }

        if ($definition->named() !== []) {
            $style = self::keyword($definition, $candidate);

            return $style === null ? null : [[
                new Declaration('--tw-outline-style', $style),
                new Declaration('outline-style', $style),
            ], []];
        }

        if ($definition->modifier() === 'opacity') {
            return self::typedAs($candidate, ['length', 'number', 'percentage'])
                ? null
                : $this->color($definition, $candidate, $theme, ['outline-color']);
        }

        $width = $this->width($definition, $candidate, $theme);

        return $width === null ? null : [[
            new Declaration('outline-style', 'var(--tw-outline-style)'),
            new Declaration('outline-width', $width[0]),
        ], $width[1]];
    }

    /**
     * `outline-hidden`: no outline, but a transparent one in forced-colours
     * mode so the focus stays visible.
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private static function outlineHidden(Candidate $candidate): ?array
    {
        if (!self::isPlain($candidate)) {
            return null;
        }

        return [[
            new Declaration('--tw-outline-style', 'none'),
            new Declaration('outline-style', 'none'),
            new AtRule('media', '(forced-colors: active)', [
                new Declaration('outline', '2px solid transparent'),
                new Declaration('outline-offset', '2px'),
            ]),
        ], []];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function ring(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        $offset = $definition->root() === 'ring-offset';

        if ($definition->modifier() === 'opacity') {
            $property = $offset ? '--tw-ring-offset-color' : '--tw-ring-color';

            return self::typedAs($candidate, ['length'])
                ? null
                : $this->color($definition, $candidate, $theme, [$property]);
        }

        $width = $this->width($definition, $candidate, $theme);

        if ($width === null) {
            return null;
        }

        if ($offset) {
            return [[
                new Declaration('--tw-ring-offset-width', $width[0]),
                new Declaration(
                    '--tw-ring-offset-shadow',
                    'var(--tw-ring-inset,) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color)'
                ),
            ], $width[1]];
        }

        return [[
            new Declaration(
                '--tw-ring-shadow',
                'var(--tw-ring-inset,) 0 0 0 calc(' . $width[0] . ' + var(--tw-ring-offset-width)) '
                . 'var(--tw-ring-color, currentcolor)'
            ),
            new Declaration('box-shadow', self::RING_BOX_SHADOW),
        ], $width[1]];
    }

    /**
     * A width: the row's default for the root alone, otherwise the resolver's
     * value — `Npx` for a canonical integer N (the rows' `bare: pixel`), an
     * arbitrary value or a variable. A negative only where the row allows it
     * (`-outline-offset-2` → `calc(2px * -1)`).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function width(Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        // The resolver refuses a negative the row does not allow.
        if ($candidate->modifier !== null) {
            return null;
        }

        if ($candidate->kind === Candidate::STATIC) {
            return $this->resolver->resolveDefault($definition, $theme, $candidate->negative);
        }

        $value = $candidate->value;

        if ($candidate->kind !== Candidate::FUNCTIONAL || $value === null) {
            return null;
        }

        return $this->resolver->resolve($definition, $value, $theme, $candidate->negative);
    }

    /**
     * Whether the candidate's arbitrary value or variable is typed as one of
     * $types — by its hint, or (an arbitrary value without one) by inference,
     * as the CLI decides before it falls back to a colour.
     *
     * @param list<string> $types
     */
    private static function typedAs(Candidate $candidate, array $types): bool
    {
        $value = $candidate->value;

        if ($value instanceof ArbitraryValue) {
            $type = $value->dataType ?? Classifier::infer($value->normalised, $types);
        } elseif ($value instanceof VarValue) {
            $type = $value->dataType;
        } else {
            return false;
        }

        return $type !== null && \in_array($type, $types, true);
    }

    /**
     * The row literal a keyword stands for (`border-solid` → `solid`).
     */
    private static function keyword(Definition $definition, Candidate $candidate): ?string
    {
        $value = $candidate->value;

        if (!$value instanceof NamedValue || $value->fraction || !self::takesValue($candidate)) {
            return null;
        }

        return $definition->named()[$value->name] ?? null;
    }

    /**
     * A root with a value, without a negative or a modifier.
     */
    private static function takesValue(Candidate $candidate): bool
    {
        return $candidate->kind === Candidate::FUNCTIONAL && !$candidate->negative && $candidate->modifier === null;
    }

    /**
     * A colour for $props with an optional opacity modifier, as the compiler
     * writes a colour row (rule 7): the colour as is at `100%`, a literal
     * colour mixed in oklab, otherwise a fallback first — the theme value
     * mixed in sRGB (as is when the percentage is a variable), or the colour
     * unmixed — and the oklab mix under
     * `@supports (color: color-mix(in lab, red, red))`.
     *
     * @param list<string> $props
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function color(Definition $definition, Candidate $candidate, ThemeRegistry $theme, array $props): ?array
    {
        $value = $candidate->value;

        if ($candidate->kind !== Candidate::FUNCTIONAL || $value === null || $candidate->negative) {
            return null;
        }

        $color = $this->resolver->resolve($definition, $value, $theme, false);

        if ($color === null) {
            return null;
        }

        if (!$candidate->modifier instanceof Modifier) {
            return [self::declare($props, $color[0]), $color[1]];
        }

        $opacity = $this->resolver->opacity($candidate->modifier, $theme);

        if ($opacity === null) {
            return null;
        }

        $used = \array_values(\array_unique(\array_merge($color[1], $opacity[1])));

        if ($opacity[0] === '100%') {
            return [self::declare($props, $color[0]), $used];
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
            return [self::declare($props, $mixed), $used];
        }

        $nodes = self::declare($props, $fallback);
        $nodes[] = new AtRule('supports', '(color: color-mix(in lab, red, red))', self::declare($props, $mixed));

        return [$nodes, $used];
    }

    /**
     * @param list<string> $props
     *
     * @return list<Node>
     */
    private static function declare(array $props, string $value): array
    {
        $nodes = [];

        foreach ($props as $property) {
            $nodes[] = new Declaration($property, $value);
        }

        return $nodes;
    }

    /**
     * A root used alone, without a negative or a modifier.
     */
    private static function isPlain(Candidate $candidate): bool
    {
        return $candidate->kind === Candidate::STATIC && !$candidate->negative && $candidate->modifier === null;
    }
}
