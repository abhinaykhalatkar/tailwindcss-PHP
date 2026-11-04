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
            $pretty = Serializer::pretty($flat);
            $compact = Serializer::compact($flat);
            $elapsed = self::cpuSeconds() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertLessThanOrEqual(Parser::MAX_DEPTH, AstDump::depth($sheet));
        // Parser and flattener each cap their own diagnostics.
        self::assertLessThanOrEqual(2 * (Tokenizer::MAX_DIAGNOSTICS + 1), \count($bag));
        // Output stays linear in the input: no level of nesting may multiply it.
        // Nesting may emit up to its output budget on top of the authored
        // CSS (ENG-A14), but never more.
        $bound = Nesting::MAX_OUTPUT_BYTES + 8 * \strlen($css) + 64 * 1024;
        self::assertLessThan($bound, \strlen($pretty), 'pretty output is bounded');
        self::assertLessThan($bound, \strlen($compact), 'compact output is bounded');
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

            // TASK-0024 round-1 verifier probes (ENG-A14): selector expansion.
            'a chain of 22 ampersand pairs (117 bytes)' => [
                '.a{' . \str_repeat('& &{', 22) . 'c:d' . \str_repeat('}', 23),
            ],
            '18 nested two-selector lists' => [\str_repeat('.a,.b{', 18) . 'c:d' . \str_repeat('}', 18)],
            '4 000 simple parents with 500 children' => [self::parentsWithChildren(4000, 500)],
            '1 000 simple parents with 500 children' => [self::parentsWithChildren(1000, 500)],
            'a long parent list with nested lists' => [
                self::parentsWithChildren(1000, 1) . \str_repeat('.x,.y{', 10) . 'c:d' . \str_repeat('}', 10),
            ],

            // TASK-0024 round-2 verifier probes (ENG-A14 amended): the total
            // output of nesting, not just each selector, must be bounded.
            '2 000 children of an 8 KB selector' => [self::longParent() . '{' . \str_repeat('b{c:d}', 2000) . '}'],
            '30 000 children of an 8 KB selector' => [self::longParent() . '{' . \str_repeat('b{c:d}', 30000) . '}'],
            '1 600 simple parents with 30 000 children' => [self::parentsWithChildren(1600, 30000, '&:x{c:d}')],
            'a complex parent list with 30 000 children' => [self::complexParentsWithChildren(500, 30000)],
            '30 000 media blocks under an 8 KB selector' => [
                self::longParent() . '{' . \str_repeat('@media x{c:d}', 30000) . '}',
            ],
            '40 000 declaration chunks under an 8 KB selector' => [
                self::longParent() . '{' . \str_repeat('c:d;x{}', 40000) . '}',
            ],
        ];
    }

    /**
     * Constructs too long to keep in memory (ENG-A14): each 2 MB input used
     * to need a 64 MB allocation and failed at PHP's default 128 MB. Each
     * now ends with exactly one diagnostic, in a few megabytes.
     *
     * @test
     *
     * @dataProvider longConstructs
     */
    public function aConstructPastTheTokenLimitIsDroppedInConstantMemory(string $css): void
    {
        $bag = new DiagnosticBag();
        $resetPeak = \function_exists('memory_reset_peak_usage');
        $collecting = \gc_enabled();
        \gc_disable();

        try {
            if ($resetPeak) {
                \memory_reset_peak_usage();
            }

            $before = \memory_get_usage();
            $started = self::cpuSeconds();
            $sheet = Parser::parse($css, $bag);
            $elapsed = self::cpuSeconds() - $started;
            $retained = \memory_get_usage() - $before;
            $peak = \memory_get_peak_usage() - $before;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertCount(1, $bag);
        self::assertStringContainsString('Construct too long', $bag->all()[0]->message());
        // The construct is gone; an enclosing rule (the declaration cases)
        // survives empty.
        self::assertContains(AstDump::of($sheet), [[], [['rule', 'x', []]]]);
        self::assertLessThan(32 * 1024 * 1024, $retained);

        // Measured on this host: peak 2–4.2 MB above the baseline.
        if ($resetPeak) {
            self::assertLessThan(32 * 1024 * 1024, $peak, \sprintf('peak %.1f MB', $peak / 1048576));
        }

        // Informational: two million tokens are still read (≈ 2–5 s here).
        self::assertLessThan(20.0, $elapsed, \sprintf('%.2f s of CPU', $elapsed));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function longConstructs(): array
    {
        $twoMegabytes = 2 * 1024 * 1024;

        return [
            'a 2 MB selector list' => [\str_repeat('a,', $twoMegabytes / 2) . '{x:y}'],
            'a 2 MB declaration value' => ['x{b:' . \str_repeat('c ', $twoMegabytes / 2) . '}'],
            'a 2 MB custom property of empty blocks' => ['x{--x:' . \str_repeat('{}', $twoMegabytes / 2) . '}'],
            'a 2 MB custom property nested 30 deep' => [
                'x{--x:' . \str_repeat(\str_repeat('{', 30) . \str_repeat('}', 30), (int) ($twoMegabytes / 60)) . '}',
            ],
        ];
    }

    /**
     * Parent lists whose children were once resolved part by part before
     * being refused or dropped, costing parents × children (8–50 s of CPU
     * for 20–60 KB). The size of every expansion is now known up front, and
     * only the parts actually kept are visited, so the work is proportional
     * to what is emitted — here nothing (ENG-A14, round 4).
     *
     * @test
     *
     * @dataProvider parentListsTimesChildren
     */
    public function parentListsTimesChildrenCostLinearWork(string $css): void
    {
        $bag = new DiagnosticBag();
        $collecting = \gc_enabled();
        \gc_disable();

        try {
            $started = self::cpuSeconds();
            $flat = Nesting::flatten(Parser::parse($css, $bag), $bag);
            $compact = Serializer::compact($flat);
            $elapsed = self::cpuSeconds() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertSame('', $compact);
        self::assertLessThanOrEqual(2 * (Tokenizer::MAX_DIAGNOSTICS + 1), \count($bag));
        // Measured on this host after the fix: 0.05–0.3 s; before: 8–51 s.
        self::assertLessThan(self::SECONDS, $elapsed, \sprintf('%d bytes took %.2f s of CPU', \strlen($css), $elapsed));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parentListsTimesChildren(): array
    {
        $pseudo = \implode(',', \array_fill(0, 1000, 'a::b'));
        $typed = \implode(',', \array_fill(0, 700, 'div'));
        $mixed = '.p::before,' . \implode(',', \array_fill(0, 2000, 'a'));

        return [
            'pseudo-element parents, children that cannot follow them' => [
                $pseudo . '{' . \str_repeat('x{}', 5000) . '}',
            ],
            'type-led parents, typed children that must wrap them' => [
                $typed . '{' . \str_repeat('&span{}', 8000) . '}',
            ],
            'one pseudo-element parent in a long plain list' => [
                $mixed . '{' . \str_repeat('&:h{}', 4000) . '}',
            ],
            'pseudo-element parents, children that follow them' => [
                $pseudo . '{' . \str_repeat('&:h{}', 4000) . '}',
            ],
        ];
    }

    /**
     * The token cap counts the construct's own tokens, never its terminator:
     * exactly MAX_CONSTRUCT_TOKENS tokens are kept, one more is dropped.
     *
     * @test
     */
    public function exactlyTheTokenLimitIsKeptAndOneMoreIsDropped(): void
    {
        $half = (int) (Parser::MAX_CONSTRUCT_TOKENS / 2);
        $bag = new DiagnosticBag();
        $sheet = Parser::parse(\str_repeat('a,', $half) . '{x:y}', $bag);

        self::assertCount(0, $bag);
        self::assertCount(1, $sheet->nodes);

        $bag = new DiagnosticBag();
        $sheet = Parser::parse(\str_repeat('a,', $half) . 'a{x:y}', $bag);

        self::assertCount(1, $bag);
        self::assertStringContainsString('Construct too long', $bag->all()[0]->message());
        self::assertCount(0, $sheet->nodes);
    }

    /**
     * Skipping a dropped construct keeps the same bracket discipline as
     * collecting it: a `)` never closes a `[`, so the construct ends exactly
     * where it would have ended had it been kept — the text after it is not
     * read as a new declaration a browser would treat as part of the old one.
     *
     * @test
     */
    public function skippingADroppedConstructEndsWhereCollectingWould(): void
    {
        $tail = ') ; e:f ] ; c:d}';
        $css = 'r{b:[' . \str_repeat('x ', Parser::MAX_CONSTRUCT_TOKENS + 10) . $tail;

        $bag = new DiagnosticBag();
        $compact = Serializer::compact(Parser::parse($css, $bag));

        self::assertSame('r{c:d}', $compact);
        self::assertCount(1, $bag);
    }

    /**
     * Skipping a dropped construct must cost no memory however many brackets
     * follow the cap: the exact closer stack is bounded and further openers
     * are only counted (round 4 review, TASK-0045 F1 — the first version
     * reached 36 MB here, about 18 bytes per input byte).
     *
     * @test
     *
     * @dataProvider openersAfterTheCap
     */
    public function bracketsAfterTheTokenCapCostNoMemory(string $css): void
    {
        $bag = new DiagnosticBag();
        $resetPeak = \function_exists('memory_reset_peak_usage');
        $collecting = \gc_enabled();
        \gc_disable();

        try {
            if ($resetPeak) {
                \memory_reset_peak_usage();
            }

            $before = \memory_get_usage();
            Parser::parse($css, $bag);
            $peak = \memory_get_peak_usage() - $before;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertStringContainsString('Construct too long', $bag->all()[0]->message());
        // The cap, plus at most the block left unclosed by the openers.
        self::assertLessThanOrEqual(2, \count($bag));

        // Below PHP 8.2 there is no memory_reset_peak_usage(), so the memory
        // bound is enforced by the 8.2+ jobs only; the diagnostics above run
        // everywhere. Before the bounded stack this peaked at 36 MB.
        if ($resetPeak) {
            self::assertLessThan(8 * 1024 * 1024, $peak, \sprintf('peak %.1f MB', $peak / 1048576));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openersAfterTheCap(): array
    {
        $cap = \str_repeat('c ', Parser::MAX_CONSTRUCT_TOKENS / 2 + 10);

        return [
            'two million parentheses' => ['x{b:' . $cap . \str_repeat('(', 2000000) . '}'],
            'a million paren-bracket pairs' => ['x{b:' . $cap . \str_repeat('([', 1000000) . '}'],
            'two million braces in a custom property' => ['x{--x:' . $cap . \str_repeat('{', 2000000) . '}'],
        ];
    }

    /**
     * An 8 KB class selector.
     */
    private static function longParent(): string
    {
        return '.' . \str_repeat('a', 8000);
    }

    /**
     * $parents complex selectors (just under the selector limit together)
     * with $children `&:x` rules.
     */
    private static function complexParentsWithChildren(int $parents, int $children): string
    {
        $list = [];

        for ($i = 0; $i < $parents; ++$i) {
            $list[] = '.a .q' . $i;
        }

        return \implode(',', $list) . '{' . \str_repeat('&:x{c:d}', $children) . '}';
    }

    /**
     * 2 MB of tiny rules: about 600 000 nodes' worth of input, which used to
     * exhaust PHP's default 128 MB. The parser stops at Parser::MAX_NODES with
     * one diagnostic (ENG-A14) and stays far inside 64 MB on this host.
     *
     * @test
     */
    public function twoMegabytesOfTinyRulesStopAtTheNodeLimit(): void
    {
        $css = \str_repeat('.a{b:c}', (int) (2 * 1024 * 1024 / 7));
        $bag = new DiagnosticBag();
        $resetPeak = \function_exists('memory_reset_peak_usage');

        if ($resetPeak) {
            \memory_reset_peak_usage();
        }

        $before = \memory_get_usage();
        $sheet = Parser::parse($css, $bag);
        $retained = \memory_get_usage() - $before;
        $peak = \memory_get_peak_usage() - $before;

        self::assertCount(1, $bag);
        self::assertStringContainsString('Stylesheet too large', $bag->all()[0]->message());
        self::assertCount((int) (Parser::MAX_NODES / 2), $sheet->nodes, 'rules + declarations = MAX_NODES');
        self::assertLessThan(48 * 1024 * 1024, $retained, \sprintf('retained %.1f MB', $retained / 1048576));

        // Measured on this host (PHP 8.2 ZTS): peak ≈ 26 MB above the
        // baseline, retained ≈ 25.5 MB (≈ 268 bytes per node). The peak can
        // only be isolated where memory_reset_peak_usage() exists (PHP 8.2+).
        if ($resetPeak) {
            self::assertLessThan(64 * 1024 * 1024, $peak, \sprintf('peak %.1f MB', $peak / 1048576));
        }
    }

    /**
     * A rule with $parents simple selectors and $children `&:hover` rules.
     */
    private static function parentsWithChildren(int $parents, int $children, string $child = '&:hover{c:d}'): string
    {
        $list = [];

        for ($i = 0; $i < $parents; ++$i) {
            $list[] = '.p' . $i;
        }

        return \implode(',', $list) . '{' . \str_repeat($child, $children) . '}';
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
