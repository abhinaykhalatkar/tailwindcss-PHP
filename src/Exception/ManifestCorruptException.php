<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * The manifest could not be parsed or failed its schema check.
 *
 * @internal This never escapes the pipeline: the caller catches it, emits a
 *           `W_MANIFEST_CORRUPT` diagnostic and rebuilds from scratch
 *           (design doc 15 §Exception hierarchy).
 */
final class ManifestCorruptException extends CacheException
{
    /**
     * @var string
     */
    private $reason;

    /**
     * @param string $reason Why the manifest was rejected.
     * @param string|null $relativePath Manifest path, relative to the project root.
     */
    public function __construct(string $reason, ?string $relativePath = null, ?\Throwable $previous = null)
    {
        $safePath = self::redactIfAbsolute($relativePath);
        $safeReason = self::scrubText($reason);

        parent::__construct(
            \sprintf('Manifest is corrupt: %s', $safeReason),
            [
                'reason' => $safeReason,
                'path' => $safePath,
            ],
            $previous
        );

        $this->reason = $safeReason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
