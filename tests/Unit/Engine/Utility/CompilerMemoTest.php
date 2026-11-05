<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Compiler as EngineCompiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tests\Support\Tripwire;

/**
 * The compiler memoises a body by the candidate's utility part, everything but its variants (TASK-0042,
 * T-05): every variant prefix of one utility builds its body once, a refusal included, and two utilities
 * that differ in any field are never confused.
 */
final class CompilerMemoTest extends TestCase
{
    private const IMPORT = '@import "tailwindcss" source(none);';

    private const CHAIN = 'first:last:odd:even:only:empty:visited:checked:';

    /**
     * @test
     *
     * @dataProvider sameUtility
     */
    public function theVariantsDoNotChangeTheUtilityPart(string $first, string $second): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $a = self::compiled($compiler, $first, $theme);
        $b = self::compiled($compiler, $second, $theme);

        self::assertInstanceOf(CompiledUtility::class, $a);
        self::assertSame($a, $b, 'the body is built once, not once per variant prefix');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sameUtility(): array
    {
        return [
            'a static utility' => ['hover:flex', 'focus:flex'],
            'with and without a variant' => ['flex', 'md:flex'],
            'a theme value' => ['hover:p-4', 'dark:lg:p-4'],
            'a modifier' => ['hover:bg-red-500/50', 'focus:bg-red-500/50'],
            'an arbitrary value' => ['hover:w-[13px]', 'focus:w-[13px]'],
            'an arbitrary property' => ['hover:[color:red]', 'focus:[color:red]'],
            'a variant that is itself arbitrary' => ['[&_.x1]:p-4', '[&_.x2]:p-4'],
        ];
    }

    /**
     * Any other field of the utility makes another utility.
     *
     * @test
     *
     * @dataProvider otherUtility
     */
    public function anyOtherFieldIsAnotherUtility(string $first, string $second): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        $a = self::compiled($compiler, $first, $theme);
        $b = self::compiled($compiler, $second, $theme);

        self::assertNotNull($a, $first);
        self::assertNotNull($b, $second);
        self::assertNotSame($a, $b);
        self::assertNotEquals($a->nodes, $b->nodes, $first . ' and ' . $second . ' do not compile alike');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherUtility(): array
    {
        return [
            'a value' => ['p-4', 'p-5'],
            'a root' => ['p-4', 'm-4'],
            'a modifier' => ['bg-red-500/50', 'bg-red-500/40'],
            'with and without a modifier' => ['bg-red-500/50', 'bg-red-500'],
            'a negative' => ['-m-4', 'm-4'],
            'important' => ['p-4!', 'p-4'],
            'an arbitrary value' => ['w-[1px]', 'w-[2px]'],
            'an arbitrary type hint' => ['text-[length:2px]', 'text-[color:red]'],
            'an arbitrary property value' => ['[color:red]', '[color:blue]'],
            'an arbitrary property name' => ['[color:red]', '[background:red]'],
            'a value that is a variable' => ['w-(--a)', 'w-(--b)'],
            'the type hint of a variable' => ['text-(length:--a)', 'text-(color:--a)'],
            'a fraction and a name' => ['w-1/2', 'w-px'],
        ];
    }

    /**
     * The key reads every field, also of a candidate built by hand (the value classes have public
     * constructors): the same text with another type hint, another normal form or another fraction flag is
     * another utility.
     *
     * @test
     */
    public function aCandidateBuiltByHandIsKeyedByEveryFieldOfItsValue(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();
        $make = static function (string $root, $value) {
            return new Candidate('x', [], false, false, Candidate::FUNCTIONAL, $root, $value);
        };

        $length = $compiler->compile($make('text', new ArbitraryValue('2px', '2px', 'length')), $theme);
        $color = $compiler->compile($make('text', new ArbitraryValue('2px', '2px', 'color')), $theme);
        $other = $compiler->compile($make('bg', new ArbitraryValue('red', 'blue', 'color')), $theme);
        $same = $compiler->compile($make('bg', new ArbitraryValue('red', 'red', 'color')), $theme);
        $whole = $compiler->compile($make('w', new NamedValue('1/2', false)), $theme);
        $fraction = $compiler->compile($make('w', new NamedValue('1/2', true)), $theme);

        self::assertNotSame($length, $color, 'the type hint');
        self::assertNotSame($other, $same, 'the normal form');
        self::assertNotNull($other);
        self::assertNotNull($same);
        self::assertNotEquals($other->nodes, $same->nodes);
        self::assertNotSame($whole, $fraction, 'the fraction flag');
        self::assertNull($whole, 'a name with a slash that is not a fraction is no width');
        self::assertNotNull($fraction);
    }

