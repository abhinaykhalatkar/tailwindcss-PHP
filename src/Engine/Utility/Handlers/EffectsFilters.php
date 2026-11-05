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
use TailwindPhp\Engine\Data\Colors;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The handlers of the effects family (`Data\Utilities\EffectsFilters`),
 * TASK-0039: `shadow`, for the `shadow` and `inset-shadow` roots.
 *
 * One root, two meanings, as in the CLI:
 *
 * - a shadow: a theme shadow (`--shadow-md`), `none`, the default `shadow`, or
 *   an arbitrary value that is not a colour. It is written to `--tw-shadow`
 *   (`--tw-inset-shadow`, with `inset ` in front, for `inset-shadow`) with
 *   every colour in it replaced by `var(--tw-shadow-color, <colour>)`, so a
 *   later `shadow-red-500` can recolour it; `box-shadow` composes the
 *   five shadow layers. A variable is used as written. An opacity modifier
 *   becomes `--tw-shadow-alpha` and each colour `oklab(from <colour> l a b /
 *   <alpha>)`.
 * - a colour: `--tw-shadow-color` (`--tw-inset-shadow-color`) as the plain
 *   colour, then under `@supports (color: color-mix(in lab, red, red))` mixed
 *   with `--tw-shadow-alpha` in oklab. `inherit` is written alone.
 *
 * Both read only the parsed value, the modifier, the definition and the theme
 * (T-15): the text that reaches a declaration is a theme value, a row literal
 * or the validated arbitrary value, never the raw candidate. A theme shadow is
 * inlined, so it marks no variable used; a theme colour does (`--color-*`).
 */
final class EffectsFilters implements FamilyHandlerInterface
{
    private const COLOR_SUPPORT = '(color: color-mix(in lab, red, red))';

    private const LAB_SUPPORT = '(color: lab(from red l a b))';

    /**
     * Added to a level of {@see self::withAlpha()} when a colour of the list reads a variable: the literal colours of
     * that list wait for the relative-colour level as well.
     */
    private const WAIT = 4;

    /**
     * Tokens of a shadow that are neither an offset nor a colour: `inset` and the CSS-wide keywords.
     */
    private const SKIPPED = ['inset' => true, 'inherit' => true, 'initial' => true, 'revert' => true, 'unset' => true];

    private const BOX_SHADOW = 'var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), '
        . 'var(--tw-ring-shadow), var(--tw-shadow)';

