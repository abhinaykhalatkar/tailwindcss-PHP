<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * A cache artefact could not be created or replaced — mkdir, fopen, fwrite or
 * the atomic rename failed (design doc 15 §Exception hierarchy).
 *
 * The message carries the *project-relative* path of the artefact (ADR-014);
 * an absolute path is redacted rather than printed.
 */
final class CacheWriteException extends CacheException
{
    /**
     * @var string
     */
    private $operation;

    /**
     * @var string|null
     */
    private $relativePath;

    /**
     * @param string $operation The failed filesystem step, e.g. 'rename' or 'mkdir'.
     * @param string $relativePath Path of the artefact, relative to the project root.
     */
    public function __construct(string $operation, string $relativePath, ?\Throwable $previous = null)
    {
        $safePath = self::redactIfAbsolute($relativePath);
        $safeOperation = self::scrubText($operation);

        parent::__construct(
            \sprintf('Cannot %s cache artefact "%s".', $safeOperation, (string) $safePath),
            [
                'operation' => $safeOperation,
                'path' => $safePath,
            ],
            $previous
        );

        $this->operation = $safeOperation;
        $this->relativePath = $safePath;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function relativePath(): ?string
    {
        return $this->relativePath;
    }
}
