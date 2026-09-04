<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\CandidateExtractor;

/**
 * Recall against real-world-shaped fixtures (design doc 09 §Tests, ADR-019):
 * every genuine Tailwind-looking class embedded in each fixture must survive
 * extraction. This is a recall floor, not an exact-match golden — the
 * CLI-derived conformance suite that checks precision too lands in M2
 * (design doc 16 §Conformance suite); until then this is the only check that
 * these fixtures work at all.
 */
final class ExtractorFixtureRecallTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/extractor';

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
}
