<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;

/**
 * Recall against real-world-shaped fixtures (design doc 09 §Tests, ADR-019):
 * every genuine Tailwind-looking class embedded in each fixture must survive
 * extraction. Two checks:
 *
 * - **the CLI goldens** (M2, TASK-0041): `golden/extractor/<stem>.json` is
 *   the pinned Tailwind CLI's own answer for each fixture — the candidates it
 *   found that produce CSS — and doc 09 requires 100 % recall of it; the
 *   failure message gives each fixture's recall and what was missed.
 *   Precision is reported by doc 09, not enforced, so extra candidates pass;
 * - **the hand-pinned lists** (M1), kept: they name the classes a reviewer
 *   expected before any golden existed, including the `classList.add(...)`
 *   regression of review round 1.
 *
 * @covers-requirement FR-SCAN-2
 */
final class ExtractorFixtureRecallTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/extractor';

    /**
     * The fixtures with a committed CLI golden (design doc 16 §Corpus layout).
     */
    private const GOLDEN_FIXTURES = 5;

    /**
     * @test
     *
     * @dataProvider goldenFixtures
     */
    public function theExtractorRecallsEveryCandidateTheCliFound(string $key): void
    {
        $paths = new CorpusPaths();
        $golden = \json_decode(self::read($paths->goldenPath($key)), true, 4, \JSON_THROW_ON_ERROR);

        self::assertIsArray($golden);
        self::assertNotSame([], $golden, $key . ': the golden lists no candidate');

        $extracted = (new CandidateExtractor())->extract(self::read($paths->sourcePath($key)));
        $missed = \array_values(\array_diff($golden, $extracted));
        $found = \count($golden) - \count($missed);

        self::assertSame([], $missed, \sprintf(
            '%s: recall %.1f %% (%d of %d CLI candidates; doc 09 requires 100 %%), %d extracted in all; missed: %s',
            $key,
            $found / \count($golden) * 100,
            $found,
            \count($golden),
            \count($extracted),
            \implode(' ', $missed)
        ));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function goldenFixtures(): array
    {
        $cases = [];

        foreach ((new CorpusPaths())->select('extractor') as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    /** @test */
    public function everyFixtureHasACommittedGolden(): void
    {
        $paths = new CorpusPaths();
        $keys = $paths->select('extractor');

        self::assertCount(self::GOLDEN_FIXTURES, $keys);

        foreach ($keys as $key) {
            self::assertFileExists($paths->goldenPath($key));
        }
    }

    /**
     * @test
     *
     * @dataProvider recallCases
     *
     * @param list<string> $expectedCandidates
     */
    public function fixtureRecallsEveryEmbeddedClass(string $filename, array $expectedCandidates): void
    {
        $bytes = \file_get_contents(self::FIXTURES_DIR . '/' . $filename);
        self::assertNotFalse($bytes);

        $result = (new CandidateExtractor())->extract($bytes);

        foreach ($expectedCandidates as $expected) {
            self::assertContains(
                $expected,
                $result,
                \sprintf('"%s" was not recalled from %s.', $expected, $filename)
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function recallCases(): array
    {
        return [
            'twig-basic.twig' => ['twig-basic.twig', [
                'flex', 'items-center', 'justify-between', 'p-4', 'bg-white', 'shadow-md',
                'text-xl', 'font-bold', 'text-gray-900', 'gap-4',
                'hover:text-blue-500', 'md:inline-block', 'text-blue-600', 'font-semibold',
            ]],
            'blade.blade.php' => ['blade.blade.php', [
                'flex', 'flex-col', 'gap-2', 'rounded-lg', 'border', 'border-gray-200', 'p-6',
                'inline-flex', 'items-center', 'px-2', 'py-1', 'text-xs', 'font-medium',
                'bg-red-100', 'text-red-800', 'rounded-full',
                'w-full', 'sm:w-auto', 'bg-indigo-600', 'hover:bg-indigo-700', 'text-white',
                'px-4', 'py-2', 'rounded-md',
            ]],
            'vue.vue' => ['vue.vue', [
                'flex', 'md:flex-row',
                'px-3', 'py-2', 'bg-emerald-500', 'hover:bg-emerald-600', 'text-white',
                'rounded-lg', 'transition-colors',
                'mt-2', 'p-4', 'bg-gray-50', 'border', 'border-gray-200', 'rounded-xl',
            ]],
            'js-template.js' => ['js-template.js', [
                'inline-flex', 'items-center', 'px-4', 'py-2', 'bg-blue-600',
                'hover:bg-blue-700', 'text-white', 'rounded-md', 'shadow-sm',
                'bg-white', 'hover:bg-gray-50', 'text-gray-700', 'border', 'border-gray-300',
                // Review round 1 item 2: the classList.add(...) call at the
                // end of this fixture used to be swallowed whole; CSF-A22
                // recovers both arguments.
                'opacity-0', 'pointer-events-none',
            ]],
            'json-translations.json' => ['json-translations.json', [
                'max-w-7xl', 'mx-auto', 'px-4', 'sm:px-6', 'lg:px-8',
                'bg-white', 'overflow-hidden', 'shadow', 'rounded-lg', 'divide-y', 'divide-gray-200',
            ]],
        ];
    }

    /** @test */
    public function everyFixtureFileExists(): void
    {
        foreach (self::recallCases() as [$filename]) {
            self::assertFileExists(self::FIXTURES_DIR . '/' . $filename);
        }
    }

    private static function read(string $path): string
    {
        $contents = \file_get_contents($path);

        self::assertIsString($contents, \basename($path) . ' is readable');

        return $contents;
    }
}