    /** @test */
    public function aRefusalIsMemoisedToo(): void
    {
        $compiler = new Compiler(Registry::default());
        $theme = self::theme();

        foreach (['hover:p-foo', 'focus:p-foo', 'hover:flex/50', 'focus:flex/50'] as $raw) {
            self::assertNull(self::compiled($compiler, $raw, $theme), $raw);
            self::assertNull(self::compiled($compiler, $raw, $theme), $raw . ' again');
        }
    }

    /**
     * The first answer stays: a utility part is compiled once per instance (the per-theme contract of the raw
     * memo applies, a second theme needs a second instance).
     *
     * @test
     */
    public function theMemoIsPerInstance(): void
    {
        $theme = self::theme();
        $first = self::compiled(new Compiler(Registry::default()), 'p-4', $theme);
        $second = self::compiled(new Compiler(Registry::default()), 'p-4', $theme);

        self::assertNotSame($first, $second);
        self::assertEquals($first, $second);
    }

    /**
     * T-05: a body that is built and then refused by the emission guard (an `@apply` chain spliced past the
     * depth limit) was walked again for every distinct variant text, 53 ms apiece, ≈ 27 minutes at the default
     * candidate cap. Now it is built once per utility: 30 000 candidates finish in a fraction of a second.
     *
     * @test
     */
    public function aRefusedBodyIsNotWalkedAgainForEveryVariantPrefix(): void
    {
        $candidates = [];

        for ($i = 0; $i < 30000; ++$i) {
            $candidates[] = '[&_.x' . $i . ']:u0';
        }

        $request = new CompileRequest(self::refusedChain(), new InMemoryImportResolver([]), $candidates);
        [$output, $cpu] = self::timed($request);

        self::assertSame(0, $output->stats['utilities']);
        // A tripwire, not a budget (EngineBench owns those): about 0.6 s here, 27 minutes before.
        Tripwire::assertUnder(36.0, $cpu, __FUNCTION__);
    }

    /**
     * The same through `@apply`: 200 variant prefixes of the refused utility applied to one rule.
     *
     * @test
     */
    public function aRefusedBodyAppliedUnderManyVariantPrefixesIsBuiltOnce(): void
    {
        $applied = [];

        for ($i = 0; $i < 200; ++$i) {
            $applied[] = '[&_.x' . $i . ']:u0';
        }

        $css = self::refusedChain() . '.h { @apply ' . \implode(' ', $applied) . '; }';
        [$output, $cpu] = self::timed(new CompileRequest($css, new InMemoryImportResolver([]), ['h']));

        self::assertSame(0, $output->stats['utilities']);
        // About 0.2 s here, 10.3 s before.
        Tripwire::assertUnder(8.0, $cpu, __FUNCTION__);
    }

    /**
     * `u0` applies a depth-four fan-out of ten variants (inside the budget) and a chain of eight stacked
     * variants over `d1 … d3`, which splices past the parser's depth limit: the body is built, then refused.
     */
    private static function refusedChain(): string
    {
        $variants = ['hover', 'focus', 'active', 'visited', 'disabled', 'first', 'last', 'odd', 'even', 'checked'];
        $css = self::IMPORT . "\n";

        for ($i = 0; $i < 4; ++$i) {
            $next = $i === 3 ? 'p-4' : 'w' . ($i + 1);
            $applied = [];

            foreach ($variants as $variant) {
                $applied[] = $variant . ':' . $next;
            }

            $css .= '@utility w' . $i . ' { @apply ' . \implode(' ', $applied) . '; } ';
        }

        for ($i = 0; $i < 4; ++$i) {
            $css .= '@utility d' . $i . ' { @apply ' . self::CHAIN . ($i === 3 ? 'p-4' : 'd' . ($i + 1)) . '; } ';
        }

        return $css . '@utility u0 { @apply w0; @apply ' . self::CHAIN . 'd1; } ';
    }

    /**
     * The first reading of $raw that the compiler takes, as `compileReadings()` tries them but through
     * `compile()` itself, which is what holds the memo.
     */
    private static function compiled(Compiler $compiler, string $raw, ThemeRegistry $theme): ?CompiledUtility
    {
        $readings = (new CandidateParser(Registry::default()))->parse($raw);

        self::assertNotSame([], $readings, $raw);

        foreach ($readings as $reading) {
            $compiled = $compiler->compile($reading, $theme);

            if ($compiled !== null) {
                return $compiled;
            }
        }

        return null;
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }

    /**
     * @return array{0: \TailwindPhp\Engine\CompileOutput, 1: float} The output and the CPU seconds it took.
     */
    private static function timed(CompileRequest $request): array
    {
        $collecting = \gc_enabled();
        \gc_disable();

        try {
            $started = Tripwire::cpu();
            $output = (new EngineCompiler())->compile($request);
            $elapsed = Tripwire::cpu() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        return [$output, $elapsed];
    }
}
