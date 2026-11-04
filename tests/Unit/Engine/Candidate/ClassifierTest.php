<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Value\Classifier;

/**
 * Data-type inference (v4 `inferDataType`): one row per type with v4-style
 * examples, then the near misses.
 */
final class ClassifierTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider passing
     */
    public function valuesPassTheirType(string $type, string $value): void
    {
        self::assertTrue(Classifier::is($type, $value), $value . ' should be ' . $type);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function passing(): array
    {
        return [
            'color hex' => ['color', '#0088cc'],
            'color short hex' => ['color', '#fff'],
            'color rgb' => ['color', 'rgb(0 0 0 / 50%)'],
            'color rgba' => ['color', 'rgba(0,0,0,0.5)'],
            'color hsl' => ['color', 'hsl(200 50% 50%)'],
            'color oklch' => ['color', 'oklch(63.7% 0.237 25.331)'],
            'color function any case' => ['color', 'RGB(0 0 0)'],
            'color light-dark' => ['color', 'light-dark(white, black)'],
            'color color-mix' => ['color', 'color-mix(in oklab, red 50%, blue)'],
            'color named' => ['color', 'rebeccapurple'],
            'color named any case' => ['color', 'Red'],
            'color currentcolor' => ['color', 'currentColor'],
            'color transparent' => ['color', 'transparent'],
            'color system' => ['color', 'canvastext'],
            'length px' => ['length', '32px'],
            'length rem decimal' => ['length', '1.5rem'],
            'length negative' => ['length', '-2em'],
            'length container unit' => ['length', '10cqw'],
            'length quarter-millimetre' => ['length', '4Q'],
            'length calc' => ['length', 'calc(100% - 1rem)'],
            'percentage' => ['percentage', '50%'],
            'percentage decimal' => ['percentage', '.5%'],
            'percentage math' => ['percentage', 'min(10%, 2rem)'],
            'ratio' => ['ratio', '16/9'],
            'ratio with spaces' => ['ratio', '16 / 9'],
            'ratio decimals' => ['ratio', '1.5/1'],
            'number integer' => ['number', '3'],
            'number decimal' => ['number', '0.5'],
            'number leading dot' => ['number', '.5'],
            'number signed exponent' => ['number', '-2e3'],
            'number math' => ['number', 'calc(1 + 2)'],
            'integer' => ['integer', '12'],
            'integer zero' => ['integer', '0'],
            'url' => ['url', 'url(/a.png)'],
            'url quoted' => ['url', 'url("a b.png")'],
            'position keyword' => ['position', 'center'],
            'position two keywords' => ['position', 'center top'],
            'position lengths' => ['position', '10px 20%'],
            'position with var' => ['position', 'var(--x) top'],
            'bg-size cover' => ['bg-size', 'cover'],
            'bg-size auto and length' => ['bg-size', 'auto 100%'],
            'bg-size list' => ['bg-size', '10px,contain'],
            'line-width' => ['line-width', 'thick'],
            'image url' => ['image', 'url(a.png)'],
            'image gradient' => ['image', 'linear-gradient(red, blue)'],
            'image repeating gradient' => ['image', 'repeating-conic-gradient(red, blue)'],
            'image image-set' => ['image', 'image-set("a.png" 1x)'],
            'image list' => ['image', 'url(a.png),linear-gradient(red,blue)'],
            'family-name' => ['family-name', 'Inter'],
            'family-name list' => ['family-name', '"Noto Sans",sans-serif'],
            'generic-name' => ['generic-name', 'ui-monospace'],
            'absolute-size' => ['absolute-size', 'xx-large'],
            'relative-size' => ['relative-size', 'smaller'],
            'angle deg' => ['angle', '45deg'],
            'angle turn' => ['angle', '0.25turn'],
            'angle negative rad' => ['angle', '-1.5rad'],
            'vector' => ['vector', '1 0 0'],
            'vector decimals' => ['vector', '0.5 -1 2'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider failing
     */
    public function nearMissesFailTheirType(string $type, string $value): void
    {
        self::assertFalse(Classifier::is($type, $value), $value . ' should not be ' . $type);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failing(): array
    {
        return [
            'color unknown word' => ['color', 'reddish'],
            'color empty' => ['color', ''],
            'length unitless' => ['length', '32'],
            'length unknown unit' => ['length', '32pxx'],
            'length wrong case unit' => ['length', '32PX'],
            'length percentage' => ['length', '50%'],
            'percentage without sign' => ['percentage', '50'],
            'ratio single number' => ['ratio', '16'],
            'ratio missing denominator' => ['ratio', '16/'],
            'number with unit' => ['number', '1px'],
            'number trailing dot' => ['number', '1.'],
            'number lone sign' => ['number', '-'],
            'integer leading zero' => ['integer', '01'],
            'integer negative' => ['integer', '-1'],
            'integer decimal' => ['integer', '1.0'],
            'integer exponent' => ['integer', '1e3'],
            'url without url()' => ['url', '/a.png'],
            'position unknown word' => ['position', 'middle'],
            'position var only' => ['position', 'var(--x)'],
            'bg-size three values' => ['bg-size', '1px 2px 3px'],
            'line-width other' => ['line-width', 'thin-ish'],
            'image plain word' => ['image', 'none'],
            'image leading space part' => ['image', 'url(a), url(b)'],
            'family-name starting with a digit' => ['family-name', '3d'],
            'generic-name family' => ['generic-name', 'Inter'],
            'absolute-size relative' => ['absolute-size', 'larger'],
            'angle unitless' => ['angle', '45'],
            'vector two numbers' => ['vector', '1 0'],
            'vector four numbers' => ['vector', '1 0 0 1'],
            'unknown type' => ['nonsense', 'anything'],
        ];
    }

    /** @test */
    public function inferTriesTypesInTheGivenOrder(): void
    {
        self::assertSame('length', Classifier::infer('10px', ['color', 'length', 'percentage']));
        self::assertSame('percentage', Classifier::infer('10%', ['length', 'percentage']));
        self::assertSame('position', Classifier::infer('center', ['position', 'family-name']));
        self::assertSame('family-name', Classifier::infer('center', ['family-name', 'position']));
        self::assertNull(Classifier::infer('10px', ['color', 'url']));
    }

    /** @test */
    public function varValuesAreNeverInferred(): void
    {
        self::assertNull(Classifier::infer('var(--x)', Classifier::TYPES));
        self::assertSame('any', Classifier::classify('var(--brand)'));
    }

    /**
     * @test
     *
     * @dataProvider classified
     */
    public function classifyReturnsTheFirstTypeInCanonicalOrder(string $value, string $expected): void
    {
        self::assertSame($expected, Classifier::classify($value));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function classified(): array
    {
        return [
            'hex' => ['#fff', 'color'],
            'named colour' => ['red', 'color'],
            'length' => ['2rem', 'length'],
            'calc is a length first' => ['calc(1px + 2px)', 'length'],
            'percentage' => ['50%', 'percentage'],
            'ratio' => ['16/9', 'ratio'],
            'number' => ['1.5', 'number'],
            'url' => ['url(a.png)', 'url'],
            'keyword position' => ['top', 'position'],
            'cover' => ['cover', 'bg-size'],
            'gradient' => ['linear-gradient(red, blue)', 'image'],
            'any word is a family name' => ['Inter', 'family-name'],
            'generic family before family-name' => ['sans-serif', 'generic-name'],
            'absolute size before family-name' => ['xx-small', 'absolute-size'],
            'relative size before family-name' => ['larger', 'relative-size'],
            'a css-wide keyword falls through to family-name' => ['inherit', 'family-name'],
            'digit-led word' => ['3d', 'any'],
        ];
    }
}
