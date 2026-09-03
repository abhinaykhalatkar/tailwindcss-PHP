<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

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
            $trimmed = self::cutAtCharacterBoundary($trimmed, self::STDERR_LIMIT) . '…';
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

    /**
     * Cut to at most $length bytes without splitting a UTF-8 sequence.
     *
     * Implemented here rather than delegating to `Support\Str`: the exception
     * layer sits below Support in the dependency order (design doc 17), and a
     * half-emitted character in a log line is invalid UTF-8 that can break
     * whatever reads it.
     */
    private static function cutAtCharacterBoundary(string $value, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if (\strlen($value) <= $length) {
            return $value;
        }

        $cut = \substr($value, 0, $length);
        $index = \strlen($cut);
        $steps = 0;

        // Walk back over continuation bytes (10xxxxxx) to the lead byte.
        while ($index > 0 && $steps <= 4) {
            $byte = \ord($cut[$index - 1]);

            if (($byte & 0xC0) === 0x80) {
                --$index;
                ++$steps;

                continue;
            }

            $sequenceLength = self::sequenceLength($byte);

            if ($sequenceLength === 0) {
                return \substr($cut, 0, $index - 1);
            }

            return ($index - 1) + $sequenceLength <= \strlen($cut)
                ? \substr($cut, 0, ($index - 1) + $sequenceLength)
                : \substr($cut, 0, $index - 1);
        }

        // A run of continuation bytes longer than any real sequence means the
        // input is not valid UTF-8 at this point. Returning '' here would throw
        // away the whole excerpt over a few stray bytes, so keep the raw cut:
        // a bounded, slightly-invalid excerpt is far more useful than nothing.
        return $cut;
    }

    /**
     * Length of the UTF-8 sequence a lead byte starts, or 0 if it cannot.
     */
    private static function sequenceLength(int $leadByte): int
    {
        if (($leadByte & 0x80) === 0x00) {
            return 1;
        }

        if (($leadByte & 0xE0) === 0xC0) {
            return 2;
        }

        if (($leadByte & 0xF0) === 0xE0) {
            return 3;
        }

        if (($leadByte & 0xF8) === 0xF0) {
            return 4;
        }

        return 0;
    }
}
