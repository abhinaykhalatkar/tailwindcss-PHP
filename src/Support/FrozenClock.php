<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * A clock that does not move unless a test moves it
 * (design doc 03 §Extension points, design doc 16 §Unit test conventions).
 *
 * **This is a test double and is deliberately mutable**, which is the one
 * documented exception to the project's immutable-value-object rule
 * (design doc 18 §Design rules). Freshness and lock-timeout tests need to hold a
 * reference to the clock and step it forward mid-scenario; a `withTime()` clone
 * would hand back a new object the system under test is not holding, which is
 * exactly the wrong shape for the job. `advance()` mutates on purpose.
 *
 * It ships in `src/` rather than `tests/` because integrators writing tests
 * against their own wiring need it too.
 */
final class FrozenClock implements ClockInterface
{
    /**
     * @var int
     */
    private $now;

    /**
     * @var float
     */
    private $microtime;

    /**
     * @param int $now Fixed time in whole Unix seconds.
     * @param float|null $microtime Fixed sub-second reading; defaults to $now exactly.
     */
    public function __construct(int $now, ?float $microtime = null)
    {
        $this->now = $now;
        $this->microtime = $microtime ?? (float) $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function microtime(): float
    {
        return $this->microtime;
    }

    /**
     * Move both readings by $seconds, keeping any sub-second offset intact.
     *
     * A negative step is allowed: staleness tests routinely need to place a
     * file in the past.
     */
    public function advance(int $seconds): void
    {
        $this->now += $seconds;
        $this->microtime += (float) $seconds;
    }
}
