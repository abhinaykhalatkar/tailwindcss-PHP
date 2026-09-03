<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\Bytes;

/**
 * The standalone Tailwind binary exited non-zero, crashed, or could not be
 * spawned (design doc 15 §Exception hierarchy).
 *
 * The binary's stderr is attached because it is the only useful description of
 * what went wrong, but it is trimmed to a bounded length: it is output from a
 * subprocess and must not be able to flood a log line (threat T-06).
 */
final class CliProcessException extends CompilationException
{
    /**
     * Longest stderr excerpt kept, in bytes.
     */
    private const STDERR_LIMIT = 2000;

    /**
     * @var int
     */
    private $exitCode;

    /**
     * @var string
     */
    private $stderr;

    /**
     * @param int $exitCode Process exit status; -1 when it could not be spawned.
     * @param string $stderr Raw stderr; trimmed and truncated before storage.
     */
    public function __construct(
        int $exitCode,
        string $stderr,
        ?DiagnosticBag $diagnostics = null,
        ?\Throwable $previous = null
    ) {
        // Scrubbed before truncation so a path cannot be split across the cut
        // and survive as a fragment, and stored scrubbed so stderr() is as safe
        // as context().
        $trimmed = \trim(self::scrubText($stderr));

        if (\strlen($trimmed) > self::STDERR_LIMIT) {
            $trimmed = Bytes::cutAtCharacterBoundary($trimmed, self::STDERR_LIMIT) . '…';
        }

        parent::__construct(
            \sprintf('The Tailwind CLI failed with exit code %d.', $exitCode),
            'cli',
            $diagnostics,
            [
                'exit_code' => $exitCode,
                'stderr' => $trimmed,
            ],
            $previous
        );

        $this->exitCode = $exitCode;
        $this->stderr = $trimmed;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Trimmed, length-bounded stderr of the failed process.
     */
    public function stderr(): string
    {
        return $this->stderr;
    }
}
