<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\FlexboxGrid as FlexboxGridHandlers;

/**
 * The flexbox and grid utilities (design doc 08 §Flexbox & Grid, T1; `flex`
 * itself is a Layout root): the rows `Data\Utilities::table()` merges in
 * catalogue order, every value checked against the pinned 4.1.18 CLI
 * (TASK-0036).
 *
 * What the CLI does that the table has to say in data:
 *
 * - `order-first|last|none` are literals that never negate (`-order-none` is
 *   refused), so they sit in their own sub-definition next to the integer one.
 * - `grid-cols|rows` (`repeat(n, minmax(0, 1fr))`), `col-span|row-span`
 *   (`span n / span n`) and `content` (`--tw-content` plus `content`) are
 *   handler rows (`gridCols`, `content`).
 * - The keyword roots take their keywords only, arbitrary values and
 *   variables are refused; the `-safe` and `-last` forms are the 4.1 ones.
 */
final class FlexboxGrid
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = FlexboxGridHandlers::class;

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
            'basis' => [
                'props' => ['flex-basis'],
                'named' => ['auto' => 'auto', 'full' => '100%', 'px' => '1px'],
                'theme' => ['container'],
                'bare' => 'spacing-multiplier',
                'fraction' => true,
                'arbitrary' => true,
            ],
            'flex-row' => ['static' => ['flex-direction' => 'row']],
            'flex-row-reverse' => ['static' => ['flex-direction' => 'row-reverse']],
            'flex-col' => ['static' => ['flex-direction' => 'column']],
            'flex-col-reverse' => ['static' => ['flex-direction' => 'column-reverse']],
            'flex-wrap' => ['static' => ['flex-wrap' => 'wrap']],
            'flex-wrap-reverse' => ['static' => ['flex-wrap' => 'wrap-reverse']],
            'flex-nowrap' => ['static' => ['flex-wrap' => 'nowrap']],
            'grow' => self::factor('flex-grow'),
            'shrink' => self::factor('flex-shrink'),
            'order' => [
                'variants' => [
                    ['props' => ['order'], 'named' => ['first' => '-9999', 'last' => '9999', 'none' => '0']],
                    ['props' => ['order'], 'bare' => 'integer', 'negative' => true, 'arbitrary' => true],
                ],
            ],
            'grid-cols' => self::tracks('grid-template-columns'),
            'grid-rows' => self::tracks('grid-template-rows'),
            'col' => self::line('grid-column'),
            'col-start' => self::line('grid-column-start'),
            'col-end' => self::line('grid-column-end'),
            'row' => self::line('grid-row'),
            'row-start' => self::line('grid-row-start'),
            'row-end' => self::line('grid-row-end'),
            'col-span' => self::span('grid-column'),
            'row-span' => self::span('grid-row'),
            'grid-flow' => [
                'props' => ['grid-auto-flow'],
                'named' => [
                    'row' => 'row',
                    'col' => 'column',
                    'dense' => 'dense',
                    'row-dense' => 'row dense',
                    'col-dense' => 'column dense',
                ],
            ],
            'auto-cols' => self::autoTracks('grid-auto-columns'),
            'auto-rows' => self::autoTracks('grid-auto-rows'),
            'gap' => self::gap('gap'),
            'gap-x' => self::gap('column-gap'),
            'gap-y' => self::gap('row-gap'),
            'justify' => [
                'props' => ['justify-content'],
                'named' => [
                    'start' => 'flex-start',
                    'end' => 'flex-end',
                    'end-safe' => 'safe flex-end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'between' => 'space-between',
                    'around' => 'space-around',
                    'evenly' => 'space-evenly',
                    'stretch' => 'stretch',
                    'baseline' => 'baseline',
                    'normal' => 'normal',
                ],
            ],
            'justify-items' => [
                'props' => ['justify-items'],
                'named' => [
                    'start' => 'start',
                    'end' => 'end',
                    'end-safe' => 'safe end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'stretch' => 'stretch',
                    'normal' => 'normal',
                ],
            ],
            'justify-self' => [
                'props' => ['justify-self'],
                'named' => [
                    'auto' => 'auto',
                    'start' => 'flex-start',
                    'end' => 'flex-end',
                    'end-safe' => 'safe flex-end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'stretch' => 'stretch',
                ],
            ],
            'items' => [
                'props' => ['align-items'],
                'named' => [
                    'start' => 'flex-start',
                    'end' => 'flex-end',
                    'end-safe' => 'safe flex-end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'baseline' => 'baseline',
                    'baseline-last' => 'last baseline',
                    'stretch' => 'stretch',
                ],
            ],
            'self' => [
                'props' => ['align-self'],
                'named' => [
                    'auto' => 'auto',
                    'start' => 'flex-start',
                    'end' => 'flex-end',
                    'end-safe' => 'safe flex-end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'stretch' => 'stretch',
                    'baseline' => 'baseline',
                    'baseline-last' => 'last baseline',
                ],
            ],
            'place-content' => [
                'props' => ['place-content'],
                'named' => [
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'start' => 'start',
                    'end' => 'end',
                    'end-safe' => 'safe end',
                    'between' => 'space-between',
                    'around' => 'space-around',
                    'evenly' => 'space-evenly',
                    'baseline' => 'baseline',
                    'stretch' => 'stretch',
                ],
            ],
            'place-items' => [
                'props' => ['place-items'],
                'named' => [
                    'start' => 'start',
                    'end' => 'end',
                    'end-safe' => 'safe end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'baseline' => 'baseline',
                    'stretch' => 'stretch',
                ],
            ],
            'place-self' => [
                'props' => ['place-self'],
                'named' => [
                    'auto' => 'auto',
                    'start' => 'start',
                    'end' => 'end',
                    'end-safe' => 'safe end',
                    'center' => 'center',
                    'center-safe' => 'safe center',
                    'stretch' => 'stretch',
                ],
            ],
            'content' => [
                'variants' => [
                    [
                        'props' => ['align-content'],
                        'named' => [
                            'normal' => 'normal',
                            'center' => 'center',
                            'center-safe' => 'safe center',
                            'start' => 'flex-start',
                            'end' => 'flex-end',
                            'end-safe' => 'safe flex-end',
                            'between' => 'space-between',
                            'around' => 'space-around',
                            'evenly' => 'space-evenly',
                            'baseline' => 'baseline',
                            'stretch' => 'stretch',
                        ],
                    ],
                    [
                        'handler' => 'content',
                        'props' => ['content'],
                        'named' => ['none' => 'none'],
                        'arbitrary' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * `grow` / `shrink`: 1 alone, an integer or any arbitrary value.
     *
     * @return array<string, mixed>
     */
    private static function factor(string $property): array
    {
        return ['props' => [$property], 'default' => '1', 'bare' => 'integer', 'arbitrary' => true];
    }

    /**
     * `grid-cols` / `grid-rows`: `none`, `subgrid`, a count as a repeat, or any value.
     *
     * @return array<string, mixed>
     */
    private static function tracks(string $property): array
    {
        return [
            'handler' => 'gridCols',
            'props' => [$property],
            'named' => ['none' => 'none', 'subgrid' => 'subgrid'],
            'bare' => 'integer',
            'arbitrary' => true,
        ];
    }

    /**
     * `col` / `col-start` / `col-end` and the row ones: `auto`, an integer
     * (negative too) or any value.
     *
     * @return array<string, mixed>
     */
    private static function line(string $property): array
    {
        return [
            'props' => [$property],
            'named' => ['auto' => 'auto'],
            'bare' => 'integer',
            'negative' => true,
            'arbitrary' => true,
        ];
    }

    /**
     * `col-span` / `row-span`: `full`, a count or any value, as `span n / span n`.
     *
     * @return array<string, mixed>
     */
    private static function span(string $property): array
    {
        return [
            'handler' => 'gridCols',
            'props' => [$property],
            'named' => ['full' => '1 / -1'],
            'bare' => 'integer',
            'arbitrary' => true,
        ];
    }

    /**
     * `auto-cols` / `auto-rows`.
     *
     * @return array<string, mixed>
     */
    private static function autoTracks(string $property): array
    {
        return [
            'props' => [$property],
            'named' => ['auto' => 'auto', 'min' => 'min-content', 'max' => 'max-content', 'fr' => 'minmax(0, 1fr)'],
            'arbitrary' => true,
        ];
    }

    /**
     * `gap`, `gap-x`, `gap-y`: the spacing scale, `px` or any value.
     *
     * @return array<string, mixed>
     */
    private static function gap(string $property): array
    {
        return [
            'props' => [$property],
            'named' => ['px' => '1px'],
            'bare' => 'spacing-multiplier',
            'arbitrary' => true,
        ];
    }
}
