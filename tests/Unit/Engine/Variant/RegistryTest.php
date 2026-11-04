<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Variant;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Variant\Definition;
use TailwindPhp\Engine\Variant\Registry;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * The variant registry: the table, the breakpoints built from the theme, the
 * compound `group-*` and `peer-*` rows expanded for every pseudo-class, and the
 * custom variants a stylesheet adds (design doc 07 §Phase 3c).
 */
final class RegistryTest extends TestCase
{
    private const BREAKPOINTS = [
        'sm' => '40rem',
        'md' => '48rem',
        'lg' => '64rem',
        'xl' => '80rem',
        '2xl' => '96rem',
    ];

    // ------------------------------------------------------------------ table

    /** @test */
    public function aTableVariantIsLookedUpByName(): void
    {
        $hover = self::registry()->get('hover');

        self::assertNotNull($hover);
        self::assertSame(['&:hover'], $hover->selectors());
        self::assertSame('(hover: hover)', $hover->media());
        self::assertSame(112000, self::registry()->order('hover'));
    }

    /** @test */
    public function anUnknownNameIsNull(): void
    {
        self::assertNull(self::registry()->get('nope'));
        self::assertNull(self::registry()->get(''));
        self::assertNull(self::registry()->get('group'));
        self::assertNull(self::registry()->get('hover:focus'));
    }

