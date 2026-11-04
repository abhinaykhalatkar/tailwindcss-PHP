<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\ValueInterface;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\PropertyOrder;
use TailwindPhp\Engine\Data\Utilities;
use TailwindPhp\Engine\Data\Utilities\Layout;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\CompiledUtility;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;

/**
 * The compiler: readings in try-order, definitions in order, the value rules
 * through the resolver, important, the sort key, the per-compile memo, and
 * the T-15 boundary. Expected CSS comes from the pinned CLI (TASK-0032
 * probes) unless a row says otherwise.
 */
final class CompilerTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider layoutCandidates
     */
    public function layoutCandidatesCompileAsTheCliDoes(string $candidate, ?string $expected): void
    {
        self::assertSame($expected, self::css($candidate));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function layoutCandidates(): array
    {
        return [
            'static' => ['block', 'display:block'],
            'static with several declarations' => [
                'not-sr-only',
                'position:static;width:auto;height:auto;padding:0;margin:0;overflow:visible;'
                . 'clip-path:none;white-space:normal',
            ],
            'static and functional root, alone' => ['flex', 'display:flex'],
            'static and functional root, valued' => ['flex-1', 'flex:1'],
            'keyword' => ['float-start', 'float:inline-start'],
            'keyword root alone has no default' => ['float', null],
            'keyword root refuses arbitrary' => ['overflow-[x]', null],
            'two sub-definitions, the second takes it' => ['object-left-top', 'object-position:left top'],
            'arbitrary goes to the sub-definition that takes it' => [
                'object-[center_top]',
                'object-position:center top',
            ],
            'several properties' => [
                'box-decoration-clone',
                '-webkit-box-decoration-break:clone;box-decoration-break:clone',
            ],
            'spacing' => ['top-4', 'top:calc(var(--spacing) * 4)'],
            'negative spacing' => ['-top-4', 'top:calc(var(--spacing) * -4)'],
            'negative px' => ['-inset-px', 'inset:-1px'],
            'negative auto refused' => ['-inset-auto', null],
            'fraction (the modifier reading is tried first and refused)' => ['top-1/2', 'top:calc(1/2 * 100%)'],
            'negative fraction' => ['-left-1/2', 'left:calc(calc(1/2 * 100%) * -1)'],
            'arbitrary with any hint' => ['top-[length:3px]', 'top:3px'],
            'arbitrary normalised' => ['top-[calc(100%-2rem)]', 'top:calc(100% - 2rem)'],
            'negative arbitrary' => ['-inset-[3px]', 'inset:calc(3px * -1)'],
            'variable' => ['inset-(--i)', 'inset:var(--i)'],
            'theme' => ['columns-xs', 'columns:var(--container-xs)'],
            'negative integer' => ['-z-10', 'z-index:calc(10 * -1)'],
            'negative z-auto refused' => ['-z-auto', null],
            'modifier refused' => ['z-10/50', null],
            'negative static refused' => ['-flex', null],
            'static with a modifier refused' => ['block/50', null],
            'important suffix' => ['z-[100]!', 'z-index:100!important'],
            'important prefix' => ['!hidden', 'display:none!important'],
            'aspect ratio' => ['aspect-16/9', 'aspect-ratio:16/9'],
            'aspect keyword' => ['aspect-square', 'aspect-ratio:1 / 1'],
            'aspect theme' => ['aspect-video', 'aspect-ratio:var(--aspect-video)'],
            'aspect has no bare number' => ['aspect-3', null],
            // A handler row never sees a modifier its definition does not take.
            'handler row refuses a modifier' => ['aspect-video/50', null],
            'handler row refuses a modifier on a keyword' => ['aspect-square/50', null],
            'static handler refuses a modifier' => ['container/50', null],
            'unknown' => ['nothing-here', null],
        ];
    }

    /** @test */
    public function readingsAreTriedInOrderAndTheFirstThatCompilesWins(): void
    {
        $registry = Registry::default();
        $readings = (new CandidateParser($registry))->parse('top-1/2');

        // The parser's try-order: the modifier reading first, the fraction second.
        self::assertCount(2, $readings);
        self::assertNotNull($readings[0]->modifier);
        self::assertNull((new Compiler($registry))->compile($readings[0], self::theme()));

        $compiled = (new Compiler($registry))->compileReadings('top-1/2', $readings, self::theme());

        self::assertNotNull($compiled);
        self::assertSame('top:calc(1/2 * 100%)', self::declarations($compiled->nodes));
    }

    /** @test */
    public function aFunctionalRootUsedAloneTakesItsDefault(): void
    {
        $registry = new Registry([
            'rounded' => ['props' => ['border-radius'], 'theme' => ['radius'], 'default' => '0.25rem'],
            'bg' => ['props' => ['background-color'], 'theme' => ['color']],
        ]);

        self::assertSame('border-radius:0.25rem', self::css('rounded', $registry));
        self::assertNull(self::css('bg', $registry));
        self::assertSame('border-radius:var(--radius-lg)', self::css('rounded-lg', $registry));
    }

    /**
     * Rule 7 against the CLI's output for `bg-*` (the colour family itself is
     * wave 4; this row stands in for it).
     *
     * @test
     *
     * @dataProvider opacityCandidates
     */
    public function opacityModifiersHaveTheCliShape(string $candidate, ?string $expected): void
    {
        $registry = new Registry([
            'bg' => [
                'props' => ['background-color'],
                'theme' => ['color'],
                'modifier' => 'opacity',
                'arbitrary' => ['color'],
            ],
        ]);

        self::assertSame($expected, self::css($candidate, $registry));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function opacityCandidates(): array
    {
        $red = 'oklch(63.7% 0.237 25.331)';
        $supports = static function (string $mixed): string {
            return '@supports (color: color-mix(in lab, red, red)){background-color:' . $mixed . '}';
        };

        return [
            'theme colour' => [
                'bg-red-500/50',
                'background-color:color-mix(in srgb, ' . $red . ' 50%, transparent);'
                . $supports('color-mix(in oklab, var(--color-red-500) 50%, transparent)'),
            ],
            'arbitrary fraction' => [
                'bg-red-500/[0.33]',
                'background-color:color-mix(in srgb, ' . $red . ' 33%, transparent);'
                . $supports('color-mix(in oklab, var(--color-red-500) 33%, transparent)'),
            ],
            'variable percentage' => [
                'bg-red-500/(--o)',
                'background-color:' . $red . ';'
                . $supports('color-mix(in oklab, var(--color-red-500) var(--o), transparent)'),
            ],
            'a hundred percent leaves the colour' => ['bg-red-500/100', 'background-color:var(--color-red-500)'],
            'literal colour' => ['bg-[#0088cc]/50', 'background-color:color-mix(in oklab, #0088cc 50%, transparent)'],
            'currentcolor' => [
                'bg-current/50',
                'background-color:currentcolor;' . $supports('color-mix(in oklab, currentcolor 50%, transparent)'),
            ],
            'variable colour' => [
                'bg-(--c)/50',
                'background-color:var(--c);' . $supports('color-mix(in oklab, var(--c) 50%, transparent)'),
            ],
            'no opacity' => ['bg-red-500/half', null],
        ];
    }

    /** @test */
    public function importantReachesNestedDeclarations(): void
    {
        $compiled = self::compiled('container!');

        self::assertNotNull($compiled);

        foreach (self::allDeclarations($compiled->nodes) as $declaration) {
            self::assertTrue($declaration->important, $declaration->property);
        }
    }

    /** @test */
    public function theSortKeyIsThePropertyOrderOfTheDeclarations(): void
    {
        $srOnly = self::compiled('sr-only');
        $container = self::compiled('container');
        $boxDecoration = self::compiled('box-decoration-slice');

        self::assertNotNull($srOnly);
        self::assertNotNull($container);
        self::assertNotNull($boxDecoration);

        $expected = [];

        $properties = ['position', 'width', 'height', 'padding', 'margin', 'overflow', 'white-space', 'border-width'];

        foreach ($properties as $property) {
            $expected[] = (int) PropertyOrder::index($property);
        }

        \sort($expected);

        // `clip-path` is not in v4's list and does not count.
        self::assertNull(PropertyOrder::index('clip-path'));
        self::assertSame($expected, $srOnly->sortKey);
        // A row's `order` replaces the declared properties.
        self::assertSame([(int) PropertyOrder::index('--tw-container-component')], $container->sortKey);
        // `-webkit-box-decoration-break` is not in the list either.
        self::assertSame([(int) PropertyOrder::index('box-decoration-break')], $boxDecoration->sortKey);
    }

    /** @test */
    public function usedVariablesAreReportedNotMarked(): void
    {
        $theme = self::theme();
        $compiled = self::compiled('columns-xs', null, $theme);
        $spacing = self::compiled('top-4', null, $theme);

        self::assertNotNull($compiled);
        self::assertNotNull($spacing);
        self::assertSame(['--container-xs'], $compiled->usedVars);
        self::assertSame(['--spacing'], $spacing->usedVars);
        self::assertSame([], $theme->usedVars(), 'the caller marks them when it emits');
    }

    /** @test */
    public function theMemoAnswersARepeatedCandidateWithoutCompilingAgain(): void
    {
        $registry = Registry::default();
        $parser = new CandidateParser($registry);
        $compiler = new Compiler($registry);
        $first = $compiler->compileReadings('top-4', $parser->parse('top-4'), self::theme());

        // Same string, no readings: only the memo can answer.
        self::assertSame($first, $compiler->compileReadings('top-4', [], self::theme()));
        self::assertNull($compiler->compileReadings('nope', $parser->parse('nope'), self::theme()));
        self::assertNull($compiler->compileReadings('nope', [], self::theme()));
    }

    /** @test */
    public function anArbitraryPropertyEmitsItsValidatedValue(): void
    {
        self::assertSame('color:red', self::css('[color:red]'));
        self::assertSame('--my-x:calc(1px + 2px)', self::css('[--my-x:calc(1px+2px)]'));
        self::assertNull(self::css('[color:red]/50'));
    }

    /** @test */
    public function aCustomUtilityEmitsItsBody(): void
    {
        $registry = Registry::default();
        $registry->addCustom('tab-4', [new Declaration('tab-size', '4')]);

        self::assertSame('tab-size:4', self::css('tab-4', $registry));
        self::assertNull(self::css('-tab-4', $registry));
    }

    /**
     * T-15: the parser never builds an arbitrary value that could end a
     * declaration or a rule, so none reaches the compiler.
     *
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileArbitraryValuesNeverReachTheCompiler(string $candidate): void
    {
        $registry = Registry::default();

        foreach ((new CandidateParser($registry))->parse($candidate) as $reading) {
            self::assertNotInstanceOf(ArbitraryValue::class, $reading->value, $candidate);
        }

        self::assertNull(self::css($candidate));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileCandidates(): array
    {
        return [
            'semicolon' => ['top-[1px;color:red]'],
            'close brace' => ['top-[1px}a{b:c]'],
            'open brace' => ['z-[{]'],
            'comment' => ['inset-[1px/*x*/]'],
            'semicolon in a property' => ['[color:red;x:y]'],
        ];
    }

    /**
     * T-15: an arbitrary value whose data type the definition does not accept
     * is refused, hinted or inferred.
     *
     * @test
     */
    public function aDataTypeOutsideTheAcceptedListIsRefused(): void
    {
        $registry = new Registry([
            'bg' => ['props' => ['background-color'], 'arbitrary' => ['color']],
        ]);

        self::assertSame('background-color:#fff', self::css('bg-[#fff]', $registry));
        self::assertNull(self::css('bg-[3px]', $registry));
        self::assertNull(self::css('bg-[length:3px]', $registry));
        self::assertSame('background-color:x', self::css('bg-[color:x]', $registry));

        $compiler = new Compiler($registry);
        $url = new ArbitraryValue('x', 'x', 'url');
        $crafted = new Candidate('bg-[x]', [], false, false, Candidate::FUNCTIONAL, 'bg', $url);

        self::assertNull($compiler->compile($crafted, self::theme()));
    }

    /**
     * T-15, defence in depth (round 2): the value classes have public
     * constructors and `@apply` / `@utility` will build candidates too, so
     * `finish()` checks what is about to be emitted, whatever built it. Each
     * object here bypasses the parser; none may reach CSS.
     *
     * @test
     *
     * @dataProvider handBuiltHostileCandidates
     */
    public function handBuiltHostileObjectsAreRefusedAtEmission(Candidate $candidate): void
    {
        $registry = new Registry([
            'top' => ['props' => ['top'], 'arbitrary' => true],
            'bg' => [
                'props' => ['background-color'],
                'theme' => ['color'],
                'modifier' => 'opacity',
                'arbitrary' => ['color'],
            ],
        ]);

        self::assertNull((new Compiler($registry))->compile($candidate, self::theme()));
    }

    /**
     * @return array<string, array{0: Candidate}>
     */
    public static function handBuiltHostileCandidates(): array
    {
        $functional = static function (string $root, ValueInterface $value): Candidate {
            return new Candidate($root . '-x', [], false, false, Candidate::FUNCTIONAL, $root, $value);
        };

        return [
            'arbitrary value ending the rule' => [
                $functional('top', new ArbitraryValue('x', '1px;color:red}x{')),
            ],
            'variable name ending the declaration' => [
                $functional('top', new VarValue('--x);color:red;--y:(')),
            ],
            'arbitrary opacity modifier' => [
                new Candidate(
                    'bg-red-500/x',
                    [],
                    false,
                    false,
                    Candidate::FUNCTIONAL,
                    'bg',
                    new NamedValue('red-500'),
                    new Modifier(Modifier::ARBITRARY, '50%;x:y')
                ),
            ],
            'arbitrary property name' => [
                new Candidate(
                    '[x]',
                    [],
                    false,
                    false,
                    Candidate::ARBITRARY,
                    null,
                    new ArbitraryValue('red', 'red'),
                    null,
                    'color;x:y'
                ),
            ],
            'typed hint that skips the classifier' => [
                $functional('bg', new ArbitraryValue('x', '1px;x:y', 'color')),
            ],
            'line feed in a value' => [
                $functional('top', new ArbitraryValue('x', "1px\nx:y")),
            ],
            'nul in a value' => [
                $functional('top', new ArbitraryValue('x', "1px\0")),
            ],
            'comment in a value' => [
                $functional('top', new ArbitraryValue('x', '1px/*')),
            ],
            // Plain bytes only, so the guard's fast path sees them first.
            'parentheses closed before they open' => [
                $functional('top', new ArbitraryValue('x', 'a)(b')),
            ],
            'script url without quotes' => [
                $functional('top', new ArbitraryValue('x', 'url(javascript:x)')),
            ],
            'long value ending the rule' => [
                $functional('top', new ArbitraryValue('x', \str_repeat('a ', 200) . '}x{')),
            ],
            'long value with an unterminated string' => [
                $functional('top', new ArbitraryValue('x', \str_repeat('a ', 200) . '"x')),
            ],
        ];
    }

    /**
     * Round 3: text longer than 256 bytes meets exactly the rules of short
     * text (the review's verifier inputs). Each goes through four routes: an
     * arbitrary property, a custom `@utility` body, a theme colour emitted in
     * the opacity fallback, and a theme breakpoint emitted by `container`.
     *
     * @test
     *
     * @dataProvider longHostileTexts
     */
    public function longHostileTextIsRefusedOnEveryRoute(string $text): void
    {
        self::assertGreaterThan(256, \strlen($text));

        $property = self::arbitraryProperty('color', $text);
        $registry = new Registry([
            'bg' => ['props' => ['background-color'], 'theme' => ['color'], 'modifier' => 'opacity'],
        ]);
        $registry->addCustom('evil', [new Declaration('color', $text)]);
        $theme = self::theme();
        $theme->merge(['--color-evil' => $text, '--breakpoint-evil' => '1px ' . $text], new ThemeFlags());
        $compiler = new Compiler($registry);

        self::assertNull($compiler->compile($property, self::theme()), 'arbitrary property');
        self::assertNull(self::compiled('evil', $registry), 'custom body');
        self::assertNull(self::compiled('bg-evil/50', $registry, $theme), 'theme colour in the opacity fallback');
        self::assertNull(self::compiled('container', null, $theme), 'theme breakpoint in container');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function longHostileTexts(): array
    {
        $long = \str_repeat('a ', 140);

        return [
            'escaped line feed' => [$long . "\\\n" . 'b'],
            'escaped carriage return' => [$long . "\\\r" . 'b'],
            'escaped nul' => [$long . "\\\0"],
            'escaped tab' => [$long . "\\\t" . 'b'],
            'escape decoding to a control character' => [$long . '\\a b'],
            'script url' => ['url(javascript:alert(1)) ' . $long],
            'script url, quoted' => ["url('javascript:alert(1)') " . $long],
            'script scheme in a string' => ['"javascript:x" ' . $long],
            'expression' => ['expression(alert(1)) ' . $long],
            'escaped function name' => ['\\75rl(javascript:x) ' . $long],
            'bad url' => ['url(a b) ' . $long],
        ];
    }

    /**
     * What long text may hold: a 352-byte font stack (strings) and a long
     * gradient still compile on the theme and arbitrary-property routes.
     *
     * @test
     */
    public function longLegitimateTextStillCompiles(): void
    {
        $fonts = 'ui-sans-serif, system-ui, sans-serif, ' . \str_repeat('"Some Font Family", ', 14) . 'serif';
        $gradient = 'linear-gradient(to right, ' . \str_repeat('rgb(10 20 30 / 50%) 1%, ', 12) . 'red 100%)';
        $theme = self::theme();
        $theme->merge(['--color-long' => $gradient], new ThemeFlags());
        $registry = new Registry([
            'bg' => ['props' => ['background-color'], 'theme' => ['color'], 'modifier' => 'opacity'],
        ]);

        self::assertGreaterThan(256, \strlen($fonts));
        self::assertGreaterThan(256, \strlen($gradient));

        foreach ([$fonts, $gradient] as $text) {
            $property = self::arbitraryProperty('x', $text);

            self::assertNotNull((new Compiler(Registry::default()))->compile($property, self::theme()));
        }

        self::assertSame(
            'background-color:color-mix(in srgb, ' . $gradient . ' 50%, transparent);'
            . '@supports (color: color-mix(in lab, red, red))'
            . '{background-color:color-mix(in oklab, var(--color-long) 50%, transparent)}',
            self::css('bg-long/50', $registry, $theme)
        );
    }

    /**
     * The guard's length limit (64 KiB) holds on the fast path too: plain
     * bytes alone do not make text of any size acceptable.
     *
     * @test
     */
    public function textBeyondTheGuardLimitIsRefused(): void
    {
        $registry = Registry::default();
        $registry->addCustom('huge', [new Declaration('x', \str_repeat('a', 65537))]);
        $registry->addCustom('large', [new Declaration('x', \str_repeat('a', 65536))]);

        self::assertNull(self::compiled('huge', $registry));
        self::assertNotNull(self::compiled('large', $registry));
    }

    /**
     * `important` never doubles a value that already ends in `!important`
     * (a hand-built or custom-utility declaration): the suffix moves into
     * the flag.
     *
     * @test
     */
    public function importantIsNotAppendedTwice(): void
    {
        $registry = Registry::default();
        $registry->addCustom('loud', [
            new Declaration('color', 'red !important'),
            new Declaration('top', '0 !IMPORTANT'),
        ]);

        self::assertSame('color:red!important;top:0!important', self::css('loud!', $registry));
        self::assertSame(
            'color:red !important;top:0 !IMPORTANT',
            self::css('loud', $registry),
            'kept as written otherwise'
        );
    }

    /**
     * CSS allows whitespace between the `!` and `important`: the flag still
     * carries it, so the value never ends in `important!important`.
     *
     * @test
     *
     * @dataProvider spacedImportantValues
     */
    public function importantStripsASpacedSuffixToo(string $value, string $expected): void
    {
        $registry = Registry::default();
        $registry->addCustom('loud', [new Declaration('color', $value)]);

        self::assertSame($expected, self::css('loud!', $registry));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spacedImportantValues(): array
    {
        return [
            'space after the bang' => ['red ! important', 'color:red!important'],
            'several spaces, upper case' => ['red  !   IMPORTANT  ', 'color:red!important'],
            'no space before the bang' => ['red! important', 'color:red!important'],
            'plain suffix' => ['red !important', 'color:red!important'],
            'a bang that is not the flag' => ['red ! imp', 'color:red ! imp!important'],
            'important without a bang' => ['important', 'color:important!important'],
            'important word inside a value' => ['var(--important)', 'color:var(--important)!important'],
        ];
    }

    /**
     * The theme route: a value the theme parser stored after recovering from
     * a broken block must not become a broken `@media` prelude.
     *
     * @test
     */
    public function aHostileThemeValueIsRefusedAtEmission(): void
    {
        $theme = self::theme();
        $theme->merge(['--breakpoint-evil' => '1px) or (x; y}'], new ThemeFlags());

        self::assertNull(self::compiled('container', null, $theme));
        self::assertNotNull(self::compiled('container'), 'the default theme still compiles');
    }

    /**
     * What the guard accepts: everything the Layout family emits, long theme
     * values included.
     *
     * @test
     */
    public function legitimateValuesPassTheEmissionGuard(): void
    {
        $long = 'ui-sans-serif, system-ui, sans-serif, ' . \str_repeat('"Some Font Family", ', 14) . 'serif';
        $theme = self::theme();
        $theme->merge(['--container-long' => $long], new ThemeFlags(true));
        $registry = new Registry(['columns' => ['props' => ['columns'], 'theme' => ['container']]]);

        self::assertGreaterThan(256, \strlen($long));
        self::assertSame('columns:' . $long, self::css('columns-long', $registry, $theme));
        self::assertSame('--my-x:calc(1px + 2px)', self::css('[--my-x:calc(1px+2px)]'));
        self::assertSame(
            '-webkit-box-decoration-break:clone;box-decoration-break:clone',
            self::css('box-decoration-clone')
        );
    }

    /**
     * Doc 13 / criterion 10 (informational): 2 000 Layout candidates compile
     * within 100 ms of CPU, the cycle collector paused (memory
     * host-php-zts-no-opcache-timing).
     *
     * @test
     */
    public function twoThousandLayoutCandidatesCompileQuickly(): void
    {
        $registry = Registry::default();
        $parser = new CandidateParser($registry);
        $compiler = new Compiler($registry);
        $theme = self::theme();
        $words = [
            'block', 'flex', 'flex-1', 'top-4', '-top-4', 'inset-x-0', 'z-10', 'columns-xs', 'aspect-video',
            'object-cover', 'overflow-x-auto', 'top-1/2', 'top-[3px]', 'container', 'sr-only', 'float-left',
            'break-inside-avoid', 'box-border', 'overscroll-y-none', 'invisible',
        ];
        $candidates = [];

        for ($i = 0; $i < 100; ++$i) {
            foreach ($words as $word) {
                $candidates[] = $i === 0 ? $word : $word . '!';
            }
        }

        $collecting = \gc_enabled();
        \gc_disable();

        try {
            $started = self::cpu();
            $compiled = 0;

            foreach ($candidates as $index => $candidate) {
                // Distinct strings so the memo does not answer: compile each.
                $raw = $candidate . '#' . $index;

                if ($compiler->compileReadings($raw, $parser->parse($candidate), $theme) !== null) {
                    ++$compiled;
                }
            }

            $elapsed = self::cpu() - $started;
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        self::assertSame(2000, $compiled);
        // Measured on this host: 16–31 ms of CPU (getrusage ticks in 15.6 ms
        // steps on Windows); the bound is the criterion's.
        self::assertLessThan(0.1, $elapsed, \sprintf('2 000 candidates took %.0f ms of CPU', $elapsed * 1000));
    }

    /**
     * A broader theme namespace never reaches a sub-namespace (v4's
     * `ignoredThemeKeyMap`, TASK-0051): `font-weight-bold` is no font family
     * and `text-shadow-sm` no font size, while `font-bold` and `text-sm` are
     * what they were.
     *
     * @test
     */
    public function aBroaderNamespaceDoesNotSwallowASubNamespace(): void
    {
        $registry = Registry::default();
        $theme = ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
        $theme->merge(['--text-shadow-sm' => '0 1px 0 red', '--font-weight-bold' => '700'], ThemeFlags::none());

        self::assertNull(self::css('font-weight-bold', $registry, $theme));
        self::assertNull(self::css('font-weight-thin', $registry, $theme));
        self::assertNull(self::css('text-shadow-sm', $registry, $theme));
        self::assertNull(self::css('text-shadow-2xs', $registry, $theme));
        self::assertSame(
            '--tw-font-weight:var(--font-weight-bold);font-weight:var(--font-weight-bold)',
            self::css('font-bold', $registry, $theme)
        );
        self::assertSame('font-family:var(--font-sans)', self::css('font-sans', $registry, $theme));
        self::assertNotNull(self::css('text-sm', $registry, $theme));
    }

    /** @test */
    public function everyLayoutRowIsValid(): void
    {
        self::assertSame(\count(Utilities::table()), (new Registry(Utilities::table()))->validateAll());
    }

    /** @test */
    public function customPropertiesToRegisterComeOnlyFromTheRowOrHandler(): void
    {
        $registry = new Registry([
            // A static row declaring a --tw-* property and listing what the CLI registers.
            'rev' => [
                'static' => ['--tw-divide-x-reverse' => '1'],
                'properties' => ['--tw-divide-y-reverse', '--tw-divide-x-reverse', '--tw-divide-x-reverse'],
            ],
            // A functional row declaring a --tw-* property it does not register (the CLI does
            // the same for `outline-solid`): declarations are never collected.
            'glow' => ['props' => ['--tw-glow'], 'bare' => 'integer', 'properties' => ['--tw-shadow']],
            'plain' => ['static' => ['display' => 'flex']],
        ]);

        $rev = self::compiled('rev', $registry);
        $glow = self::compiled('glow-2', $registry);
        $plain = self::compiled('plain', $registry);

        self::assertNotNull($rev);
        self::assertNotNull($glow);
        self::assertNotNull($plain);
        // The row's list, in its order, without duplicates — and nothing else.
        self::assertSame(['--tw-divide-y-reverse', '--tw-divide-x-reverse'], $rev->properties);
        self::assertSame(['--tw-shadow'], $glow->properties);
        self::assertSame([], $plain->properties);

        // A custom utility body declaring a --tw-* property registers nothing by itself.
        $registry->addCustom('spin', [new Declaration('--tw-rotate', '45deg'), new Declaration('rotate', '45deg')]);
        $spin = self::compiled('spin', $registry);

        self::assertNotNull($spin);
        self::assertSame([], $spin->properties);
    }

    /** @test */
    public function aRowMayOnlyRegisterTailwindCustomProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('properties');

        (new Registry(['x' => ['static' => ['display' => 'flex'], 'properties' => ['--x']]]))->definitions('x');
    }

    private static function css(string $candidate, ?Registry $registry = null, ?ThemeRegistry $theme = null): ?string
    {
        $compiled = self::compiled($candidate, $registry, $theme);

        return $compiled === null ? null : self::declarations($compiled->nodes);
    }

    private static function compiled(
        string $candidate,
        ?Registry $registry = null,
        ?ThemeRegistry $theme = null
    ): ?CompiledUtility {
        $registry = $registry ?? Registry::default();

        return (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            $theme ?? self::theme()
        );
    }

    /**
     * Declarations as `property:value[!important]` joined by `;`, at-rules as
     * `@name params{…}`.
     *
     * @param list<Node> $nodes
     */
    private static function declarations(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $parts[] = $node->property . ':' . $node->value . ($node->important ? '!important' : '');
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::declarations($node->nodes) . '}';
            } elseif ($node instanceof Rule) {
                $parts[] = $node->selector . '{' . self::declarations($node->nodes) . '}';
            }
        }

        return \implode(';', $parts);
    }

    /**
     * @param list<Node> $nodes
     *
     * @return list<Declaration>
     */
    private static function allDeclarations(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $found[] = $node;
            } elseif (($node instanceof AtRule || $node instanceof Rule) && $node->nodes !== null) {
                foreach (self::allDeclarations($node->nodes) as $declaration) {
                    $found[] = $declaration;
                }
            }
        }

        return $found;
    }

    /**
     * A hand-built arbitrary-property candidate, bypassing the parser.
     */
    private static function arbitraryProperty(string $property, string $value): Candidate
    {
        $value = new ArbitraryValue('x', $value);

        return new Candidate('[x]', [], false, false, Candidate::ARBITRARY, null, $value, null, $property);
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }

    private static function cpu(): float
    {
        $usage = \getrusage();

        if ($usage === false) {
            return \microtime(true);
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }
}
