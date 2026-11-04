<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
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
 * The handlers of the typography family (`Data\Utilities\Typography`), TASK-0037:
 *
 * - `fontSize`: `text-sm` → `font-size: var(--text-sm)` plus the theme's
 *   companions `--text-sm--line-height`, `--text-sm--letter-spacing` and
 *   `--text-sm--font-weight` when it has them, each behind the `--tw-*`
 *   property the `leading-*`, `tracking-*` and `font-*` utilities set
 *   (`var(--tw-leading, …)`); a `/<line-height>` modifier replaces the
 *   line-height companion with a theme leading, a spacing multiplier, `none`
 *   (`1`), an arbitrary value or a variable. An arbitrary size or a variable
 *   with a length hint is the size alone (plus the modifier).
 * - `lineClamp`: `line-clamp-3` → the four declarations of the CLI, `none`
 *   undoing them.
 *
 * Both read only the parsed value, the modifier, the definition and the theme
 * (T-15): the text that reaches a declaration is a theme value, a row
 * literal, a checked number or the validated arbitrary value, never the raw
 * candidate. Theme variables are reported in `usedVars`, never marked.
 */
final class Typography implements FamilyHandlerInterface
{
    /**
     * The companions of a theme size, `[suffix, property, the --tw-* property read first]`, in the
     * order the CLI writes them.
     */
    private const COMPANIONS = [
        ['line-height', 'line-height', '--tw-leading'],
        ['letter-spacing', 'letter-spacing', '--tw-tracking'],
        ['font-weight', 'font-weight', '--tw-font-weight'],
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
        return ['fontSize', 'lineClamp'];
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
            case 'fontSize':
                return $this->fontSize($definition, $value, $candidate->modifier, $theme);
            case 'lineClamp':
                return $candidate->modifier === null ? $this->lineClamp($definition, $value, $theme) : null;
            default:
                return null;
        }
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function fontSize(
        Definition $definition,
        ValueInterface $value,
        ?Modifier $modifier,
        ThemeRegistry $theme
    ): ?array {
        $companions = [];
        $used = [];

        if ($value instanceof NamedValue) {
            $themed = null;

            if (!$value->fraction && !self::isOtherNamespace($value->name)) {
                $themed = $theme->resolve('text', $value->name);
            }

            if ($themed === null) {
                return null;
            }

            [$size, $used] = ValueResolver::reference($themed);

            foreach (self::COMPANIONS as [$suffix, $property, $fallbackOf]) {
                $companion = $theme->companion('text', $value->name, $suffix);

                if ($companion === null) {
                    continue;
                }

                [$css, $companionUsed] = ValueResolver::reference($companion);
                $companions[$property] = ['var(' . $fallbackOf . ', ' . $css . ')', $companionUsed];
            }
        } else {
            $resolved = $this->resolver->resolve($definition, $value, $theme, false);

            if ($resolved === null) {
                return null;
            }

            [$size, $used] = $resolved;
        }

        $nodes = [new Declaration('font-size', $size)];

        if ($modifier !== null) {
            $leading = $this->lineHeight($modifier, $theme);

            if ($leading === null) {
                return null;
            }

            $nodes[] = new Declaration('line-height', $leading[0]);
            $used = \array_merge($used, $leading[1]);
            unset($companions['line-height']);
        }

        foreach ($companions as $property => [$css, $companionUsed]) {
            $nodes[] = new Declaration((string) $property, $css);
            $used = \array_merge($used, $companionUsed);
        }

        return [$nodes, \array_values(\array_unique($used))];
    }

    /**
     * The line-height a modifier stands for: an arbitrary value or a variable
     * as written, a theme `--leading-<name>`, `none` (1), or a spacing
     * multiplier (`7` → `calc(var(--spacing) * 7)`, steps of 0.25).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function lineHeight(Modifier $modifier, ThemeRegistry $theme): ?array
    {
        if ($modifier->kind === Modifier::VAR) {
            return ['var(' . $modifier->value . ')', []];
        }

        if ($modifier->kind === Modifier::ARBITRARY) {
            return [$modifier->value, []];
        }

        $themed = $theme->resolve('leading', $modifier->value);

        if ($themed !== null) {
            return ValueResolver::reference($themed);
        }

        if ($modifier->value === 'none') {
            return ['1', []];
        }

        $spacing = $theme->resolve('spacing', '');

        if ($spacing === null || !ValueResolver::isNumber($modifier->value) || !self::isQuarterStep($modifier->value)) {
            return null;
        }

        [$css, $used] = ValueResolver::reference($spacing);

        return ['calc(' . $css . ' * ' . $modifier->value . ')', $used];
    }

    /**
     * @return array{0: list<Node>, 1: list<string>}|null
     */
    private function lineClamp(Definition $definition, ValueInterface $value, ThemeRegistry $theme): ?array
    {
        if ($value instanceof NamedValue) {
            if ($value->fraction) {
                return null;
            }

            if (isset($definition->named()[$value->name])) {
                return [self::clamp('visible', 'block', 'horizontal', 'unset'), []];
            }

            return ValueResolver::isInteger($value->name)
                ? [self::clamp('hidden', '-webkit-box', 'vertical', $value->name), []]
                : null;
        }

        if (!$value instanceof ArbitraryValue && !$value instanceof VarValue) {
            return null;
        }

        $resolved = $this->resolver->resolve($definition, $value, $theme, false);

        return $resolved === null
            ? null
            : [self::clamp('hidden', '-webkit-box', 'vertical', $resolved[0]), $resolved[1]];
    }

    /**
     * @return list<Node>
     */
    private static function clamp(string $overflow, string $display, string $orient, string $lines): array
    {
        return [
            new Declaration('overflow', $overflow),
            new Declaration('display', $display),
            new Declaration('-webkit-box-orient', $orient),
            new Declaration('-webkit-line-clamp', $lines),
        ];
    }

    /**
     * Whether `--text-<name>` belongs to a namespace of its own (`--text-shadow-*`: the CLI never reads it
     * as a size, v4's `ignoredThemeKeyMap`).
     */
    private static function isOtherNamespace(string $name): bool
    {
        return $name === 'shadow' || \strncmp($name, 'shadow-', 7) === 0;
    }

    /**
     * A multiple of 0.25 (the spacing scale): `7`, `1.5`, `0.25`; not `0.3`.
     * Expects {@see ValueResolver::isNumber()}.
     */
    private static function isQuarterStep(string $number): bool
    {
        $dot = \strpos($number, '.');

        if ($dot === false) {
            return true;
        }

        $fraction = (string) \substr($number, $dot + 1);

        return $fraction === '25' || $fraction === '5' || $fraction === '75';
    }
}
