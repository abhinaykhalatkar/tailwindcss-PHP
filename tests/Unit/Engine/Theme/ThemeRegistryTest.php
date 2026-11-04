<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Theme;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Theme\ThemeValue;

/**
 * The theme registry (design doc 07 §Phase 2, doc 13 #20).
 *
 * The test names follow the bullets of doc 07. Where a rule was checked against
 * the pinned Tailwind CLI — the namespaces that survive a wildcard reset, and
 * what is emitted for inline, reference, static and default variables — the
 * comment says so, and the scenario mirrors the `theme-flags` golden.
 */
final class ThemeRegistryTest extends TestCase
{
    // ---------------------------------------------------------- merge order

    /** @test */
    public function laterDefinitionsOverrideEarlierOnesInPlace(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--a' => '1', '--b' => '2', '--c' => '3'], ThemeFlags::none());
        $registry->merge(['--b' => 'two'], ThemeFlags::none());

        self::assertSame(['--a' => '1', '--b' => 'two', '--c' => '3'], $registry->allVars());
        self::assertSame(['--a', '--b', '--c'], \array_keys($registry->allVars()));
    }

    /** @test */
    public function aDefaultBlockNeverOverridesAnAuthoredVariable(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--color-brand' => 'red'], ThemeFlags::none());
        $registry->merge(
            ['--color-brand' => 'blue', '--color-new' => 'green'],
            new ThemeFlags(false, false, false, true)
        );

        self::assertSame('red', $this->resolved($registry, 'color', 'brand')->value());
        self::assertSame('green', $this->resolved($registry, 'color', 'new')->value());
    }

    /** @test */
    public function anAuthoredBlockOverridesADefaultOneWhicheverCameFirst(): void
    {
        $registry = new ThemeRegistry();
        $default = new ThemeFlags(false, false, false, true);

        $registry->merge(['--color-brand' => 'blue'], $default);
        $registry->merge(['--color-brand' => 'red'], ThemeFlags::none());

        self::assertSame('red', $this->resolved($registry, 'color', 'brand')->value());

        // And a later default cannot take it back.
        $registry->merge(['--color-brand' => 'blue'], $default);

        self::assertSame('red', $this->resolved($registry, 'color', 'brand')->value());
    }

    /** @test */
    public function aLaterDefaultBlockOverridesAnEarlierDefaultOne(): void
    {
        $registry = new ThemeRegistry();
        $default = new ThemeFlags(false, false, false, true);

        $registry->merge(['--a' => '1'], $default);
        $registry->merge(['--a' => '2'], $default);

        self::assertSame('2', $this->named($registry, '--a')->value());
    }

    // --------------------------------------------------------------- initial

    /** @test */
    public function initialRemovesAVariable(): void
    {
        $registry = $this->registry(['--color-red-500' => 'red', '--color-blue-500' => 'blue']);

        $registry->merge(['--color-red-500' => 'initial'], ThemeFlags::none());

        self::assertNull($registry->resolve('color', 'red-500'));
        self::assertNotNull($registry->resolve('color', 'blue-500'));
    }

    /** @test */
    public function initialOnAMissingVariableIsNotAnError(): void
    {
        $registry = $this->registry(['--a' => '1']);

        $registry->merge(['--nope' => 'initial'], ThemeFlags::none());

        self::assertSame(['--a' => '1'], $registry->allVars());
    }

    /** @test */
    public function aRemovedVariableComesBackAtTheEnd(): void
    {
        $registry = $this->registry(['--a' => '1', '--b' => '2', '--c' => '3']);

        $registry->merge(['--a' => 'initial'], ThemeFlags::none());
        $registry->merge(['--a' => 'again'], ThemeFlags::none());

        self::assertSame(['--b', '--c', '--a'], \array_keys($registry->allVars()));
    }

    /** @test */
    public function aDefaultBlockCannotRemoveAnAuthoredVariable(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--a' => 'authored'], ThemeFlags::none());
        $registry->merge(['--a' => 'initial'], new ThemeFlags(false, false, false, true));

        self::assertSame('authored', $this->named($registry, '--a')->value());
    }

    // ------------------------------------------------------------- wildcards

