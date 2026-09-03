<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * A configured scan bound was exceeded, e.g. `limits.max_files`
 * (design doc 15 §Exception hierarchy).
 *
 * Bounding the scan is what keeps a pathological source tree from turning one
 * request into unbounded work (threat T-05), so hitting a limit is a hard stop
 * rather than a warning.
 */
final class ScanLimitException extends ScanException
{
    /**
     * @var string
     */
    private $limitName;

    /**
     * @var int
     */
    private $limitValue;

    /**
     * @param string $limitName Config key of the bound, e.g. 'max_files'.
     * @param int $limitValue The configured value that was reached.
     */
    public function __construct(string $limitName, int $limitValue, ?\Throwable $previous = null)
    {
        $safeLimitName = self::scrubText($limitName);

        parent::__construct(
            \sprintf('Scan aborted: limit "%s" of %d was reached.', $safeLimitName, $limitValue),
            [
                'limit' => $safeLimitName,
                'value' => $limitValue,
            ],
            $previous
        );

        $this->limitName = $safeLimitName;
        $this->limitValue = $limitValue;
    }

    public function limitName(): string
    {
        return $this->limitName;
    }

    public function limitValue(): int
    {
        return $this->limitValue;
    }
}
