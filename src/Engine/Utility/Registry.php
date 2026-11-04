<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\RootSetInterface;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Data\Utilities;

/**
 * The utility roots and their definitions (design doc 07 §Phase 3b, doc 13
 * #16), the {@see RootSetInterface} the candidate parser splits utilities
 * with (ENG-A3).
 *
 * Roots live in a trie keyed by `-`-separated segments, built once from the
 * table: matching a utility walks its segments once (O(length), T-05) and
 * collects the roots on the way — the whole utility when it is a root, then
 * every functional root it continues with `-`, longest first, exactly as
 * `tests/Support/StaticRootSet` specifies the contract. Definitions are
 * validated lazily, the first time a root is compiled; {@see self::validateAll()}
 * builds every one (tests, tooling).
 */
final class Registry implements RootSetInterface
{
    private const TERMINAL = "\0root";

    /**
     * @var array<string, mixed> Segment => child node; a node holds
     *                           {@see self::TERMINAL} => functional? when a root ends there.
     */
    private $trie = [];

    /**
     * @var array<string, array<string, mixed>> Root => row, not yet built.
     */
    private $rows = [];

    /**
     * @var array<string, list<Definition>> Root => definitions, built.
     */
    private $built = [];

    /**
     * @var array<string, list<Definition>> Root => `@utility` definitions.
     */
    private $custom = [];

    /**
     * @param array<string, array<string, mixed>> $table Root => row.
     */
    public function __construct(array $table)
    {
        foreach ($table as $root => $row) {
            $root = (string) $root;
            $this->rows[$root] = $row;
            $this->insert($root, Definition::rowIsFunctional($row));
        }
    }

    /**
     * The registry of the built-in table ({@see Utilities::table()}).
     */
    public static function default(): self
    {
        return new self(Utilities::table());
    }

    public function matchRoots(string $utility): array
    {
        $node = $this->trie;
        $functional = [];
        $start = 0;
        $length = \strlen($utility);

        while (true) {
            $dash = \strpos($utility, '-', $start);
            $segment = $dash === false
                ? (string) \substr($utility, $start)
                : (string) \substr($utility, $start, $dash - $start);

            if (!isset($node[$segment]) || !\is_array($node[$segment])) {
                break;
            }

            $node = $node[$segment];

            if ($dash === false) {
                // The whole utility: a root of either kind.
                if (isset($node[self::TERMINAL])) {
                    \array_unshift($functional, $utility);

                    return $functional;
                }

                break;
            }

            if (($node[self::TERMINAL] ?? false) === true && $dash < $length) {
                // A functional root followed by `-`: longer roots come first.
                \array_unshift($functional, (string) \substr($utility, 0, $dash));
            }

            $start = $dash + 1;
        }

        return $functional;
    }

    /**
     * Whether $root is a known root (built-in or `@utility`).
     */
    public function has(string $root): bool
    {
        return isset($this->rows[$root]) || isset($this->custom[$root]);
    }

    /**
     * The definitions of $root to try, in order: the built-in row's
     * (sub-)definitions, then any `@utility` added for the same name.
     *
     * @return list<Definition>
     *
     * @throws \InvalidArgumentException When the table row is invalid.
     */
    public function definitions(string $root): array
    {
        if (!isset($this->built[$root])) {
            $this->built[$root] = isset($this->rows[$root])
                ? Definition::fromRow($root, $this->rows[$root])->definitions()
                : [];
        }

        return \array_merge($this->built[$root], $this->custom[$root] ?? []);
    }

    /**
     * Register a static `@utility` (TASK-0033's directive handler). Its body
     * is emitted as written; the functional `--value()` form is M3.
     *
     * @param list<Node> $nodes
     *
     * @throws \InvalidArgumentException When $root is not a valid root name.
     */
    public function addCustom(string $root, array $nodes): void
    {
        $this->custom[$root][] = Definition::custom($root, $nodes);

        if (!isset($this->rows[$root])) {
            $this->insert($root, false);
        }
    }

    /**
     * Build every definition of the table (the build-time check of the rows).
     *
     * @return int The number of roots.
     *
     * @throws \InvalidArgumentException At the first invalid row.
     */
    public function validateAll(): int
    {
        foreach (\array_keys($this->rows) as $root) {
            $this->definitions((string) $root);
        }

        return \count($this->rows);
    }

    /**
     * @return list<string> Every root, table order then `@utility` additions.
     */
    public function roots(): array
    {
        $roots = [];

        foreach (\array_keys($this->rows) as $root) {
            $roots[] = (string) $root;
        }

        foreach (\array_keys($this->custom) as $root) {
            if (!isset($this->rows[$root])) {
                $roots[] = (string) $root;
            }
        }

        return $roots;
    }

    private function insert(string $root, bool $functional): void
    {
        $node = &$this->trie;

        foreach (\explode('-', $root) as $segment) {
            if (!isset($node[$segment]) || !\is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        $node[self::TERMINAL] = ($node[self::TERMINAL] ?? false) === true || $functional;
        unset($node);
    }
}