    /**
     * `--color-*: initial` removes the namespace and nothing else (checked
     * against the pinned CLI with `theme(static)` output).
     *
     * @test
     */
    public function aWildcardRemovesWholeNamespaces(): void
    {
        $registry = $this->registry([
            '--color-red-500' => 'red',
            '--color-white' => '#fff',
            '--spacing' => '0.25rem',
            '--font-sans' => 'ui-sans-serif',
        ]);

        $registry->merge(['--color-*' => 'initial'], ThemeFlags::none());

        self::assertSame(['--spacing' => '0.25rem', '--font-sans' => 'ui-sans-serif'], $registry->allVars());
    }

    /** @test */
    public function aWildcardThenRedefinitionKeepsOnlyWhatFollows(): void
    {
        $registry = $this->registry(['--color-red-500' => 'red', '--color-white' => '#fff']);

        $registry->merge(['--color-*' => 'initial', '--color-brand' => '#f00'], ThemeFlags::none());

        self::assertSame(['--color-brand' => '#f00'], $registry->allVars());
    }

    /**
     * @test
     *
     * @dataProvider protectedSubNamespaces
     *
     * @param array<string, string> $vars
     * @param array<string, string> $expected
     */
    public function aWildcardLeavesTheSubNamespacesTailwindKeeps(string $wildcard, array $vars, array $expected): void
    {
        $registry = $this->registry($vars);

        $registry->merge([$wildcard => 'initial'], ThemeFlags::none());

        self::assertSame($expected, $registry->allVars());
    }

    /**
     * Each row was confirmed against the pinned 4.1.18 CLI: `--font-*` leaves
     * `--font-weight-*`, `--inset-*` leaves `--inset-shadow-*`, `--text-*`
     * leaves `--text-shadow-*`.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, string>}>
     */
    public static function protectedSubNamespaces(): array
    {
        return [
            'font keeps font-weight' => [
                '--font-*',
                ['--font-sans' => 's', '--font-mono' => 'm', '--font-weight-bold' => '700'],
                ['--font-weight-bold' => '700'],
            ],
            'font keeps font-size' => [
                '--font-*',
                ['--font-sans' => 's', '--font-size-x' => '1rem'],
                ['--font-size-x' => '1rem'],
            ],
            'inset keeps inset-shadow' => [
                '--inset-*',
                ['--inset-1' => '1', '--inset-shadow-sm' => 'x', '--inset-ring-1' => 'y'],
                ['--inset-shadow-sm' => 'x', '--inset-ring-1' => 'y'],
            ],
            'text keeps text-shadow' => [
                '--text-*',
                ['--text-xl' => '1.25rem', '--text-xl--line-height' => '1.4', '--text-shadow-sm' => 'z'],
                ['--text-shadow-sm' => 'z'],
            ],
            'text keeps text-underline-offset' => [
                '--text-*',
                ['--text-xs' => '1', '--text-underline-offset-1' => '1px'],
                ['--text-underline-offset-1' => '1px'],
            ],
            'shadow is not protected from itself' => [
                '--shadow-*',
                ['--shadow-sm' => 'a', '--inset-shadow-sm' => 'b', '--drop-shadow-sm' => 'c'],
                ['--inset-shadow-sm' => 'b', '--drop-shadow-sm' => 'c'],
            ],
        ];
    }

    /** @test */
    public function theStarWildcardRemovesEverything(): void
    {
        $registry = $this->registry(['--a' => '1', '--font-sans' => 's', '--text-xl' => '2']);

        $registry->merge(['--*' => 'initial'], ThemeFlags::none());

        self::assertSame([], $registry->allVars());
    }

    /** @test */
    public function aWildcardFromADefaultBlockOnlyRemovesDefaultVariables(): void
    {
        $registry = new ThemeRegistry();
        $default = new ThemeFlags(false, false, false, true);

        $registry->merge(['--color-brand' => 'red'], ThemeFlags::none());
        $registry->merge(['--color-soft' => 'grey'], $default);
        $registry->merge(['--color-*' => 'initial'], $default);

        self::assertSame(['--color-brand' => 'red'], $registry->allVars());
    }

