<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

/**
 * Read access to the independent T1 inventory (decision ENG-A12).
 *
 * The data is `tests/Conformance/inventory/t1.php`, written by hand from design
 * doc 08. This class is the only way the rest of the conformance harness reads
 * it, so the schema lives in one place: `ConformanceTest` takes the 98 %
 * denominator from {@see self::roots()} and {@see self::expectedForms()},
 * `tools/generate-corpus.php` checks its coverage against them, and the utility
 * table is checked against the inventory — never the other way round.
 *
 * **It depends on nothing under `src/Engine`, and must not.** An inventory that
 * could see the implementation could be quietly made to agree with it.
 */
final class Inventory
{
    /**
     * The families, in the order doc 08 presents them.
     */
    public const FAMILIES = [
        'layout',
        'flexbox-grid',
        'spacing-sizing',
        'typography',
        'backgrounds-borders',
        'effects-filters',
        'tables-transitions-transforms',
        'interactivity-svg-accessibility',
        'arbitrary-property',
    ];

    /**
     * The candidate forms, in canonical order.
     */
    public const FORMS = [
        'static',
        'theme',
        'bare',
        'fraction',
        'arbitrary',
        'var',
        'negative',
        'important',
        'modifier',
    ];

    /**
     * How an unadorned value reads (design doc 07 §Value resolution rules).
     */
    public const BARE_KINDS = [
        'spacing-multiplier',
        'integer',
        'percentage',
        'angle',
        'duration',
        'line-count',
        'keyword',
    ];

    /**
     * The one entry that is not a utility name: `[mask-type:luminance]`.
     */
    public const ARBITRARY_PROPERTY = '[property:value]';

    /**
     * @var string
     */
    private $file;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private $entries;

    /**
     * @param string|null $file The inventory file; the committed one by default.
     */
    public function __construct(?string $file = null)
    {
        $this->file = $file ?? self::defaultFile();
    }

    public static function defaultFile(): string
    {
        return \dirname(__DIR__) . '/inventory/t1.php';
    }

    /**
     * Every root, in file order.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return \array_map('strval', \array_keys($this->entries()));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When `$root` is not in the inventory.
     */
    public function entry(string $root): array
    {
        $entries = $this->entries();

        if (!isset($entries[$root])) {
            throw new \InvalidArgumentException(
                \sprintf('"%s" is not a root of the T1 inventory.', \substr($root, 0, 60))
            );
        }

        return $entries[$root];
    }

    public function family(string $root): string
    {
        return (string) $this->entry($root)['family'];
    }

    /**
     * Roots grouped by family, families in doc 08 order.
     *
     * @return array<string, list<string>>
     */
    public function families(): array
    {
        $families = [];

        foreach (self::FAMILIES as $family) {
            $families[$family] = [];
        }

        foreach ($this->roots() as $root) {
            $families[$this->family($root)][] = $root;
        }

        return $families;
    }

    /**
     * The candidate forms that count toward the 98 % for a root.
     *
     * @return list<string>
     */
    public function expectedForms(string $root): array
    {
        /** @var list<string> $forms */
        $forms = $this->entry($root)['forms'];

        return $forms;
    }

    /**
     * @return array<string, int>
     */
    public function countsByFamily(): array
    {
        $counts = [];

        foreach ($this->families() as $family => $roots) {
            $counts[$family] = \count($roots);
        }

        return $counts;
    }

