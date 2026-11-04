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

        return $this->colorNodes($color->value(), $reference, $used, $modifier, $theme, $inset);
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
        bool $inset
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
                $fallback = \strncmp($opacity[0], 'var(', 4) === 0
                    ? $raw
                    : 'color-mix(in srgb, ' . $raw . ' ' . $opacity[0] . ', transparent)';
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
        $inline = $inset && $prefix ? 'inset ' : '';
        $plain = $inline . ($replace ? self::replaceColors($text, $colorProperty, null) : $text);
        $nodes = [];

        if ($alpha === null) {
            $nodes[] = new Declaration($property, $plain);
        } else {
            $tinted = $inline . ($replace ? self::replaceColors($text, $colorProperty, $alpha) : $text);
            $nodes[] = new Declaration($alphaProperty, $alpha);

            if (\strncmp($alpha, 'var(', 4) === 0) {
                // A variable alpha only works where the browser understands relative colours: the plain
                // value first, the tinted one under a support query.
                $nodes[] = new Declaration($property, $plain);
                $nodes[] = new AtRule('supports', self::LAB_SUPPORT, [new Declaration($property, $tinted)]);
            } else {
                $nodes[] = new Declaration($property, $tinted);
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
     * Every colour of a comma-separated shadow list becomes
     * `var(<property>, <colour>)` (`oklab(from <colour> l a b / <alpha>)`
     * inside when $alpha is given); a shadow without one gets `currentcolor`;
     * a shadow that is a single `var()` is left alone.
     */
    private static function replaceColors(string $text, string $property, ?string $alpha): string
    {
        $shadows = [];

        foreach (self::split($text, ',') as $shadow) {
            $tokens = self::split($shadow, ' ');

            // A shadow of one token (`var(--s)`, `3px`) is a whole value, used as written.
            if (\count($tokens) === 1) {
                $shadows[] = $tokens[0];

                continue;
            }

            $found = false;

            foreach ($tokens as $index => $token) {
                if ($token === 'inset' || self::isLength($token)) {
                    continue;
                }

                $tokens[$index] = self::wrapColor($token, $property, $alpha);
                $found = true;

                break;
            }

            if (!$found) {
                $tokens[] = self::wrapColor('currentcolor', $property, $alpha);
            }

            $shadows[] = \implode(' ', $tokens);
        }

        return \implode(', ', $shadows);
    }

    private static function wrapColor(string $color, string $property, ?string $alpha): string
    {
        $inner = $alpha === null ? $color : 'oklab(from ' . $color . ' l a b / ' . $alpha . ')';

        return 'var(' . $property . ', ' . $inner . ')';
    }

    /**
     * Whether a shadow token is a length or a number (`0`, `10px`, `-1px`,
     * `.5em`, `calc(…)`), not a colour.
     */
    private static function isLength(string $token): bool
    {
        $first = $token[0];

        if ($first >= '0' && $first <= '9' || $first === '.') {
            return true;
        }

        if (($first === '-' || $first === '+') && isset($token[1])) {
            return ($token[1] >= '0' && $token[1] <= '9') || $token[1] === '.';
        }

        foreach (['calc(', 'min(', 'max(', 'clamp('] as $function) {
            if (\strncmp($token, $function, \strlen($function)) === 0) {
                return true;
            }
        }

        return false;
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