    /** @test */
    public function aWildcardDoesNotTouchKeyframes(): void
    {
        $registry = new ThemeRegistry();
        $block = new AtRule('keyframes', 'spin', [new Rule('to', [new Declaration('transform', 'rotate(1turn)')])]);

        $registry->merge(['--animate-spin' => 'spin 1s linear infinite'], ThemeFlags::none(), ['spin' => $block]);
        $registry->merge(['--animate-*' => 'initial'], ThemeFlags::none());

        self::assertNull($registry->resolve('animate', 'spin'));
        self::assertSame($block, $registry->keyframes('spin'), 'Harmless: emitted only if an animation uses it.');
    }

    // ----------------------------------------------------- resolve and friends

    /** @test */
    public function resolveReturnsTheVariableByNamespaceAndKey(): void
    {
        $registry = $this->registry(['--color-red-500' => 'oklch(63.7% 0.237 25.331)']);

        $value = $registry->resolve('color', 'red-500');

        self::assertNotNull($value);
        self::assertSame('--color-red-500', $value->name());
        self::assertSame('oklch(63.7% 0.237 25.331)', $value->value());
        self::assertFalse($value->isInline());
        self::assertFalse($value->isReference());
        self::assertTrue($registry->has('color', 'red-500'));
    }

    /** @test */
    public function anUnknownVariableIsNull(): void
    {
        $registry = $this->registry(['--color-red-500' => 'red']);

        self::assertNull($registry->resolve('color', 'red-501'));
        self::assertNull($registry->resolve('colour', 'red-500'));
        self::assertFalse($registry->has('color', 'red-501'));
    }

    /** @test */
    public function theBareSpacingVariableResolvesThroughAnEmptyKey(): void
    {
        $registry = $this->registry(['--spacing' => '0.25rem']);

        self::assertSame('0.25rem', $this->resolved($registry, 'spacing', '')->value());
        self::assertSame('--spacing', $this->resolved($registry, 'spacing', '')->name());
        self::assertTrue($registry->has('spacing'));
        self::assertNull($registry->resolve('spacing', '4'));
    }

    /** @test */
    public function companionsResolveThroughASuffix(): void
    {
        $registry = $this->registry([
            '--text-xl' => '1.25rem',
            '--text-xl--line-height' => 'calc(1.75 / 1.25)',
        ]);

        $companion = $registry->companion('text', 'xl', 'line-height');

        self::assertNotNull($companion);
        self::assertSame('--text-xl--line-height', $companion->name());
        self::assertSame('calc(1.75 / 1.25)', $companion->value());
        self::assertNull($registry->companion('text', 'xl', 'letter-spacing'));
        self::assertNull($registry->companion('text', '2xl', 'line-height'));
    }

    /** @test */
    public function getResolvesAFullName(): void
    {
        $registry = $this->registry(['--x-y' => '1']);

        self::assertSame('1', $this->named($registry, '--x-y')->value());
        self::assertNull($registry->get('--x-z'));
    }

    /** @test */
    public function inlineAndReferenceFlagsTravelWithTheVariable(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--font-body' => 'var(--font-sans)'], new ThemeFlags(true));
        $registry->merge(['--color-hidden' => '#654321'], new ThemeFlags(false, false, true));

        self::assertTrue($this->resolved($registry, 'font', 'body')->isInline());
        self::assertFalse($this->resolved($registry, 'font', 'body')->isReference());
        self::assertTrue($this->resolved($registry, 'color', 'hidden')->isReference());
        self::assertFalse($this->resolved($registry, 'color', 'hidden')->isInline());
    }

    // ---------------------------------------------------------- namespaceKeys

    /** @test */
    public function namespaceKeysListABreakpointsInDefinitionOrder(): void
    {
        $registry = $this->registry([
            '--breakpoint-sm' => '40rem',
            '--breakpoint-md' => '48rem',
            '--color-red-500' => 'red',
            '--breakpoint-lg' => '64rem',
        ]);

        self::assertSame(['sm', 'md', 'lg'], $registry->namespaceKeys('breakpoint'));
    }

    /** @test */
    public function namespaceKeysSkipCompanionsAndTheBareVariable(): void
    {
        $registry = $this->registry([
            '--text' => 'x',
            '--text-' => 'a variable named exactly like the prefix has no key',
            '--text-xs' => '0.75rem',
            '--text-xs--line-height' => '1.33',
            '--text-xl' => '1.25rem',
        ]);

        self::assertSame(['xs', 'xl'], $registry->namespaceKeys('text'));
    }

