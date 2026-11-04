<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Variant\Definition;

/**
 * The hand-maintained T1 variant table (design doc 07 §Phase 3c, doc 08 §Variants).
 *
 * The expected names are written out here from doc 08 and the expected order from
 * the pinned 4.1.18 CLI (one `<variant>:flex` per variant, compiled together and
 * read back in the order the CLI emitted them), never derived from the table.
 */
final class VariantsTest extends TestCase
{
    private const PSEUDO_CLASSES = [
        'hover', 'focus', 'focus-within', 'focus-visible', 'active', 'visited', 'target', 'first', 'last', 'only',
        'odd', 'even', 'first-of-type', 'last-of-type', 'only-of-type', 'empty', 'disabled', 'enabled', 'checked',
        'indeterminate', 'default', 'optional', 'required', 'valid', 'invalid', 'in-range', 'out-of-range',
        'placeholder-shown', 'autofill', 'read-only', 'user-valid', 'user-invalid', 'open', 'inert',
    ];

    private const PSEUDO_ELEMENTS = [
        'before', 'after', 'first-letter', 'first-line', 'marker', 'selection', 'file', 'backdrop', 'placeholder',
        'details-content',
    ];

    private const MEDIA = [
        'dark', 'print', 'motion-safe', 'motion-reduce', 'contrast-more', 'contrast-less', 'portrait', 'landscape',
        'forced-colors', 'inverted-colors', 'pointer-none', 'pointer-coarse', 'pointer-fine', 'any-pointer-none',
        'any-pointer-coarse', 'any-pointer-fine', 'noscript',
    ];

    /**
     * Doc 08 §Variants rows that are T2 (or later): none of them may be in the table yet.
     */
    private const NOT_YET = [
        'has', 'not', 'in', 'data', 'aria', 'nth', 'nth-last', 'nth-of-type', 'nth-last-of-type', 'supports',
        'ltr', 'rtl', 'starting', '@', 'forced', 'force',
    ];

    /**
     * The CLI's order of the T1 rows, first to last.
     */
    private const CLI_ORDER = [
        '*', '**', 'group', 'peer',
        'first-letter', 'first-line', 'marker', 'selection', 'file', 'placeholder', 'backdrop', 'details-content',
        'before', 'after',
        'first', 'last', 'only', 'odd', 'even', 'first-of-type', 'last-of-type', 'only-of-type', 'visited', 'target',
        'open', 'default', 'checked', 'indeterminate', 'placeholder-shown', 'autofill', 'optional', 'required',
        'valid', 'invalid', 'user-valid', 'user-invalid', 'in-range', 'out-of-range', 'read-only', 'empty',
        'focus-within', 'hover', 'focus', 'focus-visible', 'active', 'enabled', 'disabled', 'inert',
        'motion-safe', 'motion-reduce', 'contrast-more', 'contrast-less',
        'max', 'min',
        'portrait', 'landscape', 'dark', 'print', 'forced-colors', 'inverted-colors', 'pointer-none',
        'pointer-coarse', 'pointer-fine', 'any-pointer-none', 'any-pointer-coarse', 'any-pointer-fine', 'noscript',
    ];

    /** @test */
    public function everyT1MemberOfDoc08IsARow(): void
    {
        $table = Variants::table();
        $expected = \array_merge(
            self::PSEUDO_CLASSES,
            self::PSEUDO_ELEMENTS,
            self::MEDIA,
            ['*', '**', 'group', 'peer', 'min', 'max']
        );

        foreach ($expected as $name) {
            self::assertArrayHasKey($name, $table, $name . ' is missing from the variant table.');
        }

        self::assertCount(\count($expected), $table, 'The table has rows beyond doc 08\'s T1 members.');
    }

    /** @test */
    public function noT2MemberIsARowYet(): void
    {
        foreach (self::NOT_YET as $name) {
            self::assertArrayNotHasKey($name, Variants::table(), $name . ' is not T1.');
        }
    }

    /** @test */
    public function breakpointVariantsAreNotRows(): void
    {
        $table = Variants::table();

        foreach (['sm', 'md', 'lg', 'xl', '2xl', 'max-sm', 'max-md', 'min-md'] as $name) {
            self::assertArrayNotHasKey($name, $table, $name . ' is generated from the theme, not a row.');
        }
    }

