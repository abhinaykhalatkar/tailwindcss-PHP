<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * A driver was explicitly configured but cannot run on this host — for example
 * `driver: cli` where `proc_open` is disabled or the binary fails verification
 * (design doc 15 §Exception hierarchy).
 *
 * Only `auto` falls back silently; naming a driver and not getting it is an
 * error, so that a shared host does not quietly serve engine output to someone
 * who asked for the official CLI.
 */
final class DriverUnavailableException extends CompilationException
{
    /**
     * @var string
     */
    private $reason;

    /**
     * @param string $driver The driver that was requested but is unusable.
     * @param string $reason Why it cannot run, free of absolute paths.
     */
    public function __construct(
        string $driver,
        string $reason,
        ?DiagnosticBag $diagnostics = null,
        ?\Throwable $previous = null
    ) {
        // The reason is free text and is scrubbed; the driver is validated by
        // the parent instead, so driver() keeps returning the real name.
        $safeReason = self::scrubText($reason);

        parent::__construct(
            \sprintf('Compiler driver "%s" is not available: %s', $driver, $safeReason),
            $driver,
            $diagnostics,
            ['reason' => $safeReason],
            $previous
        );

        $this->reason = $safeReason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
