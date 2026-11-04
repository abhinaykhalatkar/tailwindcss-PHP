<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility\Handlers;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Handlers\FlexboxGrid;
use TailwindPhp\Engine\Utility\Registry;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The flexbox and grid handlers (TASK-0036): `gridCols` for `grid-cols`,
 * `grid-rows`, `col-span`, `row-span` and `content`. Each case runs the real
 * parser, registry and compiler, so the handler is exercised exactly as the
 * engine calls it; the shapes are the pinned 4.1.18 CLI's.
 */
final class FlexboxGridTest extends TestCase
{
    /** @test */
    public function theFamilyImplementsTheContractAndOwnsTwoHandlers(): void
    {
        self::assertInstanceOf(FamilyHandlerInterface::class, new FlexboxGrid(new ValueResolver()));
        self::assertSame(['gridCols', 'content'], FlexboxGrid::names());

        foreach (FlexboxGrid::names() as $name) {
            self::assertArrayHasKey($name, Handlers::TAKES_VALUE);
        }

        self::assertNotContains('gridCols', (new Handlers(new ValueResolver()))->unimplemented());
        self::assertNotContains('content', (new Handlers(new ValueResolver()))->unimplemented());
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
        return [
            'a column count' => ['grid-cols-3', 'grid-template-columns:repeat(3, minmax(0, 1fr))'],
            'a big column count' => ['grid-cols-13', 'grid-template-columns:repeat(13, minmax(0, 1fr))'],
            'a row count' => ['grid-rows-2', 'grid-template-rows:repeat(2, minmax(0, 1fr))'],
            'columns none' => ['grid-cols-none', 'grid-template-columns:none'],
            'columns subgrid' => ['grid-cols-subgrid', 'grid-template-columns:subgrid'],
            'rows subgrid' => ['grid-rows-subgrid', 'grid-template-rows:subgrid'],
            'arbitrary columns' => ['grid-cols-[1fr_2fr]', 'grid-template-columns:1fr 2fr'],
            'arbitrary repeat' => [
                'grid-cols-[repeat(7,minmax(0,1fr))]',
                'grid-template-columns:repeat(7,minmax(0,1fr))',
            ],
            'a variable' => ['grid-cols-(--cols)', 'grid-template-columns:var(--cols)'],
            'a column span' => ['col-span-2', 'grid-column:span 2 / span 2'],
            'a zero span' => ['col-span-0', 'grid-column:span 0 / span 0'],
            'a row span' => ['row-span-3', 'grid-row:span 3 / span 3'],
            'full span' => ['col-span-full', 'grid-column:1 / -1'],
            'full row span' => ['row-span-full', 'grid-row:1 / -1'],
            'arbitrary span' => ['col-span-[7]', 'grid-column:span 7 / span 7'],
            'variable span' => ['row-span-(--n)', 'grid-row:span var(--n) / span var(--n)'],
            'content none' => ['content-none', '--tw-content:none;content:none'],
            'content string' => ["content-['hello']", "--tw-content:'hello';content:var(--tw-content)"],
            'content function' => ['content-[attr(data-x)]', '--tw-content:attr(data-x);content:var(--tw-content)'],
            'content variable' => ['content-(--c)', '--tw-content:var(--c);content:var(--tw-content)'],
            'content keyword is align-content' => ['content-center', 'align-content:center'],
            'important reaches every declaration' => [
                'content-none!',
                '--tw-content:none!important;content:none!important',
            ],
            'important grid' => ['col-span-2!', 'grid-column:span 2 / span 2!important'],
        ];
    }

    /**
     * What the CLI refuses, the handlers refuse: zero tracks, fractions,
     * negatives, keywords they do not have, a modifier they do not take.
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
            'zero columns' => ['grid-cols-0'],
            'zero rows' => ['grid-rows-0'],
            'a decimal count' => ['grid-cols-1.5'],
            'a fraction count' => ['grid-cols-1/2'],
            'a negative count' => ['-grid-cols-2'],
            'an unknown keyword' => ['grid-cols-auto'],
            'span auto' => ['col-span-auto'],
            'a negative span' => ['-col-span-1'],
            'a fraction span' => ['row-span-1/2'],
            'a decimal span' => ['col-span-1.5'],
            'content fraction' => ['content-1/2'],
            'a negative content' => ['-content-none'],
            'content with a modifier' => ['content-none/50'],
            'an unknown content keyword' => ['content-auto'],
            'columns with a modifier' => ['grid-cols-[1fr]/2'],
        ];
    }

    /**
     * T-15: text that could end a declaration or a rule, open a comment or
     * smuggle a script URL is refused by the parser before any handler sees it.
     *
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileArbitraryValuesNeverReachAHandler(string $candidate): void
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
        $cases = [];

        foreach (['grid-cols', 'grid-rows', 'col-span', 'row-span', 'content'] as $root) {
            $cases[$root . ' semicolon'] = [$root . '-[1px;color:red]'];
            $cases[$root . ' close brace'] = [$root . '-[1px}a{b:c]'];
            $cases[$root . ' comment'] = [$root . '-[1px/*x*/]'];
            $cases[$root . ' script url'] = [$root . '-[url(javascript:alert(1))]'];
        }

        return $cases;
    }

    /**
     * A candidate object built around the parser (the value classes have
     * public constructors) is still stopped: a handler reports nothing for
     * a static candidate, a negative one, or a value it does not know.
     *
     * @test
     */
    public function aCraftedCandidateOutsideTheContractGivesNothing(): void
    {
        $registry = Registry::default();
        $compiler = new Compiler($registry);
        $theme = self::theme();

        $static = new Candidate('grid-cols', [], false, false, Candidate::STATIC, 'grid-cols', null);
        $content = new Candidate('content', [], false, false, Candidate::STATIC, 'content', null);

        self::assertNull($compiler->compile($static, $theme));
        self::assertNull($compiler->compile($content, $theme));
    }

    /**
     * Handlers report the theme variables they read and never mark them: the
     * family reads none, so the list is empty for every form.
     *
     * @test
     */
    public function noThemeVariableIsReported(): void
    {
        foreach (['grid-cols-3', 'col-span-2', 'content-none', 'content-(--c)', 'col-span-full'] as $candidate) {
            $compiled = self::compiled($candidate);

            self::assertNotNull($compiled, $candidate);
            self::assertSame([], $compiled->usedVars, $candidate);
        }
    }

    /**
     * `content` declares `--tw-content`, which the assembler registers with
     * `@property` (the golden has the rule); the grid handlers declare none.
     *
     * @test
     */
    public function theCustomPropertiesToRegisterAreReported(): void
    {
        foreach (['content-none', "content-['a']", 'content-(--c)'] as $candidate) {
            $compiled = self::compiled($candidate);

            self::assertNotNull($compiled, $candidate);
            self::assertSame(['--tw-content'], $compiled->properties, $candidate);
        }

        foreach (['grid-cols-3', 'col-span-2', 'content-center'] as $candidate) {
            $compiled = self::compiled($candidate);

            self::assertNotNull($compiled, $candidate);
            self::assertSame([], $compiled->properties, $candidate);
        }
    }

    private static function css(string $candidate): ?string
    {
        $compiled = self::compiled($candidate);

        return $compiled === null ? null : self::render($compiled->nodes);
    }

    private static function compiled(string $candidate): ?\TailwindPhp\Engine\Utility\CompiledUtility
    {
        $registry = Registry::default();

        return (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            self::theme()
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
