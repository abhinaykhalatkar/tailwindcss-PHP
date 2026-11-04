<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Data\Utilities;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Handlers\FamilyHandlerInterface;

/**
 * The dispatcher for utilities whose output is not "property := resolved
 * value" (design doc 07 §Definition schema, `handler`). A row names a handler;
 * the compiler calls {@see self::run()} with the definition, the candidate
 * interpretation and the theme, and gets `[nodes, usedVars]` back — or null
 * when the handler does not take this candidate (the next definition is tried).
 *
 * It implements no handler itself (TASK-0049). It builds, once and in order, a
 * map from handler name to the family class that implements it, by asking each
 * part of {@see Utilities::PARTS} for its `HANDLERS` class and each class for its
 * {@see FamilyHandlerInterface::names()}. A name two families claim, or one that
 * is not in {@see self::TAKES_VALUE}, is a `\LogicException` when the dispatcher
 * is built. A name in the vocabulary that no family implements yet answers
 * null, and is listed by {@see self::unimplemented()} for the reports.
 *
 * Handlers never read raw candidate text (T-15): only the parsed value
 * objects, the definition and the theme. A handler class is created on first
 * use, once per dispatcher, and holds no static state.
 */
final class Handlers
{
    /**
     * Handler name => whether it takes a value (so its root also matches
     * `<root>-…`). The closed vocabulary of names a row may use; a family
     * implements some of them in its own class under `Utility\Handlers\`.
     *
     * @var array<string, bool>
     */
    public const TAKES_VALUE = [
        'container' => false,
        'aspect' => true,
        'fontSize' => true,
        'space' => true,
        'divide' => true,
        'ring' => true,
        'shadow' => true,
        'gradient' => true,
        'transform' => true,
        'truncate' => false,
        'divideReverse' => false,
        'spaceReverse' => false,
        'outlineHidden' => false,
        'lineClamp' => true,
        'size' => true,
        'gridCols' => true,
        'content' => true,
        'transition' => true,
        'animate' => true,
        'filter' => true,
        'backdropFilter' => true,
        'outline' => true,
        'border' => true,
    ];

    /**
     * @var ValueResolver
     */
    private $resolver;

    /**
     * @var array<string, class-string<FamilyHandlerInterface>> Handler name => the class that implements it.
     */
    private $classes = [];

    /**
     * @var array<class-string<FamilyHandlerInterface>, FamilyHandlerInterface>
     */
    private $instances = [];

    /**
     * @param list<class-string<FamilyHandlerInterface>>|null $families The handler classes to build the map from;
     *                                                                  by default those of `Data\Utilities::PARTS`.
     *
     * @throws \LogicException When two classes implement the same name, or one implements a name that is not in
     *                         {@see self::TAKES_VALUE}.
     */
    public function __construct(ValueResolver $resolver, ?array $families = null)
    {
        $this->resolver = $resolver;

        foreach ($families ?? self::handlerClasses(Utilities::PARTS) as $class) {
            foreach ($class::names() as $name) {
                if (!isset(self::TAKES_VALUE[$name])) {
                    throw new \LogicException(\sprintf(
                        'Handler "%s" of %s is not in the handler vocabulary.',
                        $name,
                        $class
                    ));
                }

                if (isset($this->classes[$name])) {
                    throw new \LogicException(\sprintf(
                        'Utility handler "%s" is implemented by both %s and %s.',
                        $name,
                        $this->classes[$name],
                        $class
                    ));
                }

                $this->classes[$name] = $class;
            }
        }
    }

    /**
     * The handler classes of a list of parts, in part order; a part whose
     * `HANDLERS` is null contributes none.
     *
     * @param list<class-string> $parts
     *
     * @return list<class-string<FamilyHandlerInterface>>
     */
    public static function handlerClasses(array $parts): array
    {
        $classes = [];

        foreach ($parts as $part) {
            $class = \constant($part . '::HANDLERS');

            if ($class !== null) {
                /** @var class-string<FamilyHandlerInterface> $class */
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Run handler $name.
     *
     * @return array{0: list<Node>, 1: list<string>}|null
     *
     * @throws \InvalidArgumentException When $name is not a handler.
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array
    {
        if (!isset(self::TAKES_VALUE[$name])) {
            throw new \InvalidArgumentException(\sprintf('Unknown utility handler "%s".', $name));
        }

        if (!isset($this->classes[$name])) {
            // TODO M2-wave4: the family that needs this handler implements it.
            return null;
        }

        return $this->instance($this->classes[$name])->run($name, $definition, $candidate, $theme);
    }

    /**
     * The names of the vocabulary no family implements yet, in vocabulary order.
     *
     * @return list<string>
     */
    public function unimplemented(): array
    {
        $names = [];

        foreach (\array_keys(self::TAKES_VALUE) as $name) {
            if (!isset($this->classes[$name])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param class-string<FamilyHandlerInterface> $class
     */
    private function instance(string $class): FamilyHandlerInterface
    {
        if (!isset($this->instances[$class])) {
            $this->instances[$class] = new $class($this->resolver);
        }

        return $this->instances[$class];
    }
}
