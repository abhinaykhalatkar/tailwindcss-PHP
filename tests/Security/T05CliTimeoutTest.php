<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Exception\CliTimeoutException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-05 — a hung binary must not hold the request** (design doc 12; FR-COMP-4).
 *
 * `ProcessTest` already pins the deadline at the `Process` level. What is left,
 * and what nothing else covers, is the *compile-level* consequence, which has
 * three parts and only makes sense as three:
 *
 * - the exception reaches the caller instead of an FPM worker being held for as
 *   long as the binary feels like running;
 * - the temp directory is still removed, because the timeout is the one path
 *   where no happy-path cleanup runs and a leaked `.cli-*` directory under a
 *   predictable, writable name is both a disclosure and a target (T-14);
 * - and the child is *dead*, not merely abandoned. `proc_terminate($p, 9)` is
 *   SIGKILL rather than SIGTERM precisely because a process that has ignored its
 *   deadline has already shown it will not shut down politely, and a timeout
 *   that leaves the work running has bounded nothing.
 *
 * The last two are asserted where they can be asserted honestly. On Windows a
 * `.cmd` binary (an explicitly supported form, CCP-A23) is started through
 * `cmd.exe`, so `proc_terminate()` reaches the shim and not the process it
 * spawned — see the skip messages, and the task report, for what that costs.
 */
final class T05CliTimeoutTest extends TestCase
{
    /**
     * Short enough to keep the suite quick, long enough that a slow spawn cannot
     * be mistaken for the deadline firing.
     */
    private const TIMEOUT_SEC = 0.5;

    /**
     * How long the fixture is told to sleep — 60× the deadline on POSIX.
     *
     * Shorter on Windows, and only because of the orphan described in
     * {@see self::reclaimLeftovers()}: the sleeping grandchild holds the temp
     * directory open for exactly this long, and a 30 s hold would make every
     * later cleanup in this class wait for it. Either value is far past the
     * deadline, which is the property under test.
     */
    private const SLEEP_SEC = \DIRECTORY_SEPARATOR === '\\' ? 5 : 30;

    /**
     * Bound on the quiet cleanup wait. Never reached on POSIX.
     */
    private const RECLAIM_SEC = 20.0;

    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-t05cli');
        $this->cacheDir = $this->root . '/cache';

        \mkdir($this->cacheDir, 0777, true);

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        $this->reclaimLeftovers();

        TempTree::remove($this->root);

