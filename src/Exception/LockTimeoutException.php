<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * The compile lock was still held after `lock_timeout` seconds
 * (design doc 15 §Exception hierarchy, ADR-006).
 *
 * The pipeline normally converts this into "serve the stale artefact" rather
 * than letting it reach the caller; it escapes only when there is no previous
 * good build to fall back to.
 */
final class LockTimeoutException extends CacheException
{
    /**
     * @var float
     */
    private $secondsWaited;

    /**
     * @param float $secondsWaited How long we actually waited before giving up.
     * @param string|null $relativePath Lock file path, relative to the project root.
     */
    public function __construct(float $secondsWaited, ?string $relativePath = null, ?\Throwable $previous = null)
    {
        $safePath = self::redactIfAbsolute($relativePath);

        parent::__construct(
            \sprintf('Timed out after %.3f s waiting for the compile lock.', $secondsWaited),
            [
                'seconds_waited' => $secondsWaited,
                'path' => $safePath,
            ],
            $previous
        );

        $this->secondsWaited = $secondsWaited;
    }

    public function secondsWaited(): float
    {
        return $this->secondsWaited;
    }
}
