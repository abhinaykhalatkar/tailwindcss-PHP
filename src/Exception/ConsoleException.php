<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * A usage error in the console entry point: unknown command, missing argument,
 * bad option value (design doc 15 §Console error output).
 *
 * Always exits 2, which separates "you typed something wrong" from "the build
 * failed" (exit 1) so scripts and CI can tell the two apart.
 */
final class ConsoleException extends \RuntimeException implements TailwindPhpException
{
    use ExceptionContext;

    /**
     * Process exit status for a usage error.
     */
    public const EXIT_CODE = 2;

    /**
     * @param string|null $command The command that was being parsed, if known.
     */
    public function __construct(string $message, ?string $command = null, ?\Throwable $previous = null)
    {
        parent::__construct(self::scrubText($message), self::EXIT_CODE, $previous);

        $this->initContext([
            'exit_code' => self::EXIT_CODE,
            'command' => $command,
        ]);
    }

    public function exitCode(): int
    {
        return self::EXIT_CODE;
    }
}
