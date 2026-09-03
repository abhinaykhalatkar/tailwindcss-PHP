<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\PreProcessor;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\PreProcessor\NullPreProcessor;
use TailwindPhp\PreProcessor\PreProcessorInterface;
use TailwindPhp\PreProcessor\PreProcessResult;

final class NullPreProcessorTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(NullPreProcessor::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function itImplementsThePreProcessorInterface(): void
    {
        self::assertInstanceOf(PreProcessorInterface::class, new NullPreProcessor());
    }

    /** @test */
    public function itReturnsAPreProcessResult(): void
    {
        $result = (new NullPreProcessor())->process('.a{color:red}', 'assets/styles/main.css');

        self::assertInstanceOf(PreProcessResult::class, $result);
    }

    /**
     * @test
     *
     * @dataProvider entryTexts
     */
    public function itReturnsItsInputUnchanged(string $entryText): void
    {
        $result = (new NullPreProcessor())->process($entryText, 'assets/styles/main.css');

        self::assertSame($entryText, $result->css());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entryTexts(): array
    {
        return [
            'empty' => [''],
            'a rule' => ['.a{color:red}'],
            'tailwind directives' => ["@import \"tailwindcss\";\n@theme {\n  --color-brand: #f00;\n}\n"],
            'crlf line endings' => [".a{\r\n  color: red;\r\n}\r\n"],
            'utf-8 bytes' => ['.a::after{content:"ü→✓"}'],
            'null byte' => [".a{}\0"],
        ];
    }

    /** @test */
    public function itIsByteExactAndDoesNotTouchWhitespace(): void
    {
        $entryText = "\n\n  .a { color : red }  \n\t\n";

        self::assertSame($entryText, (new NullPreProcessor())->process($entryText, 'a.css')->css());
    }

    /**
     * It opens no files, so it contributes no watched inputs to the manifest.
     *
     * @test
     */
    public function itReportsNoImports(): void
    {
        self::assertSame([], (new NullPreProcessor())->process('.a{}', 'assets/styles/main.css')->imports());
        self::assertSame([], (new NullPreProcessor())->process('', 'a.scss')->imports());
    }

    /** @test */
    public function theEntryPathDoesNotAffectTheOutput(): void
    {
        $processor = new NullPreProcessor();

        $first = $processor->process('.a{}', 'assets/styles/main.css');
        $second = $processor->process('.a{}', 'deep/nested/other.scss');

        self::assertSame($first->css(), $second->css());
        self::assertSame($first->imports(), $second->imports());
    }

    /** @test */
    public function itIsStatelessAcrossCalls(): void
    {
        $processor = new NullPreProcessor();

        self::assertSame('.a{}', $processor->process('.a{}', 'a.css')->css());
        self::assertSame('.b{}', $processor->process('.b{}', 'a.css')->css());
        self::assertSame('.a{}', $processor->process('.a{}', 'a.css')->css());
    }
}