    /** @test */
    public function namespaceKeysIncludeReferenceVariablesBecauseTheyResolve(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--breakpoint-md' => '48rem'], new ThemeFlags(false, false, true));

        self::assertSame(['md'], $registry->namespaceKeys('breakpoint'));
    }

    /** @test */
    public function namespaceKeysFollowLaterMerges(): void
    {
        $registry = $this->registry(['--breakpoint-sm' => '40rem']);

        self::assertSame(['sm'], $registry->namespaceKeys('breakpoint'));

        $registry->merge(['--breakpoint-tablet' => '48rem'], ThemeFlags::none());

        self::assertSame(['sm', 'tablet'], $registry->namespaceKeys('breakpoint'));

        $registry->merge(['--breakpoint-*' => 'initial'], ThemeFlags::none());

        self::assertSame([], $registry->namespaceKeys('breakpoint'));
    }

    /** @test */
    public function aNamespaceMustMatchWholly(): void
    {
        $registry = $this->registry(['--color-red-500' => 'red', '--colorful-x' => 'x']);

        self::assertSame(['red-500'], $registry->namespaceKeys('color'));
        self::assertSame([], $registry->namespaceKeys('col'));
    }

    // -------------------------------------------------------------- keyframes

    /** @test */
    public function keyframesAreFoundByName(): void
    {
        $registry = new ThemeRegistry();
        $block = new AtRule('keyframes', 'wiggle', [new Rule('50%', [new Declaration('transform', 'rotate(3deg)')])]);

        $registry->merge(['--animate-wiggle' => 'wiggle 1s infinite'], ThemeFlags::none(), ['wiggle' => $block]);

        self::assertSame($block, $registry->keyframes('wiggle'));
        self::assertNull($registry->keyframes('missing'));
    }

    /** @test */
    public function keyframesGivenAsCssAreParsedOnFirstUse(): void
    {
        $registry = ThemeRegistry::fromDefault(
            [],
            ['spin' => "@keyframes spin {\n  to {\n    transform: rotate(360deg);\n  }\n}"]
        );

        $block = $registry->keyframes('spin');

        self::assertInstanceOf(AtRule::class, $block);
        self::assertSame('keyframes', $block->name);
        self::assertSame('spin', $block->params);
        self::assertSame($block, $registry->keyframes('spin'), 'Parsed once.');
    }

