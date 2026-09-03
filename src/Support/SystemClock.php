<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The real clock — the production implementation of {@see ClockInterface}.
 *
 * Deliberately trivial and stateless: it exists so that every other class can
 * take a `ClockInterface` and be tested against {@see FrozenClock}.
 */
final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return \time();
    }

    public function microtime(): float
    {
        return \microtime(true);
    }
}
