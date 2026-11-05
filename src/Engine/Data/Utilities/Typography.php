<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\Typography as TypographyHandlers;

/**
 * The typography utilities (design doc 08 §Typography, T1): the rows
 * `Data\Utilities::table()` merges in catalogue order, every value checked
 * against the pinned 4.1.18 CLI (TASK-0037).
 *
 * What the CLI does that the table has to say in data:
 *
 * - `font`, `text` and `decoration` are one root with several meanings. The
 *   sub-definitions are tried in order, and a variable without a type hint
 *   takes the first one that can hold it, so the order is the CLI's:
 *   `font-(--x)` is a weight, `text-(--x)` and `decoration-(--x)` a colour.
 * - `font-weight`, `leading` and `tracking` also set a `--tw-*` property
 *   (`--tw-font-weight`, `--tw-leading`, `--tw-tracking`) that the font-size
 *   utilities read as a fallback, so their rows list both properties.
 * - The font-variant-numeric utilities compose five `--tw-*` properties and
 *   register all five whichever one is used.
 * - `text-<size>` and `line-clamp` are handler rows (`fontSize`, `lineClamp`).
 */
final class Typography
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = TypographyHandlers::class;

    private const NUMERIC = 'var(--tw-ordinal,) var(--tw-slashed-zero,) var(--tw-numeric-figure,) '
        . 'var(--tw-numeric-spacing,) var(--tw-numeric-fraction,)';

    private const NUMERIC_PROPERTIES = [
        '--tw-ordinal',
        '--tw-slashed-zero',
        '--tw-numeric-figure',
        '--tw-numeric-spacing',
        '--tw-numeric-fraction',
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
            'font' => [
                'variants' => [
                    [
                        'props' => ['--tw-font-weight', 'font-weight'],
                        'theme' => ['font-weight'],
                        'arbitrary' => ['number'],
                        'properties' => ['--tw-font-weight'],
                    ],
                    [
                        'props' => ['font-family'],
                        'theme' => ['font'],
                        'arbitrary' => ['family-name', 'generic-name'],
                    ],
                    [
                        'props' => ['--tw-font-weight', 'font-weight'],
                        'arbitrary' => true,
                        'properties' => ['--tw-font-weight'],
                    ],
                ],
            ],
            // The typed colour comes first so a variable without a hint stays a colour (`text-(--x)`); the last
            // sub-definition takes any other arbitrary value as a colour too (`text-[var(--x)]`, `text-[foo]`), as
            // the CLI does and as the paint rows of `fill` and `stroke` do.
            'text' => [
                'variants' => [
                    [
                        'props' => ['color'],
                        'theme' => ['color'],
                        'modifier' => 'opacity',
                        'arbitrary' => ['color'],
                    ],
                    [
                        'handler' => 'fontSize',
                        'theme' => ['text'],
                        'modifier' => 'line-height',
                        'arbitrary' => ['length', 'percentage', 'absolute-size', 'relative-size'],
                    ],
                    [
                        'props' => ['text-align'],
                        'named' => self::keywords(['left', 'center', 'right', 'justify', 'start', 'end']),
                    ],
                    [
                        'props' => ['text-wrap'],
                        'named' => self::keywords(['wrap', 'nowrap', 'balance', 'pretty']),
                    ],
                    ['props' => ['color'], 'modifier' => 'opacity', 'arbitrary' => true],
                ],
            ],
            'antialiased' => [
                'static' => ['-webkit-font-smoothing' => 'antialiased', '-moz-osx-font-smoothing' => 'grayscale'],
            ],
            'subpixel-antialiased' => [
                'static' => ['-webkit-font-smoothing' => 'auto', '-moz-osx-font-smoothing' => 'auto'],
            ],
            'italic' => ['static' => ['font-style' => 'italic']],
            'not-italic' => ['static' => ['font-style' => 'normal']],
            'normal-nums' => ['static' => ['font-variant-numeric' => 'normal']],
            'ordinal' => self::numeric('--tw-ordinal', 'ordinal'),
            'slashed-zero' => self::numeric('--tw-slashed-zero', 'slashed-zero'),
            'lining-nums' => self::numeric('--tw-numeric-figure', 'lining-nums'),
            'oldstyle-nums' => self::numeric('--tw-numeric-figure', 'oldstyle-nums'),
            'proportional-nums' => self::numeric('--tw-numeric-spacing', 'proportional-nums'),
            'tabular-nums' => self::numeric('--tw-numeric-spacing', 'tabular-nums'),
            'diagonal-fractions' => self::numeric('--tw-numeric-fraction', 'diagonal-fractions'),
            'stacked-fractions' => self::numeric('--tw-numeric-fraction', 'stacked-fractions'),
            'tracking' => [
                'props' => ['--tw-tracking', 'letter-spacing'],
                'properties' => ['--tw-tracking'],
                'theme' => ['tracking'],
                'negative' => true,
                'arbitrary' => true,
            ],
            'leading' => [
                'props' => ['--tw-leading', 'line-height'],
                'properties' => ['--tw-leading'],
                'named' => ['none' => '1', 'px' => '1px'],
                'theme' => ['leading'],
                'bare' => 'spacing-multiplier',
                'arbitrary' => true,
            ],
            'line-clamp' => [
                'handler' => 'lineClamp',
                'named' => ['none' => 'none'],
                'bare' => 'line-count',
                'arbitrary' => true,
            ],
            'list' => [
                'props' => ['list-style-type'],
                'named' => self::keywords(['none', 'disc', 'decimal']),
                'arbitrary' => true,
            ],
            'list-image' => [
                'props' => ['list-style-image'],
                'named' => ['none' => 'none'],
                'arbitrary' => true,
            ],
            'list-inside' => ['static' => ['list-style-position' => 'inside']],
            'list-outside' => ['static' => ['list-style-position' => 'outside']],
            // As `text`: the typed colour first, a catch-all colour last (`decoration-[auto]` is a colour in the CLI).
            'decoration' => [
                'variants' => [
                    [
                        'props' => ['text-decoration-color'],
                        'theme' => ['color'],
                        'modifier' => 'opacity',
                        'arbitrary' => ['color'],
                    ],
                    [
                        'props' => ['text-decoration-thickness'],
                        'named' => ['auto' => 'auto', 'from-font' => 'from-font'],
                        'bare' => 'pixel',
                        'arbitrary' => ['length', 'percentage'],
                    ],
                    [
                        'props' => ['text-decoration-style'],
                        'named' => self::keywords(['solid', 'double', 'dotted', 'dashed', 'wavy']),
                    ],
                    ['props' => ['text-decoration-color'], 'modifier' => 'opacity', 'arbitrary' => true],
                ],
            ],
            'underline' => ['static' => ['text-decoration-line' => 'underline']],
            'overline' => ['static' => ['text-decoration-line' => 'overline']],
            'line-through' => ['static' => ['text-decoration-line' => 'line-through']],
            'no-underline' => ['static' => ['text-decoration-line' => 'none']],
            'underline-offset' => [
                'props' => ['text-underline-offset'],
                'named' => ['auto' => 'auto'],
                'bare' => 'pixel',
                'negative' => true,
                'arbitrary' => true,
            ],
            'uppercase' => ['static' => ['text-transform' => 'uppercase']],
            'lowercase' => ['static' => ['text-transform' => 'lowercase']],
            'capitalize' => ['static' => ['text-transform' => 'capitalize']],
            'normal-case' => ['static' => ['text-transform' => 'none']],
            'truncate' => [
                'static' => ['overflow' => 'hidden', 'text-overflow' => 'ellipsis', 'white-space' => 'nowrap'],
            ],
            'text-ellipsis' => ['static' => ['text-overflow' => 'ellipsis']],
            'text-clip' => ['static' => ['text-overflow' => 'clip']],
            // The deprecated v3 name of `text-ellipsis` (TASK-0055).
            'overflow-ellipsis' => ['static' => ['text-overflow' => 'ellipsis']],
            'indent' => [
                'props' => ['text-indent'],
                'named' => ['px' => '1px'],
                'bare' => 'spacing-multiplier',
                'negative' => true,
                'arbitrary' => true,
            ],
            'align' => [
                'props' => ['vertical-align'],
                'named' => self::keywords([
                    'baseline', 'top', 'middle', 'bottom', 'text-top', 'text-bottom', 'sub', 'super',
                ]),
                'arbitrary' => true,
            ],
            'whitespace' => [
                'props' => ['white-space'],
                'named' => self::keywords(['normal', 'nowrap', 'pre', 'pre-line', 'pre-wrap', 'break-spaces']),
            ],
            'hyphens' => [
                'props' => ['-webkit-hyphens', 'hyphens'],
                'named' => self::keywords(['none', 'manual', 'auto']),
            ],
            'break-normal' => ['static' => ['overflow-wrap' => 'normal', 'word-break' => 'normal']],
            'break-words' => ['static' => ['overflow-wrap' => 'break-word']],
            'break-all' => ['static' => ['word-break' => 'break-all']],
            'break-keep' => ['static' => ['word-break' => 'keep-all']],
            'wrap-break-word' => ['static' => ['overflow-wrap' => 'break-word']],
            'wrap-anywhere' => ['static' => ['overflow-wrap' => 'anywhere']],
            'wrap-normal' => ['static' => ['overflow-wrap' => 'normal']],
        ];
    }

    /**
     * A font-variant-numeric utility: its own `--tw-*` part, then the
     * composition of all five. The CLI registers all five with `@property`
     * whichever one is used.
     *
     * @return array<string, mixed>
     */
    private static function numeric(string $property, string $value): array
    {
        return [
            'static' => [$property => $value, 'font-variant-numeric' => self::NUMERIC],
            'properties' => self::NUMERIC_PROPERTIES,
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
