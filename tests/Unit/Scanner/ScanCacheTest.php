<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Scanner\ExtractorInterface;
use TailwindPhp\Scanner\PrePassInterface;
use TailwindPhp\Scanner\ScanCache;
use TailwindPhp\Support\FilesystemInterface;

final class ScanCacheTest extends TestCase
{
    /** @test */
    public function readsAndExtractsAFileWithNoPreviousRecord(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => 'flex p-4']);
        $extractor = self::recordingExtractor();
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), [], 1024, '/project');

        $result = $cache->extract('a.twig', 's', 100, 8);

        self::assertSame(['flex', 'p-4'], $result);
        self::assertTrue($cache->warnings()->isEmpty());
    }

    /** @test */
    public function reusesThePreviousCandidatesWhenIdentityMatches(): void
    {
        $filesystem = self::fakeFilesystem([]);
        $extractor = self::throwingExtractor();
        $previous = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8, 'c' => ['cached-candidate']]];
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), $previous, 1024, '/project');

        $result = $cache->extract('a.twig', 's', 100, 8);

        self::assertSame(['cached-candidate'], $result);
    }

    /**
     * @test
     *
     * @dataProvider identityMismatchCases
     */
    public function reReadsWhenIdentityDiffers(int $mtime, int $size): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => 'grid']);
        $extractor = self::recordingExtractor();
        $previous = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8, 'c' => ['cached-candidate']]];
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), $previous, 1024, '/project');

        $result = $cache->extract('a.twig', 's', $mtime, $size);

        self::assertSame(['grid'], $result);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function identityMismatchCases(): array
    {
        return [
            'mtime changed' => [200, 8],
            'size changed' => [100, 9],
            'both changed' => [200, 9],
        ];
    }

    /** @test */
    public function anEntryWithoutAStoredCandidateListIsTreatedAsUncached(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => 'grid']);
        $extractor = self::recordingExtractor();
        // Same (m, s) as before, but no 'c' key — a manifest row that never
        // finished a Layer 2 pass.
        $previous = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8]];
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), $previous, 1024, '/project');

        self::assertSame(['grid'], $cache->extract('a.twig', 's', 100, 8));
    }

    /** @test */
    public function joinsTheProjectRootAndRelativePathToReadTheFile(): void
    {
        $filesystem = self::fakeFilesystem(['/project/templates/a.twig' => 'flex']);
        $extractor = self::recordingExtractor();
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), [], 1024, '/project');

        self::assertSame(['flex'], $cache->extract('templates/a.twig', 's', 1, 4));
    }

    /** @test */
    public function skipsAndWarnsWhenTheKnownSizeAlreadyExceedsTheBudget(): void
    {
        $filesystem = self::throwingFilesystem();
        $extractor = self::throwingExtractor();
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), [], 10, '/project');

        $result = $cache->extract('big.twig', 's', 1, 999);

        self::assertSame([], $result);
        self::assertCount(1, $cache->warnings()->warnings());
        self::assertSame(Diagnostic::W_FILE_TOO_LARGE, $cache->warnings()->warnings()[0]->code());
        self::assertSame('big.twig', $cache->warnings()->warnings()[0]->file());
    }

    /** @test */
    public function skipsAndWarnsWhenTheActualReadExceedsTheBudget(): void
    {
        // The snapshot's recorded size understated reality (a race between
        // stat and read); the read-budget check catches it independently.
        $filesystem = self::fakeFilesystem(['/project/big.twig' => \str_repeat('x', 11)]);
        $extractor = self::throwingExtractor();
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), [], 10, '/project');

        $result = $cache->extract('big.twig', 's', 1, 5);

        self::assertSame([], $result);
        self::assertSame(Diagnostic::W_FILE_TOO_LARGE, $cache->warnings()->warnings()[0]->code());
    }

    /** @test */
    public function requestsOneMoreByteThanTheBudgetFromTheFilesystem(): void
    {
        $filesystem = new class () implements FilesystemInterface {
            /** @var int|null */
            public $lastMaxBytes;

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
                $this->lastMaxBytes = $maxBytes;

                return 'flex';
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

        $cache = new ScanCache($filesystem, self::recordingExtractor(), self::noPrepass(), [], 2097152, '/project');
        $cache->extract('a.twig', 's', 1, 4);

        self::assertSame(2097153, $filesystem->lastMaxBytes);
    }

    /** @test */
    public function skipsAndWarnsOnABinaryLookingFile(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => "abc\0def"]);
        $extractor = self::throwingExtractor();
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), [], 1024, '/project');

        $result = $cache->extract('a.twig', 's', 1, 7);

        self::assertSame([], $result);
        self::assertSame(Diagnostic::W_BINARY_SKIPPED, $cache->warnings()->warnings()[0]->code());
    }

    /** @test */
    public function appliesThePrePassSelectedForTheFileBeforeExtracting(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => '{# comment #}flex']);
        $extractor = self::recordingExtractor();
        $prepass = new class () implements PrePassInterface {
            public function apply(string $text): string
            {
                return \str_replace('{# comment #}', '', $text);
            }
        };

        $cache = new ScanCache(
            $filesystem,
            $extractor,
            static function (string $rel) use ($prepass): PrePassInterface {
                return $prepass;
            },
            [],
            1024,
            '/project'
        );

        self::assertSame(['flex'], $cache->extract('a.twig', 's', 1, 17));
    }

    /**
     * Review round 1 item 3: a read failure (the file vanished between the
     * snapshot stat and this read — an ordinary deploy race) must not escape
     * as an uncaught exception.
     *
     * @test
     */
    public function treatsAReadFailureAsEmptyRatherThanLettingItEscape(): void
    {
        $filesystem = self::readFailsFilesystem();
        $extractor = self::throwingExtractor();
        $cache = new ScanCache($filesystem, $extractor, self::noPrepass(), [], 1024, '/project');

        $result = $cache->extract('vanished.twig', 's', 1, 4);

        self::assertSame([], $result);
    }

    /** @test */
    public function aReadFailureRecordsAWFileUnreadableWarning(): void
    {
        $filesystem = self::readFailsFilesystem();
        $cache = new ScanCache($filesystem, self::throwingExtractor(), self::noPrepass(), [], 1024, '/project');

        $cache->extract('vanished.twig', 's', 1, 4);

        $warnings = $cache->warnings()->warnings();
        self::assertCount(1, $warnings);
        self::assertSame(Diagnostic::W_FILE_UNREADABLE, $warnings[0]->code());
        self::assertSame('vanished.twig', $warnings[0]->file());
    }

    /**
     * Review round 1 item 3: the failed read must not be memoised as a valid
     * cache entry — a second call with the *same* identity must try to read
     * again rather than silently returning the same empty result forever.
     *
     * @test
     */
    public function aReadFailureIsNotMemoisedSoTheNextRequestRetries(): void
    {
        $filesystem = self::readFailsThenSucceedsFilesystem('flex');
        $cache = new ScanCache($filesystem, new class () implements ExtractorInterface {
            public function extract(string $bytes): array
            {
                return [$bytes];
            }
        }, self::noPrepass(), [], 1024, '/project');

        $first = $cache->extract('flaky.twig', 's', 1, 4);
        $second = $cache->extract('flaky.twig', 's', 1, 4);

        self::assertSame([], $first);
        self::assertSame(['flex'], $second);
    }

    /** @test */
    public function recordsIsEmptyBeforeAnyExtractCall(): void
    {
        $cache = new ScanCache(
            self::fakeFilesystem([]),
            self::throwingExtractor(),
            self::noPrepass(),
            [],
            1024,
            '/project'
        );

        self::assertSame([], $cache->records());
    }

    /** @test */
    public function recordsCapturesTheIdentityAndCandidatesOfAFreshRead(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => 'flex p-4']);
        $cache = new ScanCache($filesystem, self::recordingExtractor(), self::noPrepass(), [], 1024, '/project');

        $cache->extract('a.twig', 's', 100, 8);

        self::assertSame(
            ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8, 'c' => ['flex', 'p-4']]],
            $cache->records()
        );
    }

    /** @test */
    public function recordsCarriesOverACacheHitUnderTheFreshKind(): void
    {
        $filesystem = self::fakeFilesystem([]);
        $previous = ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8, 'c' => ['cached']]];
        $cache = new ScanCache($filesystem, self::throwingExtractor(), self::noPrepass(), $previous, 1024, '/project');

        $cache->extract('a.twig', 's', 100, 8);

        self::assertSame(
            ['a.twig' => ['k' => 's', 'm' => 100, 's' => 8, 'c' => ['cached']]],
            $cache->records()
        );
    }

    /**
     * @test
     *
     * @dataProvider skipReasonCases
     */
    public function recordsOmitsCForEverySkipReason(callable $makeCache): void
    {
        $cache = $makeCache();

        $cache->extract('x.twig', 's', 1, 999);

        $record = $cache->records()['x.twig'] ?? null;
        self::assertNotNull($record);
        self::assertArrayNotHasKey('c', $record);
    }

    /**
     * @return array<string, array{0: callable(): ScanCache}>
     */
    public static function skipReasonCases(): array
    {
        return [
            'oversized by known size' => [static function (): ScanCache {
                return new ScanCache(
                    self::throwingFilesystem(),
                    self::throwingExtractor(),
                    self::noPrepass(),
                    [],
                    10,
                    '/project'
                );
            }],
            'unreadable' => [static function (): ScanCache {
                return new ScanCache(
                    self::readFailsFilesystem(),
                    self::throwingExtractor(),
                    self::noPrepass(),
                    [],
                    1024,
                    '/project'
                );
            }],
            'binary' => [static function (): ScanCache {
                return new ScanCache(
                    self::fakeFilesystem(['/project/x.twig' => "a\0b"]),
                    self::throwingExtractor(),
                    self::noPrepass(),
                    [],
                    1024,
                    '/project'
                );
            }],
        ];
    }

    /**
     * @param array<string, string> $contentsByAbsPath
     */
    private static function fakeFilesystem(array $contentsByAbsPath): FilesystemInterface
    {
        return new class ($contentsByAbsPath) implements FilesystemInterface {
            /** @var array<string, string> */
            private $contents;

            /**
             * @param array<string, string> $contents
             */
            public function __construct(array $contents)
            {
                $this->contents = $contents;
            }

            public function exists(string $path): bool
            {
                return isset($this->contents[$path]);
            }

            public function isFile(string $path): bool
            {
                return isset($this->contents[$path]);
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
                if (!isset($this->contents[$path])) {
                    throw new \RuntimeException('Unexpected read: ' . $path);
                }

                return \substr($this->contents[$path], 0, $maxBytes);
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
    }

    private static function throwingFilesystem(): FilesystemInterface
    {
        return new class () implements FilesystemInterface {
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
                throw new \RuntimeException('read() must not be called: ' . $path);
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
    }

    /**
     * A filesystem whose read() always throws, simulating a file that
     * vanished (or became unreadable) between the snapshot stat and the
     * read — an ordinary deploy race (review round 1 item 3), not a
     * "read() should never be called" assertion like
     * {@see self::throwingFilesystem()}.
     */
    private static function readFailsFilesystem(): FilesystemInterface
    {
        return new class () implements FilesystemInterface {
            public function exists(string $path): bool
            {
                return false;
            }

            public function isFile(string $path): bool
            {
                return false;
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
                throw new \RuntimeException('Cannot open file for reading: ' . $path);
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
    }

    private static function readFailsThenSucceedsFilesystem(string $contentsOnSecondRead): FilesystemInterface
    {
        return new class ($contentsOnSecondRead) implements FilesystemInterface {
            /** @var string */
            private $contentsOnSecondRead;

            /** @var int */
            private $calls = 0;

            public function __construct(string $contentsOnSecondRead)
            {
                $this->contentsOnSecondRead = $contentsOnSecondRead;
            }

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
                ++$this->calls;

                if ($this->calls === 1) {
                    throw new \RuntimeException('Cannot open file for reading: ' . $path);
                }

                return $this->contentsOnSecondRead;
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
    }

    /**
     * A second extract() for the same file in one request costs no read
     * (review round 2 item 6).
     *
     * The first call is served from disk and recorded; the second is answered
     * from that record. Proven by swapping the filesystem for one that throws
     * on read — if the memo were absent the second call would raise, so this
     * cannot pass by accident.
     *
     * @test
     */
    public function asecondExtractInTheSameRequestIsMemoised(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => 'flex p-4']);
        $cache = new ScanCache($filesystem, self::recordingExtractor(), self::noPrepass(), [], 1024, '/project');

        self::assertSame(['flex', 'p-4'], $cache->extract('a.twig', 's', 100, 8));
        self::assertSame(['flex', 'p-4'], $cache->extract('a.twig', 's', 100, 8));
    }

    /**
     * A changed stat within the request is not the same file, so the memo must
     * not answer for it.
     *
     * @test
     */
    public function amemoIsNotReusedWhenTheStatChanges(): void
    {
        $filesystem = self::fakeFilesystem(['/project/a.twig' => 'flex p-4']);
        $cache = new ScanCache($filesystem, self::recordingExtractor(), self::noPrepass(), [], 1024, '/project');

        self::assertSame(['flex', 'p-4'], $cache->extract('a.twig', 's', 100, 8));
        // Different mtime => re-read, not the memo.
        self::assertSame(['flex', 'p-4'], $cache->extract('a.twig', 's', 200, 8));
    }

    /**
     * An unreadable file is deliberately *not* memoised, so a later call in the
     * same request tries again rather than inheriting an empty result — the
     * whole point of leaving `c` off that record (round 1 item 3).
     *
     * @test
     */
    public function anUnreadableFileIsNotMemoised(): void
    {
        $filesystem = self::fakeFilesystem([]);
        $cache = new ScanCache($filesystem, self::recordingExtractor(), self::noPrepass(), [], 1024, '/project');

        self::assertSame([], $cache->extract('gone.twig', 's', 1, 4));

        $records = $cache->records();

        self::assertArrayHasKey('gone.twig', $records);
        self::assertArrayNotHasKey('c', $records['gone.twig'], 'An unreadable record must carry no candidates.');
    }
    private static function recordingExtractor(): ExtractorInterface
    {
        return new class () implements ExtractorInterface {
            public function extract(string $bytes): array
            {
                $words = \explode(' ', $bytes);

                \sort($words, \SORT_STRING);

                return $words;
            }
        };
    }

    private static function throwingExtractor(): ExtractorInterface
    {
        return new class () implements ExtractorInterface {
            public function extract(string $bytes): array
            {
                throw new \RuntimeException('extract() must not be called.');
            }
        };
    }

    /**
     * @return callable(string): (PrePassInterface|null)
     */
    private static function noPrepass(): callable
    {
        return static function (string $rel): ?PrePassInterface {
            return null;
        };
    }
}
