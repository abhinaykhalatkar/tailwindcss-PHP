<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Benchmark;

/**
 * The one statistic the benchmark rows report: a median is indifferent to the
 * one iteration that hit a garbage-collection pause.
 */
final class Median
{
    /**
     * @param list<float> $samples
     */
    public static function of(array $samples): float
    {
        if ($samples === []) {
            return 0.0;
        }

        \sort($samples);
        $count = \count($samples);
        $middle = \intdiv($count, 2);

        if ($count % 2 === 1) {
            return $samples[$middle];
        }

        return ($samples[$middle - 1] + $samples[$middle]) / 2.0;
    }
}
