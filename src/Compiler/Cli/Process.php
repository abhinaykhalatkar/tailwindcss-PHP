<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\CliProcessException;
use TailwindPhp\Exception\CliTimeoutException;

/**
 * The only place in the library that starts a child process
 * (design doc 06, doc 14; threats T-03, T-05).
 *
 * Everything here exists because a web request is on the other end of it.
 *
 * **argv is never assembled from user data.** The caller passes an array whose
 * only members are a confined binary path, literal flags, and absolute paths
 * this library generated. That is what makes shell injection a non-question
 * rather than a question answered carefully (T-03) — and on PHP ≥ 7.4 the array
 * form of `proc_open` means no shell is involved at all.
 *
 * **The 7.3 fallback is the interesting case.** Array `proc_open` landed in
 * **PHP 7.4**; on the 7.3 floor the arguments must be joined into a command
 * string, which *does* go through a shell. Every element is passed through
 * `escapeshellarg()` — see {@see self::commandFor()}.
 *
 * **A timeout must actually kill.** A hung binary holding an FPM worker is a
 * denial of service (T-05), so the loop is bounded by a deadline and ends with
 * `proc_terminate($p, 9)` — SIGKILL, not SIGTERM, because a process ignoring
 * the deadline has already shown it will not shut down politely.
 *
 * **Output is capped.** A binary that prints megabytes of warnings must not be
 * able to exhaust memory through us.
 */
final class Process
{
    /**
     * Most stdout/stderr the caller ever sees, per stream (T-05).
     */
    public const MAX_OUTPUT_BYTES = 1048576;

    /**
     * How often the child is polled for exit. Short enough that the deadline
     * stays accurate to a millisecond, long enough not to spin the CPU.
     */
    private const POLL_SLEEP_US = 1000;

    /**
     * Grace between SIGKILL and giving up on the handle.
     */
    private const REAP_SLEEP_US = 10000;

