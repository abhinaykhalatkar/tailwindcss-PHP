<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner\PrePass;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Scanner\PrePass\TwigPrePass;
use TailwindPhp\Scanner\PrePassInterface;

final class TwigPrePassTest extends TestCase
{
    /** @test */
    public function implementsThePrePassInterface(): void
    {
        self::assertInstanceOf(PrePassInterface::class, new TwigPrePass());
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(TwigPrePass::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider cases
     */
    public function appliesTheExpectedTransform(string $input, string $expected): void
    {
        self::assertSame($expected, (new TwigPrePass())->apply($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'strips a comment' => ['{# a comment #}flex', 'flex'],
            'strips a comment in the middle' => ['before {# noise #} after', 'before  after'],
            'strips multiple comments' => ['{# one #}flex{# two #}', 'flex'],
            'strips a multi-line comment' => ["{#\n multi\n line\n #}flex", 'flex'],
            'leaves a percent tag body alone' => [
                "{% set klass = 'flex' %}",
                "{% set klass = 'flex' %}",
            ],
            'leaves a variable expression alone' => ['{{ variable }}', '{{ variable }}'],
            'leaves text without any comment alone' => ['just plain text', 'just plain text'],
            'leaves an unterminated comment unstripped' => ['{# never closed flex', '{# never closed flex'],
            'empty comment body' => ['{##}flex', 'flex'],
            'empty input' => ['', ''],
        ];
    }
}
