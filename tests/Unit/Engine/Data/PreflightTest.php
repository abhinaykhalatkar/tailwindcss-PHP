<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Data\Preflight;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The generated preflight (decision ENG-A5, review R-0001 finding 4).
 *
 * Like the theme test, this is the independent half: the selector list below
 * was written by hand from Tailwind v4's documented preflight, not read back out
 * of the generated file. (v4's preflight has no `body` rule — the old v3 one
 * folded `margin: 0` into the universal rule — so none is expected here.)
 *
 * It also proves the property the assembler relies on: `ast()` is exactly the
 * parsed form of `css()`, so splicing it never needs a second tokenising pass.
 */
final class PreflightTest extends TestCase
{
    /** @test */
    public function itIsAFinalNonInstantiableDataClass(): void
    {
        $reflection = new ReflectionClass(Preflight::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame('4.1.18', Preflight::VERSION);
    }

    /** @test */
    public function cssIsLfOnlyEndsWithOneNewlineAndCarriesNoBanner(): void
    {
        $css = Preflight::css();

        self::assertSame(0, \substr_count($css, "\r"));
        self::assertSame("}\n", \substr($css, -2));
        self::assertStringNotContainsString('tailwindcss v', $css);
        self::assertStringNotContainsString('@import', $css);
    }

    /** @test */
    public function cssContainsTheCornerstonesOfPreflight(): void
    {
        $css = Preflight::css();

        self::assertStringContainsString('box-sizing: border-box', $css);
        self::assertStringContainsString('::before', $css);
        self::assertStringContainsString('::after', $css);
        self::assertStringContainsString('border: 0 solid', $css);
        self::assertStringContainsString('line-height: 1.5', $css);
        self::assertStringContainsString('tab-size: 4', $css);
    }

    /**
     * The hand-written selector list: at least ten, here more.
     *
     * @test
     *
     * @dataProvider expectedSelectors
     */
    public function theDocumentedSelectorsAreTopLevelRules(string $selector): void
    {
        self::assertContains($selector, $this->topLevelSelectors());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function expectedSelectors(): array
    {
        $selectors = [
            '*, ::after, ::before, ::backdrop, ::file-selector-button',
            'html, :host',
            'hr',
            'abbr:where([title])',
            'h1, h2, h3, h4, h5, h6',
            'a',
            'b, strong',
            'code, kbd, samp, pre',
            'small',
            'sub, sup',
            'table',
            ':-moz-focusring',
            'progress',
            'summary',
            'ol, ul, menu',
            'img, svg, video, canvas, audio, iframe, embed, object',
            'img, video',
            'button, input, select, optgroup, textarea, ::file-selector-button',
            '::placeholder',
            'textarea',
            '[hidden]:where(:not([hidden=\'until-found\']))',
        ];
        $cases = [];

        foreach ($selectors as $selector) {
            $cases[$selector] = [$selector];
        }

        return $cases;
    }

    /** @test */
    public function selectedDeclarationsAreWhatTheDocumentationSays(): void
    {
        $rules = $this->rulesBySelector();

        $universal = $rules['*, ::after, ::before, ::backdrop, ::file-selector-button'];

        self::assertSame('border-box', $this->declaration($universal, 'box-sizing'));
        self::assertSame('0', $this->declaration($universal, 'margin'));
        self::assertSame('1.5', $this->declaration($rules['html, :host'], 'line-height'));
        self::assertSame('4', $this->declaration($rules['html, :host'], 'tab-size'));
        self::assertSame('100%', $this->declaration($rules['img, video'], 'max-width'));
        self::assertSame('auto', $this->declaration($rules['img, video'], 'height'));
        $replaced = $rules['img, svg, video, canvas, audio, iframe, embed, object'];

        self::assertSame('block', $this->declaration($replaced, 'display'));
    }

    /** @test */
    public function theHiddenAttributeRuleIsImportant(): void
    {
        $rule = $this->rulesBySelector()['[hidden]:where(:not([hidden=\'until-found\']))'];

        $declaration = $rule->nodes[0];

        self::assertInstanceOf(Declaration::class, $declaration);
        self::assertSame('display', $declaration->property);
        self::assertSame('none', $declaration->value);
        self::assertTrue($declaration->important);
    }

    /** @test */
    public function theWebkitPlaceholderBlockIsGuardedBySupports(): void
    {
        $supports = null;

        foreach ($this->parse()->nodes as $node) {
            if ($node instanceof AtRule && $node->name === 'supports') {
                $supports = $node;
            }
        }

        self::assertNotNull($supports);
        self::assertNotNull($supports->nodes);
        self::assertStringContainsString('-apple-pay-button', $supports->params);
    }

    /** @test */
    public function thereAreNoPositionsOrObjectsInTheAst(): void
    {
        $this->assertPlain(Preflight::ast());
    }

    /**
     * The converter below is written here on purpose, independent of the
     * importer's own, so the two cannot share a bug.
     *
     * @test
     */
    public function astIsExactlyTheParsedFormOfCss(): void
    {
        $expected = [];

        foreach ($this->parse()->nodes as $node) {
            $expected[] = self::toArray($node);
        }

        self::assertSame($expected, Preflight::ast());
    }

    /** @test */
    public function astHasOneTopLevelNodePerRuleInTheCss(): void
    {
        self::assertCount(\count($this->parse()->nodes), Preflight::ast());
        self::assertGreaterThanOrEqual(30, \count(Preflight::ast()));
    }

    // ---------------------------------------------------------------- helpers

    private function parse(): \TailwindPhp\Engine\Css\Node\StyleSheet
    {
        $diagnostics = new DiagnosticBag();
        $sheet = Parser::parse(Preflight::css(), $diagnostics);

        self::assertTrue($diagnostics->isEmpty(), 'Preflight::css() does not parse cleanly.');

        return $sheet;
    }

    /**
     * @return list<string>
     */
    private function topLevelSelectors(): array
    {
        return \array_keys($this->rulesBySelector());
    }

    /**
     * @return array<string, Rule>
     */
    private function rulesBySelector(): array
    {
        $rules = [];

        foreach ($this->parse()->nodes as $node) {
            if ($node instanceof Rule) {
                $rules[$node->selector] = $node;
            }
        }

        return $rules;
    }

    private function declaration(Rule $rule, string $property): ?string
    {
        foreach ($rule->nodes as $node) {
            if ($node instanceof Declaration && $node->property === $property) {
                return $node->value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Node $node): array
    {
        if ($node instanceof Declaration) {
            return [
                'type' => 'decl',
                'property' => $node->property,
                'value' => $node->value,
                'important' => $node->important,
            ];
        }

        if ($node instanceof Rule) {
            return [
                'type' => 'rule',
                'selector' => $node->selector,
                'nodes' => self::children($node->nodes),
            ];
        }

        if ($node instanceof AtRule) {
            return [
                'type' => 'at-rule',
                'name' => $node->name,
                'params' => $node->params,
                'nodes' => $node->nodes === null ? null : self::children($node->nodes),
            ];
        }

        if ($node instanceof Comment) {
            return ['type' => 'comment', 'text' => $node->text];
        }

        self::fail('Unexpected node type ' . \get_class($node));
    }

    /**
     * @param array<array-key, Node> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function children(array $nodes): array
    {
        $children = [];

        foreach ($nodes as $node) {
            $children[] = self::toArray($node);
        }

        return $children;
    }

    /**
     * @param mixed $value
     */
    private function assertPlain($value): void
    {
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertNotSame('line', $key);
                self::assertNotSame('col', $key);
                $this->assertPlain($item);
            }

            return;
        }

        self::assertTrue($value === null || \is_scalar($value), 'The AST holds a non-scalar value.');
    }
}
