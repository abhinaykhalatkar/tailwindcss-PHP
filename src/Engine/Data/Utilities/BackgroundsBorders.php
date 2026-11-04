<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\BackgroundsBorders as BackgroundsBordersHandlers;

/**
 * The backgrounds and borders utilities (design doc 08 §Utilities, T1): the rows
 * `Data\Utilities::table()` merges in catalogue order — the 42 roots the
 * inventory lists for the family, in its order. Output that is not
 * "property := value" (widths with a style variable, gradients, rings,
 * dividers) goes to {@see BackgroundsBordersHandlers}.
 *
 * Sub-definition order reproduces the CLI's choice for a value (4.1.18):
 *
 * - keywords first (`bg-fixed`, `border-solid`), then the colour with the
 *   theme, an arbitrary `[color]` and a variable without a hint (the CLI's
 *   default for an untyped value is the colour), then the arbitrary types of
 *   the other properties (`bg-[url(…)]`, `border-[3px]`), and for `bg` a last
 *   colour that takes any other arbitrary value, as the CLI's `default:` does;
 * - `properties` lists the `--tw-*` properties a utility reads but does not
 *   declare, which the CLI registers with `@property` for it (the compiler
 *   adds the ones the body declares);
 * - `order` gives the CLI's sort group where the declared properties do not:
 *   the stops sort by their own colour variable (`from` < `via` < `to`), the
 *   dividers by their `divide-*` entries. The schema takes one order per root,
 *   so a root's colour and position (or style) tie, and the assembler's
 *   tie-break (more declarations first) orders them as the CLI does.
 */