    /**
     * Every `--tw-*` property the box-shadow composition relies on; the CLI
     * registers all of them whichever shadow utility is used.
     */
    private const PROPERTIES = [
        '--tw-shadow',
        '--tw-shadow-color',
        '--tw-shadow-alpha',
        '--tw-inset-shadow',
        '--tw-inset-shadow-color',
        '--tw-inset-shadow-alpha',
        '--tw-ring-color',
        '--tw-ring-shadow',
        '--tw-inset-ring-color',
        '--tw-inset-ring-shadow',
        '--tw-ring-inset',
        '--tw-ring-offset-width',
        '--tw-ring-offset-color',
        '--tw-ring-offset-shadow',
    ];

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
        return ['shadow'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        if ($name !== 'shadow' || $candidate->negative) {
            return null;
        }

        $inset = $candidate->root === 'inset-shadow';
        $namespace = $inset ? 'inset-shadow' : 'shadow';
        $result = $candidate->kind === Candidate::STATIC
            ? $this->defaultShadow($definition, $candidate, $inset)
            : $this->functional($definition, $candidate, $theme, $namespace, $inset);

        return $result === null ? null : [$result[0], $result[1], self::PROPERTIES];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function defaultShadow(Definition $definition, Candidate $candidate, bool $inset): ?array
    {
        $default = $definition->defaultValue();

        if ($default === null || $candidate->modifier !== null) {
            return null;
        }

        return [self::shadowNodes($default, $inset, null), []];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function functional(
        Definition $definition,
        Candidate $candidate,
        ThemeRegistry $theme,
        string $namespace,
        bool $inset
    ): ?array {
        $value = $candidate->value;

        if ($candidate->kind !== Candidate::FUNCTIONAL || $value === null) {
            return null;
        }

        if ($value instanceof NamedValue) {
            return $this->named($definition, $value, $candidate->modifier, $theme, $namespace, $inset);
        }

        $resolved = $this->resolver->resolve($definition, $value, $theme, false);

        if ($resolved === null) {
            return null;
        }

        $hint = $value instanceof ArbitraryValue || $value instanceof VarValue ? $value->dataType : null;
        $isColor = $hint === 'color' || (
            $hint === null && $value instanceof ArbitraryValue && Classifier::infer($resolved[0], ['color']) === 'color'
        );

        if ($isColor) {
            // A colour that is one reference to a theme variable is the theme colour: its value is what the
            // plain line carries and the fallback mixes (the compiler counts the variable used, as it does for
            // every reference in the output).
            $variable = Compiler::themeVariableOf($resolved[0], $theme);

            if ($variable !== null) {
                $modifier = $candidate->modifier;

                return $this->colorNodes($variable[1], $resolved[0], $resolved[1], $modifier, $theme, $inset, true);
            }

            return $this->colorNodes($resolved[0], $resolved[0], $resolved[1], $candidate->modifier, $theme, $inset);
        }

        // A variable is a whole shadow, used as written; an arbitrary shadow gets its colours replaced.
        $text = $value instanceof VarValue ? $resolved[0] : null;

        return $this->shadow($text ?? $resolved[0], $text === null, $inset, $candidate->modifier, $theme, $resolved[1]);
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function named(
        Definition $definition,
        NamedValue $value,
        ?Modifier $modifier,
        ThemeRegistry $theme,
        string $namespace,
        bool $inset
    ): ?array {
        if ($value->fraction) {
            return null;
        }

        if (isset($definition->named()[$value->name])) {
            // `none` is a literal: no colour to replace, nothing to scale.
            return $modifier === null ? [self::shadowNodes('0 0 #0000', $inset, null, false, false), []] : null;
        }

        $shadow = $theme->resolve($namespace, $value->name);

        if ($shadow !== null) {
            // A theme inset shadow already starts with `inset`.
            return $this->shadow($shadow->value(), true, $inset, $modifier, $theme, [], false);
        }

        $special = Colors::special($value->name);

        if ($special === 'inherit') {
            return $modifier === null ? [[new Declaration(self::colorProperty($inset), 'inherit')], []] : null;
        }

        if ($special !== null) {
            return $this->colorNodes($special, $special, [], $modifier, $theme, $inset);
        }

        $color = $theme->resolve('color', $value->name);

        if ($color === null) {
            return null;
        }

        [$reference, $used] = ValueResolver::reference($color);

        return $this->colorNodes($color->value(), $reference, $used, $modifier, $theme, $inset, true);
    }

    /**
     * The shadow declarations for $text, its colours replaced when $replace.
     *
     * @param list<string> $used
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function shadow(
        string $text,
        bool $replace,
        bool $inset,
        ?Modifier $modifier,
        ThemeRegistry $theme,
        array $used,
        bool $prefix = true
    ): ?array {
        if ($modifier === null) {
            return [self::shadowNodes($text, $inset, null, $replace, $prefix), $used];
        }

        $opacity = $this->resolver->opacity($modifier, $theme);

        if ($opacity === null) {
            return null;
        }

        return [
            self::shadowNodes($text, $inset, $opacity[0], $replace, $prefix),
            \array_merge($used, $opacity[1]),
        ];
    }

    /**
     * @param list<string> $used
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function colorNodes(
        string $raw,
        string $reference,
        array $used,
        ?Modifier $modifier,
        ThemeRegistry $theme,
        bool $inset,
        bool $fromTheme = false
    ): ?array {
        $property = self::colorProperty($inset);
        $alpha = $inset ? '--tw-inset-shadow-alpha' : '--tw-shadow-alpha';
        $fallback = $raw;
        $mixed = $reference;

        if ($modifier !== null) {
            $opacity = $this->resolver->opacity($modifier, $theme);

            if ($opacity === null) {
                return null;
            }

            $used = \array_merge($used, $opacity[1]);

            if ($opacity[0] !== '100%') {
                // The fallback is the plain colour where a mix could not be computed: a variable alpha, a
                // colour that is a variable or `currentcolor` (as the CLI writes `bg-current/50` and
                // `shadow-[color:var(--c)]/50`). A theme colour that is itself `currentcolor` or one variable
                // falls back to its reference, whatever the alpha.
                $fallback = Compiler::opacityFallbackAlways($reference, $fromTheme ? $raw : null, $opacity[0]);
                $mixed = 'color-mix(in oklab, ' . $reference . ' ' . $opacity[0] . ', transparent)';
            }
        }

        $supported = new Declaration($property, 'color-mix(in oklab, ' . $mixed . ' var(' . $alpha . '), transparent)');

        return [
            [new Declaration($property, $fallback), new AtRule('supports', self::COLOR_SUPPORT, [$supported])],
            \array_values(\array_unique($used)),
        ];
    }

    /**
     * @return list<Node>
     */
    private static function shadowNodes(
        string $text,
        bool $inset,
        ?string $alpha,
        bool $replace = true,
        bool $prefix = true
    ): array {
        $property = $inset ? '--tw-inset-shadow' : '--tw-shadow';
        $alphaProperty = $inset ? '--tw-inset-shadow-alpha' : '--tw-shadow-alpha';
        $colorProperty = self::colorProperty($inset);
        $list = $inset && $prefix;
        $nodes = [];
        $wait = $replace && $alpha !== null && self::readsVariable($text) ? self::WAIT : 0;
        $at = static function (?string $alpha, int $level) use ($text, $colorProperty, $list, $replace, $wait): string {
            if ($list) {
                return self::insetShadows($text, $colorProperty, $alpha, $replace, $level | $wait);
            }

            return $replace ? self::replaceColors($text, $colorProperty, $alpha, $level | $wait) : $text;
        };

        if ($alpha === null) {
            $nodes[] = new Declaration($property, $at(null, 0));
        } else {
            $nodes[] = new Declaration($alphaProperty, $alpha);

            // The value is written at up to three levels, each only when it differs from the one before: the
            // plain one, the one under `@supports (color: lab(from red l a b))` (relative colours, for a
            // colour that reads a variable and for a variable alpha) and, inside it or on its own, the one
            // under `@supports (color: color-mix(in lab, red, red))` (`currentcolor` cannot be an oklab base).
            $first = $at($alpha, 0);
            $second = $at($alpha, 1);
            $third = $at($alpha, 2);
            $nodes[] = new Declaration($property, $first);

            if ($second !== $first) {
                $inner = [new Declaration($property, $second)];

                if ($third !== $second) {
                    $inner[] = new AtRule('supports', self::COLOR_SUPPORT, [new Declaration($property, $third)]);
                }

                $nodes[] = new AtRule('supports', self::LAB_SUPPORT, $inner);
            } elseif ($third !== $second) {
                $nodes[] = new AtRule('supports', self::COLOR_SUPPORT, [new Declaration($property, $third)]);
            }
        }

        $nodes[] = new Declaration('box-shadow', self::BOX_SHADOW);

        return $nodes;
    }

    private static function colorProperty(bool $inset): string
    {
        return $inset ? '--tw-inset-shadow-color' : '--tw-shadow-color';
    }

    /**
     * The colour of every shadow of a comma-separated list becomes
     * `var(<property>, <colour>)` (`oklab(from <colour> l a b / <alpha>)`
     * inside when $alpha is given), as v4's `replaceShadowColors` does it: a
     * shadow is rewritten only when two offsets (a number, see {@see self::isOffset()})
     * are found in it; `inset` and the CSS-wide keywords
     * (`inherit`, `initial`, `revert`, `unset`) are skipped; the first other
     * token is the colour and is replaced in place; with none, `currentcolor` is
     * appended. A shadow with fewer than two offsets (`3px`, `var(--s)`,
     * `1px red`, `calc(1px) calc(1px) red`) is left as written.
     */
    private static function replaceColors(string $text, string $property, ?string $alpha, int $level = 0): string
    {
        $shadows = [];

        foreach (self::splitCommas($text) as $shadow) {
            $shadows[] = self::replaceShadowColor($shadow, $property, $alpha, $level);
        }

        return \implode(', ', $shadows);
    }

    /**
     * Every shadow of the list gets `inset` in front, as the CLI writes it
     * (`inset-shadow-[1px_1px_red,2px_2px_blue]` is two inset shadows), and then
     * its colour replaced like any other. The shadows are trimmed, an empty one is
     * kept, and the list is joined with a bare comma: the first shadow is written
     * `inset 1px …`, every later one `inset  2px …` with two spaces whatever white
     * space the candidate had around the comma (probed against 4.1.18).
     */
    private static function insetShadows(
        string $text,
        string $property,
        ?string $alpha,
        bool $replace,
        int $level = 0
    ): string {
        $shadows = [];

        foreach (self::splitCommas($text) as $index => $shadow) {
            $shadow = ($index === 0 ? 'inset ' : 'inset  ') . $shadow;
            $shadows[] = $replace ? self::replaceShadowColor($shadow, $property, $alpha, $level) : $shadow;
        }

        return \implode(',', $shadows);
    }

    private static function replaceShadowColor(string $shadow, string $property, ?string $alpha, int $level): string
    {
        $color = self::colorOf($shadow);

        if ($color === null) {
            return $shadow;
        }

        if (!self::hasColor($shadow)) {
            return $shadow . ' ' . self::wrapColor('currentcolor', $property, $alpha, $level);
        }

        $position = (int) \strpos($shadow, $color);

        return \substr($shadow, 0, $position)
            . self::wrapColor($color, $property, $alpha, $level)
            . \substr($shadow, $position + \strlen($color));
    }

    /**
     * The colour of one shadow that is rewritten, `currentcolor` when it has none to rewrite, or null when the shadow
     * is left as written (fewer than two offsets).
     */
    private static function colorOf(string $shadow): ?string
    {
        $color = null;
        $offsets = 0;

        foreach (self::split($shadow, ' ') as $token) {
            if (isset(self::SKIPPED[$token])) {
                continue;
            }

            if (self::isOffset($token)) {
                ++$offsets;

                continue;
            }

            if ($color === null) {
                $color = $token;
            }
        }

        return $offsets < 2 ? null : ($color ?? 'currentcolor');
    }

    /**
     * Whether a shadow that is rewritten names a colour (and not only offsets: `currentcolor` is then appended).
     */
    private static function hasColor(string $shadow): bool
    {
        foreach (self::split($shadow, ' ') as $token) {
            if (!isset(self::SKIPPED[$token]) && !self::isOffset($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the colour of any shadow of the list reads a variable (`var(--c)`).
     */
    private static function readsVariable(string $text): bool
    {
        foreach (self::splitCommas($text) as $shadow) {
            $color = self::colorOf($shadow);

            if ($color !== null && \strncmp($color, 'var(', 4) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function wrapColor(string $color, string $property, ?string $alpha, int $level): string
    {
        return 'var(' . $property . ', ' . ($alpha === null ? $color : self::withAlpha($color, $alpha, $level)) . ')';
    }

    /**
     * A shadow colour with an alpha at one of the three levels of {@see self::shadowNodes()} (probed against 4.1.18):
     *
     * - `currentcolor` (any case) stays as it is until level 2, where it is
     *   `color-mix(in oklab, <colour> <alpha>, transparent)` unless the alpha is `100%`;
     * - any other colour is `oklab(from <colour> l a b / <alpha>)` at once, except that it waits for level 1 when
     *   the alpha is a variable or when a colour of the list reads a variable (`var(--c)`), which stays as it is
     *   at level 0 ({@see self::WAIT}).
     */
    private static function withAlpha(string $color, string $alpha, int $mode): string
    {
        $level = $mode & 3;

        if (\strtolower($color) === 'currentcolor') {
            return $level === 2 && $alpha !== '100%'
                ? 'color-mix(in oklab, ' . $color . ' ' . $alpha . ', transparent)'
                : $color;
        }

        $waits = ($mode & self::WAIT) !== 0 || \strncmp($alpha, 'var(', 4) === 0;

        if ($level === 0 && $waits) {
            return $color;
        }

        return 'oklab(from ' . $color . ' l a b / ' . $alpha . ')';
    }

    /**
     * Whether a shadow token counts as an offset: an optional `-`, then a digit
     * or a `.` followed by a digit, whatever follows (`1px`, `.5`, `-1.5em`, `1%`
     * and also `1.`, `1e3`, `1/2`: the 4.1.18 CLI looks at the start only). A `+`
     * sign, a function (`calc(…)`) and a lone `-` or `.` are not offsets.
     */
    private static function isOffset(string $token): bool
    {
        $position = $token[0] === '-' ? 1 : 0;

        if (!isset($token[$position])) {
            return false;
        }

        $char = $token[$position];

        if ($char >= '0' && $char <= '9') {
            return true;
        }

        return $char === '.' && isset($token[$position + 1])
            && $token[$position + 1] >= '0' && $token[$position + 1] <= '9';
    }

    /**
     * $text split at the commas outside parentheses, each part trimmed, an empty part kept (the CLI writes
     * `a,,b` as three shadows, the middle one empty).
     *
     * @return list<string>
     */
    private static function splitCommas(string $text): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;

        for ($i = 0, $length = \strlen($text); $i < $length; ++$i) {
            $char = $text[$i];

            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                $depth = \max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = (string) \substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $parts[] = (string) \substr($text, $start);

        return \array_map('trim', $parts);
    }

    /**
     * $text split at $separator outside parentheses, empty parts dropped.
     *
     * @return list<string>
     */
    private static function split(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;

        for ($i = 0, $length = \strlen($text); $i < $length; ++$i) {
            $char = $text[$i];

            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                $depth = \max(0, $depth - 1);
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = \trim((string) \substr($text, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $parts[] = \trim((string) \substr($text, $start));

        return \array_values(\array_filter($parts, static function (string $part): bool {
            return $part !== '';
        }));
    }
}
