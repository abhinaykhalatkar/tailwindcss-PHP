<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Handlers;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\PropertyOrder;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Handlers\SpacingSizing;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The spacing and sizing handler (TASK-0036): `space` for `space-x`,
 * `space-y` and their `-reverse` forms. Each case runs the real parser,
 * registry and compiler; the shapes are the pinned 4.1.18 CLI's.
 */
final class SpacingSizingTest extends TestCase
{
    private const CHILDREN = ':where(& > :not(:last-child))';

    /** @test */
    public function theFamilyImplementsTheContractAndOwnsTheSpaceHandlers(): void
    {
        self::assertInstanceOf(FamilyHandlerInterface::class, new SpacingSizing(new ValueResolver()));
        self::assertSame(['space', 'spaceReverse'], SpacingSizing::names());
        self::assertArrayHasKey('space', Handlers::TAKES_VALUE);
        self::assertArrayHasKey('spaceReverse', Handlers::TAKES_VALUE);
        self::assertNotContains('space', (new Handlers(new ValueResolver()))->unimplemented());
        self::assertNotContains('spaceReverse', (new Handlers(new ValueResolver()))->unimplemented());
    }

    /**
     * @test
     *
     * @dataProvider shapes
     */
    public function eachFormHasTheCliShape(string $candidate, string $expected): void
    {
        self::assertSame($expected, self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapes(): array
    {
        $x = '--tw-space-x-reverse';
        $y = '--tw-space-y-reverse';

        return [
            'x scale' => [
                'space-x-4',
                self::CHILDREN . '{' . $x . ':0;margin-inline-start:calc(calc(var(--spacing) * 4) * var(' . $x . '));'
                . 'margin-inline-end:calc(calc(var(--spacing) * 4) * calc(1 - var(' . $x . ')))}',
            ],
            'y scale' => [
                'space-y-0.5',
                self::CHILDREN . '{' . $y . ':0;margin-block-start:calc(calc(var(--spacing) * 0.5) * var(' . $y . '));'
                . 'margin-block-end:calc(calc(var(--spacing) * 0.5) * calc(1 - var(' . $y . ')))}',
            ],
            'zero' => [
                'space-x-0',
                self::CHILDREN . '{' . $x . ':0;margin-inline-start:calc(calc(var(--spacing) * 0) * var(' . $x . '));'
                . 'margin-inline-end:calc(calc(var(--spacing) * 0) * calc(1 - var(' . $x . ')))}',
            ],
            'px' => [
                'space-y-px',
                self::CHILDREN . '{' . $y . ':0;margin-block-start:calc(1px * var(' . $y . '));'
                . 'margin-block-end:calc(1px * calc(1 - var(' . $y . ')))}',
            ],
            'negative px' => [
                '-space-y-px',
                self::CHILDREN . '{' . $y . ':0;margin-block-start:calc(-1px * var(' . $y . '));'
                . 'margin-block-end:calc(-1px * calc(1 - var(' . $y . ')))}',
            ],
            'negative scale' => [
                '-space-x-0.5',
                self::CHILDREN . '{' . $x . ':0;'
                . 'margin-inline-start:calc(calc(var(--spacing) * -0.5) * var(' . $x . '));'
                . 'margin-inline-end:calc(calc(var(--spacing) * -0.5) * calc(1 - var(' . $x . ')))}',
            ],
            'arbitrary' => [
                'space-x-[10px]',
                self::CHILDREN . '{' . $x . ':0;margin-inline-start:calc(10px * var(' . $x . '));'
                . 'margin-inline-end:calc(10px * calc(1 - var(' . $x . ')))}',
            ],
            'variable' => [
                'space-y-(--gap)',
                self::CHILDREN . '{' . $y . ':0;margin-block-start:calc(var(--gap) * var(' . $y . '));'
                . 'margin-block-end:calc(var(--gap) * calc(1 - var(' . $y . ')))}',
            ],
            'x reverse' => ['space-x-reverse', self::CHILDREN . '{' . $x . ':1}'],
            'y reverse' => ['space-y-reverse', self::CHILDREN . '{' . $y . ':1}'],
            'important reaches the nested rule' => [
                'space-x-reverse!',
                self::CHILDREN . '{' . $x . ':1!important}',
            ],
        ];
    }

    /**
     * What the CLI refuses, the handler refuses: keywords the scale does not
     * have, fractions, off-scale decimals, a negative flag, a value after
     * `-reverse`, an axis that does not exist, a modifier.
     *
     * @test
     *
     * @dataProvider refusals
     */
    public function theCliRefusalsAreRefused(string $candidate): void
    {
        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusals(): array
    {
        return [
            'auto' => ['space-x-auto'],
            'full' => ['space-x-full'],
            'a fraction' => ['space-y-1/2'],
            'an off-scale decimal' => ['space-x-0.3'],
            'a negative reverse' => ['-space-x-reverse'],
            'a value after reverse' => ['space-x-reverse-2'],
            'an unknown axis' => ['space-z-4'],
            'a bare root' => ['space-x'],
            'a modifier' => ['space-x-4/2'],
            'the other reverse spelling' => ['space-reverse'],
        ];
    }

    /**
     * T-15: hostile arbitrary text is stopped by the parser before the
     * handler sees it, and the value a handler wraps in `calc()` is the
     * validated one.
     *
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileArbitraryValuesNeverReachTheHandler(string $candidate): void
    {
        $registry = Registry::default();

        foreach ((new CandidateParser($registry))->parse($candidate) as $reading) {
            self::assertNotInstanceOf(ArbitraryValue::class, $reading->value, $candidate);
        }

        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileCandidates(): array
    {
        return [
            'semicolon' => ['space-x-[1px;color:red]'],
            'close brace' => ['space-y-[1px}a{b:c]'],
            'comment' => ['space-x-[1px/*x*/]'],
            'script url' => ['space-y-[url(javascript:alert(1))]'],
            'negative semicolon' => ['-space-x-[1px;x:y]'],
        ];
    }

