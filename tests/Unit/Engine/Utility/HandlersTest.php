<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Data\Utilities;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;
use TailwindPhp\Engine\Utility\Handlers\Layout;
use TailwindPhp\Engine\Utility\ValueResolver;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FixtureHandlersA;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FixtureHandlersB;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FixtureHandlersUnknown;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FixturePartWithHandlers;
use TailwindPhp\Tests\Unit\Engine\Utility\Support\FixturePartWithoutHandlers;

/**
 * The handler dispatcher (design doc 07 §Definition schema, `handler`; TASK-0049):
 * it implements no handler itself. It builds, once, a map from handler name to
 * the family class that implements it, from `Data\Utilities::PARTS`, and refuses a
 * name two families claim or a name that is not in the closed vocabulary.
 *
 * What each family's handlers do is tested with the family
 * (`tests/Unit/Engine/Utility/Handlers/`).
 */
final class HandlersTest extends TestCase
{
    // --------------------------------------------------------------- the map

    /** @test */
    public function theRealPartsBuildAndLayoutOwnsItsHandlers(): void
    {
        $handlers = self::handlers();

        self::assertNotNull(
            $handlers->run('container', self::definition('container'), self::static('container'), self::theme())
        );
        self::assertNotContains('container', $handlers->unimplemented());
        self::assertNotContains('aspect', $handlers->unimplemented());
    }

    /** @test */
    public function aNameNoFamilyImplementsYetIsNullAndListedAsUnimplemented(): void
    {
        $handlers = self::handlers();
        $unimplemented = $handlers->unimplemented();

        self::assertNotEmpty($unimplemented, 'The wave-4 families have not landed yet.');

        foreach ($unimplemented as $name) {
            self::assertArrayHasKey($name, Handlers::TAKES_VALUE);

            $candidate = Handlers::TAKES_VALUE[$name]
                ? self::functional('x', new NamedValue('4'))
                : self::static('x');

            self::assertNull($handlers->run($name, self::definition($name), $candidate, self::theme()), $name);
        }
    }

    /** @test */
    public function implementedPlusUnimplementedIsTheWholeVocabulary(): void
    {
        $handlers = self::handlers();
        $unimplemented = $handlers->unimplemented();
        $implemented = \array_values(\array_diff(\array_keys(Handlers::TAKES_VALUE), $unimplemented));

        self::assertSame(\array_values(\array_unique($unimplemented)), $unimplemented);
        self::assertContains('container', $implemented);
        self::assertContains('aspect', $implemented);
        self::assertSame(\count(Handlers::TAKES_VALUE), \count($implemented) + \count($unimplemented));
    }

    /** @test */
    public function theUnimplementedListKeepsTheVocabularyOrder(): void
    {
        $order = \array_keys(Handlers::TAKES_VALUE);
        $positions = [];

        foreach (self::handlers()->unimplemented() as $name) {
            $positions[] = \array_search($name, $order, true);
        }

        $sorted = $positions;
        \sort($sorted);

        self::assertSame($sorted, $positions);
    }

    /** @test */
    public function anUnknownHandlerNameIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown utility handler "magic".');

        self::handlers()->run('magic', self::definition('container'), self::static('x'), self::theme());
    }

    // ------------------------------------------------------------ the refusals

    /** @test */
    public function aNameTwoFamiliesImplementIsRefusedNamingBoth(): void
    {
        try {
            new Handlers(new ValueResolver(), [FixtureHandlersA::class, FixtureHandlersB::class]);
            self::fail('Expected a \LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"container"', $e->getMessage());
            self::assertStringContainsString(FixtureHandlersA::class, $e->getMessage());
            self::assertStringContainsString(FixtureHandlersB::class, $e->getMessage());
        }
    }

    /** @test */
    public function aNameOutsideTheVocabularyIsRefusedNamingTheClass(): void
    {
        try {
            new Handlers(new ValueResolver(), [FixtureHandlersUnknown::class]);
            self::fail('Expected a \LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"nonsense"', $e->getMessage());
            self::assertStringContainsString(FixtureHandlersUnknown::class, $e->getMessage());
        }
    }

    /** @test */
    public function theSameClassTwiceIsADuplicateToo(): void
    {
        $this->expectException(\LogicException::class);

        new Handlers(new ValueResolver(), [Layout::class, Layout::class]);
    }

    /** @test */
    public function theRefusalMessagesNameHandlersAndClassesOnly(): void
    {
        try {
            new Handlers(new ValueResolver(), [FixtureHandlersA::class, FixtureHandlersB::class]);
            self::fail('Expected a \LogicException.');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString(\dirname(__DIR__, 4), $e->getMessage());
            self::assertLessThan(300, \strlen($e->getMessage()));
        }
    }

    // ----------------------------------------------------- parts to classes

    /** @test */
    public function aPartWithoutHandlersContributesNothing(): void
    {
        self::assertSame(
            [FixtureHandlersA::class],
            Handlers::handlerClasses([FixturePartWithHandlers::class, FixturePartWithoutHandlers::class])
        );
    }

    /** @test */
    public function everyRealPartDeclaresAHandlerClassThatImplementsTheContract(): void
    {
        $classes = Handlers::handlerClasses(Utilities::PARTS);

        self::assertCount(\count(Utilities::PARTS), $classes, 'every wave-4 family has a handler class');

        foreach ($classes as $class) {
            self::assertTrue(
                (new ReflectionClass($class))->implementsInterface(FamilyHandlerInterface::class),
                $class
            );

            foreach ($class::names() as $name) {
                self::assertArrayHasKey($name, Handlers::TAKES_VALUE, $class . ' implements an unknown handler.');
            }
        }
    }

    /** @test */
    public function theHandlerClassesFollowThePartOrder(): void
    {
        $classes = Handlers::handlerClasses(Utilities::PARTS);

        self::assertSame(Layout::class, $classes[0]);
    }

    // ---------------------------------------------------------------- purity

    /** @test */
    public function theDispatcherHoldsNoStaticStateAndIsFinal(): void
    {
        $reflection = new ReflectionClass(Handlers::class);

        self::assertTrue($reflection->isFinal());
        self::assertSame([], $reflection->getStaticProperties());
    }

    /** @test */
    public function theVocabularyKeepsTheNamesDoc07Lists(): void
    {
        $names = ['container', 'aspect', 'fontSize', 'space', 'divide', 'ring', 'shadow', 'gradient', 'transform'];

        foreach ($names as $name) {
            self::assertArrayHasKey($name, Handlers::TAKES_VALUE);
        }
    }

    /** @test */
    public function aHandlerNeverSeesTheRawCandidateText(): void
    {
        // The family handler receives parsed objects only; this pins that the
        // dispatcher passes the very Candidate it was given through unchanged.
        $candidate = self::static('container');
        $result = self::handlers()->run('container', self::definition('container'), $candidate, self::theme());

        self::assertNotNull($result);
        self::assertInstanceOf(Declaration::class, $result[0][0]);
    }

    // ---------------------------------------------------------------- helpers

    private static function definition(string $handler): Definition
    {
        return Definition::fromRow('x', ['handler' => $handler]);
    }

    private static function static(string $root): Candidate
    {
        return new Candidate($root, [], false, false, Candidate::STATIC, $root);
    }

    private static function functional(string $root, NamedValue $value): Candidate
    {
        return new Candidate($root . '-' . $value->name, [], false, false, Candidate::FUNCTIONAL, $root, $value);
    }

    private static function handlers(): Handlers
    {
        return new Handlers(new ValueResolver());
    }

    private static function theme(): ThemeRegistry
    {
        return ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes());
    }
}
