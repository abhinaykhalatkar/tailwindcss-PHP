<?php

declare(strict_types=1);

namespace TailwindPhp\Freshness;

use TailwindPhp\Support\ClockInterface;
use TailwindPhp\Support\FilesystemInterface;

/**
 * `check_interval` (FR-FRESH-6, design doc 04 §4.5): lets a high-traffic
 * bundle skip the Layer 1 stat sweep on every request and only re-check every
 * N seconds.
 *
 * The stamp is touched with the injected clock's time, not wall time
 * (design decision CSF-A14), so `interval` is testable with
 * {@see \TailwindPhp\Support\FrozenClock} instead of sleeping in a test.
 *
 * M1 supports `check_store: file` only; `apcu` arrives in M3 (design decision
 * CSF-A14) — `ConfigBuilder` is expected to reject it before this class is
 * ever constructed with it, but the check here is a second, defensive line.
 */
final class CheckThrottle
{
    /**
     * @var FilesystemInterface
     */
    private $filesystem;

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var string
     */
    private $stampPath;

    /**
     * @var int
     */
    private $interval;

    /**
     * @param string $stampPath Absolute path to `<cache_dir>/<bundle>.checked`.
     * @param int $interval Seconds between checks; 0 means "always check".
     * @param string $store Only `'file'` is implemented in M1.
     *
     * @throws \InvalidArgumentException When $store is not `'file'`.
     */
    public function __construct(
        FilesystemInterface $filesystem,
        ClockInterface $clock,
        string $stampPath,
        int $interval,
        string $store = 'file'
    ) {
        if ($store !== 'file') {
            throw new \InvalidArgumentException(
                \sprintf('CheckThrottle only supports the "file" store in M1, got "%s".', $store)
            );
        }

        $this->filesystem = $filesystem;
        $this->clock = $clock;
        $this->stampPath = $stampPath;
        $this->interval = $interval;
    }

    /**
     * Whether Layer 1 should run this request. `interval === 0` always
     * checks; a missing stamp always checks (first request, or the cache was
     * cleared).
     */
    public function shouldCheck(): bool
    {
        if ($this->interval === 0) {
            return true;
        }

        $stat = $this->filesystem->stat($this->stampPath);

        if ($stat === null) {
            return true;
        }

        return ($this->clock->now() - $stat['mtime']) >= $this->interval;
    }

    /**
     * Record that a check just happened. Called on every SERVE, TOUCH and
     * COMPILE outcome (design doc 04 §4.5), not only when the interval
     * elapsed — otherwise a bundle sitting exactly at the boundary would
     * check every request instead of every $interval seconds.
     */
    public function markChecked(): void
    {
        $this->filesystem->touch($this->stampPath, $this->clock->now());
    }
}
