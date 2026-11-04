<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\ValueInterface;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * One row per rule of design doc 07 §Value resolution rules and per bare
 * kind; the expected strings are the pinned CLI's output for the same
 * utility where one exists (TASK-0032 probes).
 */
final class ValueResolverTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider rules
     *
     * @param array<string, mixed> $row
     * @param array{0: string, 1: list<string>}|null $expected
     */
    public function valuesResolveByTheRules(array $row, ValueInterface $value, bool $negative, ?array $expected): void
    {
        $resolved = (new ValueResolver())->resolve(Definition::fromRow('u', $row), $value, self::theme(), $negative);

        self::assertSame($expected, $resolved);
    }

    /**
     * @return array<string, array{
     *     0: array<string, mixed>,
     *     1: ValueInterface,
     *     2: bool,
     *     3: array{0: string, 1: list<string>}|null
     * }>
     */
    public static function rules(): array
    {
        $inset = [
            'props' => ['inset'],
            'named' => ['auto' => 'auto', 'px' => '1px', 'full' => '100%'],
            'bare' => 'spacing-multiplier',
            'fraction' => true,
            'negative' => true,
            'arbitrary' => true,
        ];
        $color = ['props' => ['color'], 'theme' => ['color'], 'arbitrary' => ['color']];
        $z = [
            'props' => ['z-index'],
            'named' => ['auto' => 'auto'],
            'bare' => 'integer',
            'negative' => true,
            'arbitrary' => true,
        ];
        $opacity = ['props' => ['opacity'], 'bare' => 'percentage'];
        $rotate = ['props' => ['rotate'], 'bare' => 'angle', 'negative' => true];
        $duration = ['props' => ['transition-duration'], 'bare' => 'duration'];
        $clamp = ['props' => ['-webkit-line-clamp'], 'bare' => 'line-count'];
        $offset = ['props' => ['text-underline-offset'], 'bare' => 'pixel', 'negative' => true];
        $columns = ['props' => ['columns'], 'theme' => ['container'], 'bare' => 'integer'];

        return [
            // Rule 1.
            'named literal' => [$inset, new NamedValue('auto'), false, ['auto', []]],
            'negative named length' => [$inset, new NamedValue('px'), true, ['-1px', []]],
            'negative named percentage' => [$inset, new NamedValue('full'), true, ['-100%', []]],
            'negative keyword has none' => [$inset, new NamedValue('auto'), true, null],
            // Rule 2.
            'theme variable' => [$columns, new NamedValue('xs'), false, ['var(--container-xs)', ['--container-xs']]],
            'theme colour' => [$color, new NamedValue('red-500'), false, ['var(--color-red-500)', ['--color-red-500']]],
            'colour keyword current' => [$color, new NamedValue('current'), false, ['currentcolor', []]],
            'colour keyword inherit' => [$color, new NamedValue('inherit'), false, ['inherit', []]],
            'black is a theme colour' => [
                $color,
                new NamedValue('black'),
                false,
                ['var(--color-black)', ['--color-black']],
            ],
            'unknown colour' => [$color, new NamedValue('nope-500'), false, null],
            // Rule 3, spacing-multiplier (v4 canonical numbers, steps of 0.25).
            'spacing integer' => [$inset, new NamedValue('4'), false, self::spacing('4')],
            'spacing zero' => [$inset, new NamedValue('0'), false, self::spacing('0')],
            'spacing quarter' => [$inset, new NamedValue('0.25'), false, self::spacing('0.25')],
            'spacing large' => [$inset, new NamedValue('1000'), false, self::spacing('1000')],
            'spacing negative folds the sign' => [$inset, new NamedValue('4'), true, self::spacing('-4')],
            'spacing negative zero' => [$inset, new NamedValue('0'), true, self::spacing('-0')],
            'spacing not a quarter' => [$inset, new NamedValue('0.3'), false, null],
            'spacing leading zero' => [$inset, new NamedValue('01'), false, null],
            'spacing trailing zero' => [$inset, new NamedValue('1.0'), false, null],
            'spacing exponent' => [$inset, new NamedValue('1e3'), false, null],
            'spacing plus sign' => [$inset, new NamedValue('+1'), false, null],
            'spacing bare dot' => [$inset, new NamedValue('.5'), false, null],
            // Rule 3, other kinds.
            'integer' => [$z, new NamedValue('10'), false, ['10', []]],
            'negative integer' => [$z, new NamedValue('10'), true, ['calc(10 * -1)', []]],
            'integer refuses decimals' => [$z, new NamedValue('1.5'), false, null],
            'negative auto refused' => [$z, new NamedValue('auto'), true, null],
            'percentage' => [$opacity, new NamedValue('50'), false, ['50%', []]],
            'angle' => [$rotate, new NamedValue('45'), true, ['calc(45deg * -1)', []]],
            'duration' => [$duration, new NamedValue('300'), false, ['300ms', []]],
            'line-count' => [$clamp, new NamedValue('3'), false, ['3', []]],
            'pixel' => [$offset, new NamedValue('2'), false, ['2px', []]],
            'pixel zero' => [$offset, new NamedValue('0'), false, ['0px', []]],
            'negative pixel' => [$offset, new NamedValue('2'), true, ['calc(2px * -1)', []]],
            'pixel refuses decimals' => [$offset, new NamedValue('1.5'), false, null],
            'no bare kind' => [['props' => ['float'], 'named' => ['left' => 'left']], new NamedValue('4'), false, null],
            // Rule 4.
            'fraction' => [$inset, new NamedValue('1/2', true), false, ['calc(1/2 * 100%)', []]],
            'negative fraction' => [$inset, new NamedValue('1/2', true), true, ['calc(calc(1/2 * 100%) * -1)', []]],
            'fraction over zero (as the CLI)' => [$inset, new NamedValue('1/0', true), false, ['calc(1/0 * 100%)', []]],
            'fraction with a leading zero' => [$inset, new NamedValue('01/2', true), false, null],
            'fraction where the row has none' => [$z, new NamedValue('1/2', true), false, null],
            // Rule 5.
            'arbitrary, any type' => [$inset, new ArbitraryValue('3px', '3px'), false, ['3px', []]],
            'arbitrary, any type drops the hint' => [
                $inset,
                new ArbitraryValue('color:red', 'red', 'color'),
                false,
                ['red', []],
            ],
            'arbitrary negative' => [$inset, new ArbitraryValue('3px', '3px'), true, ['calc(3px * -1)', []]],
            'arbitrary, typed, inferred' => [$color, new ArbitraryValue('#fff', '#fff'), false, ['#fff', []]],
            'arbitrary, typed, not that type' => [$color, new ArbitraryValue('3px', '3px'), false, null],
            'arbitrary, typed, hint accepted' => [
                $color,
                new ArbitraryValue('color:x', 'x', 'color'),
                false,
                ['x', []],
            ],
            'arbitrary, typed, hint refused' => [
                $color,
                new ArbitraryValue('length:3px', '3px', 'length'),
                false,
                null,
            ],
            'arbitrary where the row takes none' => [$columns, new ArbitraryValue('3', '3'), false, null],
            // Rule 6.
            'variable' => [$inset, new VarValue('--x'), false, ['var(--x)', []]],
            'negative variable' => [$inset, new VarValue('--x'), true, ['calc(var(--x) * -1)', []]],
            'typed variable, any hint' => [$color, new VarValue('--x'), false, ['var(--x)', []]],
            'typed variable, refused hint' => [$color, new VarValue('--x', 'length'), false, null],
            // Rule 8 gate.
            'negative where the row has none' => [$columns, new NamedValue('3'), true, null],
        ];
    }

    /** @test */
    public function inlineAndReferenceThemeValuesAreWrittenAsTheEmissionRuleSays(): void
    {
        $theme = self::theme();
        $theme->merge(['--container-inline' => '1rem'], new ThemeFlags(true, false, false, false));
        $theme->merge(['--container-ref' => '2rem'], new ThemeFlags(false, false, true, false));
        $columns = Definition::fromRow('columns', ['props' => ['columns'], 'theme' => ['container']]);
        $resolver = new ValueResolver();

        self::assertSame(['1rem', []], $resolver->resolve($columns, new NamedValue('inline'), $theme, false));
        self::assertSame(
            ['var(--container-ref, 2rem)', ['--container-ref']],
            $resolver->resolve($columns, new NamedValue('ref'), $theme, false)
        );
    }

    /** @test */
    public function spacingNeedsTheSpacingVariable(): void
    {
        $theme = new ThemeRegistry();
        $top = Definition::fromRow('top', ['props' => ['top'], 'bare' => 'spacing-multiplier']);

        self::assertNull((new ValueResolver())->resolve($top, new NamedValue('4'), $theme, false));
    }

    /** @test */
    public function theDefaultValueIsUsedForTheRootAlone(): void
    {
        $resolver = new ValueResolver();
        $border = Definition::fromRow('border', ['props' => ['border-width'], 'default' => '1px']);
        $plain = Definition::fromRow('bg', ['props' => ['background-color'], 'theme' => ['color']]);

        self::assertSame(['1px', []], $resolver->resolveDefault($border, self::theme(), false));
        self::assertNull($resolver->resolveDefault($plain, self::theme(), false));
        self::assertNull($resolver->resolveDefault($border, self::theme(), true));
    }

    /**
     * @test
     *
     * @dataProvider opacities
     */
    public function opacityModifiersBecomePercentages(Modifier $modifier, ?string $expected): void
    {
        $resolved = (new ValueResolver())->opacity($modifier, self::theme());

        self::assertSame($expected, $resolved === null ? null : $resolved[0]);
    }

    /**
     * @return array<string, array{0: Modifier, 1: string|null}>
     */
    public static function opacities(): array
    {
        return [
            // The CLI's readings (TASK-0032 probe of `bg-red-500/<modifier>`).
            'named' => [new Modifier(Modifier::NAMED, '50'), '50%'],
            'zero' => [new Modifier(Modifier::NAMED, '0'), '0%'],
            'a quarter step' => [new Modifier(Modifier::NAMED, '2.5'), '2.5%'],
            'over a hundred' => [new Modifier(Modifier::NAMED, '150'), '150%'],
            'not a quarter step' => [new Modifier(Modifier::NAMED, '2.3'), null],
            'leading zero' => [new Modifier(Modifier::NAMED, '01'), null],
            'not a number' => [new Modifier(Modifier::NAMED, 'half'), null],
            'arbitrary fraction' => [new Modifier(Modifier::ARBITRARY, '0.33'), '33%'],
            'arbitrary bare dot' => [new Modifier(Modifier::ARBITRARY, '.5'), '50%'],
            'arbitrary one' => [new Modifier(Modifier::ARBITRARY, '1'), '100%'],
            'arbitrary above one' => [new Modifier(Modifier::ARBITRARY, '1.5'), '150%'],
            'arbitrary fine fraction' => [new Modifier(Modifier::ARBITRARY, '0.125'), '12.5%'],
            'arbitrary percentage passes through' => [new Modifier(Modifier::ARBITRARY, '37%'), '37%'],
            'arbitrary anything passes through' => [new Modifier(Modifier::ARBITRARY, 'red'), 'red'],
            'variable' => [new Modifier(Modifier::VAR, '--o'), 'var(--o)'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider numbers
     */
    public function numbersAreCanonicalDecimals(string $text, bool $number, bool $integer): void
    {
        self::assertSame($number, ValueResolver::isNumber($text), $text);
        self::assertSame($integer, ValueResolver::isInteger($text), $text);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function numbers(): array
    {
        return [
            'zero' => ['0', true, true],
            'seven' => ['7', true, true],
            'ten' => ['10', true, true],
            'one and a half' => ['1.5', true, false],
            'a quarter' => ['0.25', true, false],
            'leading zero' => ['01', false, false],
            'trailing zero' => ['1.50', false, false],
            'bare dot' => ['.5', false, false],
            'dot only' => ['1.', false, false],
            'exponent' => ['1e3', false, false],
            'sign' => ['-1', false, false],
            'empty' => ['', false, false],
            'fifteen digits' => ['123456789012345', true, true],
            'sixteen digits' => ['1234567890123456', false, false],
        ];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function spacing(string $multiplier): array
    {
        return ['calc(var(--spacing) * ' . $multiplier . ')', ['--spacing']];
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }
}