        parent::tearDown();
    }

    /**
     * The binary sleeps far past the deadline; the compile gives up at it.
     *
     * The upper bound is what makes this a test rather than a formality: without
     * it, a compile that simply waited out the fixture would pass.
     *
     * @test
     */
    public function ahangingBinaryTimesOutInsteadOfHoldingTheRequest(): void
    {
        $started = \microtime(true);

        try {
            $this->compileHanging();

            self::fail('A binary that outlives its deadline must not return normally.');
        } catch (CliTimeoutException $e) {
            $elapsed = \microtime(true) - $started;

            self::assertGreaterThanOrEqual(
                self::TIMEOUT_SEC - 0.1,
                $elapsed,
                'The deadline fired before it was due.'
            );
            self::assertLessThan(3.0, $elapsed, 'The compile waited for the binary instead of killing it.');
            self::assertSame(self::TIMEOUT_SEC, $e->secondsWaited());

            // The message is what a fail-soft response logs, so it must carry no
            // absolute path out of this host (T-06, ADR-014).
            self::assertStringNotContainsString($this->root, $e->getMessage());
        }
    }

    /**
     * The `finally` on the one path where nothing else would clean up (T-14).
     *
     * @test
     */
    public function atimedOutCompileStillRemovesItsTemporaryDirectory(): void
    {
        try {
            $this->compileHanging();

            self::fail('A binary that outlives its deadline must not return normally.');
        } catch (CliTimeoutException $e) {
            // Expected; the assertion below is the point of the test.
        }

        self::assertSame(
            [],
            $this->leftoverDirectories(),
            'A timed-out compile left its temp directory behind.'
        );
    }

    /**
     * Killed, not abandoned. A timeout that leaves the binary running has bounded
     * the *request* and nothing else — the CPU, the memory and the disk are still
     * being spent, which is the denial of service T-05 is about.
     *
     * Identified by the temp directory's name, which appears in the child's
     * `--cwd` argument and is unique to this compile, so a concurrent suite or a
     * stray fixture from another run cannot be mistaken for our child. The
     * bracket in the pattern is the classic `ps | grep '[x]yz'` guard: `pgrep -f`
     * would otherwise match the shell it is itself running in, whose command line
     * contains the pattern literally.
     *
     * @test
     */
    public function thekilledChildDoesNotOutliveTheCall(): void
    {
        $this->assertTheChildIsDead();
    }

    /**
     * One mechanism, both platforms: the child's own pid.
     *
     * The process that matters is not always the direct child. A `.cmd` binary
     * is run *by* `cmd.exe`, so the fixture's `php.exe` is a grandchild, and
     * before the kill-tree fix that was exactly the leak — `proc_terminate()`
     * reached the shim while the interpreter ran on, holding the temp directory
     * as its working directory and writing into it afterwards.
     *
     * Identified by the pid the fixture recorded for itself rather than by name,
     * pattern or count. Each of the alternatives answers a slightly different
     * question than the one being asked: a count answers "is anything running?"
     * and fails as soon as an unrelated PHP process exists (it did, once, during
     * review), and a `pgrep -f` pattern matches the shell running the `pgrep`.
     * A pid is unambiguous.
     */
    private function assertTheChildIsDead(): void
    {
        try {
            $this->compileHanging();

            self::fail('A binary that outlives its deadline must not return normally.');
        } catch (CliTimeoutException $e) {
            // Expected.
        }

        // The fixture's own pid, written into the record before it slept. An
        // earlier version of this test counted every php.exe on the machine and
        // failed once during review while unrelated PHP processes were running —
        // a global count answers "is anything running?", not "did *our* child
        // survive?".
        $pid = $this->recordedChildPid();

        self::assertFalse(
            $this->waitForExit($pid),
            'The hung child outlived the timeout that was supposed to kill it: proc_terminate reached the '
            . 'cmd.exe shim but not the interpreter it started, which is the orphan the kill-tree in '
            . 'Process::kill() exists to stop.'
        );

        self::assertSame(
            [],
            $this->leftoverDirectories(),
            'The temp directory survived, which on Windows means something still holds it open.'
        );
    }

    /**
     * The pid the fixture recorded for itself before sleeping.
     */
    private function recordedChildPid(): int
    {
        foreach ($this->cacheEntries() as $entry) {
            if (\substr($entry, -10) !== '.argv.json') {
                continue;
            }

            $decoded = \json_decode((string) \file_get_contents($this->cacheDir . '/' . $entry), true);

            if (\is_array($decoded) && isset($decoded['pid']) && \is_int($decoded['pid'])) {
                return $decoded['pid'];
            }
        }

        self::fail('The fixture recorded no pid, so the binary was never reached.');
    }

    /**
     * Poll until $pid is gone, or the bound expires.
     *
     * Polled rather than sampled once because the process table takes a moment
     * to settle after a kill — so a slow reaper reads as slow rather than as a
     * leak.
     *
     * @return bool True if the process is *still alive* when the bound expires.
     */
    private function waitForExit(int $pid): bool
    {
        $deadline = \microtime(true) + 10.0;

        while (true) {
            if (!self::processExists($pid)) {
                return false;
            }

            if (\microtime(true) >= $deadline) {
                return true;
            }

            \usleep(100000);
        }
    }

    /**
     * Whether one specific pid is still running.
     *
     * `tasklist /FI "PID eq …"` on Windows — present everywhere and needing no
     * extension. On POSIX, signal 0 is the standard existence probe: it performs
     * the permission and liveness checks without delivering anything.
     */
    private static function processExists(int $pid): bool
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $output = (string) \shell_exec(\sprintf('tasklist /FI "PID eq %d" /NH 2>NUL', $pid));

            return \strpos($output, (string) $pid) !== false;
        }

        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }

        return \is_dir('/proc/' . $pid);
    }

    // --------------------------------------------------------------- driving

    /**
     * @throws CliTimeoutException Always, by design.
     */
    private function compileHanging(): void
    {
        $this->compiler()->compile(
            \sprintf("/* fake: sleep %d */\n.t05-entry{color:red}\n", self::SLEEP_SEC),
            new InMemoryImportResolver([]),
            ['flex'],
            new CompileOptions(false, false)
        );
    }

    private function compiler(): StandaloneCliCompiler
    {
        $windows = \DIRECTORY_SEPARATOR === '\\';

        return new StandaloneCliCompiler(
            \dirname(__DIR__, 1) . '/Fixtures/bin/fake-tailwindcss' . ($windows ? '.cmd' : ''),
            '4.1.18',
            $this->cacheDir,
            self::TIMEOUT_SEC,
            new Process(),
            new Filesystem(),
            $this->root,
            new AtomicWriter($this->root),
            // The real Environment: it is the one place allowed to read the two
            // machine variables Windows needs (R3 allowlist), so a test that
            // substituted its own would be testing a different child world than
            // production gets.
            new Environment()
        );
    }

    /**
     * `scandir`, never `glob`: the temp directory name starts with a dot and
     * `glob()` does not match those, so a `glob`-based check reports an empty
     * cache directory whatever is actually in it.
     *
     * @return list<string>
     */
    private function leftoverDirectories(): array
    {
        $directories = [];

        foreach ($this->cacheEntries() as $entry) {
            if (\is_dir($this->cacheDir . '/' . $entry)) {
                $directories[] = $entry;
            }
        }

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function cacheEntries(): array
    {
        return self::entriesIn($this->cacheDir);
    }

    /**
     * @return list<string>
     */
    private static function entriesIn(string $directory): array
    {
        $entries = \scandir($directory);

        if ($entries === false) {
            return [];
        }

        return \array_values(\array_diff($entries, ['.', '..']));
    }

    /**
     * Wait, quietly, for whatever is still holding a leftover temp directory.
     *
     * A no-op on POSIX, where the child is dead before `compile()` returns. On
     * Windows the orphaned fixture keeps the directory as its working directory
     * until it finishes sleeping, and `TempTree::remove()` would otherwise warn
     * once per level on the way out. Suppressed and bounded on purpose: this is
     * housekeeping after the assertions, and a host that never releases the
     * directory must still finish the run.
     */
    private function reclaimLeftovers(): void
    {
        $deadline = \microtime(true) + self::RECLAIM_SEC;

        while (true) {
            $held = 0;

            foreach ($this->leftoverDirectories() as $name) {
                $path = $this->cacheDir . '/' . $name;

                foreach (self::entriesIn($path) as $inner) {
                    @\unlink($path . '/' . $inner);
                }

                if (!@\rmdir($path)) {
                    ++$held;
                }
            }

            if ($held === 0 || \microtime(true) >= $deadline) {
                return;
            }

            \usleep(100000);
        }
    }
}
