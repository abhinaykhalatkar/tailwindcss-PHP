<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Directive\LayerHandler;
use TailwindPhp\Engine\Directive\ProcessorOptions;

/**
 * The walk (criteria 1, 3, 8): every directive consumed, everything else kept
 * in source order with its `@layer` wrappers, layer order from statements.
 */
final class ProcessorTest extends TestCase
{
    /** @test */
    public function authoredCssStaysInSourceOrderWithItsLayers(): void
    {
        $run = new ProcessorHarness(
            '@layer base { h1 { x: 1 } } .u { x: 2 } @layer my-layer { .s { x: 3 } } @media print { .p { x: 4 } }'
        );

        self::assertSame('@layer base{h1{x:1}}.u{x:2}@layer my-layer{.s{x:3}}@media print{.p{x:4}}', $run->authored());
        self::assertSame([], $run->codes());
    }

    /** @test */
    public function byLayerGroupsTheAuthoredNodes(): void
    {
        $run = new ProcessorHarness(
            '@layer base { h1 { x: 1 } } .u { x: 2 } @layer components { .c { x: 3 } } @layer base { h2 { x: 4 } }'
        );
        $groups = $run->sheet->byLayer();

        self::assertSame(['base', '', 'components'], \array_keys($groups));
        self::assertSame('h1{x:1}h2{x:4}', ProcessorHarness::css($groups['base']));
        self::assertSame('.u{x:2}', ProcessorHarness::css($groups['']));
    }

    /** @test */
    public function layerStatementsDeclareOrder(): void
    {
        $run = new ProcessorHarness('@layer a, b; @layer b, c; @layer d { .x { y: z } }');

        self::assertSame(['a', 'b', 'c'], $run->sheet->layers);
        self::assertSame('@layer d{.x{y:z}}', $run->authored(), 'the statement is consumed, the block kept');
    }

    /** @test */
    public function invalidLayerNamesAreDropped(): void
    {
        self::assertSame([], LayerHandler::names(new \TailwindPhp\Engine\Css\Node\AtRule('layer', '"x", a b')));
    }

    /**
     * @test
     *
     * @dataProvider unsupported
     */
    public function pluginAndConfigPointAtTheCliDriver(string $css): void
    {
        $run = new ProcessorHarness($css);

        self::assertSame([Diagnostic::E_UNSUPPORTED_DIRECTIVE], $run->codes());
        self::assertStringContainsString('CLI driver', $run->messages()[0]);
        self::assertSame('', $run->authored());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupported(): array
    {
        return [
            'plugin' => ['@plugin "@tailwindcss/typography";'],
            'config' => ['@config "./tailwind.config.js";'],
        ];
    }

    /** @test */
    public function unknownAtRulesAreAuthoredCss(): void
    {
        $run = new ProcessorHarness(
            '@font-face { font-family: X; } @charset "utf-8"; @supports (display: grid) { .g { x: 1 } }'
        );

        self::assertSame(
            '@font-face{font-family:X}@charset "utf-8";@supports (display:grid){.g{x:1}}',
            $run->authored()
        );
        self::assertSame([], $run->codes());
    }

    /** @test */
    public function directivesInsideConditionalBlocksAreHandledToo(): void
    {
        $run = new ProcessorHarness(
            '@media print { @import "./b.css"; @source inline("flex"); }',
            ['b.css' => '.b{x:1}']
        );

        self::assertSame('@media print{.b{x:1}}', $run->authored());
        self::assertSame(['flex'], $run->sheet->inlineCandidates);
    }

    /** @test */
    public function aDefaultRunNeedsNoOptions(): void
    {
        $options = new ProcessorOptions('input.css', 0);

        self::assertSame(4194304, $options->maxTotalCss);
        self::assertSame(16, $options->maxImportDepth);
        self::assertSame(1000, $options->maxImports);
        self::assertSame(20000, $options->maxInlineCandidates);
        self::assertTrue($options->preflight);
        self::assertSame('input.css', $options->entryPath);
    }

    /**
     * Round 2: the byte budget counts the entry, so the caller must say how
     * large it is — `entryBytes` has no default.
     *
     * @test
     */
    public function theEntrySizeIsARequiredArgument(): void
    {
        $parameters = (new \ReflectionMethod(ProcessorOptions::class, '__construct'))->getParameters();

        self::assertSame('entryBytes', $parameters[1]->getName());
        self::assertFalse($parameters[1]->isOptional());
        self::assertFalse($parameters[0]->isOptional());
    }

    /** @test */
    public function negativeLimitsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProcessorOptions('input.css', -1);
    }
}
