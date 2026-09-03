<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The library's single time source (design doc 03 §Extension points).
 *
 * Freshness checks, `check_interval` throttling and lock timeouts all depend on
 * the current time. Injecting it rather than calling `\time()` inline is what
 * lets those be tested deterministically with {@see FrozenClock} instead of
 * with sleeps (design doc 16 §Unit test conventions).
 *
 * Two readings rather than one: whole seconds are what file mtimes are compared
 * against, while sub-second precision is what a timeout needs.
 */
interface ClockInterface
{
    /**
     * Current time as whole Unix seconds, for comparison against file mtimes.
     */
    public function now(): int;

    /**
     * Current time as a float of Unix seconds, for measuring durations.
     */
    public function microtime(): float;
}
