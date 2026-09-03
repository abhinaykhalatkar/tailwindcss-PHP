<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * Base of the scanner branch (design doc 15 §Exception hierarchy): the source
 * tree could not be walked far enough to produce a trustworthy candidate set.
 *
 * Not final: {@see ScanLimitException} specialises it.
 */
class ScanException extends \RuntimeException implements TailwindPhpException
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
