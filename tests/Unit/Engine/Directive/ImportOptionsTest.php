<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Directive\ImportOptions;

/**
 * The `@import` prelude: a specifier then Tailwind's options, read with the
 * CSS tokenizer (no regular expression, T-12).
 */
final class ImportOptionsTest extends TestCase
{
    /** @test */
    public function everyOptionIsRead(): void
    {
        $options = ImportOptions::parse(
            '"tailwindcss" layer(x) source("./src") prefix(tw) important theme(static inline)'
        );

        self::assertNotNull($options);
        self::assertSame('tailwindcss', $options->spec);
        self::assertSame('all', $options->bundle());
        self::assertSame('x', $options->layer);
        self::assertSame('./src', $options->source);
        self::assertSame('tw', $options->prefix);
        self::assertTrue($options->important);
        self::assertSame(['static', 'inline'], $options->theme);
    }

    /**
     * @test
     *
     * @dataProvider specifiers
     */
    public function theSpecifierMayBeAStringOrAUrl(string $params, string $spec): void
    {
        $options = ImportOptions::parse($params);

        self::assertNotNull($options);
        self::assertSame($spec, $options->spec);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function specifiers(): array
    {
        return [
            'double quotes' => ['"./a.css"', './a.css'],
            'single quotes' => ["'./a.css'", './a.css'],
            'url' => ['url(./a.css)', './a.css'],
            'url with a string' => ['url("./a.css") layer(x)', './a.css'],
        ];
    }

    /** @test */
    public function bundlesAreRecognised(): void
    {
        self::assertSame('theme', $this->bundle('"tailwindcss/theme.css"'));
        self::assertSame('preflight', $this->bundle('"tailwindcss/preflight"'));
        self::assertSame('utilities', $this->bundle('"tailwindcss/utilities.css"'));
        self::assertNull($this->bundle('"./tailwindcss.css"'));
    }

    /** @test */
    public function sourceNoneAndAnEmptyLayerKeyword(): void
    {
        $options = ImportOptions::parse('"tailwindcss" source(none) layer');

        self::assertNotNull($options);
        self::assertSame('none', $options->source);
        self::assertSame('', $options->layer);
    }

    /**
     * @test
     *
     * @dataProvider refused
     */
    public function malformedPreludesAreRefused(string $params): void
    {
        $problem = null;

        self::assertNull(ImportOptions::parse($params, $problem));
        self::assertNotNull($problem);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refused(): array
    {
        return [
            'no specifier' => ['layer(x)'],
            'empty' => [''],
            'unknown option' => ['"a.css" media(print)'],
            'supports' => ['"a.css" supports(display: grid)'],
            'stray word' => ['"a.css" screen'],
            'unclosed option' => ['"a.css" layer(x'],
            'layer without a name' => ['"a.css" layer()'],
            'upper-case prefix' => ['"tailwindcss" prefix(TW)'],
            'unknown theme flag' => ['"tailwindcss" theme(loud)'],
            'empty source' => ['"tailwindcss" source("")'],
        ];
    }

    private function bundle(string $params): ?string
    {
        $options = ImportOptions::parse($params);

        self::assertNotNull($options);

        return $options->bundle();
    }
}
