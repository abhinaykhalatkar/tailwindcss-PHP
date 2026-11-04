<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use TailwindPhp\Engine\Candidate\RootSetInterface;

/**
 * A fixed set of utility (or variant) roots for tests — the stand-in for
 * `Utility\Registry` (ENG-A3).
 *
 * Static roots match only the whole utility; functional roots match the whole
 * utility (a functional utility used without a value) or any utility that
 * continues with `-`. A root may be both (`flex`, `border`). Matching walks
 * the `-` positions from the right, so results come out longest first, just
 * as the registry's trie returns them.
 */
final class StaticRootSet implements RootSetInterface
{
    /**
     * @var array<string, true>
     */
    private $static;

    /**
     * @var array<string, true>
     */
    private $functional;

    /**
     * @param list<string> $static
     * @param list<string> $functional
     */
    public function __construct(array $static, array $functional)
    {
        $this->static = \array_fill_keys($static, true);
        $this->functional = \array_fill_keys($functional, true);
    }

    /**
     * The roots used by the candidate tests.
     */
    public static function forTests(): self
    {
        return new self(
            ['flex', 'block', 'hidden', 'underline', 'text-center', 'border', 'uppercase', 'container', 'shadow'],
            [
                'p', 'px', 'm', 'mt', 'w', 'h', 'bg', 'text', 'border', 'border-t', 'flex', 'grid-cols',
                'opacity', 'inset', 'z', 'shadow', 'ring', 'content', 'translate-x', 'aspect', 'font',
            ]
        );
    }

    public function matchRoots(string $utility): array
    {
        $roots = [];

        if (isset($this->static[$utility]) || isset($this->functional[$utility])) {
            $roots[] = $utility;
        }

        $dash = \strrpos($utility, '-');

        while ($dash !== false && $dash > 0) {
            $root = (string) \substr($utility, 0, $dash);

            if (isset($this->functional[$root])) {
                $roots[] = $root;
            }

            $dash = \strrpos($root, '-');
        }

        return $roots;
    }
}
