<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\TablesTransitionsTransforms as TablesTransitionsTransformsHandlers;

/**
 * The table, transition and transform utilities (design doc 08 §Tables,
 * Transitions, Transforms, T1): the rows `Data\Utilities::table()` merges in
 * catalogue order, every value checked against the pinned 4.1.18 CLI
 * (TASK-0039). The 3D transforms, skew and perspective of that doc 08 row
 * are T2 and not here.
 *
 * - `translate`, `scale` and `border-spacing` (and their axis forms) are
 *   handler rows (`transform`): a per-axis `--tw-*` variable and one property
 *   composed of the pair, which only a handler can write.
 * - `duration`, `ease` and `delay` set the matching `--tw-*` variable next to
 *   the property the `transition` utility reads it from; `initial` sets the
 *   variable alone.
 * - `transition` is a handler row (`transition`): the property list, then
 *   the timing function and duration read from the variables above.
 * - `animate` only names a theme animation; its keyframes are the
 *   assembler's.
 */
final class TablesTransitionsTransforms
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = TablesTransitionsTransformsHandlers::class;

    private const TRANSITION_COLORS = 'color, background-color, border-color, outline-color, text-decoration-color, '
        . 'fill, stroke, --tw-gradient-from, --tw-gradient-via, --tw-gradient-to';

    private const TRANSITION_ALL = 'color, background-color, border-color, outline-color, text-decoration-color, '
        . 'fill, stroke, --tw-gradient-from, --tw-gradient-via, --tw-gradient-to, opacity, box-shadow, transform, '
        . 'translate, scale, rotate, filter, -webkit-backdrop-filter, backdrop-filter, display, content-visibility, '
        . 'overlay, pointer-events';

    private const TRANSFORM = 'var(--tw-rotate-x,) var(--tw-rotate-y,) var(--tw-rotate-z,) var(--tw-skew-x,) '
        . 'var(--tw-skew-y,)';

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
            'border-collapse' => ['static' => ['border-collapse' => 'collapse']],
            'border-separate' => ['static' => ['border-collapse' => 'separate']],
            'table-auto' => ['static' => ['table-layout' => 'auto']],
            'table-fixed' => ['static' => ['table-layout' => 'fixed']],
            'caption-top' => ['static' => ['caption-side' => 'top']],
            'caption-bottom' => ['static' => ['caption-side' => 'bottom']],
            'border-spacing' => self::spacing(),
            'border-spacing-x' => self::spacing(),
            'border-spacing-y' => self::spacing(),
            'transition' => [
                'handler' => 'transition',
                'named' => [
                    'all' => 'all',
                    'colors' => self::TRANSITION_COLORS,
                    'opacity' => 'opacity',
                    'shadow' => 'box-shadow',
                    'transform' => 'transform, translate, scale, rotate',
                    'none' => 'none',
                    'discrete' => 'allow-discrete',
                    'normal' => 'normal',
                ],
                'arbitrary' => true,
                'default' => self::TRANSITION_ALL,
            ],
            'duration' => [
                'variants' => [
                    [
                        'props' => ['--tw-duration'],
                        'named' => ['initial' => 'initial'],
                        'properties' => ['--tw-duration'],
                    ],
                    [
                        'props' => ['--tw-duration', 'transition-duration'],
                        'bare' => 'duration',
                        'arbitrary' => true,
                        'properties' => ['--tw-duration'],
                    ],
                ],
            ],
            'delay' => ['props' => ['transition-delay'], 'bare' => 'duration', 'arbitrary' => true],
            'ease' => [
                'variants' => [
                    [
                        'props' => ['--tw-ease'],
                        'named' => ['initial' => 'initial'],
                        'properties' => ['--tw-ease'],
                    ],
                    [
                        'props' => ['--tw-ease', 'transition-timing-function'],
                        'theme' => ['ease'],
                        'named' => ['linear' => 'linear'],
                        'arbitrary' => true,
                        'properties' => ['--tw-ease'],
                    ],
                ],
            ],
            'animate' => [
                'props' => ['animation'],
                'theme' => ['animate'],
                'named' => ['none' => 'none'],
                'arbitrary' => true,
            ],
            'rotate' => [
                'handler' => 'transform',
                'named' => ['none' => 'none'],
                'bare' => 'angle',
                'negative' => true,
                'arbitrary' => true,
            ],
            'scale' => self::scale(true),
            'scale-x' => self::scale(false),
            'scale-y' => self::scale(false),
            'translate' => self::translate(true),
            'translate-x' => self::translate(false),
            'translate-y' => self::translate(false),
            'origin' => [
                'props' => ['transform-origin'],
                'named' => [
                    'center' => 'center',
                    'top' => 'top',
                    'top-right' => '100% 0',
                    'right' => '100%',
                    'bottom-right' => '100% 100%',
                    'bottom' => 'bottom',
                    'bottom-left' => '0 100%',
                    'left' => '0',
                    'top-left' => '0 0',
                ],
                'arbitrary' => true,
            ],
            'transform' => [
                'static' => ['transform' => self::TRANSFORM],
                'properties' => [
                    '--tw-rotate-x',
                    '--tw-rotate-y',
                    '--tw-rotate-z',
                    '--tw-skew-x',
                    '--tw-skew-y',
                ],
            ],
            'transform-gpu' => ['static' => ['transform' => 'translateZ(0) ' . self::TRANSFORM]],
            'transform-none' => ['static' => ['transform' => 'none']],
        ];
    }

    /**
     * `border-spacing`, `-x`, `-y`: the spacing scale, `px` or any value.
     *
     * @return array<string, mixed>
     */
    private static function spacing(): array
    {
        return [
            'handler' => 'transform',
            'named' => ['px' => '1px'],
            'bare' => 'spacing-multiplier',
            'arbitrary' => true,
        ];
    }

    /**
     * `scale` (all axes, `none`) and `scale-x` / `scale-y`: a percentage,
     * negative too, or any value.
     *
     * @return array<string, mixed>
     */
    private static function scale(bool $all): array
    {
        return [
            'handler' => 'transform',
            'named' => $all ? ['none' => 'none'] : [],
            'bare' => 'percentage',
            'negative' => true,
            'arbitrary' => true,
        ];
    }

    /**
     * `translate` (both axes, `none`) and `translate-x` / `translate-y`: the
     * spacing scale, `px`, `full`, a fraction, negative too, or any value.
     *
     * @return array<string, mixed>
     */
    private static function translate(bool $all): array
    {
        $named = ['px' => '1px', 'full' => '100%'];

        return [
            'handler' => 'transform',
            'named' => $all ? $named + ['none' => 'none'] : $named,
            'bare' => 'spacing-multiplier',
            'fraction' => true,
            'negative' => true,
            'arbitrary' => true,
        ];
    }
}
