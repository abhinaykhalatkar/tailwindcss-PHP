<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Engine;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\CssComparator;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * The whole engine — directives, candidates, variants, `@apply`, the assembler,
 * flattening and serialising — against the pinned CLI's directive goldens: every
 * one is reproduced **exactly** (`isIdentical()`: the theme layer, the layers,
 * authored CSS, `@property`, `@keyframes`), and every engine output re-parses
 * without a diagnostic (TASK-0034 criterion 7).
 *
 * The utility and variant goldens are measured once per suite run, by
 * `tests/Conformance/ConformanceTest` (TASK-0047), the strict superset: every
 * candidate, every region, the directives too, and the allow-list's honesty in
 * both directions. The per-file rows that stood here before it existed were
 * removed in TASK-0053.
 */
final class EngineGoldenTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider directives
     */
    public function aDirectiveGoldenIsReproducedExactly(string $name): void
    {
        $paths = new CorpusPaths();
        $key = 'directives/' . $name;
        $candidatesFile = \dirname($paths->sourcePath($key)) . '/' . $name . '.candidates.txt';
        $candidates = \is_file($candidatesFile) ? CorpusPaths::parseCandidates(self::read($candidatesFile)) : [];
        $css = self::compile(self::read($paths->sourcePath($key)), $candidates, self::partials($paths), $name . '.css');
        $result = (new CssComparator())->compare(self::read($paths->goldenPath($key)), $css, $candidates);

        self::assertTrue($result->isIdentical(), $name . ":\n" . self::describe($result));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function directives(): array
    {
        $paths = new CorpusPaths();
        $cases = [];

        foreach ((array) \glob(\dirname($paths->sourcePath('directives/apply')) . '/*.css') as $file) {
            $name = \basename((string) $file, '.css');

            if (\is_file($paths->root() . '/golden/directives/' . $name . '.css')) {
                $cases[$name] = [$name];
            }
        }

        return $cases;
    }

    /** @test */
    public function everyDirectiveGoldenHasARow(): void
    {
        self::assertCount(15, self::directives());
    }

    /**
     * @param list<string> $candidates
     * @param array<string, string> $files
     */
    private static function compile(string $entry, array $candidates, array $files, string $entryPath): string
    {
        $output = (new Compiler())->compile(new CompileRequest(
            $entry,
            new InMemoryImportResolver($files),
            $candidates,
            false,
            true,
            CompileRequest::DEFAULT_MAX_CANDIDATES,
            $entryPath
        ));
        $bag = new DiagnosticBag();

        Parser::parse($output->css, $bag);
        self::assertSame([], $bag->toArray(), 'the engine output re-parses clean');

        return $output->css;
    }

    /**
     * The failures, one per line with their class.
     */
    private static function describe(ComparisonResult $result): string
    {
        $lines = '';

        foreach ($result->failures() as $failure) {
            $lines .= "\n  " . $failure['status'] . ' ' . (string) $failure['candidate']
                . "\n    expected " . \substr((string) $failure['expected'], 0, 300)
                . "\n    actual   " . \substr((string) $failure['actual'], 0, 300);
        }

        return $lines;
    }

    /**
     * Every directive fixture by file name, for `@import "./x.css"`.
     *
     * @return array<string, string>
     */
    private static function partials(CorpusPaths $paths): array
    {
        $files = [];

        foreach ((array) \glob(\dirname($paths->sourcePath('directives/apply')) . '/*.css') as $file) {
            $files[\basename((string) $file)] = self::read((string) $file);
        }

        return $files;
    }

    private static function read(string $path): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('Cannot read ' . \basename($path) . '.');
        }

        return \str_replace("\r\n", "\n", $contents);
    }
}
