<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Conformance\Support;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Conformance\Support\Inventory;

/**
 * The independent T1 inventory (decision ENG-A12).
 *
 * It exists so that the 98 % gate has a denominator that does not come from the
 * implementation, which makes it worthless unless it is itself guarded: the
 * counts below are literals on purpose. Deleting a root from
 * `tests/Conformance/inventory/t1.php` — or adding one — fails here until the
 * number is changed by hand in the same pull request, next to the doc 08 row that
 * justifies it.
 */
final class InventoryTest extends TestCase
{
    /**
     * The per-family counts, from design doc 08 §Utilities (T1 rows only).
     *
     * layout                            doc 08 §Layout, plus `flex` and `container` (doc 08 §Corrections from M2)
     * flexbox-grid                      doc 08 §Flexbox & Grid
     * spacing-sizing                    doc 08 §Spacing & Sizing
     * typography                        doc 08 §Typography
     * backgrounds-borders               doc 08 §Backgrounds & Borders, T1 gradients only
     * effects-filters                   doc 08 last table: shadow, opacity, blend modes
     * tables-transitions-transforms     doc 08 last table: tables, transition, animate, transforms
     * interactivity-svg-accessibility   doc 08 last table: cursor ... touch-*, fill, stroke
     * arbitrary-property                doc 08 §Arbitrary property `[prop:value]`
     */
    private const COUNTS = [
        'layout' => 60,
        'flexbox-grid' => 36,
        'spacing-sizing' => 29,
        'typography' => 46,
        'backgrounds-borders' => 42,
        'effects-filters' => 5,
        'tables-transitions-transforms' => 25,
        'interactivity-svg-accessibility' => 49,
        'arbitrary-property' => 1,
    ];

    private const TOTAL = 293;

    private const KEYS = [
        'family', 'kind', 'theme', 'bare', 'fraction', 'negative', 'arbitrary', 'modifier', 'tier', 'forms',
    ];