    /**
     * @test
     *
     * @dataProvider badKeyframesText
     */
    public function keyframesTextThatIsNotOneCleanBlockIsNotFound(string $css): void
    {
        $registry = ThemeRegistry::fromDefault([], ['x' => $css]);

        self::assertNull($registry->keyframes('x'));
        self::assertNull($registry->keyframes('x'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badKeyframesText(): array
    {
        return [
            'empty' => [''],
            'a rule' => ['.x { color: red; }'],
            'two blocks' => ['@keyframes a { to { x: 1 } } @keyframes b { to { x: 2 } }'],
            'another at-rule' => ['@media print { a { x: 1 } }'],
            'unterminated' => ['@keyframes a { to { x: 1 }'],
        ];
    }

    /** @test */
    public function aDefaultKeyframesBlockDoesNotReplaceAnAuthoredOne(): void
    {
        $registry = new ThemeRegistry();
        $authored = new AtRule('keyframes', 'spin', [new Rule('to', [])]);

        $registry->merge([], ThemeFlags::none(), ['spin' => $authored]);
        $registry->merge([], new ThemeFlags(false, false, false, true), ['spin' => '@keyframes spin { to { x: 1 } }']);

        self::assertSame($authored, $registry->keyframes('spin'));
    }

    // -------------------------------------------- used, static, reference, inline

    /** @test */
    public function usedVariablesComeBackInDefinitionOrderNotUseOrder(): void
    {
        $registry = $this->registry(['--a' => '1', '--b' => '2', '--c' => '3']);

        $registry->markUsed('--c');
        $registry->markUsed('--a');

        self::assertSame(['--a' => '1', '--c' => '3'], $registry->usedVars());
        self::assertSame(['--a', '--c'], \array_keys($registry->usedVars()));
    }

    /** @test */
    public function markingAnUnknownVariableIsIgnored(): void
    {
        $registry = $this->registry(['--a' => '1']);

        $registry->markUsed('--nope');
        $registry->markUsed('--a');
        $registry->markUsed('--a');

        self::assertSame(['--a' => '1'], $registry->usedVars());
    }

    /**
     * v4 writes `--default-font-family: --theme(--font-sans, initial)`: once
     * `--font-sans` is gone the default is `initial`, i.e. not emitted (and
     * not followed). Probed with the pinned CLI (TASK-0050):
     *
     * - `--font-*: initial` → neither default font variable;
     * - `--font-mono: initial` → only the mono one goes;
     * - `--font-*: initial; --font-mono: x` → the mono one stays.
     *
     * @test
     *
     * @dataProvider defaultFontResets
     *
     * @param array<string, string> $authored
     * @param list<string> $expected
     */
    public function aDefaultVariableReadingAMissingVariableIsNotEmitted(array $authored, array $expected): void
    {
        $registry = $this->defaults();
        $registry->merge($authored, ThemeFlags::none());
        $registry->markUsed('--default-font-family');
        $registry->markUsed('--default-mono-font-family');

        self::assertSame($expected, \array_keys($registry->emittable()));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: list<string>}>
     */
    public static function defaultFontResets(): array
    {
        return [
            'no reset' => [
                [],
                ['--font-sans', '--font-mono', '--default-font-family', '--default-mono-font-family'],
            ],
            'every font reset' => [['--font-*' => 'initial'], []],
            'the mono font reset' => [['--font-mono' => 'initial'], ['--font-sans', '--default-font-family']],
            'every font reset, mono redefined' => [
                ['--font-*' => 'initial', '--font-mono' => 'x'],
                ['--default-mono-font-family', '--font-mono'],
            ],
        ];
    }

    /**
     * A used variable that reads an unset default does not bring it back
     * through the `var()` closure.
     *
     * @test
     */
    public function anUnsetDefaultIsNotFollowedFromAUsedVariable(): void
    {
        $registry = $this->defaults();
        $authored = ['--font-mono' => 'initial', '--code' => 'var(--default-mono-font-family)'];
        $registry->merge($authored, ThemeFlags::none());
        $registry->markUsed('--code');

        self::assertSame(['--code'], \array_keys($registry->emittable()));
    }

    /**
     * The rule is the default block's `--theme(…, initial)` form only: an
     * authored variable reading an undefined one is emitted as written.
     *
     * @test
     */
    public function anAuthoredVariableReadingAMissingVariableIsStillEmitted(): void
    {
        $registry = $this->registry(['--foo' => 'var(--nope)', '--bar' => 'var(--nope, red)']);
        $registry->markUsed('--foo');
        $registry->markUsed('--bar');

        self::assertSame(['--foo' => 'var(--nope)', '--bar' => 'var(--nope, red)'], $registry->emittable());
    }

    /**
     * Only a value that is exactly one `var(--x)`: a fallback or more text
     * keeps a default variable, as the CLI prints those whatever is defined.
     *
     * @test
     */
    public function aDefaultVariableWithMoreThanOneReferenceIsKept(): void
    {
        $registry = new ThemeRegistry();
        $defaults = ['--a' => 'var(--gone, 1px)', '--b' => 'calc(var(--gone) * 2)'];
        $registry->merge($defaults, new ThemeFlags(false, false, false, true));
        $registry->markUsed('--a');
        $registry->markUsed('--b');

        self::assertSame(['--a', '--b'], \array_keys($registry->emittable()));
    }

    /** @test */
    public function onlyUsedVariablesAreEmittedUnlessStatic(): void
    {
        $registry = $this->registry(['--a' => '1', '--b' => '2', '--c' => '3']);

        self::assertSame([], $registry->emittable());

        $registry->markUsed('--b');

        self::assertSame(['--b' => '2'], $registry->emittable());
    }

    /** @test */
    public function aStaticBlockIsEmittedWholeAndSetsTheRegistryFlag(): void
    {
        $registry = new ThemeRegistry();

        self::assertFalse($registry->isStatic());

        $registry->merge(['--a' => '1'], ThemeFlags::none());
        $registry->merge(['--always' => 'x', '--also' => 'y'], new ThemeFlags(false, true));

        self::assertTrue($registry->isStatic());
        self::assertSame(['--always' => 'x', '--also' => 'y'], $registry->emittable());
        self::assertSame(['--a' => '1', '--always' => 'x', '--also' => 'y'], $registry->allVars());
    }

    /** @test */
    public function aReferenceVariableIsNeverEmitted(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--hidden' => '#654321'], new ThemeFlags(false, true, true));
        $registry->markUsed('--hidden');

        self::assertSame([], $registry->emittable());
        self::assertSame(['--hidden' => '#654321'], $registry->usedVars());
    }

    /** @test */
    public function anInlineVariableIsEmittedOnlyIfSomethingMarksItUsedOrItIsStatic(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--font-body' => 'var(--font-sans)'], new ThemeFlags(true));

        // A utility that substitutes the value never calls markUsed.
        self::assertSame([], $registry->emittable());

        $registry->markUsed('--font-body');

        self::assertSame(['--font-body' => 'var(--font-sans)'], $registry->emittable());
    }

    /** @test */
    public function variablesReadThroughVarInAnEmittedValueAreEmittedToo(): void
    {
        $registry = $this->registry([
            '--font-sans' => 'ui-sans-serif, system-ui',
            '--font-mono' => 'ui-monospace',
            '--default-font-family' => 'var(--font-sans)',
            '--default-mono-font-family' => 'var(--font-mono)',
            '--color-red-500' => 'red',
        ]);

        $registry->markUsed('--default-font-family');

        self::assertSame(
            ['--font-sans' => 'ui-sans-serif, system-ui', '--default-font-family' => 'var(--font-sans)'],
            $registry->emittable()
        );
    }

    /** @test */
    public function theReferenceFollowsAFallbackAndNestedFunctions(): void
    {
        $registry = $this->registry([
            '--a' => 'calc(var(--b) * 2)',
            '--b' => 'var( --c , 1px)',
            '--c' => '3px',
            '--d' => '4px',
        ]);

        $registry->markUsed('--a');

        self::assertSame(['--a', '--b', '--c'], \array_keys($registry->emittable()));
    }

    /** @test */
    public function circularReferencesTerminate(): void
    {
        $registry = $this->registry(['--a' => 'var(--b)', '--b' => 'var(--a)', '--c' => 'var(--c)']);

        $registry->markUsed('--a');
        $registry->markUsed('--c');

        self::assertSame(['--a', '--b', '--c'], \array_keys($registry->emittable()));
    }

    /** @test */
    public function theReferenceClosureNeverPullsInAReferenceVariable(): void
    {
        $registry = new ThemeRegistry();

        $registry->merge(['--hidden' => 'red'], new ThemeFlags(false, false, true));
        $registry->merge(['--shown' => 'var(--hidden)'], ThemeFlags::none());
        $registry->markUsed('--shown');

        self::assertSame(['--shown' => 'var(--hidden)'], $registry->emittable());
    }

    /** @test */
    public function aReferenceToAnUnknownVariableIsIgnored(): void
    {
        $registry = $this->registry(['--a' => 'var(--not-in-the-theme)']);

        $registry->markUsed('--a');

        self::assertSame(['--a' => 'var(--not-in-the-theme)'], $registry->emittable());
    }

    // ----------------------------------------- mirrors the theme-flags golden

    /**
     * The scenario of `corpus/directives/theme-flags.css`, with the answer the
     * pinned 4.1.18 CLI gave (`golden/directives/theme-flags.css`): the layer
     * holds the default font variables the preflight reads, the static block's
     * variable although nothing used it, and the used default variable — and
     * not the inline pair or the reference variable.
     *
     * @test
     */
    public function theFlagsGoldenEmissionRule(): void
    {
        $registry = ThemeRegistry::fromDefault(
            [
                '--font-sans' => 'ui-sans-serif, system-ui, sans-serif',
                '--font-mono' => 'ui-monospace, SFMono-Regular',
                '--default-font-family' => 'var(--font-sans)',
                '--default-mono-font-family' => 'var(--font-mono)',
                '--color-red-500' => 'oklch(63.7% 0.237 25.331)',
            ],
            []
        );

        $registry->merge(
            ['--font-body' => 'var(--font-sans)', '--color-ink' => 'var(--ink)'],
            new ThemeFlags(true)
        );
        $registry->merge(['--color-always' => '#123456'], new ThemeFlags(false, true));
        $registry->merge(['--color-soft' => '#eeeeee'], new ThemeFlags(false, false, false, true));
        $registry->merge(['--color-hidden' => '#654321'], new ThemeFlags(false, false, true));

        // The preflight reads the default font families; the candidates use
        // font-body and bg-ink (inline: no mark), bg-always, bg-soft, bg-hidden.
        $registry->markUsed('--default-font-family');
        $registry->markUsed('--default-mono-font-family');
        $registry->markUsed('--color-always');
        $registry->markUsed('--color-soft');
        $registry->markUsed('--color-hidden');

        self::assertSame(
            [
                '--font-sans',
                '--font-mono',
                '--default-font-family',
                '--default-mono-font-family',
                '--color-always',
                '--color-soft',
            ],
            \array_keys($registry->emittable())
        );
    }

    // ------------------------------------------------------------ fromDefault

    /** @test */
    public function fromDefaultSeedsDefaultFlaggedVariablesThatAuthoredBlocksOverride(): void
    {
        $registry = ThemeRegistry::fromDefault(['--color-red-500' => 'oklch(1 2 3)', '--spacing' => '0.25rem'], []);

        $registry->merge(['--color-red-500' => '#f00'], ThemeFlags::none());

        self::assertSame('#f00', $this->resolved($registry, 'color', 'red-500')->value());
        self::assertSame('0.25rem', $this->resolved($registry, 'spacing', '')->value());
        self::assertFalse($registry->isStatic());
    }

    /** @test */
    public function aWildcardResetRemovesTheSeededDefaults(): void
    {
        $registry = ThemeRegistry::fromDefault(['--color-red-500' => 'x', '--spacing' => '0.25rem'], []);

        $registry->merge(['--color-*' => 'initial', '--color-brand' => '#f00'], ThemeFlags::none());

        self::assertSame(['--spacing' => '0.25rem', '--color-brand' => '#f00'], $registry->allVars());
    }

    // ------------------------------------------------- bounds and verbatim

    /**
     * T-05: a very large theme merges in one linear pass and keeps its order.
     *
     * @test
     */
    public function aFiftyThousandVariableThemeMerges(): void
    {
        $vars = [];

        for ($i = 0; $i < 50000; ++$i) {
            $vars['--color-v' . $i] = (string) $i;
        }

        $registry = new ThemeRegistry();

        $registry->merge($vars, ThemeFlags::none());
        $registry->merge(['--color-v25000' => 'changed'], ThemeFlags::none());
        $registry->markUsed('--color-v49999');

        self::assertCount(50000, $registry->allVars());
        self::assertSame('changed', $this->resolved($registry, 'color', 'v25000')->value());
        self::assertSame('--color-v0', \array_keys($registry->allVars())[0]);
        self::assertSame(['--color-v49999' => '49999'], $registry->emittable());
        self::assertCount(50000, $registry->namespaceKeys('color'));
    }

    /**
     * T-15: values are data. Nothing in the registry interprets them.
     *
     * @test
     */
    public function valuesAreStoredVerbatimAndNeverEvaluated(): void
    {
        $values = [
            '--a' => '<?php echo 1; ?>',
            '--b' => 'url(data:text/html;base64,PD9waHA=)',
            '--c' => '${x} {{ y }} $(z)',
            '--d' => "line one\nline two",
            '--e' => '  padded  ',
        ];
        $registry = $this->registry($values);

        self::assertSame($values, $registry->allVars());

        foreach ($values as $name => $value) {
            self::assertSame($value, $this->named($registry, $name)->value());
        }
    }

    // ------------------------------------------------- ignored sub-namespaces

    /** @test */
    public function aBroaderNamespaceDoesNotReachItsSubNamespaces(): void
    {
        $registry = $this->registry([
            '--font-sans' => 'ui-sans-serif',
            '--font-weight-bold' => '700',
            '--text-sm' => '0.875rem',
            '--text-shadow-sm' => '0 1px 0 red',
            '--inset-shadow-sm' => 'inset 0 1px red',
            '--inset-ring-1' => 'inset 0 0 0 1px',
        ]);

        self::assertNull($registry->resolve('font', 'weight-bold'));
        self::assertNotNull($registry->resolve('font-weight', 'bold'));
        self::assertNotNull($registry->resolve('font', 'sans'));
        self::assertNull($registry->resolve('text', 'shadow-sm'));
        self::assertNotNull($registry->resolve('text-shadow', 'sm'));
        self::assertNotNull($registry->resolve('text', 'sm'));
        self::assertNull($registry->resolve('inset', 'shadow-sm'));
        self::assertNull($registry->resolve('inset', 'ring-1'));
        self::assertNotNull($registry->resolve('inset-shadow', 'sm'));
    }

    /** @test */
    public function hasAndCompanionFollowTheSameRule(): void
    {
        $registry = $this->registry([
            '--font-weight-bold' => '700',
            '--font-weight-bold--x' => '1',
            '--text-shadow-sm' => '0 1px 0 red',
            '--text-sm' => '0.875rem',
            '--text-sm--line-height' => '1.4',
        ]);

        self::assertFalse($registry->has('font', 'weight-bold'));
        self::assertTrue($registry->has('font-weight', 'bold'));
        self::assertFalse($registry->has('text', 'shadow-sm'));
        self::assertTrue($registry->has('text', 'sm'));
        self::assertNull($registry->companion('font', 'weight-bold', 'x'));
        self::assertNotNull($registry->companion('font-weight', 'bold', 'x'));
        self::assertNotNull($registry->companion('text', 'sm', 'line-height'));
    }

    /** @test */
    public function namespaceKeysLeaveTheSubNamespacesOut(): void
    {
        $registry = $this->registry([
            '--font-sans' => 'a',
            '--font-weight-bold' => '700',
            '--font-size-lg' => '1rem',
            '--font-serif' => 'b',
            '--text-xs' => '0.75rem',
            '--text-shadow-sm' => 'x',
            '--text-decoration-color-red' => 'red',
            '--text-sm' => '0.875rem',
        ]);

        self::assertSame(['sans', 'serif'], $registry->namespaceKeys('font'));
        self::assertSame(['bold'], $registry->namespaceKeys('font-weight'));
        self::assertSame(['xs', 'sm'], $registry->namespaceKeys('text'));
        self::assertSame(['sm'], $registry->namespaceKeys('text-shadow'));
    }

    /** @test */
    public function theRuleIsAppliedToKeysAddedLaterToo(): void
    {
        $registry = $this->registry(['--font-sans' => 'a']);

        self::assertSame(['sans'], $registry->namespaceKeys('font'));

        $registry->merge(['--font-weight-thin' => '100', '--font-mono' => 'b'], ThemeFlags::none());

        self::assertSame(['sans', 'mono'], $registry->namespaceKeys('font'));
        self::assertNull($registry->resolve('font', 'weight-thin'));
    }

    /**
     * Only lookups are narrowed: the variables are still defined, found by their
     * full name and emitted like any other.
     *
     * @test
     */
    public function theVariablesStayDefinedAndEmittable(): void
    {
        $registry = $this->registry(['--font-weight-bold' => '700']);

        self::assertNotNull($registry->get('--font-weight-bold'));

        $registry->markUsed('--font-weight-bold');

        self::assertSame(['--font-weight-bold'], \array_keys($registry->usedVars()));
    }

    // ---------------------------------------------------------------- helpers

    private function resolved(ThemeRegistry $registry, string $namespace, string $key): ThemeValue
    {
        $value = $registry->resolve($namespace, $key);

        self::assertNotNull($value, '--' . $namespace . '-' . $key . ' should resolve.');

        return $value;
    }

    private function named(ThemeRegistry $registry, string $name): ThemeValue
    {
        $value = $registry->get($name);

        self::assertNotNull($value, $name . ' should be defined.');

        return $value;
    }

    /**
     * The two font defaults of `theme.css` and the fonts they read, as a
     * `default` block.
     */
    private function defaults(): ThemeRegistry
    {
        $registry = new ThemeRegistry();
        $registry->merge([
            '--font-sans' => 'ui-sans-serif',
            '--font-mono' => 'ui-monospace',
            '--default-font-family' => 'var(--font-sans)',
            '--default-mono-font-family' => 'var(--font-mono)',
        ], new ThemeFlags(false, false, false, true));

        return $registry;
    }

    /**
     * @param array<string, string> $vars
     */
    private function registry(array $vars): ThemeRegistry
    {
        $registry = new ThemeRegistry();

        $registry->merge($vars, ThemeFlags::none());

        return $registry;
    }
}
