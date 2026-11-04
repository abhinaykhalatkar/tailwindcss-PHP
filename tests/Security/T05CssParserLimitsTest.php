<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Css\Tokenizer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Unit\Engine\Css\AstDump;

/**
 * Threat T-05 (design doc 12) for the CSS front end: hostile stylesheets must
 * cost time linear in their size, must never recurse past the nesting cap,
 * and must never throw. Every row is a megabyte-scale shape aimed at one
 * weakness — unbalanced blocks, unbalanced parentheses, a string that never
 * ends, nesting far past any recursion limit.
 *
 * The time bound is informational (5 s on any host, so it never flakes); the
 * real guarantee is structural and asserted unconditionally: the depth of the
 * resulting tree, the diagnostic cap, and completion without a stack
 * overflow.
 *
 * @covers-requirement NFR-SEC-6
 * @covers-requirement NFR-SEC-7
 */
final class T05CssParserLimitsTest extends TestCase
{
    private const SECONDS = 5.0;

    /**
     * @test
     *
     * @dataProvider hostileInputs
     */
    public function hostileInputParsesFlattensAndSerializesWithinBounds(string $css): void
    {
        $bag = new DiagnosticBag();

        // The bound is about the parser's own work, so it is measured in CPU
        // time (other processes on a shared CI runner do not count) with the
        // cycle collector paused: inside PHPUnit each collector pass walks
        // the test runner's whole heap, a cost that belongs to the harness,
        // not to the stylesheet. Restored in `finally` whatever happens.
        $collecting = \gc_enabled();
        \gc_disable();

        try {
            $started = self::cpuSeconds();
            $sheet = Parser::parse($css, $bag);
            $flat = Nesting::flatten($sheet, $bag);
            Serializer::pretty($flat);
            Serializer::compact($flat);
            $elapsed = self::cpuSeconds() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertLessThanOrEqual(Parser::MAX_DEPTH, AstDump::depth($sheet));
        self::assertLessThanOrEqual(Tokenizer::MAX_DIAGNOSTICS + 1, \count($bag));
        self::assertLessThan(
            self::SECONDS,
            $elapsed,
            \sprintf('%d bytes took %.2f s of CPU', \strlen($css), $elapsed)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileInputs(): array
    {
        // The densest shapes produce one token per byte; they are sized so a
        // loaded Windows host with a thread-safe PHP build stays far inside
        // the informational bound. The criterion's own shapes run at full size.
        $dense = 128 * 1024;

        return [
            '100 000 unclosed braces' => [\str_repeat('a{', 100000)],
            '100 000 bare open braces' => [\str_repeat('{', 100000)],
            '100 000 unclosed parentheses in a value' => ['a{b:' . \str_repeat('(', 100000)],
            '100 000 unclosed functions in a selector' => [\str_repeat('f(', 100000) . '{x:y}'],
            '100 000 unclosed brackets' => [\str_repeat('[', 100000) . '{x:y}'],
            'a 1 MB unterminated string' => ['a{content:"' . \str_repeat('x', 1024 * 1024)],
            'a 1 MB unterminated url' => ['a{b:url(' . \str_repeat('x', 1024 * 1024)],
            'a 1 MB unterminated comment' => ['a{} /*' . \str_repeat('x', 1024 * 1024)],
            '50 000 nested rules' => [\str_repeat('.a{', 50000) . \str_repeat('}', 50000)],
            '50 000 nested at-rules' => [\str_repeat('@media x{', 50000) . \str_repeat('}', 50000)],
            'a 2 MB stylesheet combining the hostile shapes' => [self::combined()],
            'stray closing braces' => [\str_repeat('}', $dense)],
            'bad strings' => [\str_repeat("a{b:\"x\n}", (int) ($dense / 9))],
            'escapes' => ['.' . \str_repeat('\\31 ', (int) ($dense / 4)) . '{x:y}'],
            'custom property with nested blocks' => [
                'a{--x:' . \str_repeat('{', $dense / 2) . \str_repeat('}', $dense / 2) . '}',
            ],
            'small rules' => [\str_repeat('.a{b:c}', (int) ($dense / 7))],
            'one long selector line' => [\str_repeat('.a ', (int) ($dense / 3)) . '{x:y}'],
            'nul and invalid utf-8' => [\str_repeat("\0\xFF\xC3", (int) ($dense / 3))],
        ];
    }

    /**
     * User plus system CPU time of this process, in seconds.
     */
    private static function cpuSeconds(): float
    {
        $usage = \getrusage();

        if ($usage === false) {
            return \microtime(true);
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }

    /**
     * Exactly 2 MiB: 50 000 nested rules, 100 000 parentheses, a run of bad
     * strings, 100 000 unclosed braces, and an unterminated string filling
     * the rest (about 1.4 MB).
     */
    private static function combined(): string
    {
        $css = \str_repeat('.a{', 50000) . \str_repeat('}', 50000)
            . 'a{b:' . \str_repeat('(', 100000) . \str_repeat(')', 100000) . '}'
            . \str_repeat("a{b:\"x\n}", 20000)
            . \str_repeat('a{', 100000)
            . 'c{content:"';

        return $css . \str_repeat('x', 2 * 1024 * 1024 - \strlen($css));
    }

    /** @test */
    public function deepNestingIsCutAtThirtyTwoLevelsWithOneDiagnostic(): void
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse(\str_repeat('.a{', 50000) . 'x:y' . \str_repeat('}', 50000), $bag);

        self::assertSame(Parser::MAX_DEPTH, AstDump::depth($sheet));
        self::assertCount(1, $bag);
        self::assertStringContainsString('nesting too deep', $bag->all()[0]->message());
    }

    /** @test */
    public function aBraceInsideAStringNeverClosesABlock(): void
    {
        $sheet = Parser::parse('a{content:"}";color:red}b{x:y}', new DiagnosticBag());

        self::assertSame(
            [
                ['rule', 'a', [['decl', 'content', '"}"', false], ['decl', 'color', 'red', false]]],
                ['rule', 'b', [['decl', 'x', 'y', false]]],
            ],
            AstDump::of($sheet)
        );
    }

    /** @test */
    public function styleAndCommentClosersAreOrdinaryText(): void
    {
        $sheet = Parser::parse('a{content:x</style>y;b:z-->w}', new DiagnosticBag());

        self::assertSame(
            [['rule', 'a', [['decl', 'content', 'x</style>y', false], ['decl', 'b', 'z-->w', false]]]],
            AstDump::of($sheet)
        );
    }

    /** @test */
    public function diagnosticsNeverEchoMoreThanSixtyFourBytesOfInput(): void
    {
        $bag = new DiagnosticBag();

        Parser::parse('a{' . \str_repeat('x', 5000) . ' y;}', $bag);

        self::assertCount(1, $bag);
        self::assertLessThan(200, \strlen($bag->all()[0]->message()));
        self::assertNull($bag->all()[0]->file());
    }
}