    /**
     * @var Inventory
     */
    private $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new Inventory();
    }

    // ------------------------------------------------------------ the counts

    /** @test */
    public function theFamiliesHaveTheDocumentedCounts(): void
    {
        self::assertSame(self::COUNTS, $this->inventory->countsByFamily());
    }

    /** @test */
    public function theTotalIsPinned(): void
    {
        self::assertSame(self::TOTAL, \count($this->inventory->roots()));
        self::assertSame(self::TOTAL, \array_sum(self::COUNTS));
    }

    /** @test */
    public function familiesListTheirRootsAndNothingIsLost(): void
    {
        $families = $this->inventory->families();

        self::assertSame(\array_keys(self::COUNTS), \array_keys($families));

        $all = [];

        foreach ($families as $family => $roots) {
            self::assertCount(self::COUNTS[$family], $roots, $family);

            foreach ($roots as $root) {
                $all[] = $root;
            }
        }

        $roots = $this->inventory->roots();
        \sort($all);
        \sort($roots);

        self::assertSame($roots, $all);
    }

    /**
     * An array literal silently keeps the last of two equal keys, so uniqueness
     * is checked on the source, not on the loaded array.
     *
     * @test
     */
    public function everyRootAppearsExactlyOnceInTheSource(): void
    {
        $tokens = \token_get_all((string) \file_get_contents(Inventory::defaultFile()));
        $depth = 0;
        $keys = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token)) {
                if ($token === '[') {
                    ++$depth;
                } elseif ($token === ']') {
                    --$depth;
                }

                continue;
            }

            // A root is a string literal at depth 1 followed by `=>`.
            $isKey = $token[0] === \T_CONSTANT_ENCAPSED_STRING
                && $depth === 1
                && self::nextSignificant($tokens, $index) === \T_DOUBLE_ARROW;

            if ($isKey) {
                $keys[] = \trim($token[1], "'");
            }
        }

        self::assertSame(self::TOTAL, \count($keys));
        self::assertSame($keys, \array_values(\array_unique($keys)), 'A root is listed twice.');
        self::assertSame($keys, $this->inventory->roots());
    }

    // ------------------------------------------------------------ the schema

    /** @test */
    public function everyEntryHasExactlyTheDocumentedKeys(): void
    {
        foreach ($this->inventory->roots() as $root) {
            $keys = \array_keys($this->inventory->entry($root));
            \sort($keys);
            $expected = self::KEYS;
            \sort($expected);

            self::assertSame($expected, $keys, $root);
        }
    }

    /** @test */
    public function everyFamilyIsOneOfTheFixedSlugs(): void
    {
        foreach ($this->inventory->roots() as $root) {
            self::assertContains($this->inventory->family($root), Inventory::FAMILIES, $root);
            self::assertSame($this->inventory->entry($root)['family'], $this->inventory->family($root));
        }
    }

    /** @test */
    public function everyEntryUsesTheClosedVocabularies(): void
    {
        foreach ($this->inventory->roots() as $root) {
            $entry = $this->inventory->entry($root);

            self::assertSame('T1', $entry['tier'], $root);
            self::assertContains($entry['kind'], ['static', 'functional'], $root);
            self::assertTrue($entry['bare'] === null || \in_array($entry['bare'], Inventory::BARE_KINDS, true), $root);
            self::assertTrue(
                $entry['modifier'] === null || \in_array($entry['modifier'], ['opacity', 'line-height'], true),
                $root
            );
            self::assertIsBool($entry['fraction'], $root);
            self::assertIsBool($entry['negative'], $root);
            self::assertIsBool($entry['arbitrary'], $root);
            self::assertIsArray($entry['theme'], $root);

            foreach ($entry['theme'] as $namespace) {
                self::assertSame('--', \substr($namespace, 0, 2), $root . ' theme namespace');
            }
        }
    }

    /**
     * `forms` must say exactly what the other flags say. A form that is claimed
     * without its flag would ask the engine for something doc 08 never promised;
     * a flag without its form would let the engine skip it.
     *
     * @test
     */
    public function formsAgreeWithTheFlagsInBothDirections(): void
    {
        foreach ($this->inventory->roots() as $root) {
            $entry = $this->inventory->entry($root);
            $forms = $this->inventory->expectedForms($root);

            self::assertSame($forms, \array_values(\array_unique($forms)), $root . ' repeats a form');
            self::assertSame([], \array_diff($forms, Inventory::FORMS), $root . ' uses an unknown form');
            self::assertContains('important', $forms, $root);

            // Canonical order, so a diff of the file reads the same everywhere.
            $ordered = \array_values(\array_intersect(Inventory::FORMS, $forms));
            self::assertSame($ordered, $forms, $root . ' lists its forms out of order');

            if ($entry['kind'] === 'static') {
                self::assertSame(['static', 'important'], $forms, $root);
                self::assertSame([], $entry['theme'], $root);
                self::assertNull($entry['bare'], $root);
                self::assertNull($entry['modifier'], $root);
                self::assertFalse($entry['fraction'] || $entry['negative'] || $entry['arbitrary'], $root);

                continue;
            }

            self::assertSame($entry['theme'] !== [], \in_array('theme', $forms, true), $root . ' theme');
            self::assertSame($entry['bare'] !== null, \in_array('bare', $forms, true), $root . ' bare');
            self::assertSame($entry['fraction'], \in_array('fraction', $forms, true), $root . ' fraction');
            self::assertSame($entry['negative'], \in_array('negative', $forms, true), $root . ' negative');
            self::assertSame($entry['arbitrary'], \in_array('arbitrary', $forms, true), $root . ' arbitrary');
            self::assertSame($entry['modifier'] !== null, \in_array('modifier', $forms, true), $root . ' modifier');

            // `(--var)` accompanies `[...]` everywhere except the arbitrary property.
            self::assertSame(
                $entry['arbitrary'] && $root !== '[property:value]',
                \in_array('var', $forms, true),
                $root . ' var'
            );

            // A functional root that is not valid on its own has nothing to say with `static`.
            if (\in_array('static', $forms, true)) {
                self::assertSame('functional', $entry['kind'], $root);
            }
        }
    }

    // ----------------------------------------------- spot checks by hand

    /**
     * A handful of rows re-derived from doc 08 independently of the file, so a
     * wrong flag cannot hide behind a consistent-looking `forms`.
     *
     * @test
     *
     * @dataProvider spotChecks
     *
     * @param array<string, mixed> $expected
     */
    public function spotChecksAgainstDoc08(string $root, array $expected): void
    {
        $entry = $this->inventory->entry($root);

        foreach ($expected as $key => $value) {
            self::assertSame($value, $entry[$key], $root . ' ' . $key);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function spotChecks(): array
    {
        return [
            'a plain static' => ['hidden', ['kind' => 'static', 'family' => 'layout']],
            'accessibility static' => ['sr-only', ['kind' => 'static', 'family' => 'layout']],
            'container is a layout static the CLI accepts although doc 08 had no row' => [
                'container',
                [
                    'kind' => 'static',
                    'family' => 'layout',
                    'theme' => [],
                    'bare' => null,
                    'fraction' => false,
                    'negative' => false,
                    'arbitrary' => false,
                    'modifier' => null,
                    'tier' => 'T1',
                    'forms' => ['static', 'important'],
                ],
            ],
            'flex is layout, functional and valid alone' => [
                'flex',
                ['kind' => 'functional', 'family' => 'layout', 'fraction' => true, 'bare' => 'integer'],
            ],
            'spacing multiplier, no negative' => [
                'p',
                [
                    'bare' => 'spacing-multiplier',
                    'negative' => false,
                    'fraction' => false,
                    'family' => 'spacing-sizing',
                ],
            ],
            'margin allows negatives' => ['m', ['negative' => true]],
            'width takes a fraction and a container name' => [
                'w',
                ['fraction' => true, 'theme' => ['--container-*'], 'family' => 'spacing-sizing'],
            ],
            'inset is layout and negative' => ['inset', ['negative' => true, 'fraction' => true, 'family' => 'layout']],
            'z is an integer with negatives' => ['z', ['bare' => 'integer', 'negative' => true]],
            'break-after is keyword only' => ['break-after', ['bare' => 'keyword', 'arbitrary' => false]],
            'justify has no arbitrary values' => ['justify', ['arbitrary' => false, 'family' => 'flexbox-grid']],
            'content is flexbox-grid, first mentioned there' => [
                'content',
                ['family' => 'flexbox-grid', 'arbitrary' => true],
            ],
            'bg takes an opacity modifier' => [
                'bg',
                ['modifier' => 'opacity', 'theme' => ['--color-*'], 'family' => 'backgrounds-borders'],
            ],
            'text carries the line-height modifier' => ['text', ['modifier' => 'line-height']],
            'rounded is valid alone' => ['rounded', ['theme' => ['--radius-*']]],
            'opacity is a percentage' => ['opacity', ['bare' => 'percentage', 'family' => 'effects-filters']],
            'rotate is an angle' => ['rotate', ['bare' => 'angle', 'negative' => true]],
            'duration is a duration' => ['duration', ['bare' => 'duration']],
            'line-clamp counts lines' => ['line-clamp', ['bare' => 'line-count']],
            'scroll margin allows negatives, padding does not' => ['scroll-m', ['negative' => true]],
            'scroll padding has none' => ['scroll-p', ['negative' => false]],
            'col-span has no negative' => ['col-span', ['negative' => false]],
            'cursor is interactivity' => ['cursor', ['family' => 'interactivity-svg-accessibility']],
            'the arbitrary property' => [
                '[property:value]',
                ['family' => 'arbitrary-property', 'arbitrary' => true],
            ],
        ];
    }

    /**
     * T2 and T3 rows are deliberately absent: the 98 % gate is about T1.
     *
     * @test
     *
     * @dataProvider notT1
     */
    public function rootsOutsideTierOneAreNotListed(string $root): void
    {
        self::assertNotContains($root, $this->inventory->roots());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notT1(): array
    {
        $roots = [
            'text-shadow', 'mask', 'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate',
            'invert', 'saturate', 'sepia', 'backdrop', 'rotate-x', 'rotate-y', 'rotate-z', 'scale-3d', 'skew',
            'skew-x', 'skew-y', 'perspective', 'perspective-origin', 'backface', 'transform-3d', 'transform-flat',
            'accent', 'caret', 'color-scheme', 'field-sizing', 'will-change', 'forced-color-adjust', 'bg-radial',
            'bg-conic', 'bg-linear',
        ];
        $cases = [];

        foreach ($roots as $root) {
            $cases[$root] = [$root];
        }

        return $cases;
    }

    /** @test */
    public function anUnknownRootIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->inventory->entry('no-such-root');
    }

    // -------------------------------------------- the candidate root scanner

    /**
     * @test
     *
     * @dataProvider candidates
     */
    public function theScannerResolvesACandidateToAnInventoryRoot(string $candidate, bool $expected): void
    {
        self::assertSame($expected, $this->inventory->assertCandidateUsesKnownRoot($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function candidates(): array
    {
        return [
            'a plain value' => ['p-4', true],
            'a static' => ['flex', true],
            'a variant' => ['hover:p-4', true],
            'stacked variants' => ['sm:max-md:hover:underline', true],
            'important prefix' => ['!p-4', true],
            'important suffix' => ['p-4!', true],
            'negative' => ['-mt-4', true],
            'a fraction' => ['w-1/2', true],
            'an opacity modifier' => ['bg-red-500/50', true],
            'a modifier on a static root' => ['flex/50', true],
            'a bracketed modifier' => ['bg-red-500/[0.33]', true],
            'a slash inside a url belongs to the value' => ['bg-[url(/a/b.png)]', true],
            'a modifier cannot make an unknown root known' => ['zzz/50', false],
            'only a modifier' => ['/50', false],
            'a bracketed variant' => ['[&>*]:flex', true],
            'a colon inside brackets belongs to the value' => ["content-['a:b']", true],
            'an arbitrary property' => ['[mask-type:luminance]', true],
            'a custom property' => ['[--my-var:1rem]', true],
            'an arbitrary property under a variant' => ['md:[mask-type:luminance]', true],
            'a root with nothing after it' => ['bg', true],
            'a longer root wins over a shorter one' => ['border-spacing-x-4', true],
            'a wrong value still resolves to its root' => ['p-foo', true],
            'a trailing dash' => ['p-', true],
            'a very long candidate' => ['p-' . \str_repeat('4', 64), true],
            'an unknown root' => ['zzz-4', false],
            'a prefix that is not a root boundary' => ['pt4', false],
            'a word that merely starts with a root' => ['flexible', false],
            'an empty candidate' => ['', false],
            'only a variant' => ['md:', false],
            'an empty leading variant' => [':p-4', false],
            'only a bang' => ['!', false],
            'an unterminated arbitrary property' => ['[unterminated', false],
            'a bracket with no property' => ['[1px]', false],
        ];
    }

    // ------------------------------------------------------ the real corpus

    /**
     * A typo in a hand-written corpus line would silently become a candidate
     * that no utility can ever match. Every line of the utilities corpus must
     * name a root the inventory knows. `edge-rejections.txt` is exempt: it holds
     * malformed candidates on purpose (`md:`, `:p-4`, ...), so most of its lines
     * are not expected to resolve.
     *
     * @test
     */
    public function everyUtilitiesCorpusCandidateNamesAKnownRoot(): void
    {
        $paths = new CorpusPaths();
        $checked = 0;
        $unknown = [];

        foreach ($paths->select('utilities') as $key) {
            if ($key === 'utilities/edge-rejections') {
                continue;
            }

            $candidates = CorpusPaths::parseCandidates((string) \file_get_contents($paths->sourcePath($key)));

            self::assertNotSame([], $candidates, $key . ' is empty.');

            foreach ($candidates as $candidate) {
                ++$checked;

                if (!$this->inventory->assertCandidateUsesKnownRoot($candidate)) {
                    $unknown[] = $key . ': ' . $candidate;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'The utilities corpus is empty.');
        self::assertSame([], $unknown, 'Corpus candidates that name no inventory root.');
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if ($token[0] === \T_WHITESPACE || $token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }

                return $token[0];
            }

            return null;
        }

        return null;
    }
}
