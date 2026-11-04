<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Utility;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeFlags;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;
use TailwindPhp\Engine\Utility\Handlers;
use TailwindPhp\Engine\Utility\ValueResolver;

/**
 * The named handlers: `container` reproduces the CLI's nested shape, `aspect`
 * writes ratios; every other handler doc 07 lists is a stub that takes
 * nothing yet.
 */
final class HandlersTest extends TestCase
{
    /** @test */
    public function containerHasTheCliShape(): void
    {
        $container = self::definition('container');
        $result = self::handlers()->run('container', $container, self::static('container'), self::theme());

        self::assertNotNull($result);
        [$nodes, $used] = $result;

        self::assertSame([], $used);
        self::assertCount(6, $nodes);
        self::assertInstanceOf(Declaration::class, $nodes[0]);
        self::assertSame(['width', '100%'], [$nodes[0]->property, $nodes[0]->value]);

        $media = [];

        foreach (\array_slice($nodes, 1) as $node) {
            self::assertInstanceOf(AtRule::class, $node);
            self::assertNotNull($node->nodes);
            self::assertCount(1, $node->nodes);
            $declaration = $node->nodes[0];
            self::assertInstanceOf(Declaration::class, $declaration);
            $media[] = $node->name . ' ' . $node->params . ' ' . $declaration->property . ':' . $declaration->value;
        }

        // `golden/utilities/edge-values.css`, `.container`.
        self::assertSame([
            'media (width >= 40rem) max-width:40rem',
            'media (width >= 48rem) max-width:48rem',
            'media (width >= 64rem) max-width:64rem',
            'media (width >= 80rem) max-width:80rem',
            'media (width >= 96rem) max-width:96rem',
        ], $media);
    }

    /** @test */
    public function containerBreakpointsAreSortedSmallestFirst(): void
    {
        $theme = self::theme();
        $theme->merge(['--breakpoint-xs' => '30rem', '--breakpoint-3xl' => '120rem'], new ThemeFlags());
        $result = self::handlers()->run('container', self::definition('container'), self::static('container'), $theme);

        self::assertNotNull($result);

        $params = [];

        foreach (\array_slice($result[0], 1) as $node) {
            self::assertInstanceOf(AtRule::class, $node);
            $params[] = $node->params;
        }

        self::assertSame('(width >= 30rem)', $params[0]);
        self::assertSame('(width >= 120rem)', $params[\count($params) - 1]);
    }

    /** @test */
    public function containerTakesNoValue(): void
    {
        $valued = self::functional('container', new NamedValue('4'));
        $negative = new Candidate('-container', [], false, true, Candidate::STATIC, 'container');

        self::assertNull(self::handlers()->run('container', self::definition('container'), $valued, self::theme()));
        self::assertNull(self::handlers()->run('container', self::definition('container'), $negative, self::theme()));
    }

    /** @test */
    public function aspectWritesARatioAndDelegatesTheRest(): void
    {
        $definition = Definition::fromRow('aspect', [
            'handler' => 'aspect',
            'props' => ['aspect-ratio'],
            'named' => ['square' => '1 / 1'],
            'theme' => ['aspect'],
            'fraction' => true,
            'arbitrary' => true,
        ]);
        $handlers = self::handlers();
        $theme = self::theme();
        $sixteenByNine = self::functional('aspect', new NamedValue('16/9', true));
        $ratio = $handlers->run('aspect', $definition, $sixteenByNine, $theme);
        $named = $handlers->run('aspect', $definition, self::functional('aspect', new NamedValue('square')), $theme);
        $themed = $handlers->run('aspect', $definition, self::functional('aspect', new NamedValue('video')), $theme);

        self::assertNotNull($ratio);
        self::assertNotNull($named);
        self::assertNotNull($themed);
        self::assertSame('16/9', self::value($ratio[0][0]));
        self::assertSame('1 / 1', self::value($named[0][0]));
        self::assertSame('var(--aspect-video)', self::value($themed[0][0]));
        self::assertSame(['--aspect-video'], $themed[1]);
        $leadingZero = self::functional('aspect', new NamedValue('01/2', true));
        $bare = self::functional('aspect', new NamedValue('3'));

        self::assertNull($handlers->run('aspect', $definition, $leadingZero, $theme));
        self::assertNull($handlers->run('aspect', $definition, $bare, $theme));
    }

    /** @test */
    public function everyListedHandlerExistsAndTheStubsTakeNothing(): void
    {
        $handlers = self::handlers();
        $stubs = 0;

        foreach (Handlers::TAKES_VALUE as $name => $takesValue) {
            if (\in_array($name, Handlers::IMPLEMENTED, true)) {
                continue;
            }

            ++$stubs;
            $candidate = $takesValue
                ? self::functional('x', new NamedValue('4'))
                : self::static('x');

            self::assertNull($handlers->run($name, self::definition($name), $candidate, self::theme()), $name);
        }

        self::assertSame(\count(Handlers::TAKES_VALUE) - \count(Handlers::IMPLEMENTED), $stubs);
    }

    /** @test */
    public function anUnknownHandlerIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::handlers()->run('magic', self::definition('container'), self::static('x'), self::theme());
    }

    private static function value(\TailwindPhp\Engine\Css\Node\Node $node): string
    {
        self::assertInstanceOf(Declaration::class, $node);

        return $node->value;
    }

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
