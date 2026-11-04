<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\SpacingSizing as SpacingSizingHandlers;

/**
 * The spacing and sizing utilities (design doc 08 §Spacing & Sizing, T1): the
 * rows `Data\Utilities::table()` merges in catalogue order, every value
 * checked against the pinned 4.1.18 CLI (TASK-0036).
 *
 * What the CLI does that the table has to say in data:
 *
 * - Padding, margin, gaps and sizes take the spacing scale as a bare number
 *   (`calc(var(--spacing) * n)`, steps of 0.25), `px` as `1px`, and any
 *   arbitrary value; `m*` negates (`-mx-auto` is refused, `-m-px` is `-1px`).
 * - `w`, `min-w`, `max-w` also read `--container-*` (`w-sm`); the viewport
 *   keywords differ per axis (`w-screen` is `100vw`, `h-screen` is `100vh`),
 *   `max-*` takes `none` but not `auto`, `min-*` takes `auto` but not `none`,
 *   `h` takes `lh`, `size` takes neither `screen` nor `lh`.
 * - `space-x|y` are handler rows (`space`), their `-reverse` forms static ones (`spaceReverse`): both write a
 *   nested rule.
 */
final class SpacingSizing
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = SpacingSizingHandlers::class;

    private function __construct()
    {
    }

    /**
     * Root => row (design doc 07 §Definition schema), in catalogue order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function rows(): array
    {
        return [
            'p' => self::padding('padding'),
            'px' => self::padding('padding-inline'),
            'py' => self::padding('padding-block'),
            'ps' => self::padding('padding-inline-start'),
            'pe' => self::padding('padding-inline-end'),
            'pt' => self::padding('padding-top'),
            'pr' => self::padding('padding-right'),
            'pb' => self::padding('padding-bottom'),
            'pl' => self::padding('padding-left'),
            'm' => self::margin('margin'),
            'mx' => self::margin('margin-inline'),
            'my' => self::margin('margin-block'),
            'ms' => self::margin('margin-inline-start'),
            'me' => self::margin('margin-inline-end'),
            'mt' => self::margin('margin-top'),
            'mr' => self::margin('margin-right'),
            'mb' => self::margin('margin-bottom'),
            'ml' => self::margin('margin-left'),
            'space-x' => self::space('row-gap'),
            'space-y' => self::space('column-gap'),
            'space-x-reverse' => ['handler' => 'spaceReverse', 'order' => 'row-gap'],
            'space-y-reverse' => ['handler' => 'spaceReverse', 'order' => 'column-gap'],
            'w' => self::size(['width'], self::viewport('100vw'), true),
            'min-w' => self::size(['min-width'], self::viewport('100vw'), true),
            // `max-w-screen-<breakpoint>` first (the `size` handler: a theme breakpoint behind a prefix),
            // then the rest.
            'max-w' => [
                'variants' => [
                    ['handler' => 'size', 'props' => ['max-width']],
                    self::size(['max-width'], self::maximum(self::viewport('100vw')) + ['prose' => '65ch'], true),
                ],
            ],
            'h' => self::size(['height'], self::viewport('100vh') + ['lh' => '1lh'], false),
            'min-h' => self::size(['min-height'], self::viewport('100vh') + ['lh' => '1lh'], false),
            'max-h' => self::size(['max-height'], self::maximum(self::viewport('100vh')) + ['lh' => '1lh'], false),
            'size' => self::size(['width', 'height'], self::viewport(null), false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function padding(string $property): array
    {
        return [
            'props' => [$property],
            'named' => ['px' => '1px'],
            'bare' => 'spacing-multiplier',
            'arbitrary' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function margin(string $property): array
    {
        return [
            'props' => [$property],
            'named' => ['auto' => 'auto', 'px' => '1px'],
            'bare' => 'spacing-multiplier',
            'negative' => true,
            'arbitrary' => true,
        ];
    }

    /**
     * `space-x` / `space-y`: the handler writes the sides of the axis. The CLI sorts them as if they
     * declared a gap (`--tw-sort: row-gap` for x, `column-gap` for y), so the row says so.
     *
     * @return array<string, mixed>
     */
    private static function space(string $sortAs): array
    {
        return [
            'handler' => 'space',
            'order' => $sortAs,
            'named' => ['px' => '1px'],
            'bare' => 'spacing-multiplier',
            'negative' => true,
            'arbitrary' => true,
        ];
    }

    /**
     * A size row: the keywords every sizing root takes, the viewport and
     * extra ones of the root, the spacing scale, a fraction, any value.
     *
     * @param list<string> $props
     * @param array<string, string> $named
     *
     * @return array<string, mixed>
     */
    private static function size(array $props, array $named, bool $container): array
    {
        $row = [
            'props' => $props,
            'named' => $named,
            'bare' => 'spacing-multiplier',
            'fraction' => true,
            'arbitrary' => true,
        ];

        if ($container) {
            $row['theme'] = ['container'];
        }

        return $row;
    }

    /**
     * The keywords of every size root: `auto`, `full`, the content sizes,
     * `px`, and the viewport units (`$screen` is what `screen` stands for, or
     * null for a root without `screen`, as `size`).
     *
     * @return array<string, string>
     */
    private static function viewport(?string $screen): array
    {
        $named = ['auto' => 'auto', 'full' => '100%'];

        if ($screen !== null) {
            $named['screen'] = $screen;
        }

        return $named + [
            'svw' => '100svw',
            'lvw' => '100lvw',
            'dvw' => '100dvw',
            'svh' => '100svh',
            'lvh' => '100lvh',
            'dvh' => '100dvh',
            'min' => 'min-content',
            'max' => 'max-content',
            'fit' => 'fit-content',
            'px' => '1px',
        ];
    }

    /**
     * A `max-*` root's keywords: `none` instead of `auto`.
     *
     * @param array<string, string> $named
     *
     * @return array<string, string>
     */
    private static function maximum(array $named): array
    {
        unset($named['auto']);

        return ['none' => 'none'] + $named;
    }
}
