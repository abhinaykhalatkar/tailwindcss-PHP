<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\KnownDifferences;

/**
 * The known-differences allow-list: its shape, its renderer and its loader
 * (design doc 08 §Known differences policy; decisions ENG-A9, ENG-A12).
 *
 * The renderer is exercised from fixture arrays and the output compared as a
 * string; nothing here reads or writes `docs/compat/KNOWN-DIFFERENCES.md`.
 */
final class KnownDifferencesTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/KnownDifferences.php';
    }

    protected function setUp(): void
    {
        $this->root = TempTree::make('known-differences-');
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    // ----------------------------------------------------------------- shape

    /** @test */
    public function aWellFormedListIsAccepted(): void
    {
        $entries = [self::entry(), self::entry(['candidate' => 'p-[2px]'])];

        self::assertSame($entries, KnownDifferences::validate($entries));
    }

    /** @test */
    public function anEmptyListIsAccepted(): void
    {
        self::assertSame([], KnownDifferences::validate([]));
    }

    /**
     * @test
     *
     * @dataProvider badEntries
     *
     * @param array<array-key, mixed> $entries
     */
    public function aBadEntryIsRefusedAndNamed(array $entries, string $needle): void
    {
        try {
            KnownDifferences::validate($entries);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertLessThan(300, \strlen($e->getMessage()));
        }
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string}>
     */
    public static function badEntries(): array
    {
        $good = self::entry();

        return [
            'an unknown key' => [[$good + ['colour' => 'red']], 'entry #0'],
            'a missing key' => [
                [['candidate' => 'x', 'corpus' => 'utilities/a', 'class' => 'bug', 'reason' => 'r']],
                'entry #0',
            ],
            'a typo in a key' => [[['candidat' => 'x'] + $good], 'entry #0'],
            'an unknown class' => [[self::entry(['class' => 'wontfix'])], 'class'],
            'the class in another case' => [[self::entry(['class' => 'Bug'])], 'class'],
            'a duplicate candidate and corpus' => [[$good, $good], 'entry #1'],
            'a non-string candidate' => [[self::entry(['candidate' => 5])], 'candidate'],
            'an empty candidate' => [[self::entry(['candidate' => ''])], 'candidate'],
            'a candidate with a newline' => [[self::entry(['candidate' => "a\nb"])], 'candidate'],
            'a candidate with a NUL' => [[self::entry(['candidate' => "a\0b"])], 'candidate'],
            'a candidate with a space' => [[self::entry(['candidate' => 'a b'])], 'candidate'],
            'a very long candidate' => [[self::entry(['candidate' => \str_repeat('a', 300)])], 'candidate'],
            'an empty reason' => [[self::entry(['reason' => ''])], 'reason'],
            'a reason with a newline' => [[self::entry(['reason' => "one\ntwo"])], 'reason'],
            'a very long reason' => [[self::entry(['reason' => \str_repeat('a', 400)])], 'reason'],
            'a reason that is not text' => [[self::entry(['reason' => ['x']])], 'reason'],
            'a corpus without a slash' => [[self::entry(['corpus' => 'utilities'])], 'corpus'],
            'an unknown corpus group' => [[self::entry(['corpus' => 'plugins/a'])], 'corpus'],
            'a traversal in the corpus' => [[self::entry(['corpus' => 'utilities/../x'])], 'corpus'],
            'a nested corpus name' => [[self::entry(['corpus' => 'utilities/a/b'])], 'corpus'],
            'an upper case corpus name' => [[self::entry(['corpus' => 'utilities/Edge'])], 'corpus'],
            'an empty corpus name' => [[self::entry(['corpus' => 'utilities/'])], 'corpus'],
            'a since that is not a version' => [[self::entry(['since' => 'latest'])], 'since'],
            'an empty since' => [[self::entry(['since' => ''])], 'since'],
            'an entry that is not an array' => [['x'], 'entry #0'],
            'string keys instead of a list' => [['a' => $good], 'list'],
            'a gap in the list' => [[0 => $good, 2 => $good], 'list'],
        ];
    }

    /** @test */
    public function theSameCandidateInTwoCorporaIsNotADuplicate(): void
    {
        $entries = [self::entry(), self::entry(['corpus' => 'variants/arbitrary'])];

        self::assertCount(2, KnownDifferences::validate($entries));
    }

    /** @test */
    public function aLongCandidateIsTruncatedInTheMessage(): void
    {
        try {
            KnownDifferences::validate([self::entry(['candidate' => \str_repeat('x', 300)])]);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(\str_repeat('x', 100), $e->getMessage());
        }
    }

    // ----------------------------------------------------------------- render

    /** @test */
    public function itRendersTheDocumentedLayout(): void
    {
        $text = KnownDifferences::render(
            [
                self::entry([
                    'candidate' => 'bg-[url(javascript:alert(1))]',
                    'corpus' => 'utilities/edge-rejections',
                    'class' => 'deliberate',
                    'reason' => 'The CLI keeps a javascript: URL; the engine refuses it (T-15).',
                ]),
                self::entry([
                    'candidate' => 'group-[.x]:flex',
                    'corpus' => 'variants/group-peer',
                    'class' => 'bug',
                    'reason' => 'Not implemented before M3.',
                ]),
            ],
            '4.1.18'
        );

        $expected = \implode("\n", [
            '# Known differences',
            '',
            'Compared with the official Tailwind CLI 4.1.18. This file is generated by '
                . '`php tools/known-differences.php` from `tests/Conformance/known-differences.php`; '
                . 'do not edit it by hand.',
            '',
            '| Class | Meaning | Entries |',
            '|-------|---------|---------|',
            '| deliberate | The engine differs on purpose, for a security or scope reason. | 1 |',
            '| bug | The engine is wrong or incomplete and is to be fixed. | 1 |',
            '| upstream | The CLI itself behaves oddly; the engine follows the documented behaviour. | 0 |',
            '',
            '## deliberate',
            '',
            '| Corpus | Candidate | Reason | Since |',
            '|--------|-----------|--------|-------|',
            '| utilities/edge-rejections | `bg-[url(javascript:alert(1))]` '
                . '| The CLI keeps a javascript: URL; the engine refuses it (T-15). | 4.1.18 |',
            '',
            '## bug',
            '',
            '| Corpus | Candidate | Reason | Since |',
            '|--------|-----------|--------|-------|',
            '| variants/group-peer | `group-[.x]:flex` | Not implemented before M3. | 4.1.18 |',
            '',
            '## upstream',
            '',
            'None.',
            '',
        ]);

        self::assertSame($expected, $text);
    }

    /** @test */
    public function entriesAreSortedByCorpusThenCandidateWhateverTheInputOrder(): void
    {
        $a = self::entry(['candidate' => 'b', 'corpus' => 'utilities/z']);
        $b = self::entry(['candidate' => 'a', 'corpus' => 'utilities/z']);
        $c = self::entry(['candidate' => 'z', 'corpus' => 'utilities/a']);

        $forward = KnownDifferences::render([$a, $b, $c], '4.1.18');
        $reverse = KnownDifferences::render([$c, $b, $a], '4.1.18');

        self::assertSame($forward, $reverse);
        self::assertLessThan(\strpos($forward, '`a`'), \strpos($forward, '`z`'));
        self::assertLessThan(\strpos($forward, '`b`'), \strpos($forward, '`a`'));
    }

    /** @test */
    public function aPipeInAnyCellCannotBreakTheTable(): void
    {
        $text = KnownDifferences::render(
            [self::entry(['candidate' => 'a|b', 'reason' => 'x | y'])],
            '4.1.18'
        );

        self::assertStringContainsString('`a\|b`', $text);
        self::assertStringContainsString('x \| y', $text);

        foreach (\explode("\n", $text) as $line) {
            if (\strpos($line, '| utilities/') === 0) {
                self::assertSame(5, \substr_count($line, '|') - \substr_count($line, '\|'));
            }
        }
    }

    /** @test */
    public function aBacktickInACandidateGetsALongerFence(): void
    {
        $text = KnownDifferences::render([self::entry(['candidate' => 'a`b'])], '4.1.18');

        self::assertStringContainsString('`` a`b ``', $text);
    }

    /** @test */
    public function renderingTwiceIsByteIdenticalAndUsesLfOnly(): void
    {
        $entries = [self::entry(), self::entry(['candidate' => 'other', 'class' => 'upstream'])];

        self::assertSame(KnownDifferences::render($entries, '4.1.18'), KnownDifferences::render($entries, '4.1.18'));
        self::assertStringNotContainsString("\r", KnownDifferences::render($entries, '4.1.18'));
        self::assertSame("\n", \substr(KnownDifferences::render($entries, '4.1.18'), -1));
    }

    /** @test */
    public function anEmptyListRendersNoneUnderEveryClass(): void
    {
        $text = KnownDifferences::render([], '4.1.18');

        self::assertSame(3, \substr_count($text, "\nNone.\n"));
        self::assertStringContainsString('| deliberate |', $text);
        self::assertStringContainsString('| 0 |', $text);
    }

    /** @test */
    public function renderingValidatesFirst(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KnownDifferences::render([self::entry(['class' => 'nonsense'])], '4.1.18');
    }

    /** @test */
    public function theVersionIsCheckedBeforeItIsPrinted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KnownDifferences::render([], "4.1.18\n# injected");
    }

    // ------------------------------------------------------------------ index

    /** @test */
    public function indexGroupsByCorpusThenCandidate(): void
    {
        $a = self::entry(['candidate' => 'a', 'corpus' => 'utilities/x']);
        $b = self::entry(['candidate' => 'b', 'corpus' => 'utilities/x']);
        $c = self::entry(['candidate' => 'a', 'corpus' => 'variants/y']);

        $index = KnownDifferences::index(KnownDifferences::validate([$a, $b, $c]));

        self::assertSame(['utilities/x', 'variants/y'], \array_keys($index));
        self::assertSame($a, $index['utilities/x']['a']);
        self::assertSame($c, $index['variants/y']['a']);
        self::assertSame(['a', 'b'], \array_keys($index['utilities/x']));
    }

    // ------------------------------------------------------------------- load

    /** @test */
    public function itLoadsAPlainDataFile(): void
    {
        $file = $this->write(
            "<?php\n\ndeclare(strict_types=1);\n\n// comment\nreturn [\n    [\n        'candidate' => 'p-[1px]',\n"
            . "        'corpus' => 'utilities/edge-values',\n        'class' => 'bug',\n"
            . "        'reason' => 'A reason.',\n        'since' => '4.1.18',\n    ],\n];\n"
        );

        self::assertSame([self::entry()], KnownDifferences::load($file));
    }

    /** @test */
    public function itLoadsAnEmptyList(): void
    {
        self::assertSame([], KnownDifferences::load($this->write("<?php\nreturn [];\n")));
    }

    /**
     * @test
     *
     * @dataProvider hostileFiles
     */
    public function itRefusesAnythingButLiterals(string $php): void
    {
        $marker = $this->root . '/ran';
        $file = $this->write(\str_replace('%MARKER%', \addslashes($marker), $php));

        try {
            KnownDifferences::load($file);
            self::fail('The loader accepted a file that is not plain data.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }

        self::assertFileDoesNotExist($marker, 'The file was executed.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileFiles(): array
    {
        return [
            'a function call' => ["<?php\nreturn [file_put_contents('%MARKER%', 'x')];\n"],
            'a variable' => ["<?php\n\$x = 1;\nreturn [];\n"],
            'a closure' => ["<?php\nreturn [function () {}];\n"],
            'an include' => ["<?php\ninclude '%MARKER%';\nreturn [];\n"],
            'a constant expression' => ["<?php\nreturn [__FILE__];\n"],
            'string concatenation' => ["<?php\nreturn ['a' . 'b'];\n"],
            'an interpolated string' => ["<?php\n\$v = 1;\nreturn [\"a{\$v}\"];\n"],
            'a class constant' => ["<?php\nreturn [\\PHP_EOL];\n"],
            'code after the return' => ["<?php\nreturn [];\nfile_put_contents('%MARKER%', 'x');\n"],
            'a second statement before' => ["<?php\nfile_put_contents('%MARKER%', 'x');\nreturn [];\n"],
            'a heredoc' => ["<?php\nreturn [<<<X\nabc\nX\n];\n"],
            'inline html' => ["not php\n<?php return [];\n"],
            'a backtick shell' => ["<?php\nreturn [`echo hi`];\n"],
            'a short echo tag' => ["<?= 'x' ?>\n<?php return [];\n"],
            'an unterminated file' => ["<?php\nreturn [\n"],
        ];
    }

    /** @test */
    public function itRefusesAFileThatReturnsSomethingElse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KnownDifferences::load($this->write("<?php\nreturn 5;\n"));
    }

    /** @test */
    public function itRefusesAMissingFileWithoutNamingThePath(): void
    {
        try {
            KnownDifferences::load($this->root . '/nope.php');
            self::fail('Expected an exception.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    /** @test */
    public function itValidatesWhatItLoads(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KnownDifferences::load($this->write("<?php\nreturn [['candidate' => 'x']];\n"));
    }

    /** @test */
    public function theCommittedDataFileLoadsAndHoldsTheSeedEntries(): void
    {
        $entries = KnownDifferences::load(\dirname(__DIR__, 2) . '/Conformance/known-differences.php');
        $index = KnownDifferences::index($entries);

        self::assertGreaterThanOrEqual(6, \count($entries));

        foreach (['bg-[url(javascript:alert(1))]', 'p-[1px/*x*/]', 'text-[javascript:alert(1)]'] as $candidate) {
            self::assertArrayHasKey($candidate, $index['utilities/edge-rejections'], $candidate);
            self::assertSame('deliberate', $index['utilities/edge-rejections'][$candidate]['class']);
        }

        $candidates = \array_column($entries, 'candidate');

        foreach (['group-group-hover:flex', 'group-[.x]:flex', 'peer-[.x]:flex'] as $candidate) {
            self::assertContains($candidate, $candidates);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function entry(array $overrides = []): array
    {
        return \array_merge(
            [
                'candidate' => 'p-[1px]',
                'corpus' => 'utilities/edge-values',
                'class' => 'bug',
                'reason' => 'A reason.',
                'since' => '4.1.18',
            ],
            $overrides
        );
    }

    private function write(string $contents): string
    {
        $file = $this->root . '/data-' . \md5($contents) . '.php';

        \file_put_contents($file, $contents);

        return $file;
    }
}
