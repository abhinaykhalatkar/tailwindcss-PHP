<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

/**
 * The outcome of one child process (design doc 06).
 *
 * Immutable, and deliberately dumb: it reports what happened without deciding
 * what it means. Whether a non-zero exit is a compile error or an
 * infrastructure failure depends on what is in stderr, and that judgement
 * belongs to {@see StandaloneCliCompiler}, not here.
 */
final class ProcessResult
{
    /**
     * @var int
     */
    private $exitCode;

    /**
     * @var string
     */
    private $stdout;

    /**
     * @var string
     */
    private $stderr;

    /**
     * @var float
     */
    private $durationMs;

    /**
     * @var bool
     */
    private $timedOut;

    public function __construct(
        int $exitCode,
        string $stdout,
        string $stderr,
        float $durationMs,
        bool $timedOut = false
    ) {
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->durationMs = $durationMs;
        $this->timedOut = $timedOut;
    }

    /**
     * @return int The child's exit status, or -1 when it could not be spawned.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @return string Capped at {@see Process::MAX_OUTPUT_BYTES} (threat T-05).
     */
    public function stdout(): string
    {
        return $this->stdout;
    }

    /**
     * @return string Capped at {@see Process::MAX_OUTPUT_BYTES} (threat T-05).
     */
    public function stderr(): string
    {
        return $this->stderr;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }
}
