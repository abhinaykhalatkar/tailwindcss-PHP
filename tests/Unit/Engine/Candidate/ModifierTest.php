<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Modifier;

/**
 * Modifier classification (design doc 07 step 9): named, `[arbitrary]`,
 * `(--var)`.
 */
final class ModifierTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider modifiers
     */
    public function modifiersAreClassified(string $text, string $kind, string $value): void
    {
        $modifier = Modifier::parse($text);

        self::assertNotNull($modifier);
        self::assertSame([$kind, $value], [$modifier->kind, $modifier->value]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function modifiers(): array
    {
        return [
            'opacity' => ['50', Modifier::NAMED, '50'],
            'line height' => ['8', Modifier::NAMED, '8'],
            'named word' => ['tight', Modifier::NAMED, 'tight'],
            'group name' => ['item', Modifier::NAMED, 'item'],
            'arbitrary' => ['[0.33]', Modifier::ARBITRARY, '0.33'],
            'arbitrary normalised' => ['[calc(1px+2px)]', Modifier::ARBITRARY, 'calc(1px + 2px)'],
            'arbitrary underscores' => ['[a_b]', Modifier::ARBITRARY, 'a b'],
            'variable' => ['(--o)', Modifier::VAR, '--o'],
            'variable with underscores' => ['(--my_o)', Modifier::VAR, '--my_o'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider invalid
     */
    public function invalidModifiersAreRejected(string $text): void
    {
        self::assertNull(Modifier::parse($text));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalid(): array
    {
        return [
            'empty' => [''],
            'empty brackets' => ['[]'],
            'blank brackets' => ['[_]'],
            'unterminated brackets' => ['[1'],
            'unsafe brackets' => ['[1;x]'],
            'empty parens' => ['()'],
            'parens without dashes' => ['(o)'],
            'bare dashes' => ['(--)'],
            'unterminated parens' => ['(--o'],
            'unsafe parens' => ['(--o;x)'],
            'quote' => ["5'"],
            'stray bracket' => ['5]'],
        ];
    }
}
