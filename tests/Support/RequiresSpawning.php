<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Skips a test that spawns a process on purpose when the host forbids it — the
 * restricted shared-host cell of CI runs the suites with
 * `disable_functions=proc_open,exec,shell_exec,popen,system,passthru`
 * (NFR-COMPAT-3). On PHP 8 a disabled function does not exist, so
 * `function_exists()` is the whole question.
 *
 * Called from `setUp()` for a class that spawns in every row, or at the top of
 * the one row (or helper) that does.
 */
final class RequiresSpawning
{
    public const REASON = 'process functions are disabled on this host (restricted shared-host simulation)';

    /**
     * For a test that starts a child with `proc_open`.
     */
    public static function check(): void
    {
        if (!\function_exists('proc_open')) {
            Assert::markTestSkipped(self::REASON);
        }
    }

    /**
     * For a test that runs a command with `exec` (the Windows `mklink` helpers).
     */
    public static function checkExec(): void
    {
        if (!\function_exists('exec')) {
            Assert::markTestSkipped(self::REASON);
        }
    }
}
