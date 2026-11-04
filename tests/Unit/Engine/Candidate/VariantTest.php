<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Parser;
use TailwindPhp\Engine\Candidate\Variant;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * Variant shapes (design doc 07 grammar, `variant :=`): every field of
 * {@see Variant} for each form, as the variant applier (TASK-0031) will see it.
 */
final class VariantTest extends TestCase
{
    /**
     * $expected holds name, value, arbitrary and the modifier's value.
     *
     * @test
     *
     * @dataProvider shapes
     *
     * @param array{0: string, 1: string|null, 2: string|null, 3: string|null} $expected
     */
    public function eachVariantShapeFillsItsFields(string $variant, array $expected): void
    {
        $parser = new Parser(StaticRootSet::forTests(), null, new StaticRootSet(['hover'], ['group', 'min']));
        $candidates = $parser->parse($variant . ':flex');

        self::assertCount(1, $candidates);
        self::assertCount(1, $candidates[0]->variants);

        $parsed = $candidates[0]->variants[0];

        $modifier = $parsed->modifier === null ? null : $parsed->modifier->value;

        self::assertSame($variant, $parsed->raw);
        self::assertSame($expected, [$parsed->name, $parsed->value, $parsed->arbitrary, $modifier]);
    }

    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: string|null, 2: string|null, 3: string|null}}>
     */
    public static function shapes(): array
    {
        return [
            'name' => ['hover', ['hover', null, null, null]],
            'name-value' => ['group-hover', ['group', 'hover', null, null]],
            'name-value with a modifier' => ['group-hover/card', ['group', 'hover', null, 'card']],
            'name-[arbitrary]' => ['min-[600px]', ['min', null, '600px', null]],
            'name-[arbitrary] with underscores' => ['has-[>_img]', ['has', null, '> img', null]],
            'name-[arbitrary] with a modifier' => ['group-[.is-open]/item', ['group', null, '.is-open', 'item']],
            '[selector]' => ['[&>*]', ['', null, '&>*', null]],
            '[relative selector]' => ['[>p]', ['', null, '>p', null]],
            '[at-rule]' => ['[@supports(display:grid)]', ['', null, '@supports(display:grid)', null]],
            'name-(--var)' => ['supports-(--x)', ['supports', null, 'var(--x)', null]],
            'unknown name kept whole' => ['print-only', ['print-only', null, null, null]],
            '@container style' => ['@md', ['@md', null, null, null]],
            'star' => ['*', ['*', null, null, null]],
        ];
    }

    /** @test */
    public function variantModifiersAreFullModifiers(): void
    {
        $parser = new Parser(StaticRootSet::forTests());
        $candidates = $parser->parse('group-hover/[x]:peer-checked/(--n):flex');

        self::assertCount(1, $candidates);
        $first = $candidates[0]->variants[0]->modifier;
        $second = $candidates[0]->variants[1]->modifier;
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame([Modifier::ARBITRARY, 'x'], [$first->kind, $first->value]);
        self::assertSame([Modifier::VAR, '--n'], [$second->kind, $second->value]);
    }
}
