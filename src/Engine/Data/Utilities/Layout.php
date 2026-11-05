<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data\Utilities;

use TailwindPhp\Engine\Utility\Handlers\Layout as LayoutHandlers;

/**
 * Layout utilities (design doc 08 §Layout, T1, plus `container` from §Corrections
 * from M2): the rows `Data\Utilities::table()` merges first.
 *
 * Every value here was checked against the pinned 4.1.18 CLI (TASK-0032): keyword
 * roots take only their keywords (no `[…]`, ENG doc 08 corrections), the
 * inset family's `px`/`full` negate to `-1px`/`-100%` while `auto` has no
 * negative, `-z-auto` is refused, and roots that accept arbitrary values take
 * any of them (a type hint is accepted and dropped: `top-[length:3px]` →
 * `top: 3px`).
 */
final class Layout
{
    /**
     * The family's handler class, built behind `Utility\Handlers`.
     */
    public const HANDLERS = LayoutHandlers::class;

    private const INSET_NAMED = ['auto' => 'auto', 'px' => '1px', 'full' => '100%'];

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
            'container' => ['handler' => 'container', 'order' => '--tw-container-component'],
            'aspect' => [
                'handler' => 'aspect',
                'props' => ['aspect-ratio'],
                'named' => ['auto' => 'auto', 'square' => '1 / 1'],
                'theme' => ['aspect'],
                'fraction' => true,
                'arbitrary' => true,
            ],
            'columns' => [
                'props' => ['columns'],
                'named' => ['auto' => 'auto'],
                'theme' => ['container'],
                'bare' => 'integer',
                'arbitrary' => true,
            ],
            'break-after' => ['props' => ['break-after'], 'named' => self::breaks()],
            'break-before' => ['props' => ['break-before'], 'named' => self::breaks()],
            'break-inside' => [
                'props' => ['break-inside'],
                'named' => self::keywords(['auto', 'avoid', 'avoid-page', 'avoid-column']),
            ],
            'box-decoration' => [
                'props' => ['-webkit-box-decoration-break', 'box-decoration-break'],
                'named' => self::keywords(['clone', 'slice']),
            ],
            // The deprecated v3 names of `box-decoration-slice` and `-clone` (TASK-0055).
            'decoration-slice' => [
                'static' => ['-webkit-box-decoration-break' => 'slice', 'box-decoration-break' => 'slice'],
            ],
            'decoration-clone' => [
                'static' => ['-webkit-box-decoration-break' => 'clone', 'box-decoration-break' => 'clone'],
            ],
            'box' => ['props' => ['box-sizing'], 'named' => ['border' => 'border-box', 'content' => 'content-box']],
            'block' => self::display('block'),
            'inline-block' => self::display('inline-block'),
            'inline' => self::display('inline'),
            'inline-flex' => self::display('inline-flex'),
            'table' => self::display('table'),
            'inline-table' => self::display('inline-table'),
            'table-caption' => self::display('table-caption'),
            'table-cell' => self::display('table-cell'),
            'table-column' => self::display('table-column'),
            'table-column-group' => self::display('table-column-group'),
            'table-footer-group' => self::display('table-footer-group'),
            'table-header-group' => self::display('table-header-group'),
            'table-row-group' => self::display('table-row-group'),
            'table-row' => self::display('table-row'),
            'flow-root' => self::display('flow-root'),
            'grid' => self::display('grid'),
            'inline-grid' => self::display('inline-grid'),
            'contents' => self::display('contents'),
            'list-item' => self::display('list-item'),
            'hidden' => self::display('none'),
            'flex' => [
                'variants' => [
                    ['static' => ['display' => 'flex']],
                    [
                        'props' => ['flex'],
                        'named' => ['auto' => 'auto', 'initial' => '0 auto', 'none' => 'none'],
                        'bare' => 'integer',
                        'fraction' => true,
                        'arbitrary' => true,
                    ],
                ],
            ],
            'sr-only' => [
                'static' => [
                    'position' => 'absolute',
                    'width' => '1px',
                    'height' => '1px',
                    'padding' => '0',
                    'margin' => '-1px',
                    'overflow' => 'hidden',
                    'clip-path' => 'inset(50%)',
                    'white-space' => 'nowrap',
                    'border-width' => '0',
                ],
            ],
            'not-sr-only' => [
                'static' => [
                    'position' => 'static',
                    'width' => 'auto',
                    'height' => 'auto',
                    'padding' => '0',
                    'margin' => '0',
                    'overflow' => 'visible',
                    'clip-path' => 'none',
                    'white-space' => 'normal',
                ],
            ],
            'float' => [
                'props' => ['float'],
                'named' => [
                    'right' => 'right',
                    'left' => 'left',
                    'start' => 'inline-start',
                    'end' => 'inline-end',
                    'none' => 'none',
                ],
            ],
            'clear' => [
                'props' => ['clear'],
                'named' => [
                    'left' => 'left',
                    'right' => 'right',
                    'both' => 'both',
                    'none' => 'none',
                    'start' => 'inline-start',
                    'end' => 'inline-end',
                ],
            ],
            'isolate' => ['static' => ['isolation' => 'isolate']],
            'isolation-auto' => ['static' => ['isolation' => 'auto']],
            'object' => [
                'variants' => [
                    [
                        'props' => ['object-fit'],
                        'named' => self::keywords(['contain', 'cover', 'fill', 'none', 'scale-down']),
                    ],
                    [
                        'props' => ['object-position'],
                        'named' => [
                            'bottom' => 'bottom',
                            'center' => 'center',
                            'left' => 'left',
                            'left-bottom' => 'left bottom',
                            'left-top' => 'left top',
                            'right' => 'right',
                            'right-bottom' => 'right bottom',
                            'right-top' => 'right top',
                            'top' => 'top',
                        ],
                        'arbitrary' => true,
                    ],
                ],
            ],
            'overflow' => ['props' => ['overflow'], 'named' => self::overflows()],
            'overflow-x' => ['props' => ['overflow-x'], 'named' => self::overflows()],
            'overflow-y' => ['props' => ['overflow-y'], 'named' => self::overflows()],
            'overscroll' => ['props' => ['overscroll-behavior'], 'named' => self::overscrolls()],
            'overscroll-x' => ['props' => ['overscroll-behavior-x'], 'named' => self::overscrolls()],
            'overscroll-y' => ['props' => ['overscroll-behavior-y'], 'named' => self::overscrolls()],
            'static' => ['static' => ['position' => 'static']],
            'fixed' => ['static' => ['position' => 'fixed']],
            'absolute' => ['static' => ['position' => 'absolute']],
            'relative' => ['static' => ['position' => 'relative']],
            'sticky' => ['static' => ['position' => 'sticky']],
            'inset' => self::inset('inset'),
            'inset-x' => self::inset('inset-inline'),
            'inset-y' => self::inset('inset-block'),
            'start' => self::inset('inset-inline-start'),
            'end' => self::inset('inset-inline-end'),
            'top' => self::inset('top'),
            'right' => self::inset('right'),
            'bottom' => self::inset('bottom'),
            'left' => self::inset('left'),
            'visible' => ['static' => ['visibility' => 'visible']],
            'invisible' => ['static' => ['visibility' => 'hidden']],
            'collapse' => ['static' => ['visibility' => 'collapse']],
            'z' => [
                'props' => ['z-index'],
                'named' => ['auto' => 'auto'],
                'bare' => 'integer',
                'negative' => true,
                'arbitrary' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function display(string $value): array
    {
        return ['static' => ['display' => $value]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function inset(string $property): array
    {
        return [
            'props' => [$property],
            'named' => self::INSET_NAMED,
            'bare' => 'spacing-multiplier',
            'fraction' => true,
            'negative' => true,
            'arbitrary' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function breaks(): array
    {
        return self::keywords(['auto', 'avoid', 'all', 'avoid-page', 'page', 'left', 'right', 'column']);
    }

    /**
     * @return array<string, string>
     */
    private static function overflows(): array
    {
        return self::keywords(['auto', 'hidden', 'clip', 'visible', 'scroll']);
    }

    /**
     * @return array<string, string>
     */
    private static function overscrolls(): array
    {
        return self::keywords(['auto', 'contain', 'none']);
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
