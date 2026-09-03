<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * Base of the cache branch (design doc 15 §Exception hierarchy): the compile
 * itself was fine but the artefact could not be stored, locked or read back.
 *
 * Not final: {@see CacheWriteException}, {@see LockTimeoutException} and
 * {@see ManifestCorruptException} specialise it.
 */
class CacheException extends \RuntimeException implements TailwindPhpException
{
    use ExceptionContext;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct(self::scrubText($message), 0, $previous);

        $this->initContext($context);
    }
}