    /**
     * A candidate built around the parser (the value classes have public
     * constructors) cannot make the handler write what it was not given: a
     * static `space-x`, a root the handler does not know, a value that is not
     * a number or an arbitrary one.
     *
     * @test
     */
    public function aCraftedCandidateOutsideTheContractGivesNothing(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $static = new Candidate('space-x', [], false, false, Candidate::STATIC, 'space-x', null);
        $bogus = new NamedValue('bogus');
        $keyword = new Candidate('space-x-bogus', [], false, false, Candidate::FUNCTIONAL, 'space-x', $bogus);

        self::assertNull($compiler->compile($static, $theme));
        self::assertNull($compiler->compile($keyword, $theme));

        // The reverse roots only answer their static form.
        $two = new NamedValue('2');
        $reverseValue = new Candidate('x', [], false, false, Candidate::FUNCTIONAL, 'space-x-reverse', $two);
        $reverseNegative = new Candidate('y', [], false, true, Candidate::STATIC, 'space-y-reverse', null);

        self::assertNull($compiler->compile($reverseValue, $theme));
        self::assertNull($compiler->compile($reverseNegative, $theme));
    }

    /**
     * The scale reads `--spacing` and reports it, never marking it used (the
     * emitter does); a literal or arbitrary value reads no variable.
     *
     * @test
     */
    public function theSpacingVariableIsReportedNotMarked(): void
    {
        $theme = self::theme();
        $compiled = self::compiled('space-x-4', $theme);

        self::assertNotNull($compiled);
        self::assertSame(['--spacing'], $compiled->usedVars);
        self::assertSame([], self::compiledUsed('space-x-px'));
        self::assertSame([], self::compiledUsed('space-y-[3px]'));
        self::assertSame([], self::compiledUsed('space-x-reverse'));
    }

    /**
     * The CLI sorts `space-x` as if it declared `row-gap` and `space-y` as
     * `column-gap` (`--tw-sort`), whatever the nested rule declares.
     *
     * @test
     */
    public function theSpaceUtilitiesSortAsGaps(): void
    {
        $row = [PropertyOrder::index('row-gap')];
        $column = [PropertyOrder::index('column-gap')];

        self::assertNotNull($row[0]);
        self::assertNotNull($column[0]);
        self::assertNotSame($row, $column);

        foreach (['space-x-4', '-space-x-px', 'space-x-reverse'] as $candidate) {
            $compiled = self::compiled($candidate);

            self::assertNotNull($compiled, $candidate);
            self::assertSame($row, $compiled->sortKey, $candidate);
        }

        foreach (['space-y-4', '-space-y-px', 'space-y-reverse'] as $candidate) {
            $compiled = self::compiled($candidate);

            self::assertNotNull($compiled, $candidate);
            self::assertSame($column, $compiled->sortKey, $candidate);
        }
    }

    /**
     * The reverse flag is a `--tw-*` custom property the assembler registers
     * with `@property`: each axis reports its own, the plain scale none.
     *
     * @test
     */
    public function theReverseFlagIsReportedForRegistration(): void
    {
        $expected = [
            'space-x-4' => ['--tw-space-x-reverse'],
            '-space-x-px' => ['--tw-space-x-reverse'],
            'space-x-reverse' => ['--tw-space-x-reverse'],
            'space-y-[3px]' => ['--tw-space-y-reverse'],
            'space-y-reverse' => ['--tw-space-y-reverse'],
            'p-4' => [],
            'w-1/2' => [],
        ];

        foreach ($expected as $candidate => $properties) {
            $compiled = self::compiled((string) $candidate);

            self::assertNotNull($compiled, (string) $candidate);
            self::assertSame($properties, $compiled->properties, (string) $candidate);
        }
    }

    /**
     * @return list<string>
     */
    private static function compiledUsed(string $candidate): array
    {
        $compiled = self::compiled($candidate);

        return $compiled === null ? ['(refused)'] : $compiled->usedVars;
    }

    private static function css(string $candidate): ?string
    {
        $compiled = self::compiled($candidate);

        return $compiled === null ? null : self::render($compiled->nodes);
    }

    private static function compiled(string $candidate, ?ThemeRegistry $theme = null): ?CompiledUtility
    {
        $registry = Registry::default();

        return (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            $theme ?? self::theme()
        );
    }

    /**
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $parts[] = $node->property . ':' . $node->value . ($node->important ? '!important' : '');
            } elseif ($node instanceof Rule) {
                $parts[] = $node->selector . '{' . self::render($node->nodes) . '}';
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::render($node->nodes ?? []) . '}';
            }
        }

        return \implode(';', $parts);
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }
}
