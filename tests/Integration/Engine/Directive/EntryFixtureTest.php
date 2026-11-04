<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Directive\Processor;
use TailwindPhp\Engine\Directive\ProcessorOptions;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * The hand-written entries under `tests/Fixtures/engine/directives/`
 * (criterion 10): read from disk into an in-memory import map keyed by their
 * project-relative paths, never by an absolute one.
 */
final class EntryFixtureTest extends TestCase
{
    /** @test */
    public function theFullEntryIsProcessed(): void
    {
        [$sheet, $bag] = self::process('entry.css');

        self::assertSame([], $bag->toArray());
        self::assertSame(['theme', 'base', 'components', 'utilities'], $sheet->layers);
        $authored = Serializer::compact(new StyleSheet($sheet->authored));

        self::assertSame('@layer components{.card{padding:1rem}}', $authored);
        self::assertSame(['underline', 'p-1', 'p-2'], $sheet->inlineCandidates);
        self::assertSame(['px-4', 'hocus:underline'], $sheet->applies[0]['candidates']);
        self::assertSame('.btn', $sheet->applies[0]['host']->selector);
        self::assertArrayHasKey('hocus', $sheet->customVariants);
        self::assertSame([['kind' => 'none', 'value' => '']], $sheet->sources);
    }

    /** @test */
    public function aSelfImportingEntryEndsWithOneDiagnostic(): void
    {
        [$sheet, $bag] = self::process('self-import.css');

        self::assertCount(1, $bag);
        self::assertSame(Diagnostic::E_IMPORT_CYCLE, $bag->all()[0]->code());
        self::assertSame('.self{color:red}', Serializer::compact(new StyleSheet($sheet->authored)));
    }

    /**
     * @return array{0: ProcessedSheet, 1: DiagnosticBag}
     */
    private static function process(string $entry): array
    {
        $root = \dirname(__DIR__, 3) . '/Fixtures/engine/directives';
        $files = [];

        foreach (['entry.css', 'self-import.css', 'partials/card.css'] as $path) {
            $files[$path] = \str_replace("\r\n", "\n", (string) \file_get_contents($root . '/' . $path));
        }

        $bag = new DiagnosticBag();
        $sheet = Processor::process(
            Parser::parse($files[$entry], $bag),
            new InMemoryImportResolver($files),
            new ThemeRegistry(),
            UtilityRegistry::default(),
            new VariantRegistry(Variants::table()),
            new ProcessorOptions($entry, \strlen($files[$entry])),
            $bag
        );

        return [$sheet, $bag];
    }
}
