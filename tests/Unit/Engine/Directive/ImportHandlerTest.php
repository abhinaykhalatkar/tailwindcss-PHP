<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Directive\ProcessorOptions;

/**
 * Relative imports and the Tailwind bundles (TASK-0033 criteria 2 and the
 * T-01 / T-05 / T-06 rows): spliced in place, wrapped for `layer()`, bounded
 * by depth, cycles and bytes, never resolving a path itself.
 */
final class ImportHandlerTest extends TestCase
{
    /** @test */
    public function aRelativeImportIsSplicedInPlace(): void
    {
        $run = new ProcessorHarness('.a{x:1}@import "./b.css";.c{x:3}', ['b.css' => '.b{x:2}']);

        self::assertSame('.a{x:1}.b{x:2}.c{x:3}', $run->authored());
        self::assertSame([], $run->codes());
    }

    /** @test */
    public function aLayerImportIsWrappedInItsLayer(): void
    {
        $run = new ProcessorHarness('@import "./b.css" layer(components);', ['b.css' => '.b{x:2}']);

        self::assertSame('@layer components{.b{x:2}}', $run->authored());
        self::assertSame([], $run->sheet->layers, 'an import layer does not declare order');
    }

    /** @test */
    public function importsNestAndResolveAgainstTheImportingFile(): void
    {
        $run = new ProcessorHarness('@import "./css/a.css";', [
            'css/a.css' => '@import "./b.css";.a{x:1}',
            'css/b.css' => '.b{x:2}',
        ]);

        self::assertSame('.b{x:2}.a{x:1}', $run->authored());
    }

    /** @test */
    public function aMissingRelativeImportIsReported(): void
    {
        $run = new ProcessorHarness('@import "./missing.css";.a{x:1}');

        self::assertSame([Diagnostic::E_IMPORT_NOT_FOUND], $run->codes());
        self::assertStringContainsString('./missing.css', $run->messages()[0]);
        self::assertSame('.a{x:1}', $run->authored());
    }

    /** @test */
    public function anImportTheResolverDoesNotKnowIsLeftAsAuthoredCss(): void
    {
        $run = new ProcessorHarness('@import "https://example.com/x.css";.a{x:1}');

        self::assertSame([], $run->codes());
        self::assertSame('@import "https://example.com/x.css";.a{x:1}', $run->authored());
    }

    /** @test */
    public function aSelfImportIsOneCycleDiagnosticAndTerminates(): void
    {
        $run = new ProcessorHarness('@import "./a.css";', ['a.css' => '@import "./a.css";.a{x:1}']);

        self::assertSame([Diagnostic::E_IMPORT_CYCLE], $run->codes());
        self::assertSame('.a{x:1}', $run->authored());
    }

    /** @test */
    public function importingTheEntryAgainIsACycle(): void
    {
        $run = new ProcessorHarness(
            '@import "./b.css";.e{x:0}',
            ['b.css' => '@import "./input.css";.b{x:1}', 'input.css' => '.e{x:0}']
        );

        self::assertSame([Diagnostic::E_IMPORT_CYCLE], $run->codes());
    }

    /** @test */
    public function aSeventeenDeepChainIsOneDepthDiagnosticAndTerminates(): void
    {
        $files = [];

        for ($i = 1; $i <= 17; ++$i) {
            $next = $i < 17 ? '@import "./f' . ($i + 1) . '.css";' : '';
            $files['f' . $i . '.css'] = $next . '.f' . $i . '{x:' . $i . '}';
        }

        $run = new ProcessorHarness('@import "./f1.css";', $files);

        self::assertSame([Diagnostic::E_IMPORT_DEPTH], $run->codes());
        self::assertSame(16, \substr_count($run->authored(), '{x:'), 'sixteen levels are imported');
        self::assertStringNotContainsString('.f17', $run->authored());
    }

    /** @test */
    public function sixteenLevelsAreAllowed(): void
    {
        $files = [];

        for ($i = 1; $i <= 16; ++$i) {
            $next = $i < 16 ? '@import "./f' . ($i + 1) . '.css";' : '';
            $files['f' . $i . '.css'] = $next . '.f' . $i . '{x:' . $i . '}';
        }

        $run = new ProcessorHarness('@import "./f1.css";', $files);

        self::assertSame([], $run->codes());
        self::assertStringContainsString('.f16', $run->authored());
    }

