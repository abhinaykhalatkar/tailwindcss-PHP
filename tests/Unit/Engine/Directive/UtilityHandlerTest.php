<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Utility\Compiler;

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