    /**
     * @param list<string> $argv Binary path first, then literal flags and
     *                           absolute paths this library generated. Never
     *                           configuration or request data (T-03).
     * @param array<string, string> $env The complete environment — the child
     *                                   inherits nothing implicitly.
     *
     * @throws CliProcessException When the process cannot be spawned.
     * @throws CliTimeoutException When it outlives $timeoutSec.
     */
    public function run(array $argv, string $cwd, array $env, float $timeoutSec): ProcessResult
    {
        if ($argv === []) {
            throw new CliProcessException(-1, '');
        }

        // A non-positive deadline is already past when the child starts, so the
        // first poll kills it and reports a timeout — a "the binary hung" story
        // for a binary that never got to run. That misdirects whoever reads the
        // log, so a nonsensical timeout is rejected as the configuration error
        // it is rather than dressed up as a runtime one.
        if ($timeoutSec <= 0.0) {
            throw new \InvalidArgumentException('cli.timeout must be greater than zero.');
        }

        $outFile = $this->temporaryFile();
        $errFile = $this->temporaryFile();

        // stdout and stderr go to **files**, not pipes. `stream_set_blocking()`
        // has no effect on `proc_open` pipes on Windows — they stay blocking —
        // so a `fread()` on a stream the child never writes to blocks until the
        // child exits, and a child blocked writing to a full pipe never exits.
        // That deadlock hangs the worker for the whole timeout with no output.
        // Files cannot deadlock: the OS drains the child, and we read afterwards.
        // Done on every platform so there is one code path, exercised everywhere.
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'wb'],
            2 => ['file', $errFile, 'wb'],
        ];

        $pipes = [];
        $started = \hrtime(true);

        try {
            // Suppressed and immediately checked: a missing binary raises a
            // warning and returns false, which is the answer we act on (doc 18).
            $handle = @\proc_open($this->commandFor($argv), $spec, $pipes, $cwd, $env);

            if (!\is_resource($handle)) {
                throw new CliProcessException(-1, '');
            }

            // Nothing is ever written to the child: closing stdin immediately
            // means a binary that reads from it gets EOF rather than hanging.
            if (isset($pipes[0]) && \is_resource($pipes[0])) {
                \fclose($pipes[0]);
            }

            $deadline = \microtime(true) + $timeoutSec;
            $exitCode = -1;
            $timedOut = true;

            while (true) {
                $status = \proc_get_status($handle);

                if ($status === false || $status['running'] !== true) {
                    // `exitcode` is only valid on the first call that observes
                    // the exit; after that it is -1, so it is taken here.
                    $exitCode = $status === false ? -1 : (int) $status['exitcode'];
                    $timedOut = false;

                    break;
                }

                if (\microtime(true) >= $deadline) {
                    break;
                }

                \usleep(self::POLL_SLEEP_US);
            }

            $durationMs = (float) (\hrtime(true) - $started) / 1000000.0;

            if ($timedOut) {
                $this->kill($handle);

                $bag = new DiagnosticBag();
                $bag->add(new Diagnostic(
                    Diagnostic::ERROR,
                    Diagnostic::E_CLI_TIMEOUT,
                    \sprintf('The Tailwind CLI did not finish within %.3f seconds.', $timeoutSec)
                ));

                throw new CliTimeoutException($timeoutSec, $bag);
            }

            \proc_close($handle);

            return new ProcessResult(
                $exitCode,
                $this->readCapped($outFile),
                $this->readCapped($errFile),
                $durationMs,
                false
            );
        } finally {
            // The temp files are ours and hold only the child's output; a
            // failure to remove one is not worth reporting over whatever else
            // is being thrown.
            @\unlink($outFile);
            @\unlink($errFile);
        }
    }

    /**
     * A private file for one stream of one run.
     *
     * `tempnam()` creates it with a unique name and 0600 on POSIX, so nothing
     * else can read the binary's output or plant a symlink at a predictable
     * path (T-14).
     *
     * @throws CliProcessException When no temp file can be created.
     */
    private function temporaryFile(): string
    {
        $path = @\tempnam(\sys_get_temp_dir(), 'twphp');

        if ($path === false) {
            throw new CliProcessException(-1, '');
        }

        return $path;
    }

    /**
     * Read one output file, honouring the cap (T-05).
     */
    private function readCapped(string $path): string
    {
        // Suppressed and checked: an absent file simply means the child wrote
        // nothing to that stream.
        $contents = @\file_get_contents($path, false, null, 0, self::MAX_OUTPUT_BYTES);

        return $contents === false ? '' : $contents;
    }

    /**
     * The `proc_open` first argument.
     *
     * PHP **7.4** added the array form, which hands the arguments to the OS
     * directly and involves no shell at all. On the 7.3 floor only the string
     * form exists, so each element is quoted with `escapeshellarg()` — the
     * arguments are already trusted (T-03), and this is the belt to that
     * braces.
     *
     * @param list<string> $argv
     *
     * @return list<string>|string
     */
    private function commandFor(array $argv)
    {
        if (\PHP_VERSION_ID >= 70400) {
            return $argv;
        }

        $quoted = [];

        foreach ($argv as $argument) {
            $quoted[] = \escapeshellarg($argument);
        }

        return \implode(' ', $quoted);
    }


    /**
     * SIGKILL, then reap.
     *
     * Signal 9 rather than the default 15: a process that has already ignored
     * its deadline has shown it will not exit politely, and a second wait would
     * be another worker held open.
     *
     * @param resource $handle
     */
    private function kill($handle): void
    {
        // On Windows the direct child may not be the binary. A `.cmd` shim — an
        // explicitly supported form (CCP-A22/A23) — is run *by* `cmd.exe`, so
        // `proc_terminate()` kills the interpreter and the program it started
        // survives: measured `php.exe` 8 -> 9 across one timed-out compile, with
        // the orphan holding the temp directory as its working directory and
        // later writing into it. A timeout that leaves the work running has
        // bounded the request and nothing else, which is precisely what T-05 is
        // about, so the tree has to go.
        //
        // POSIX needs none of this: there is no shim, `proc_terminate($h, 9)`
        // reaches the binary itself, and SIGKILL is not survivable.
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->killTree($handle);
        }

        // Suppressed and not checked: the child may have exited between the
        // deadline and this call, in which case terminate legitimately fails
        // and proc_close below still reaps it.
        @\proc_terminate($handle, 9);

        \usleep(self::REAP_SLEEP_US);
        \proc_close($handle);
    }

    /**
     * Kill the child *and its descendants* on Windows.
     *
     * `taskkill /T` is what makes this a tree kill; `/F` is what makes it
     * unsurvivable, and both matter for the same reason SIGKILL is used on
     * POSIX — a process that has already ignored its deadline has shown it will
     * not shut down politely.
     *
     * Spawned through this class's own `proc_open` site so that `Process.php`
     * remains the only file in `src/` that starts a process (a static-check
     * invariant). argv is a fixed shape here too: two literal flags and an
     * integer pid that came from `proc_get_status()`, never from a caller.
     *
     * @param resource $handle
     */
    private function killTree($handle): void
    {
        $status = \proc_get_status($handle);

        // Checked rather than assumed: once the child has been reaped its pid
        // may already have been recycled by the operating system, and killing a
        // tree by a recycled pid would kill something else entirely.
        // `is_array` first because on the 7.3 floor `proc_get_status()` is typed
        // as returning false on failure.
        if (!\is_array($status) || $status['running'] !== true) {
            return;
        }

        // Absolute, never the bare name: a bare `taskkill` resolves through the
        // child `PATH`, and a `PATH` anyone can influence turns "kill the process
        // tree" into "run whatever is named taskkill.exe first". If the real tool
        // cannot be located we do nothing rather than guess — `proc_terminate`
        // below still reaps the direct child.
        $taskkill = Environment::systemTool('taskkill.exe');

        if ($taskkill === null) {
            return;
        }

        $argv = [$taskkill, '/F', '/T', '/PID', (string) $status['pid']];

        // Output discarded rather than inspected: taskkill fails routinely and
        // harmlessly when the tree is already gone, and there is nothing this
        // side could usefully do about a failure at the point of giving up.
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->nullDevice(), 'wb'],
            2 => ['file', $this->nullDevice(), 'wb'],
        ];
        $pipes = [];
        $killer = @\proc_open($this->commandFor($argv), $spec, $pipes, null, null);

        if (!\is_resource($killer)) {
            return;
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }

        // Blocking on purpose and safe: taskkill is a short-lived system tool,
        // and returning before it has finished would defeat the point — the
        // caller unlinks the temp directory next, and that only succeeds once
        // the orphan has released it.
        \proc_close($killer);
    }

    /**
     * The platform's bit bucket.
     */
    private function nullDevice(): string
    {
        return \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}