    /** @test */
    public function everyRowIsAValidDefinition(): void
    {
        foreach (Variants::table() as $name => $row) {
            $definition = Definition::fromRow((string) $name, $row);

            self::assertSame((string) $name, $definition->name());
        }
    }

    /** @test */
    public function theRowsAreInTheCliOrderAndTheOrdersAreUnique(): void
    {
        $orders = [];

        foreach (Variants::table() as $name => $row) {
            $orders[(string) $name] = $row['order'];
        }

        self::assertSame(\count($orders), \count(\array_unique($orders)), 'Two rows share an order.');

        \asort($orders);

        self::assertSame(self::CLI_ORDER, \array_keys($orders));
    }

    /** @test */
    public function theCompoundBasesComeBeforeEveryPlainVariantAndAfterTheChildSelectors(): void
    {
        $table = Variants::table();

        self::assertGreaterThan($table['**']['order'], $table['group']['order']);
        self::assertGreaterThan($table['group']['order'], $table['peer']['order']);
        self::assertLessThan($table['first-letter']['order'], $table['peer']['order'] + 1000);
    }

    /** @test */
    public function theCompoundBasesAreTemplatesForGroupAndPeer(): void
    {
        $table = Variants::table();

        self::assertSame('group', $table['group']['compound']);
        self::assertSame('&:is({inner} *)', $table['group']['selector']);
        self::assertSame('peer', $table['peer']['compound']);
        self::assertSame('&:is({inner} ~ *)', $table['peer']['selector']);
    }

    /**
     * @test
     *
     * @dataProvider selectorRows
     *
     * @param string|list<string>|null $selector
     */
    public function aRowHasTheSelectorTheCliEmits(string $name, $selector, ?string $media): void
    {
        $row = Variants::table()[$name];

        self::assertSame($selector, $row['selector'] ?? null);
        self::assertSame($media, $row['media'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string|null}>
     */
    public static function selectorRows(): array
    {
        return [
            'hover has its media query' => ['hover', '&:hover', '(hover: hover)'],
            'focus' => ['focus', '&:focus', null],
            'first' => ['first', '&:first-child', null],
            'odd' => ['odd', '&:nth-child(odd)', null],
            'open lists its three states' => ['open', '&:is([open], :popover-open, :open)', null],
            'inert matches itself and its descendants' => ['inert', '&:is([inert], [inert] *)', null],
            'before' => ['before', '&::before', null],
            'file is the selector button' => ['file', '&::file-selector-button', null],
            'marker is four selectors' => [
                'marker',
                ['& *::marker', '&::marker', '& *::-webkit-details-marker', '&::-webkit-details-marker'],
                null,
            ],
            'selection is two selectors' => ['selection', ['& *::selection', '&::selection'], null],
            'child' => ['*', ':is(& > *)', null],
            'descendant' => ['**', ':is(& *)', null],
            'dark is only a media query' => ['dark', null, '(prefers-color-scheme: dark)'],
            'print' => ['print', null, 'print'],
            'noscript' => ['noscript', null, '(scripting: none)'],
            'min is a template' => ['min', null, '(width >= {value})'],
            'max is a template' => ['max', null, '(width < {value})'],
        ];
    }

    /** @test */
    public function beforeAndAfterInjectTheContentDeclaration(): void
    {
        $table = Variants::table();

        self::assertSame(['content' => 'var(--tw-content)'], $table['before']['inject']);
        self::assertSame(['content' => 'var(--tw-content)'], $table['after']['inject']);
    }

    /** @test */
    public function minAndMaxAreFunctionalOverTheBreakpointTheme(): void
    {
        $table = Variants::table();

        self::assertSame('min', $table['min']['functional']);
        self::assertSame('breakpoint', $table['min']['theme']);
        self::assertSame('max', $table['max']['functional']);
        self::assertSame('breakpoint', $table['max']['theme']);
    }

    /** @test */
    public function theTableHoldsOnlyLiterals(): void
    {
        $found = false;
        $table = Variants::table();

        \array_walk_recursive($table, static function ($value) use (&$found): void {
            if (\is_object($value) || \is_resource($value)) {
                $found = true;
            }
        });

        self::assertFalse($found, 'The table must hold no closures or objects.');
    }

    /** @test */
    public function theTableIsStableBetweenCalls(): void
    {
        self::assertSame(Variants::table(), Variants::table());
    }
}
