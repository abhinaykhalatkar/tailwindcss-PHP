<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\EffectsFilters as EffectsFiltersHandlers;

/**
 * The effects utilities (design doc 08 §Effects, T1): the rows
 * `Data\Utilities::table()` merges in catalogue order, every value checked
 * against the pinned 4.1.18 CLI (TASK-0039). The filters of that doc 08 row
 * are T2 and not here.
 *
 * - `shadow` and `inset-shadow` are one handler row each (`shadow`): the same
 *   root takes a shadow (theme `--shadow-*`, `none`, an arbitrary shadow) or a
 *   colour (`--color-*`, a colour keyword, an arbitrary colour with an optional
 *   opacity), and which one an arbitrary value is depends on what it parses as.
 *   `shadow` alone is the 4.1 default shadow, written out as the CLI does.
 * - The blend modes take their keywords only (the CLI refuses `[…]`).
 */
final class EffectsFilters
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = EffectsFiltersHandlers::class;

    private const SHADOW = '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)';

    private const BLENDS = [
        'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light',
        'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity',
    ];

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
            'shadow' => [
                'handler' => 'shadow',
                'theme' => ['shadow', 'color'],
                'named' => ['none' => 'none'],
                'modifier' => 'opacity',
                'arbitrary' => true,
                'default' => self::SHADOW,
            ],
            'inset-shadow' => [
                'handler' => 'shadow',
                'theme' => ['inset-shadow', 'color'],
                'named' => ['none' => 'none'],
                'modifier' => 'opacity',
                'arbitrary' => true,
            ],
            'opacity' => ['props' => ['opacity'], 'bare' => 'percentage', 'arbitrary' => true],
            'mix-blend' => [
                'props' => ['mix-blend-mode'],
                'named' => self::keywords(\array_merge(self::BLENDS, ['plus-darker', 'plus-lighter'])),
            ],
            'bg-blend' => ['props' => ['background-blend-mode'], 'named' => self::keywords(self::BLENDS)],
        ];
    }

    /**
     * Keywords that emit themselves.
     *
     * @param list<string> $keywords
     *
     * @return array<string, string>
     */
    private static function keywords(array $keywords): array
    {
        return \array_combine($keywords, $keywords);
    }
}
