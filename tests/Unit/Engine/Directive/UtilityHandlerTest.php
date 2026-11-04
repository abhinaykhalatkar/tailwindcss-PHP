<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\UtilityHandler;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;

/**
 * Static `@utility` (criterion 6): its parsed body is registered with the
 * utility registry and compiles; the functional form is M3.
 */
final class UtilityHandlerTest extends TestCase
{
    /** @test */
    public function aStaticUtilityIsRegisteredAndCompiles(): void
    {
        $run = new ProcessorHarness(
            '@utility scrollbar-hidden { scrollbar-width: none; &::-webkit-scrollbar { display: none; } }'
        );
        $compiled = self::compile($run, 'scrollbar-hidden');

        self::assertSame([], $run->codes());
        self::assertSame('', $run->authored(), '@utility is consumed');
        self::assertNotNull($compiled);
        self::assertSame('scrollbar-width:none;&::-webkit-scrollbar{display:none}', ProcessorHarness::css($compiled));
    }

    /** @test */
    public function applyInsideAUtilityIsDeferredOnAnAmpersandHost(): void
    {
        $run = new ProcessorHarness('@utility btn { color: red; @apply p-4 font-bold; }');

        self::assertCount(1, $run->sheet->applies);
        self::assertSame(['p-4', 'font-bold'], $run->sheet->applies[0]['candidates']);
        self::assertSame('btn', $run->sheet->applies[0]['utility']);
        self::assertSame('&', $run->sheet->applies[0]['host']->selector);

        $compiled = self::compile($run, 'btn');

        self::assertNotNull($compiled);
        self::assertInstanceOf(Rule::class, $compiled[0]);
        self::assertSame(
            $run->sheet->applies[0]['host'],
            $compiled[0],
            'the registered body is the host the assembler fills'
        );
    }

    /** @test */
    public function aFunctionalUtilityIsUnsupported(): void
    {
        $run = new ProcessorHarness('@utility tab-* { tab-size: --value(integer); }');

        self::assertSame([Diagnostic::E_UNSUPPORTED_DIRECTIVE], $run->codes());
        self::assertStringContainsString('M3', $run->messages()[0]);
        self::assertFalse($run->utilities->has('tab-*'));
    }

    /**
     * A declaration whose property is not a plain name is reported and left
     * out; the rest of the body still registers and compiles (TASK-0042).
     *
     * @test
     */
    public function aDeclarationWithoutAPlainPropertyNameIsReportedAndLeftOut(): void
    {
        $run = new ProcessorHarness('@utility u { --x-*: initial; color: red }');
        $compiled = self::compile($run, 'u');

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertStringContainsString('--x-*', $run->messages()[0]);
        self::assertNotNull($compiled);
        self::assertSame('color:red', ProcessorHarness::css($compiled));
    }

    /** @test */
    public function aBadDeclarationInsideANestedRuleIsLeftOutToo(): void
    {
        $run = new ProcessorHarness('@utility u { color: red; &:hover { --y-*: initial; display: none } }');
        $compiled = self::compile($run, 'u');

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertNotNull($compiled);
        self::assertSame('color:red;&:hover{display:none}', ProcessorHarness::css($compiled));
    }

    /** @test */
    public function theReportedPropertyNameIsEchoedAtMostSixtyFourBytes(): void
    {
        $property = '--' . \str_repeat('a', 100) . '-*';
        $run = new ProcessorHarness('@utility u { ' . $property . ': initial; color: red }');

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertStringNotContainsString(\str_repeat('a', 70), $run->messages()[0]);
    }

    /**
     * Declarations carry a line only when the parser keeps positions, so the report uses the directive's own
     * (a node built with a position stands for the parser's debug mode).
     *
     * @test
     */
    public function theReportCarriesTheLineOfTheDirective(): void
    {
        $utility = new AtRule(
            'utility',
            'u',
            [new Declaration('--x-*', 'initial'), new Declaration('color', 'red')],
            3,
            1
        );
        $bag = new DiagnosticBag();
        $applies = [];

        UtilityHandler::handle($utility, Registry::default(), $applies, $bag);

        $diagnostics = $bag->all();
        self::assertCount(1, $diagnostics);
        self::assertSame(Diagnostic::E_CSS_SYNTAX, $diagnostics[0]->code());
        self::assertSame(3, $diagnostics[0]->line());
    }

    /**
     * The CLI writes a property in the case it is written in: letters of both cases are a plain name.
     *
     * @test
     *
     * @dataProvider caseSpellings
     */
    public function aPropertyWrittenInCapitalsIsKept(string $property): void
    {
        $run = new ProcessorHarness('@utility u { ' . $property . ': red }');
        $compiled = self::compile($run, 'u');

        self::assertSame([], $run->codes());
        self::assertNotNull($compiled);
        self::assertSame($property . ':red', ProcessorHarness::css($compiled));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function caseSpellings(): array
    {
        return [
            'all capitals' => ['COLOR'],
            'one capital' => ['Color'],
            'a vendor prefix in capitals' => ['-WEBKIT-appearance'],
        ];
    }

    /** @test */
    public function aBodyWithAnInvalidNameStaysReportedForADigitOrSymbol(): void
    {
        $run = new ProcessorHarness('@utility u { 1color: red; co_lor: red; color: red }');

        self::assertSame([Diagnostic::E_CSS_SYNTAX, Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertSame('color:red', ProcessorHarness::css((array) self::compile($run, 'u')));
    }

    /** @test */
    public function aBodyOfPlainNamesReportsNothing(): void
    {
        $run = new ProcessorHarness('@utility u { --my-var: 1; -webkit-appearance: none; color: red }');

        self::assertSame([], $run->codes());
        self::assertNotNull(self::compile($run, 'u'));
    }

    /**
     * @test
     *
     * @dataProvider invalid
     */
    public function anInvalidUtilityIsReported(string $css): void
    {
        $run = new ProcessorHarness($css);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertSame([], $run->sheet->applies);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalid(): array
    {
        return [
            'no body' => ['@utility nothing;'],
            'upper-case name' => ['@utility Bad { color: red; }'],
            'name with a space' => ['@utility a b { color: red; @apply p-4; }'],
        ];
    }

    /**
     * @return list<\TailwindPhp\Engine\Css\Node\Node>|null
     */
    private static function compile(ProcessorHarness $run, string $candidate): ?array
    {
        $compiled = (new Compiler($run->utilities))->compileReadings(
            $candidate,
            (new CandidateParser($run->utilities))->parse($candidate),
            $run->theme
        );

        return $compiled === null ? null : $compiled->nodes;
    }
}
