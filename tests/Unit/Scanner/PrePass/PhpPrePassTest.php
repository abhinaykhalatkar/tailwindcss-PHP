<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner\PrePass;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Scanner\PrePass\PhpPrePass;
use TailwindPhp\Scanner\PrePassInterface;

final class PhpPrePassTest extends TestCase
{
    /** @test */
    public function implementsThePrePassInterface(): void
    {
        self::assertInstanceOf(PrePassInterface::class, new PhpPrePass());
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(PhpPrePass::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider cases
     */
    public function appliesTheExpectedTransform(string $input, string $expected): void
    {
        self::assertSame($expected, (new PhpPrePass())->apply($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'strips a full php tag' => ['<?php $x = 1; ?>flex', 'flex'],
            'strips a short echo tag' => ['<?= $x ?>flex', 'flex'],
            'strips a tag in the middle' => ['before <?php noise(); ?> after', 'before  after'],
            'strips multiple tags' => ['<?php one(); ?>flex<?php two(); ?>', 'flex'],
            'strips a multi-line tag' => ["<?php\n\$a = 1;\n?>flex", 'flex'],
            'is case-insensitive on the php keyword' => ['<?PHP $x = 1; ?>flex', 'flex'],
            'leaves plain html alone' => ['<div class="flex"></div>', '<div class="flex"></div>'],
            'leaves text without any tag alone' => ['just plain text', 'just plain text'],
            'leaves an unterminated tag unstripped' => ['<?php never closed flex', '<?php never closed flex'],
            'empty tag body' => ['<??>flex', '<??>flex'],
            'empty input' => ['', ''],
        ];
    }
}