    /** @test */
    public function anUnknownNameHasNoOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::registry()->order('nope');
    }

    /** @test */
    public function aBadTableRowIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Registry(['x' => ['selector' => 'no-ampersand', 'order' => 1]]);
    }

    /** @test */
    public function lookupIsCaseSensitive(): void
    {
        self::assertNull(self::registry()->get('Hover'));
        self::assertNull(self::registry()->get('HOVER'));
    }

    // ------------------------------------------------------------ breakpoints

    /** @test */
    public function breakpointsAreBuiltFromTheTheme(): void
    {
        $registry = self::registry();

        self::assertSame('(width >= 48rem)', self::def($registry, 'md')->media());
        self::assertSame([], self::def($registry, 'md')->selectors());
        self::assertSame('(width < 48rem)', self::def($registry, 'max-md')->media());
        self::assertSame('(width >= 48rem)', self::def($registry, 'min-md')->media());
        self::assertSame('(width >= 96rem)', self::def($registry, '2xl')->media());
        self::assertSame('(width < 96rem)', self::def($registry, 'max-2xl')->media());
    }

    /** @test */
    public function namedBreakpointsSortAscendingAndMaxDescending(): void
    {
        $registry = self::registry();
        $min = [];
        $max = [];

        foreach (\array_keys(self::BREAKPOINTS) as $name) {
            $min[$name] = $registry->order($name);
            $max[$name] = $registry->order('max-' . $name);
        }

        $ascending = $min;
        \asort($ascending);
        $descending = $max;
        \arsort($descending);

        self::assertSame(['sm', 'md', 'lg', 'xl', '2xl'], \array_keys($ascending));
        self::assertSame(['sm', 'md', 'lg', 'xl', '2xl'], \array_keys($descending));
    }

    /** @test */
    public function breakpointsSortByValueWhateverOrderTheThemeListsThem(): void
    {
        $registry = new Registry(Variants::table(), ['xl' => '80rem', 'sm' => '40rem', 'md' => '48rem']);

        self::assertLessThan($registry->order('md'), $registry->order('sm'));
        self::assertLessThan($registry->order('xl'), $registry->order('md'));
        self::assertLessThan($registry->order('max-md'), $registry->order('max-xl'));
    }

    /** @test */
    public function breakpointsInDifferentUnitsKeepThemeOrder(): void
    {
        $registry = new Registry(Variants::table(), ['a' => '600px', 'b' => '40rem']);

        self::assertLessThan($registry->order('b'), $registry->order('a'));
    }

    /** @test */
    public function theCliOrderOfTheBreakpointFamiliesIsKept(): void
    {
        $registry = self::registry();

        // contrast-less < max-* < min-* < portrait, with the arbitrary forms first in each family.
        self::assertGreaterThan($registry->order('contrast-less'), self::functionalRow($registry, 'max')->order());
        self::assertLessThan($registry->order('max-2xl'), self::functionalRow($registry, 'max')->order());
        self::assertLessThan($registry->order('max-sm'), $registry->order('max-2xl'));
        self::assertLessThan($registry->order('min-sm'), $registry->order('max-sm'));
        self::assertLessThan($registry->order('sm'), self::functionalRow($registry, 'min')->order());
        self::assertSame($registry->order('sm'), $registry->order('min-sm'));
        self::assertLessThan($registry->order('portrait'), $registry->order('2xl'));
    }

    /**
     * @test
     *
     * @dataProvider unsafeBreakpoints
     *
     * @param array<string, string> $breakpoints
     */
    public function anUnusableBreakpointIsSkippedNotEmitted(array $breakpoints, string $skipped): void
    {
        $registry = new Registry(Variants::table(), $breakpoints + ['md' => '48rem']);

        self::assertNull($registry->get($skipped));
        self::assertNull($registry->get('max-' . $skipped));
        self::assertNotNull($registry->get('md'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function unsafeBreakpoints(): array
    {
        return [
            'a name with a colon' => [['a:b' => '10rem'], 'a:b'],
            'a name with a slash' => [['a/b' => '10rem'], 'a/b'],
            'a name with a space' => [['a b' => '10rem'], 'a b'],
            'an empty name' => [['' => '10rem'], ''],
            'a name with brackets' => [['a[b]' => '10rem'], 'a[b]'],
            'an empty value' => [['wide' => ''], 'wide'],
            'a value with a brace' => [['wide' => '10rem){}a{'], 'wide'],
            'a value with a semicolon' => [['wide' => '10rem;x'], 'wide'],
            'a value with a comment' => [['wide' => '10rem/*'], 'wide'],
            'a value with a newline' => [['wide' => "10rem\n1"], 'wide'],
            'an unbalanced value' => [['wide' => '(10rem'], 'wide'],
        ];
    }

    /** @test */
    public function withoutBreakpointsThereAreNoNamedBreakpointVariants(): void
    {
        $registry = new Registry(Variants::table());

        self::assertNull($registry->get('md'));
        self::assertNull($registry->get('max-md'));
        self::assertNotNull($registry->get('hover'));
    }

    // ---------------------------------------------------------- compound rows

    /** @test */
    public function groupAndPeerAreExpandedForEveryPseudoClass(): void
    {
        $registry = self::registry();
        $group = $registry->get('group-hover');
        $peer = $registry->get('peer-checked');

        self::assertNotNull($group);
        self::assertSame(['&:is(:where(.group):hover *)'], $group->selectors());
        self::assertSame('(hover: hover)', $group->media());
        self::assertSame('group', $group->compound());
        self::assertSame('&:hover', $group->inner());
        self::assertNotNull($peer);
        self::assertSame(['&:is(:where(.peer):checked ~ *)'], $peer->selectors());
        self::assertNull($peer->media());
    }

    /** @test */
    public function compoundOpenAndInertKeepTheirInnerSelectorWhole(): void
    {
        $registry = self::registry();

        self::assertSame(
            ['&:is(:where(.group):is([open], :popover-open, :open) *)'],
            self::def($registry, 'group-open')->selectors()
        );
        self::assertSame(
            ['&:is(:where(.peer):is([inert], [inert] *) ~ *)'],
            self::def($registry, 'peer-inert')->selectors()
        );
    }

    /** @test */
    public function compoundVariantsExistForPseudoClassesOnly(): void
    {
        $registry = self::registry();

        foreach (['hover', 'focus', 'first', 'odd', 'checked', 'open', 'inert', 'read-only', 'focus-within'] as $name) {
            self::assertNotNull($registry->get('group-' . $name), 'group-' . $name);
            self::assertNotNull($registry->get('peer-' . $name), 'peer-' . $name);
        }

        // Pseudo-elements, media variants, child selectors and breakpoints are not compoundable.
        $others = ['before', 'after', 'marker', 'selection', 'file', 'dark', 'print', 'motion-safe', '*', '**', 'md'];

        foreach ($others as $name) {
            self::assertNull($registry->get('group-' . $name), 'group-' . $name);
            self::assertNull($registry->get('peer-' . $name), 'peer-' . $name);
        }
    }

    /** @test */
    public function thereIsOneGroupAndOnePeerVariantPerPseudoClass(): void
    {
        $registry = self::registry();
        $groups = 0;
        $peers = 0;

        foreach (\array_keys($registry->all()) as $name) {
            if (\strncmp((string) $name, 'group-', 6) === 0) {
                ++$groups;
            }

            if (\strncmp((string) $name, 'peer-', 5) === 0) {
                ++$peers;
            }
        }

        self::assertSame(34, $groups);
        self::assertSame(34, $peers);
    }

    /** @test */
    public function compoundOrderIsTheTemplatesPlusTheInnerRank(): void
    {
        $registry = self::registry();

        self::assertSame(4085, $registry->order('group-first'));
        self::assertSame(4112, $registry->order('group-hover'));
        self::assertSame(5112, $registry->order('peer-hover'));
        self::assertLessThan($registry->order('group-hover'), $registry->order('group-first'));
        self::assertLessThan($registry->order('peer-first'), $registry->order('group-inert'));
        self::assertLessThan($registry->order('first-letter'), $registry->order('peer-inert'));
        self::assertGreaterThan($registry->order('**'), $registry->order('group-first'));
    }

    /** @test */
    public function theCompoundTemplatesAreAvailableToTheApplier(): void
    {
        $registry = self::registry();

        self::assertSame(['&:is({inner} *)'], self::tpl($registry, 'group')->selectors());
        self::assertSame(['&:is({inner} ~ *)'], self::tpl($registry, 'peer')->selectors());
        self::assertNull($registry->compound('hover'));
        self::assertNull($registry->compound('nope'));
    }

    // ------------------------------------------------------------- functional

    /** @test */
    public function minAndMaxAreFunctionalRowsNotVariantsOfTheirOwn(): void
    {
        $registry = self::registry();

        self::assertNull($registry->get('min'));
        self::assertNull($registry->get('max'));
        self::assertSame('(width < {value})', self::functionalRow($registry, 'max')->media());
        self::assertSame('(width >= {value})', self::functionalRow($registry, 'min')->media());
        self::assertNull($registry->functional('hover'));
        self::assertSame(137000, self::functionalRow($registry, 'max')->order());
        self::assertSame(144000, self::functionalRow($registry, 'min')->order());
    }

    // -------------------------------------------------------------------- all

    /** @test */
    public function allListsTheConcreteVariantsOnly(): void
    {
        $all = self::registry()->all();

        $names = ['hover', 'dark', 'md', 'max-md', 'min-md', 'group-hover', 'peer-focus', 'before', '*', '**'];

        foreach ($names as $name) {
            self::assertArrayHasKey($name, $all, $name);
            self::assertInstanceOf(Definition::class, $all[$name]);
            self::assertSame($name, $all[$name]->name());
        }

        foreach (['group', 'peer', 'min', 'max'] as $name) {
            self::assertArrayNotHasKey($name, $all, $name . ' is a template, not a variant.');
        }
    }

    // ----------------------------------------------------------------- custom

    /** @test */
    public function aCustomVariantIsAddedByName(): void
    {
        $registry = self::registry();
        $definition = Definition::fromRow('theme-dark', ['selector' => '&:where([data-theme=dark] *)', 'order' => 0]);

        $registry->addCustom('theme-dark', $definition->withNameAndOrder('theme-dark', $registry->nextOrder()));

        self::assertNotNull($registry->get('theme-dark'));
        self::assertSame(['&:where([data-theme=dark] *)'], self::def($registry, 'theme-dark')->selectors());
        self::assertGreaterThan($registry->order('noscript'), $registry->order('theme-dark'));
        self::assertContains('theme-dark', $registry->matchRoots('theme-dark'));
    }

    /** @test */
    public function aCustomVariantMayReplaceABuiltInOne(): void
    {
        $registry = self::registry();
        $order = $registry->order('dark');

        $registry->addCustom(
            'dark',
            Definition::fromRow('dark', ['selector' => '&:where(.dark, .dark *)', 'order' => $order])
        );

        self::assertSame(['&:where(.dark, .dark *)'], self::def($registry, 'dark')->selectors());
        self::assertNull(self::def($registry, 'dark')->media());
        self::assertSame($order, $registry->order('dark'));
    }

    /** @test */
    public function aCustomVariantTakesTheNameItIsAddedUnder(): void
    {
        $registry = self::registry();

        $registry->addCustom('mine', Definition::fromRow('other', ['media' => 'print', 'order' => 5]));

        self::assertSame('mine', self::def($registry, 'mine')->name());
        self::assertNull($registry->get('other'));
    }

    /** @test */
    public function aCustomNameWithAColonOrSpaceIsRefused(): void
    {
        $registry = self::registry();
        $definition = Definition::fromRow('x', ['media' => 'print', 'order' => 5]);

        foreach (['a:b', 'a b', '', 'a/b', 'a[b]'] as $name) {
            try {
                $registry->addCustom($name, $definition);
                self::fail('"' . $name . '" should have been refused.');
            } catch (InvalidArgumentException $e) {
                self::assertNull($registry->get($name));
            }
        }
    }

    /** @test */
    public function nextOrderIsPastEveryExistingOrder(): void
    {
        $registry = self::registry();
        $highest = 0;

        foreach ($registry->all() as $definition) {
            $highest = \max($highest, $definition->order());
        }

        self::assertGreaterThan($highest, $registry->nextOrder());
    }

    // ------------------------------------------------------------- root split

    /**
     * @test
     *
     * @dataProvider roots
     *
     * @param list<string> $expected
     */
    public function matchRootsReportsExactNamesThenFunctionalRoots(string $text, array $expected): void
    {
        self::assertSame($expected, self::registry()->matchRoots($text));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function roots(): array
    {
        return [
            'a plain variant is its own root' => ['hover', ['hover']],
            'a breakpoint is its own root' => ['md', ['md']],
            'a child selector is a root' => ['*', ['*']],
            'group splits' => ['group-hover', ['group']],
            'peer splits' => ['peer-checked', ['peer']],
            'a named group splits at the first root' => ['group-hover-x', ['group']],
            'max splits' => ['max-md', ['max']],
            'min splits' => ['min-lg', ['min']],
            'a template alone is not a root' => ['group', []],
            'min alone is not a root' => ['min', []],
            'an unknown name has no root' => ['zzz', []],
            'the empty string has none' => ['', []],
            'case matters' => ['Group-hover', []],
            'a prefix that is not followed by a dash' => ['groupie', []],
        ];
    }

    /** @test */
    public function theCandidateParserSplitsGroupHoverIntoNameValueAndModifier(): void
    {
        $parser = new Parser(new StaticRootSet(['flex'], []), null, self::registry());

        $candidates = $parser->parse('group-hover/item:flex');

        self::assertNotEmpty($candidates);
        $variant = $candidates[0]->variants[0];

        self::assertSame('group', $variant->name);
        self::assertSame('hover', $variant->value);
        self::assertNotNull($variant->modifier);
        self::assertSame('item', $variant->modifier->value);
    }

    /** @test */
    public function theCandidateParserKeepsAPlainVariantWhole(): void
    {
        $parser = new Parser(new StaticRootSet(['flex'], []), null, self::registry());

        $variant = $parser->parse('hover:flex')[0]->variants[0];

        self::assertSame('hover', $variant->name);
        self::assertNull($variant->value);
    }

    /** @test */
    public function theCandidateParserSplitsAnArbitraryBreakpoint(): void
    {
        $parser = new Parser(new StaticRootSet(['flex'], []), null, self::registry());

        $variant = $parser->parse('max-[600px]:flex')[0]->variants[0];

        self::assertSame('max', $variant->name);
        self::assertSame('600px', $variant->arbitrary);
    }

    // ---------------------------------------------------------------- helpers

    private static function def(Registry $registry, string $name): Definition
    {
        $definition = $registry->get($name);

        self::assertNotNull($definition, $name . ' is not a variant.');

        return $definition;
    }

    private static function functionalRow(Registry $registry, string $root): Definition
    {
        $definition = $registry->functional($root);

        self::assertNotNull($definition, $root . ' is not a functional row.');

        return $definition;
    }

    private static function tpl(Registry $registry, string $root): Definition
    {
        $definition = $registry->compound($root);

        self::assertNotNull($definition, $root . ' is not a compound template.');

        return $definition;
    }

    private static function registry(): Registry
    {
        return new Registry(Variants::table(), self::BREAKPOINTS);
    }
}