final class BackgroundsBorders
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = BackgroundsBordersHandlers::class;

    /**
     * The gradient group the CLI registers for `from-*` / `via-*` / `to-*`.
     *
     * @var list<string>
     */
    public const GRADIENT_PROPERTIES = [
        '--tw-gradient-position', '--tw-gradient-from', '--tw-gradient-via', '--tw-gradient-to',
        '--tw-gradient-stops', '--tw-gradient-via-stops', '--tw-gradient-from-position',
        '--tw-gradient-via-position', '--tw-gradient-to-position',
    ];

    /**
     * The shadow and ring group the CLI registers for `ring` widths and `ring-inset`.
     *
     * @var list<string>
     */
    public const RING_PROPERTIES = [
        '--tw-shadow', '--tw-shadow-color', '--tw-shadow-alpha', '--tw-inset-shadow',
        '--tw-inset-shadow-color', '--tw-inset-shadow-alpha', '--tw-ring-color', '--tw-ring-shadow',
        '--tw-inset-ring-color', '--tw-inset-ring-shadow', '--tw-ring-inset', '--tw-ring-offset-width',
        '--tw-ring-offset-color', '--tw-ring-offset-shadow',
    ];

    /**
     * `rounded` side => the corner properties it sets.
     *
     * @var array<string, list<string>>
     */
    private const CORNERS = [
        '' => ['border-radius'],
        's' => ['border-start-start-radius', 'border-end-start-radius'],
        'e' => ['border-start-end-radius', 'border-end-end-radius'],
        't' => ['border-top-left-radius', 'border-top-right-radius'],
        'r' => ['border-top-right-radius', 'border-bottom-right-radius'],
        'b' => ['border-bottom-right-radius', 'border-bottom-left-radius'],
        'l' => ['border-top-left-radius', 'border-bottom-left-radius'],
        'ss' => ['border-start-start-radius'],
        'se' => ['border-start-end-radius'],
        'ee' => ['border-end-end-radius'],
        'es' => ['border-end-start-radius'],
        'tl' => ['border-top-left-radius'],
        'tr' => ['border-top-right-radius'],
        'br' => ['border-bottom-right-radius'],
        'bl' => ['border-bottom-left-radius'],
    ];

    /**
     * `border` side => the prefix of its `-color` / `-style` / `-width` properties.
     *
     * @var array<string, string>
     */
    public const BORDER_SIDES = [
        '' => 'border',
        'x' => 'border-inline',
        'y' => 'border-block',
        's' => 'border-inline-start',
        'e' => 'border-inline-end',
        't' => 'border-top',
        'r' => 'border-right',
        'b' => 'border-bottom',
        'l' => 'border-left',
    ];

    /**
     * Gradient directions (`bg-linear-to-*`, `bg-gradient-to-*`).
     *
     * @var array<string, string>
     */
    private const DIRECTIONS = [
        't' => 'to top',
        'tr' => 'to top right',
        'r' => 'to right',
        'br' => 'to bottom right',
        'b' => 'to bottom',
        'bl' => 'to bottom left',
        'l' => 'to left',
        'tl' => 'to top left',
    ];

    /**
     * @var array<string, string>
     */
    private const BORDER_STYLES = [
        'solid' => 'solid', 'dashed' => 'dashed', 'dotted' => 'dotted', 'double' => 'double',
        'hidden' => 'hidden', 'none' => 'none',
    ];

    /**
     * The CLI has no `divide-hidden`.
     *
     * @var array<string, string>
     */
    private const DIVIDE_STYLES = [
        'solid' => 'solid', 'dashed' => 'dashed', 'dotted' => 'dotted', 'double' => 'double', 'none' => 'none',
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
        $rows = [
            'bg' => ['variants' => self::background()],
            'bg-gradient-to' => ['handler' => 'gradient', 'named' => self::DIRECTIONS],
            'bg-linear-to' => ['handler' => 'gradient', 'named' => self::DIRECTIONS],
            'from' => ['variants' => self::stop(), 'order' => '--tw-gradient-from'],
            'via' => ['variants' => self::stop(), 'order' => '--tw-gradient-via'],
            'to' => ['variants' => self::stop(), 'order' => '--tw-gradient-to'],
        ];

        foreach (self::CORNERS as $side => $props) {
            $rows[$side === '' ? 'rounded' : 'rounded-' . $side] = [
                'props' => $props,
                'theme' => ['radius'],
                'named' => ['none' => '0', 'full' => 'calc(infinity * 1px)'],
                'arbitrary' => true,
                'default' => '0.25rem',
            ];
        }

        foreach (self::BORDER_SIDES as $side => $prefix) {
            $variants = [self::color([$prefix . '-color'])];

            if ($side === '') {
                $variants[] = ['handler' => 'border', 'named' => self::BORDER_STYLES];
            }

            $variants[] = [
                'handler' => 'border',
                'bare' => 'pixel',
                'arbitrary' => ['line-width', 'length', 'number'],
                'default' => '1px',
                'properties' => ['--tw-border-style'],
            ];
            $variants[] = self::anyColor('border');
            $rows[$side === '' ? 'border' : 'border-' . $side] = ['variants' => $variants];
        }

        $width = [
            'handler' => 'divide',
            'bare' => 'pixel',
            'arbitrary' => true,
            'default' => '1px',
            'properties' => ['--tw-border-style'],
        ];

        return $rows + [
            'divide-x' => $width + ['order' => 'divide-x-width'],
            'divide-y' => $width + ['order' => 'divide-y-width'],
            'divide' => ['order' => 'divide-color', 'variants' => [
                ['handler' => 'divide', 'theme' => ['color'], 'arbitrary' => ['color'], 'modifier' => 'opacity'],
                ['handler' => 'divide', 'named' => self::DIVIDE_STYLES],
                ['handler' => 'divide', 'arbitrary' => true, 'modifier' => 'opacity'],
            ]],
            'divide-x-reverse' => ['handler' => 'divideReverse'],
            'divide-y-reverse' => ['handler' => 'divideReverse'],
            'outline' => ['variants' => [
                self::color(['outline-color']),
                [
                    'handler' => 'outline',
                    'named' => ['solid' => 'solid', 'dashed' => 'dashed', 'dotted' => 'dotted', 'double' => 'double'],
                ],
                [
                    'handler' => 'outline',
                    'bare' => 'pixel',
                    'arbitrary' => ['length', 'number', 'percentage'],
                    'default' => '1px',
                    'properties' => ['--tw-outline-style'],
                ],
                self::anyColor('outline'),
            ]],
            'outline-none' => ['static' => ['--tw-outline-style' => 'none', 'outline-style' => 'none']],
            'outline-hidden' => ['handler' => 'outlineHidden'],
            'outline-offset' => ['handler' => 'outline', 'bare' => 'pixel', 'negative' => true, 'arbitrary' => true],
            'ring' => ['variants' => [
                self::color(['--tw-ring-color']),
                [
                    'handler' => 'ring',
                    'bare' => 'pixel',
                    'arbitrary' => ['length'],
                    'default' => '1px',
                    'properties' => self::RING_PROPERTIES,
                ],
                self::anyColor('ring'),
            ]],
            'ring-inset' => ['static' => ['--tw-ring-inset' => 'inset'], 'properties' => self::RING_PROPERTIES],
            'ring-offset' => ['variants' => [
                self::color(['--tw-ring-offset-color']),
                ['handler' => 'ring', 'bare' => 'pixel', 'arbitrary' => ['length']],
                self::anyColor('ring'),
            ]],
        ];
    }

    /**
     * `bg-*`: keywords per property, the colour, the typed arbitrary values,
     * and the colour again for any other arbitrary value.
     *
     * @return list<array<string, mixed>>
     */
    private static function background(): array
    {
        return [
            ['props' => ['background-attachment'], 'named' => [
                'fixed' => 'fixed', 'local' => 'local', 'scroll' => 'scroll',
            ]],
            ['props' => ['background-clip'], 'named' => [
                'clip-border' => 'border-box', 'clip-padding' => 'padding-box', 'clip-content' => 'content-box',
                'clip-text' => 'text',
            ]],
            ['props' => ['background-origin'], 'named' => [
                'origin-border' => 'border-box', 'origin-padding' => 'padding-box', 'origin-content' => 'content-box',
            ]],
            ['props' => ['background-position'], 'named' => [
                'bottom' => 'bottom', 'center' => 'center', 'left' => 'left', 'left-bottom' => 'left bottom',
                'left-top' => 'left top', 'right' => 'right', 'right-bottom' => 'right bottom',
                'right-top' => 'right top', 'top' => 'top',
            ]],
            ['props' => ['background-repeat'], 'named' => [
                'repeat' => 'repeat', 'no-repeat' => 'no-repeat', 'repeat-x' => 'repeat-x', 'repeat-y' => 'repeat-y',
                'repeat-round' => 'round', 'repeat-space' => 'space',
            ]],
            ['props' => ['background-size'], 'named' => ['auto' => 'auto', 'cover' => 'cover', 'contain' => 'contain']],
            ['props' => ['background-image'], 'named' => ['none' => 'none']],
            self::color(['background-color']),
            ['props' => ['background-image'], 'arbitrary' => ['image', 'url']],
            ['props' => ['background-position'], 'arbitrary' => ['percentage', 'position']],
            ['props' => ['background-size'], 'arbitrary' => ['bg-size', 'length']],
            ['props' => ['background-color'], 'arbitrary' => true, 'modifier' => 'opacity'],
        ];
    }

    /**
     * `from-*` / `via-*` / `to-*`: a colour stop, then a stop position.
     *
     * @return list<array<string, mixed>>
     */
    private static function stop(): array
    {
        return [
            [
                'handler' => 'gradient',
                'theme' => ['color'],
                'arbitrary' => ['color'],
                'modifier' => 'opacity',
                'properties' => self::GRADIENT_PROPERTIES,
            ],
            [
                'handler' => 'gradient',
                'bare' => 'percentage',
                'arbitrary' => ['length', 'percentage'],
                'properties' => self::GRADIENT_PROPERTIES,
            ],
            [
                'handler' => 'gradient',
                'arbitrary' => true,
                'modifier' => 'opacity',
                'properties' => self::GRADIENT_PROPERTIES,
            ],
        ];
    }

    /**
     * A colour sub-definition: theme colours, an arbitrary `[color]` or a
     * variable, with an opacity modifier.
     *
     * @param list<string> $props
     *
     * @return array<string, mixed>
     */
    private static function color(array $props): array
    {
        return ['props' => $props, 'theme' => ['color'], 'arbitrary' => ['color'], 'modifier' => 'opacity'];
    }

    /**
     * The last colour of a root: any other arbitrary value is a colour, as
     * the CLI's `default:` branch reads it (`border-[foo]` → `border-color: foo`,
     * `border-[var(--x)]`). Through the handler, which first refuses a value
     * typed as one of the root's other kinds (`border-[3px]/50` is nothing).
     *
     * @return array<string, mixed>
     */
    private static function anyColor(string $handler): array
    {
        return ['handler' => $handler, 'arbitrary' => true, 'modifier' => 'opacity'];
    }
}
