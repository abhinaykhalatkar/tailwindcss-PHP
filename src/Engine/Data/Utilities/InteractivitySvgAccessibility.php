<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\InteractivitySvgAccessibility as InteractivitySvgAccessibilityHandlers;

/**
 * The interactivity, SVG and accessibility utilities (design doc 08 §Effects …
 * Accessibility, T1): the rows `Data\Utilities::table()` merges in catalogue
 * order, every value checked against the pinned 4.1.18 CLI (TASK-0040). The
 * T2 roots of that doc 08 row (`accent`, `caret`, `color-scheme`,
 * `field-sizing`, `will-change`, `forced-color-adjust`) are not here.
 *
 * It needs no handler: everything is a data row.
 *
 * - The keyword roots take their keywords only (the CLI refuses `[…]` for all
 *   of them but `cursor`).
 * - `snap-*` and `touch-pan-*` compose a property from `--tw-*` variables the
 *   other utilities of their group set, and the CLI registers those variables
 *   with `@property` (the rows list them: a declared variable registers
 *   nothing by itself).
 * - `fill` and `stroke` are one root with a colour and a second meaning (`none`;
 *   the stroke width): a variable without a type hint is the colour.
 */
final class InteractivitySvgAccessibility
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = InteractivitySvgAccessibilityHandlers::class;

    private const CURSORS = [
        'auto', 'default', 'pointer', 'wait', 'text', 'move', 'help', 'not-allowed', 'none', 'context-menu',
        'progress', 'cell', 'crosshair', 'vertical-text', 'alias', 'copy', 'no-drop', 'grab', 'grabbing',
        'all-scroll', 'col-resize', 'row-resize', 'n-resize', 'e-resize', 's-resize', 'w-resize', 'ne-resize',
        'nw-resize', 'se-resize', 'sw-resize', 'ew-resize', 'ns-resize', 'nesw-resize', 'nwse-resize', 'zoom-in',
        'zoom-out',
    ];

    private const TOUCH_ACTION = 'var(--tw-pan-x,) var(--tw-pan-y,) var(--tw-pinch-zoom,)';

    private const TOUCH_PROPERTIES = ['--tw-pan-x', '--tw-pan-y', '--tw-pinch-zoom'];

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
            'cursor' => ['props' => ['cursor'], 'named' => self::keywords(self::CURSORS), 'arbitrary' => true],
            'pointer-events' => ['props' => ['pointer-events'], 'named' => self::keywords(['auto', 'none'])],
            'select' => [
                'props' => ['-webkit-user-select', 'user-select'],
                'named' => self::keywords(['none', 'text', 'all', 'auto']),
            ],
            'appearance' => ['props' => ['appearance'], 'named' => self::keywords(['none', 'auto'])],
            'resize' => [
                'props' => ['resize'],
                'named' => ['none' => 'none', 'x' => 'horizontal', 'y' => 'vertical'],
                'default' => 'both',
            ],
            'scroll-auto' => ['static' => ['scroll-behavior' => 'auto']],
            'scroll-smooth' => ['static' => ['scroll-behavior' => 'smooth']],
            'scroll-m' => self::scroll('scroll-margin', true),
            'scroll-mx' => self::scroll('scroll-margin-inline', true),
            'scroll-my' => self::scroll('scroll-margin-block', true),
            'scroll-ms' => self::scroll('scroll-margin-inline-start', true),
            'scroll-me' => self::scroll('scroll-margin-inline-end', true),
            'scroll-mt' => self::scroll('scroll-margin-top', true),
            'scroll-mr' => self::scroll('scroll-margin-right', true),
            'scroll-mb' => self::scroll('scroll-margin-bottom', true),
            'scroll-ml' => self::scroll('scroll-margin-left', true),
            'scroll-p' => self::scroll('scroll-padding', false),
            'scroll-px' => self::scroll('scroll-padding-inline', false),
            'scroll-py' => self::scroll('scroll-padding-block', false),
            'scroll-ps' => self::scroll('scroll-padding-inline-start', false),
            'scroll-pe' => self::scroll('scroll-padding-inline-end', false),
            'scroll-pt' => self::scroll('scroll-padding-top', false),
            'scroll-pr' => self::scroll('scroll-padding-right', false),
            'scroll-pb' => self::scroll('scroll-padding-bottom', false),
            'scroll-pl' => self::scroll('scroll-padding-left', false),
            'snap-none' => ['static' => ['scroll-snap-type' => 'none']],
            'snap-x' => self::snapType('x'),
            'snap-y' => self::snapType('y'),
            'snap-both' => self::snapType('both'),
            'snap-mandatory' => self::strictness('mandatory'),
            'snap-proximity' => self::strictness('proximity'),
            'snap-start' => ['static' => ['scroll-snap-align' => 'start']],
            'snap-end' => ['static' => ['scroll-snap-align' => 'end']],
            'snap-center' => ['static' => ['scroll-snap-align' => 'center']],
            'snap-align-none' => ['static' => ['scroll-snap-align' => 'none']],
            'snap-normal' => ['static' => ['scroll-snap-stop' => 'normal']],
            'snap-always' => ['static' => ['scroll-snap-stop' => 'always']],
            'touch-auto' => ['static' => ['touch-action' => 'auto']],
            'touch-none' => ['static' => ['touch-action' => 'none']],
            'touch-pan-x' => self::pan('--tw-pan-x', 'pan-x'),
            'touch-pan-left' => self::pan('--tw-pan-x', 'pan-left'),
            'touch-pan-right' => self::pan('--tw-pan-x', 'pan-right'),
            'touch-pan-y' => self::pan('--tw-pan-y', 'pan-y'),
            'touch-pan-up' => self::pan('--tw-pan-y', 'pan-up'),
            'touch-pan-down' => self::pan('--tw-pan-y', 'pan-down'),
            'touch-pinch-zoom' => self::pan('--tw-pinch-zoom', 'pinch-zoom'),
            'touch-manipulation' => ['static' => ['touch-action' => 'manipulation']],
            'fill' => [
                'variants' => [
                    self::paint('fill', true),
                    ['props' => ['fill'], 'named' => ['none' => 'none']],
                ],
            ],
            'stroke' => [
                'variants' => [
                    self::paint('stroke'),
                    [
                        'props' => ['stroke-width'],
                        'bare' => 'integer',
                        'arbitrary' => ['length', 'number', 'percentage'],
                    ],
                    ['props' => ['stroke'], 'named' => ['none' => 'none']],
                    self::anyPaint('stroke'),
                ],
            ],
        ];
    }

    /**
     * `scroll-m*` (negative too) and `scroll-p*`: the spacing scale, `px` or any value.
     *
     * @return array<string, mixed>
     */
    private static function scroll(string $property, bool $negative): array
    {
        $row = [
            'props' => [$property],
            'named' => ['px' => '1px'],
            'bare' => 'spacing-multiplier',
            'arbitrary' => true,
        ];

        if ($negative) {
            $row['negative'] = true;
        }

        return $row;
    }

    /**
     * `snap-x|y|both`: the axis and the strictness the `snap-mandatory` / `snap-proximity` utilities set.
     *
     * @return array<string, mixed>
     */
    private static function snapType(string $axis): array
    {
        return [
            'static' => ['scroll-snap-type' => $axis . ' var(--tw-scroll-snap-strictness)'],
            'properties' => ['--tw-scroll-snap-strictness'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function strictness(string $value): array
    {
        return [
            'static' => ['--tw-scroll-snap-strictness' => $value],
            'properties' => ['--tw-scroll-snap-strictness'],
        ];
    }

    /**
     * `touch-pan-*` and `touch-pinch-zoom`: one of the three variables, composed into `touch-action`.
     *
     * @return array<string, mixed>
     */
    private static function pan(string $variable, string $value): array
    {
        return [
            'static' => [$variable => $value, 'touch-action' => self::TOUCH_ACTION],
            'properties' => self::TOUCH_PROPERTIES,
        ];
    }

    /**
     * The first sub-definition of `fill` and `stroke`: a theme colour with an opacity, and an arbitrary value. It
     * comes first so that a variable with no type hint is the paint, as in the CLI. For `fill` every arbitrary
     * value is a paint (`$anyValue`); for `stroke` only a colour is, the rest goes on to the width and then to
     * {@see self::anyPaint()}, so a length stays the width.
     *
     * @return array<string, mixed>
     */
    private static function paint(string $property, bool $anyValue = false): array
    {
        return [
            'props' => [$property],
            'theme' => ['color'],
            'modifier' => 'opacity',
            'arbitrary' => $anyValue ? true : ['color'],
        ];
    }

    /**
     * The last sub-definition of `fill` and `stroke`: any other arbitrary value is a paint too (`[var(--x)]`,
     * `[context-fill]`, `[inherit]`, a value with a hint of another type), with an opacity as the colour has.
     * It comes after the `stroke` width, so a length, number or percentage is still the width.
     *
     * @return array<string, mixed>
     */
    private static function anyPaint(string $property): array
    {
        return ['props' => [$property], 'modifier' => 'opacity', 'arbitrary' => true];
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
