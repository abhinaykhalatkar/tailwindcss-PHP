<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;

/**
 * `@apply` (criterion 7): removed from its rule and recorded with the host
 * rule that stays in the authored tree, so the assembler can fill it.
 */
final class ApplyHandlerTest extends TestCase
{
    /** @test */
    public function applyIsRemovedAndRecordedWithItsHost(): void
    {
        $run = new ProcessorHarness('.btn { color: red; @apply px-4 py-2; @apply hover:bg-red-500; }');

        self::assertSame('.btn{color:red}', $run->authored());
        self::assertCount(2, $run->sheet->applies);
        self::assertSame(['px-4', 'py-2'], $run->sheet->applies[0]['candidates']);
        self::assertSame(['hover:bg-red-500'], $run->sheet->applies[1]['candidates']);
        self::assertNull($run->sheet->applies[0]['utility']);
        self::assertSame($run->sheet->authored[0], $run->sheet->applies[0]['host'], 'the host is the rule in the tree');
    }

    /** @test */
    public function nestedRulesHaveTheirOwnHosts(): void
    {
        $run = new ProcessorHarness('.card { @apply p-4; &:hover { @apply shadow-md; } }');
        $card = $run->sheet->authored[0];

        self::assertInstanceOf(Rule::class, $card);
        self::assertCount(2, $run->sheet->applies);
        self::assertSame($card, $run->sheet->applies[0]['host']);
        self::assertSame($card->nodes[0], $run->sheet->applies[1]['host']);
        self::assertSame('&:hover', $run->sheet->applies[1]['host']->selector);
    }

    /** @test */
    public function applyDirectlyInAConditionalBlockGetsAnAmpersandHostInTheTree(): void
    {
        $run = new ProcessorHarness('.a { @media (width >= 48rem) { @apply p-4; } }');
        $rule = $run->sheet->authored[0];

        self::assertInstanceOf(Rule::class, $rule);
        $media = $rule->nodes[0];
        self::assertInstanceOf(AtRule::class, $media);
        self::assertNotNull($media->nodes);
        self::assertSame($media->nodes[0], $run->sheet->applies[0]['host']);
        self::assertSame('&', $run->sheet->applies[0]['host']->selector);
    }

    /** @test */
    public function applyInsideImportedAndLayeredCssIsFoundToo(): void
    {
        $run = new ProcessorHarness(
            '@layer components { .btn { @apply p-4; } } @import "./b.css";',
            ['b.css' => '.b { @apply m-2; }']
        );

        self::assertCount(2, $run->sheet->applies);
        self::assertSame(['m-2'], $run->sheet->applies[1]['candidates']);
    }

    /** @test */
    public function anEmptyApplyOrOneOutsideARuleIsReported(): void
    {
        $empty = new ProcessorHarness('.a { @apply ; }');
        $outside = new ProcessorHarness('@apply p-4;');

        self::assertSame([Diagnostic::E_APPLY_UNKNOWN], $empty->codes());
        self::assertSame([], $empty->sheet->applies);
        self::assertSame([Diagnostic::E_APPLY_UNKNOWN], $outside->codes());
        self::assertSame([], $outside->sheet->applies);
    }
}