    /** @test */
    public function aChainPastTheByteBudgetStopsImporting(): void
    {
        $big = '.x{content:"' . \str_repeat('a', 1024 * 1024) . '"}';
        $files = [];

        for ($i = 1; $i <= 5; ++$i) {
            $files['p' . $i . '.css'] = ($i < 5 ? '@import "./p' . ($i + 1) . '.css";' : '') . $big;
        }

        $run = new ProcessorHarness('@import "./p1.css";.after{x:1}', $files);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertStringContainsString('exceed 4194304 bytes', $run->messages()[0]);
        self::assertStringContainsString('.after{x:1}', $run->authored());
        self::assertLessThan(4 * 1024 * 1024 + 4096, \strlen($run->authored()));
    }

    /** @test */
    public function theEntryBytesCountAgainstTheBudget(): void
    {
        $options = new ProcessorOptions('input.css', 100, true, 120);
        $run = new ProcessorHarness('@import "./b.css";', ['b.css' => \str_repeat('.b{x:1}', 10)], $options);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
    }

    /** @test */
    public function diagnosticsEchoTheSpecifierCutAt64Bytes(): void
    {
        $spec = './' . \str_repeat('very-long-name/', 10) . 'missing.css';
        $run = new ProcessorHarness('@import "' . $spec . '";');

        self::assertStringContainsString(\substr($spec, 0, 64) . '…', $run->messages()[0]);
        self::assertStringNotContainsString('missing.css', $run->messages()[0]);
    }

    /** @test */
    public function theTailwindcssBundleRecordsItsThreeLayersAndSeedsTheTheme(): void
    {
        $run = new ProcessorHarness('@import "tailwindcss";');

        self::assertSame(['theme', 'base', 'components', 'utilities'], $run->sheet->layers);
        self::assertSame(['theme' => 'theme', 'preflight' => 'base', 'utilities' => 'utilities'], $run->sheet->bundles);
        self::assertTrue($run->sheet->preflight());
        self::assertNotNull($run->theme->get('--color-red-500'));
        self::assertSame([], $run->codes());
    }

    /** @test */
    public function singleBundlesTakeTheLayerTheirImportNames(): void
    {
        $run = new ProcessorHarness(
            '@import "tailwindcss/theme.css" layer(theme);@import "tailwindcss/utilities.css";'
        );

        self::assertSame(['theme' => 'theme', 'utilities' => null], $run->sheet->bundles);
        self::assertFalse($run->sheet->preflight());
        self::assertSame([], $run->sheet->layers);
    }

    /** @test */
    public function theImportOptionsReachTheSheet(): void
    {
        $run = new ProcessorHarness('@import "tailwindcss" prefix(tw) important source("./src");');

        self::assertSame('tw', $run->sheet->prefix);
        self::assertTrue($run->sheet->important);
        self::assertSame([['kind' => 'base', 'value' => './src']], $run->sheet->sources);
    }

    /** @test */
    public function sourceNoneIsReported(): void
    {
        $run = new ProcessorHarness('@import "tailwindcss" source(none);');

        self::assertSame([['kind' => 'none', 'value' => '']], $run->sheet->sources);
    }

    /** @test */
    public function themeOptionsBecomeFlagsOfTheDefaultTheme(): void
    {
        $static = new ProcessorHarness('@import "tailwindcss/theme.css" theme(static);');
        $plain = new ProcessorHarness('@import "tailwindcss/theme.css";');

        self::assertCount(365, $static->theme->emittable());
        self::assertSame([], $plain->theme->emittable());
    }

    /** @test */
    public function disabledPreflightIsStrippedWithAnInfo(): void
    {
        $run = new ProcessorHarness('@import "tailwindcss";', [], new ProcessorOptions('input.css', 0, false));

        self::assertFalse($run->sheet->preflight());
        self::assertSame(['theme' => 'theme', 'utilities' => 'utilities'], $run->sheet->bundles);
        self::assertSame([Diagnostic::I_PREFLIGHT_DISABLED], $run->codes());
        self::assertSame(Diagnostic::INFO, $run->bag->all()[0]->level());
    }

    /** @test */
    public function anImportWithAnUnknownOptionIsKeptAndReported(): void
    {
        $run = new ProcessorHarness('@import "./b.css" media(print);', ['b.css' => '.b{x:1}']);

        self::assertSame([Diagnostic::E_CSS_SYNTAX], $run->codes());
        self::assertStringContainsString('@import "./b.css" media(print)', $run->authored());
    }
}
