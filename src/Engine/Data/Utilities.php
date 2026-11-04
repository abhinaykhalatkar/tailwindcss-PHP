<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data;

use TailwindPhp\Engine\Data\Utilities\Layout;

/**
 * The utility table (design doc 07 §Definition schema, ENG-A2): the per-family
 * parts under `Data\Utilities\`, merged in catalogue order. Each part is a
 * class with a static `rows()` returning root => row; a root may appear in one
 * part only.
 *
 * Plain arrays, no closures (R7 and opcache), built on every call — the
 * `Utility\Registry` builds once per compile and keeps what it needs.
 */
final class Utilities
{
    /**
     * The parts, in the order of design doc 08 §Utilities. Wave 4 appends one
     * class per family.
     *
     * @var list<class-string>
     */
    public const PARTS = [Layout::class];

    private function __construct()
    {
    }

    /**
     * Root => row, every part merged.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws \LogicException When two parts define the same root.
     */
    public static function table(): array
    {
        $parts = [];

        foreach (self::PARTS as $part) {
            /** @var callable(): array<string, array<string, mixed>> $rows */
            $rows = [$part, 'rows'];
            $parts[$part] = $rows();
        }

        return self::merge($parts);
    }

    /**
     * Merge per-family row sets, refusing a root that two of them define.
     *
     * @param array<string, array<string, array<string, mixed>>> $parts part name => rows
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws \LogicException
     */
    public static function merge(array $parts): array
    {
        $table = [];
        $owner = [];

        foreach ($parts as $part => $rows) {
            foreach ($rows as $root => $row) {
                $root = (string) $root;

                if (isset($owner[$root])) {
                    throw new \LogicException(\sprintf(
                        'Utility root "%s" is defined by both %s and %s.',
                        $root,
                        $owner[$root],
                        $part
                    ));
                }

                $owner[$root] = (string) $part;
                $table[$root] = $row;
            }
        }

        return $table;
    }
}
