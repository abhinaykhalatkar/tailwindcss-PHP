<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Theme;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeFlags;

/**
 * The options of an `@theme` block (design doc 07 §Phase 2).
 */
final class ThemeFlagsTest extends TestCase
{
    /** @test */
    public function aPlainThemeHasNoFlags(): void
    {
        $bag = new DiagnosticBag();
        $flags = ThemeFlags::parse('', $bag);

        self::assertFalse($flags->isInline());
        self::assertFalse($flags->isStatic());
        self::assertFalse($flags->isReference());
        self::assertFalse($flags->isDefault());
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function noneIsTheSameAsAnEmptyPrelude(): void
    {
        $none = ThemeFlags::none();

        self::assertFalse($none->isInline() || $none->isStatic() || $none->isReference() || $none->isDefault());
    }

    /**
     * @test
     *
     * @dataProvider singleWords
     */
    public function eachWordSetsExactlyItsOwnFlag(string $word, string $expected): void
    {
        $flags = ThemeFlags::parse($word, new DiagnosticBag());
        $set = [
            'inline' => $flags->isInline(),
            'static' => $flags->isStatic(),
            'reference' => $flags->isReference(),
            'default' => $flags->isDefault(),
        ];

        foreach ($set as $name => $value) {
            self::assertSame($name === $expected, $value, $word . ' / ' . $name);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function singleWords(): array
    {
        return [
            'inline' => ['inline', 'inline'],
            'static' => ['static', 'static'],
            'reference' => ['reference', 'reference'],
            'default' => ['default', 'default'],
        ];
    }

    /** @test */
    public function wordsCombineInAnyOrder(): void
    {
        foreach (['inline reference', 'reference inline'] as $params) {
            $flags = ThemeFlags::parse($params, new DiagnosticBag());

            self::assertTrue($flags->isInline(), $params);
            self::assertTrue($flags->isReference(), $params);
            self::assertFalse($flags->isStatic(), $params);
        }

        $all = ThemeFlags::parse('default static inline reference', new DiagnosticBag());

        self::assertTrue($all->isDefault() && $all->isStatic() && $all->isInline() && $all->isReference());
    }

    /**
     * @test
     *
     * @dataProvider spacings
     */
    public function anyWhitespaceSeparatesWords(string $params): void
    {
        $bag = new DiagnosticBag();
        $flags = ThemeFlags::parse($params, $bag);

        self::assertTrue($flags->isInline());
        self::assertTrue($flags->isStatic());
        self::assertTrue($bag->isEmpty());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function spacings(): array
    {
        return [
            'one space' => ['inline static'],
            'many spaces' => ['inline      static'],
            'tab' => ["inline\tstatic"],
            'newline' => ["inline\nstatic"],
            'carriage return and form feed' => ["inline\r\n\fstatic"],
            'leading and trailing' => ["  \t inline static \n "],
        ];
    }

    /** @test */
    public function aRepeatedWordIsHarmless(): void
    {
        $bag = new DiagnosticBag();
        $flags = ThemeFlags::parse('static static inline static', $bag);

        self::assertTrue($flags->isStatic());
        self::assertTrue($flags->isInline());
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function anUnknownWordIsReportedByNameAndTheOthersStillApply(): void
    {
        $bag = new DiagnosticBag();
        $flags = ThemeFlags::parse('inline bogus static', $bag);

        self::assertTrue($flags->isInline());
        self::assertTrue($flags->isStatic());
        self::assertCount(1, $bag->all());

        $diagnostic = $bag->all()[0];

        self::assertSame(Diagnostic::E_THEME_INVALID, $diagnostic->code());
        self::assertTrue($diagnostic->isError());
        self::assertStringContainsString('"bogus"', $diagnostic->message());
        self::assertNull($diagnostic->file());
    }

    /**
     * Options are matched exactly, in lower case.
     *
     * @test
     *
     * @dataProvider notOptions
     */
    public function nearMissesAreUnknown(string $word): void
    {
        $bag = new DiagnosticBag();
        $flags = ThemeFlags::parse($word, $bag);

        self::assertCount(1, $bag->all(), $word);
        self::assertFalse($flags->isInline() || $flags->isStatic() || $flags->isReference() || $flags->isDefault());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notOptions(): array
    {
        return [
            'capitalised' => ['Inline'],
            'upper case' => ['STATIC'],
            'plural' => ['references'],
            'prefix' => ['inlin'],
            'with a dash' => ['inline-all'],
            'a bracket' => ['{'],
            'a url' => ['url(x)'],
        ];
    }

    /** @test */
    public function aHugeUnknownWordIsTruncatedInTheMessage(): void
    {
        $bag = new DiagnosticBag();

        ThemeFlags::parse(\str_repeat('x', 5000), $bag);

        self::assertLessThan(200, \strlen($bag->all()[0]->message()));
    }

    /** @test */
    public function eachUnknownWordGetsItsOwnDiagnostic(): void
    {
        $bag = new DiagnosticBag();

        ThemeFlags::parse('one two three', $bag);

        self::assertCount(3, $bag->all());
    }

    /** @test */
    public function theClassIsFinalAndImmutable(): void
    {
        $reflection = new ReflectionClass(ThemeFlags::class);

        self::assertTrue($reflection->isFinal());

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringStartsNotWith('set', $method->getName(), 'A flag object has no setters.');
        }
    }
}
