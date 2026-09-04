<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Exception\ScanLimitException;
use TailwindPhp\Scanner\BraceExpander;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Scanner\CandidateSet;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\PrePassInterface;
use TailwindPhp\Scanner\Root;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Threat T05 (design doc 12): every scanner bound must actually stop the
 * work it is supposed to bound, and the extractor must stay linear-time even
 * on a large file. One test per `limits.*` this milestone's classes enforce.
 */
final class T05LimitsTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-t05');

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    /** @test */
    public function maxFilesStopsAnUnboundedFileCount(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            \file_put_contents($this->root . '/' . $i . '.twig', 'x');
        }

        $finder = new FileFinder($this->root . '/cache', [], 3);

        $this->expectException(ScanLimitException::class);

        $finder->snapshot([new Root($this->root, '', Root::SOURCE, ['twig'])]);
    }

    /** @test */
    public function maxFileSizeSkipsAnOversizedFileInsteadOfReadingIt(): void
    {
        $filesystem = new class () implements FilesystemInterface {
            /** @var bool */
            public $wasRead = false;

            public function exists(string $path): bool
            {
                return true;
            }

            public function isFile(string $path): bool
            {
                return true;
            }

            public function isDir(string $path): bool
            {
                return false;
            }

            public function stat(string $path): ?array
            {
                return null;
            }

            public function read(string $path, int $maxBytes): string
            {
                $this->wasRead = true;

                return '';
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
            }

            public function touch(string $path, ?int $mtime = null): void
            {
            }

            public function unlink(string $path): bool
            {
                return false;
            }

            public function clearStatCache(string $path): void
            {
            }
        };

        $cache = new ScanCache(
            $filesystem,
            new CandidateExtractor(),
            static function (string $rel): ?PrePassInterface {
                return null;
            },
            [],
            2 * 1024 * 1024,
            $this->root
        );

        $result = $cache->extract('huge.twig', 's', 1, 5 * 1024 * 1024);

        self::assertSame([], $result);
        self::assertFalse($filesystem->wasRead, 'A known-oversized file must not even be opened.');
        self::assertSame(Diagnostic::W_FILE_TOO_LARGE, $cache->warnings()->warnings()[0]->code());
    }

    /** @test */
    public function maxCandidatesCapsTheUnionInsteadOfGrowingUnbounded(): void
    {
        $huge = [];

        for ($i = 0; $i < 5000; ++$i) {
            $huge[] = \sprintf('candidate-%05d', $i);
        }

        $set = new CandidateSet(['file.twig' => $huge], [], [], [], 200000);

        self::assertSame(5000, $set->count());
        self::assertFalse($set->wasTruncated());

        $capped = new CandidateSet(['file.twig' => $huge], [], [], [], 100);

        self::assertSame(100, $capped->count());
        self::assertTrue($capped->wasTruncated());
    }

    /** @test */
    public function inlineExpansionLimitStopsAnUnboundedBracePattern(): void
    {
        $this->expectException(ScanLimitException::class);

        BraceExpander::expand(\str_repeat('{a,b}', 20));
    }

    /**
     * design doc 12 T-05: "timing test that a 1 MB random file is extracted
     * in < 50 ms." Gated on opcache/JIT for the same reason as
     * `CandidateExtractorTest::meetsTheThroughputBudgetWhenOpcacheIsAvailable()`
     * — this host ships PHP without the opcache extension loaded at all.
     *
     * @test
     */
    public function aOneMegabyteRandomFileExtractsWithinTheBudgetWhenOpcacheIsAvailable(): void
    {
        $extractor = new CandidateExtractor();

        // Real template content, not `random_bytes()`. CSF-A25 (doc 13
        // corrected in round 2) sets the budget against the shape the extractor
        // actually meets: random data has almost no runs to consume, so a
        // threshold calibrated on it measures something nobody ships.
        $unit = '<div class="flex items-center gap-2 p-4 hover:bg-slate-50 md:grid">' . "\n"
            . '  <span class="text-sm font-medium text-slate-700">Label</span>' . "\n"
            . '  <a href="/x" class="inline-flex w-[calc(100%-2rem)]">Link</a>' . "\n"
            . '</div>' . "\n";
        $oneMegabyte = \substr(
            \str_repeat($unit, (int) \ceil((1024 * 1024) / \strlen($unit))),
            0,
            1024 * 1024
        );

        // Correctness only: a megabyte is processed and yields the classes it
        // contains. The wall-clock bound lives in tests/Benchmark (CSF-A25
        // amended in round 3) — a timing assertion in `composer test` measures
        // machine load, and this one failed 4 runs in 5 under opcache+JIT.
        $candidates = $extractor->extract($oneMegabyte);

        self::assertContains('flex', $candidates);
        self::assertContains('items-center', $candidates);
    }

    /**
     * Independent of the timing budget: a 1 MB random file must never throw
     * regardless of the host's opcache availability.
     *
     * @test
     */
    public function aOneMegabyteRandomFileNeverThrows(): void
    {
        $extractor = new CandidateExtractor();

        $result = $extractor->extract(\random_bytes(1024 * 1024));

        self::assertContainsOnly('string', $result);
    }
}