    /**
     * Whether a candidate names a root the inventory knows.
     *
     * Variants, a leading or trailing `!`, a leading `-`, a modifier and the
     * value are ignored; what remains must be a root on its own or a root
     * followed by `-`. The longest-root question does not arise because any
     * match is enough: `border-spacing-x-4` starts with `border-spacing-x`, and
     * would also start with `border`.
     *
     * A byte scanner and not a regular expression: a candidate is untrusted
     * text, and the corpus files that feed this are written by hand.
     */
    public function assertCandidateUsesKnownRoot(string $candidate): bool
    {
        $utility = self::utilityOf($candidate);

        if ($utility === null) {
            return false;
        }

        if ($utility[0] === '[') {
            return self::isArbitraryProperty($utility) && isset($this->entries()[self::ARBITRARY_PROPERTY]);
        }

        foreach ($this->roots() as $root) {
            if ($root === self::ARBITRARY_PROPERTY) {
                continue;
            }

            if ($utility === $root || \strncmp($utility, $root . '-', \strlen($root) + 1) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        if (!\is_file($this->file)) {
            throw new \RuntimeException('The T1 inventory file is missing.');
        }

        /** @var mixed $loaded */
        $loaded = require $this->file;

        if (!\is_array($loaded) || $loaded === []) {
            throw new \RuntimeException('The T1 inventory file does not return a non-empty array.');
        }

        $entries = [];

        foreach ($loaded as $root => $entry) {
            if (!\is_array($entry)) {
                throw new \RuntimeException(\sprintf('Inventory entry "%s" is not an array.', (string) $root));
            }

            $entries[(string) $root] = $entry;
        }

        $this->entries = $entries;

        return $entries;
    }

    /**
     * The utility part of a candidate: after the last top-level `:`, without
     * the importance bangs and the negative dash. Null when the candidate is
     * malformed (an empty variant, or nothing left).
     */
    private static function utilityOf(string $candidate): ?string
    {
        $length = \strlen($candidate);
        $depth = 0;
        $start = 0;

        for ($i = 0; $i < $length; ++$i) {
            $byte = $candidate[$i];

            if ($depth > 0 && ($byte === "'" || $byte === '"')) {
                $close = \strpos($candidate, $byte, $i + 1);

                if ($close === false) {
                    return null;
                }

                $i = $close;

                continue;
            }

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif (($byte === ']' || $byte === ')') && $depth > 0) {
                --$depth;
            } elseif ($byte === ':' && $depth === 0) {
                if ($i === $start) {
                    return null;
                }

                $start = $i + 1;
            }
        }

        $utility = $start >= $length ? '' : (string) \substr($candidate, $start);

        if ($utility !== '' && $utility[0] === '!') {
            $utility = (string) \substr($utility, 1);
        }

        if ($utility !== '' && \substr($utility, -1) === '!') {
            $utility = (string) \substr($utility, 0, -1);
        }

        if ($utility !== '' && $utility[0] === '-') {
            $utility = (string) \substr($utility, 1);
        }

        $utility = self::withoutModifier($utility);

        return $utility === '' ? null : $utility;
    }

    /**
     * Cut a `/modifier` off the end: the last `/` outside brackets and
     * parentheses, so `flex/50` becomes `flex` while `w-1/2` stays a root plus a
     * value and `bg-[url(/a/b.png)]` is left alone.
     */
    private static function withoutModifier(string $utility): string
    {
        $depth = 0;
        $slash = -1;

        for ($i = 0, $length = \strlen($utility); $i < $length; ++$i) {
            $byte = $utility[$i];

            if ($byte === '[' || $byte === '(') {
                ++$depth;
            } elseif (($byte === ']' || $byte === ')') && $depth > 0) {
                --$depth;
            } elseif ($byte === '/' && $depth === 0) {
                $slash = $i;
            }
        }

        return $slash > 0 ? (string) \substr($utility, 0, $slash) : $utility;
    }

    /**
     * `[property:value]`: a lowercase property name (or a `--custom` one), a
     * colon, something, and the closing bracket.
     */
    private static function isArbitraryProperty(string $utility): bool
    {
        $length = \strlen($utility);

        if ($length < 5 || $utility[0] !== '[' || $utility[$length - 1] !== ']') {
            return false;
        }

        $colon = \strpos($utility, ':');

        if ($colon === false || $colon < 2 || $colon >= $length - 2) {
            return false;
        }

        $property = (string) \substr($utility, 1, $colon - 1);

        return \strspn($property, 'abcdefghijklmnopqrstuvwxyz0123456789-') === \strlen($property);
    }
}
