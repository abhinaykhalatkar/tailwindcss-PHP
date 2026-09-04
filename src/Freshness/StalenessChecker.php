<?php

declare(strict_types=1);

namespace TailwindPhp\Freshness;

/**
 * Layer 1's decision procedure: compares a fresh {@see FileSnapshot} against
 * the previous manifest's `files` map (design doc 05 §Comparison).
 *
 * Mtime **and** size **and** the file set itself are compared — never content
 * — because a deploy tool (`rsync -a`, `tar`, a Git checkout) can produce a
 * file whose mtime predates the cache, and a same-second edit only shows up
 * in size (design doc 05). `Freshness` never imports `Scanner\Root` (design
 * doc 17 layering): the `k` discriminator is compared as the plain string
 * design doc 09 defines it as (`'i'` input/watch, `'s'` source), not through
 * the enum-like class that produces it.
 */
final class StalenessChecker
{
    /**
     * design doc 09 §Roots: an input/watch root's kind discriminator.
     */
    private const INPUT_KIND = 'i';

    /**
     * @param array<string, array{k?: string, m?: int, s?: int}> $then The
     *                                                                 previous manifest's `files` map.
     */
    public static function compare(FileSnapshot $now, array $then): StalenessReport
    {
        $nowFiles = $now->files();
        $changedOrAdded = [];
        $sawInputChange = false;

        foreach ($nowFiles as $rel => $record) {
            $previous = $then[$rel] ?? null;

            if (
                $previous === null
                || ($previous['m'] ?? null) !== $record['m']
                || ($previous['s'] ?? null) !== $record['s']
            ) {
                $changedOrAdded[] = $rel;

                if ($record['k'] === self::INPUT_KIND) {
                    $sawInputChange = true;
                }
            }
        }

        $missingRoots = $now->missingRoots();
        $removed = [];

        foreach ($then as $rel => $previous) {
            if (isset($nowFiles[$rel]) || self::isUnderAnyMissingRoot($rel, $missingRoots)) {
                continue;
            }

            $removed[] = $rel;

            if (($previous['k'] ?? null) === self::INPUT_KIND) {
                $sawInputChange = true;
            }
        }

        if ($changedOrAdded === [] && $removed === []) {
            return new StalenessReport(StalenessReport::FRESH);
        }

        if ($sawInputChange) {
            return new StalenessReport(StalenessReport::INPUTS_CHANGED);
        }

        \sort($changedOrAdded, \SORT_STRING);
        \sort($removed, \SORT_STRING);

        return new StalenessReport(StalenessReport::TEMPLATES_CHANGED, $changedOrAdded, $removed);
    }

    /**
     * FR-FRESH-7: a file that would have been reported "removed" only
     * because its whole root is currently missing is not actually gone —
     * the pipeline serves the cache stale instead (design doc 05 §Failure
     * modes).
     *
     * @param list<string> $missingRoots
     */
    private static function isUnderAnyMissingRoot(string $rel, array $missingRoots): bool
    {
        foreach ($missingRoots as $root) {
            if ($root === '' || $rel === $root || \strncmp($rel, $root . '/', \strlen($root) + 1) === 0) {
                return true;
            }
        }

        return false;
    }
}
