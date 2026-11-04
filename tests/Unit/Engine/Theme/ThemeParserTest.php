<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Theme;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeParser;

/**
 * Reading one `@theme` rule (design doc 07 §Phase 2).
 *
 * The rules are built from the AST node classes by hand, because that is what
 * the directive processor hands over; no CSS text is parsed here.
 */
final class ThemeParserTest extends TestCase
{
    /** @test */
    public function customPropertiesBecomeVariablesInSourceOrder(): void
    {
        $result = $this->parse(
            $this->theme('', [
                new Declaration('--color-brand', '#f00'),
                new Declaration('--spacing', '0.25rem'),
                new Declaration('--font-display', '"Satoshi", sans-serif'),
            ]),
            $bag
        );

        self::assertSame(
            ['--color-brand' => '#f00', '--spacing' => '0.25rem', '--font-display' => '"Satoshi", sans-serif'],
            $result['vars']
        );
        self::assertSame(['--color-brand', '--spacing', '--font-display'], \array_keys($result['vars']));
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function valuesAreTrimmedButOtherwiseVerbatim(): void
    {
        $result = $this->parse(
            $this->theme('', [
                new Declaration('--a', "  calc(1 / 3)\n "),
                new Declaration('--b', '<?php echo 1; ?>'),
                new Declaration('--c', 'var(--x, { })'),
            ]),
            $bag
        );

        self::assertSame('calc(1 / 3)', $result['vars']['--a']);
        self::assertSame('<?php echo 1; ?>', $result['vars']['--b']);
        self::assertSame('var(--x, { })', $result['vars']['--c']);
    }

    /** @test */
    public function initialIsKeptAsTheLiteralWordForTheRegistry(): void
    {
        $result = $this->parse(
            $this->theme('', [new Declaration('--color-red-500', 'initial')]),
            $bag
        );

        self::assertSame(['--color-red-500' => 'initial'], $result['vars']);
        self::assertTrue($bag->isEmpty());
    }

    /**
     * @test
     *
     * @dataProvider wildcards
     */
    public function aNamespaceWildcardSetToInitialIsKeptVerbatim(string $name): void
    {
        $result = $this->parse($this->theme('', [new Declaration($name, 'initial')]), $bag);

        self::assertSame([$name => 'initial'], $result['vars']);
        self::assertTrue($bag->isEmpty());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wildcards(): array
    {
        return [
            'colours' => ['--color-*'],
            'fonts' => ['--font-*'],
            'a hyphenated namespace' => ['--drop-shadow-*'],
            'everything' => ['--*'],
        ];
    }

    /** @test */
    public function aWildcardSetToAnythingButInitialIsInvalidAndSkipped(): void
    {
        $result = $this->parse(
            $this->theme('', [
                new Declaration('--color-*', 'red'),
                new Declaration('--spacing', '0.25rem'),
            ]),
            $bag
        );

        self::assertSame(['--spacing' => '0.25rem'], $result['vars']);
        $this->assertOneThemeError($bag, '--color-*');
    }

    /**
     * @test
     *
     * @dataProvider notCustomProperties
     */
    public function anythingButACustomPropertyIsInvalidAndSkipped(string $property): void
    {
        $result = $this->parse(
            $this->theme('', [
                new Declaration($property, 'red'),
                new Declaration('--kept', '1'),
            ]),
            $bag
        );

        self::assertSame(['--kept' => '1'], $result['vars']);
        $this->assertOneThemeError($bag, \substr($property, 0, 40));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notCustomProperties(): array
    {
        return [
            'a real property' => ['color'],
            'a vendor property' => ['-webkit-appearance'],
            'one dash' => ['-color'],
            'just two dashes' => ['--'],
            'an empty name' => [''],
            'a single dash custom' => ['-x'],
        ];
    }

    /** @test */
    public function anImportantVariableIsInvalidAndSkipped(): void
    {
        $result = $this->parse(
            $this->theme('', [new Declaration('--a', '1', true), new Declaration('--b', '2')]),
            $bag
        );

        self::assertSame(['--b' => '2'], $result['vars']);
        $this->assertOneThemeError($bag, '--a');
    }

    /** @test */
    public function aLaterDeclarationOfTheSameVariableWinsWithinTheBlock(): void
    {
        $result = $this->parse(
            $this->theme('', [
                new Declaration('--a', '1'),
                new Declaration('--b', '2'),
                new Declaration('--a', '3'),
            ]),
            $bag
        );

        self::assertSame(['--a' => '3', '--b' => '2'], $result['vars']);
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function anEmptyValueIsALegalCustomPropertyValue(): void
    {
        $result = $this->parse($this->theme('', [new Declaration('--empty', '')]), $bag);

        self::assertSame(['--empty' => ''], $result['vars']);
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function keyframesAreRecordedByNameAsTheSameNode(): void
    {
        $spin = new AtRule('keyframes', 'wiggle', [new Rule('to', [new Declaration('transform', 'rotate(360deg)')])]);
        $ping = new AtRule('keyframes', 'ping', [new Rule('to', [new Declaration('opacity', '0')])]);

        $result = $this->parse($this->theme('', [new Declaration('--a', '1'), $spin, $ping]), $bag);

        self::assertSame(['wiggle', 'ping'], \array_keys($result['keyframes']));
        self::assertSame($spin, $result['keyframes']['wiggle']);
        self::assertSame($ping, $result['keyframes']['ping']);
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function keyframesMatchRegardlessOfCase(): void
    {
        $block = new AtRule('KeyFrames', 'x', [new Rule('to', [])]);

        $result = $this->parse($this->theme('', [$block]), $bag);

        self::assertSame(['x'], \array_keys($result['keyframes']));
    }

    /**
     * @test
     *
     * @dataProvider badKeyframes
     */
    public function aKeyframesBlockNeedsAPlainNameAndABody(AtRule $block): void
    {
        $result = $this->parse($this->theme('', [$block, new Declaration('--a', '1')]), $bag);

        self::assertSame([], $result['keyframes']);
        self::assertSame(['--a' => '1'], $result['vars']);
        self::assertCount(1, $bag->all());
        self::assertSame(Diagnostic::E_THEME_INVALID, $bag->all()[0]->code());
    }

    /**
     * @return array<string, array{0: AtRule}>
     */
    public static function badKeyframes(): array
    {
        return [
            'no name' => [new AtRule('keyframes', '', [])],
            'no body' => [new AtRule('keyframes', 'spin', null)],
            'a quoted name' => [new AtRule('keyframes', '"spin"', [])],
            'a name with a space' => [new AtRule('keyframes', 'a b', [])],
            'a name with a brace' => [new AtRule('keyframes', 'a{', [])],
        ];
    }

    /**
     * @test
     *
     * @dataProvider foreignNodes
     */
    public function anythingElseInsideThemeIsInvalidAndSkipped(Node $node): void
    {
        $result = $this->parse($this->theme('', [$node, new Declaration('--kept', '1')]), $bag);

        self::assertSame(['--kept' => '1'], $result['vars']);
        self::assertSame([], $result['keyframes']);
        self::assertCount(1, $bag->all());
        self::assertSame(Diagnostic::E_THEME_INVALID, $bag->all()[0]->code());
    }

    /**
     * @return array<string, array{0: Node}>
     */
    public static function foreignNodes(): array
    {
        return [
            'a nested rule' => [new Rule('.x', [new Declaration('--a', '1')])],
            'a media query' => [new AtRule('media', 'print', [new Declaration('--a', '1')])],
            'a layer' => [new AtRule('layer', 'base', [])],
            'a nested theme' => [new AtRule('theme', '', [new Declaration('--a', '1')])],
            'an import' => [new AtRule('import', '"x.css"', null)],
        ];
    }

    /** @test */
    public function commentsAreIgnored(): void
    {
        $result = $this->parse(
            $this->theme('', [new Comment(' note '), new Declaration('--a', '1')]),
            $bag
        );

        self::assertSame(['--a' => '1'], $result['vars']);
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function aThemeWithoutABodyIsEmpty(): void
    {
        $result = $this->parse(new AtRule('theme', 'static', null), $bag);

        self::assertSame([], $result['vars']);
        self::assertSame([], $result['keyframes']);
        self::assertTrue($result['flags']->isStatic());
        self::assertTrue($bag->isEmpty());
    }

    /** @test */
    public function theFlagsComeFromThePrelude(): void
    {
        $result = $this->parse($this->theme('reference inline', [new Declaration('--a', '1')]), $bag);

        self::assertTrue($result['flags']->isReference());
        self::assertTrue($result['flags']->isInline());
        self::assertFalse($result['flags']->isStatic());
        self::assertFalse($result['flags']->isDefault());
    }

    /** @test */
    public function anUnknownFlagIsReportedAndTheBlockIsStillRead(): void
    {
        $result = $this->parse($this->theme('inlien static', [new Declaration('--a', '1')]), $bag);

        self::assertSame(['--a' => '1'], $result['vars']);
        self::assertTrue($result['flags']->isStatic());
        $this->assertOneThemeError($bag, 'inlien');
    }

    /** @test */
    public function everyProblemIsReportedAndTheRestOfTheBlockSurvives(): void
    {
        $result = $this->parse(
            $this->theme('bogus', [
                new Declaration('color', 'red', false, 3),
                new Declaration('--ok', '1', false, 4),
                new Rule('.x', [], 5),
                new Declaration('--also-ok', '2', false, 6),
            ]),
            $bag
        );

        self::assertSame(['--ok' => '1', '--also-ok' => '2'], $result['vars']);
        self::assertCount(3, $bag->all());
    }

    /** @test */
    public function diagnosticsCarryTheLineAndNeverAPath(): void
    {
        $this->parse(
            $this->theme('', [new Declaration('color', 'red', false, 7), new Rule('.x', [], 9)]),
            $bag
        );

        $diagnostics = $bag->all();

        self::assertSame(7, $diagnostics[0]->line());
        self::assertSame(9, $diagnostics[1]->line());
        self::assertNull($diagnostics[0]->file());
        self::assertNull($diagnostics[1]->file());
    }

    /** @test */
    public function aHugeNameIsTruncatedInTheMessage(): void
    {
        $this->parse($this->theme('', [new Declaration(\str_repeat('a', 5000), '1')]), $bag);

        self::assertLessThan(300, \strlen($bag->all()[0]->message()));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The resets as written in a stylesheet, through `Css\Parser` (the gap
     * TASK-0050 closed was in the parser, which hand-built nodes hid), and
     * applied by the registry: the namespaces are gone, the redeclared names
     * and the protected sub-namespace stay (`corpus/directives/theme-reset.css`).
     *
     * @test
     */
    public function resetsWrittenInAStylesheetReachTheRegistry(): void
    {
        $bag = new DiagnosticBag();
        $sheet = \TailwindPhp\Engine\Css\Parser::parse(
            "@theme {\n  --color-*: initial;\n  --font-*: initial;\n  --color-brand: #ff5a1f;\n}",
            $bag
        );
        $block = $sheet->nodes[0];

        self::assertInstanceOf(AtRule::class, $block);

        $result = ThemeParser::parse($block, $bag);
        $registry = \TailwindPhp\Engine\Theme\ThemeRegistry::fromDefault(
            \TailwindPhp\Engine\Data\DefaultTheme::vars(),
            \TailwindPhp\Engine\Data\DefaultTheme::keyframes()
        );
        $registry->merge($result['vars'], $result['flags'], $result['keyframes']);

        self::assertTrue($bag->isEmpty(), \json_encode($bag->toArray()) ?: '');
        $expected = ['--color-*' => 'initial', '--font-*' => 'initial', '--color-brand' => '#ff5a1f'];
        self::assertSame($expected, $result['vars']);
        self::assertNull($registry->get('--color-red-500'));
        self::assertNull($registry->get('--font-sans'));
        self::assertNotNull($registry->get('--color-brand'));
        self::assertNotNull($registry->get('--font-weight-bold'), '--font-* leaves --font-weight-*');
    }

    /**
     * @param array<array-key, Node> $nodes
     */
    private function theme(string $params, array $nodes): AtRule
    {
        return new AtRule('theme', $params, \array_values($nodes));
    }

    /**
     * @param-out DiagnosticBag $bag
     *
     * @return array{vars: array<string, string>, keyframes: array<string, AtRule>, flags: ThemeFlags}
     */
    private function parse(AtRule $theme, ?DiagnosticBag &$bag = null): array
    {
        $bag = new DiagnosticBag();

        return ThemeParser::parse($theme, $bag);
    }

    private function assertOneThemeError(DiagnosticBag $bag, string $mention): void
    {
        self::assertCount(1, $bag->all());
        self::assertSame(Diagnostic::E_THEME_INVALID, $bag->all()[0]->code());
        self::assertTrue($bag->all()[0]->isError());
        self::assertStringContainsString($mention, $bag->all()[0]->message());
    }
}
