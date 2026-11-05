<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Exception\CliProcessException;
use TailwindPhp\Exception\CliTimeoutException;
use TailwindPhp\Tests\Support\RequiresSpawning;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The only class that spawns a child process (design doc 06; threats T-03, T-05).
 *
 * Integration, and with a real child, because every property worth asserting is
 * a property of the operating system: whether arguments reach the child intact,
 * whether a hung process is actually killed, and whether large output deadlocks.
 * A double would assert only that the test author remembered the design.
 *
 * The fixture is a small PHP script rather than a shell script, so the same
 * tests run on Windows and POSIX without a second implementation.
 *
 * @covers-requirement FR-COMP-3 NFR-SEC-2
 */
final class ProcessTest extends TestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $fixture = '';

    protected function setUp(): void
    {
        parent::setUp();
        RequiresSpawning::check();

        $this->dir = TempTree::make('tailwindphp-process');
        $this->fixture = $this->dir . '/child.php';

        \file_put_contents($this->fixture, $this->fixtureSource());

        TempTree::registerShutdownCleanup($this->dir);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->dir);

        parent::tearDown();
    }

    /** @test */
    public function aSuccessfulRunReportsExitZeroAndItsOutput(): void
    {
        $result = $this->runChild(['--echo', 'hello']);

        self::assertSame(0, $result->exitCode());
        self::assertTrue($result->succeeded());
        self::assertFalse($result->timedOut());
        self::assertStringContainsString('hello', $result->stdout());
        self::assertSame('', $result->stderr());
        self::assertGreaterThan(0.0, $result->durationMs());
    }

    /** @test */
    public function aFailingRunReportsItsExitCodeAndStderr(): void
    {
        $result = $this->runChild(['--fail', '--stderr']);

        self::assertSame(2, $result->exitCode());
        self::assertFalse($result->succeeded());
        self::assertStringContainsString('Error: something broke', $result->stderr());
    }

    /**
     * The T-03 property, stated directly: an argument is delivered to the child
     * as **one literal argument**, whatever is in it. Shell metacharacters are
     * data, not syntax — on PHP ≥ 7.4 because `proc_open` takes an array and no
     * shell is involved, and on the 7.3 floor because every element goes
     * through `escapeshellarg()`.
     *
     * @test
     *
     * @dataProvider hostileArguments
     */
    public function anArgumentReachesTheChildIntactWhateverIsInIt(string $argument): void
    {
        $result = $this->runChild(['--echo', $argument]);

        // The child echoes its arguments as JSON, not one per line: an argument
        // may itself contain a newline, and the check must still be able to
        // tell where one argument ends and the next begins. It must arrive
        // whole, and nothing in it may have been executed or split.
        $echoed = \json_decode(\trim($result->stdout()), true);

        self::assertSame([$argument], $echoed);
        self::assertSame(0, $result->exitCode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileArguments(): array
    {
        return [
            'a space' => ['two words'],
            'a semicolon' => ['a;id'],
            'a command substitution' => ['$(id)'],
            'a backtick substitution' => ['`id`'],
            'an ampersand' => ['a&b'],
            'a pipe' => ['a|b'],
            'a redirect' => ['a>b'],
            'a quote' => ['a"b'],
            'a single quote' => ["a'b"],
            'a newline' => ["a\nb"],
            'a wildcard' => ['*'],
        ];
    }

    /**
     * A hung binary holding an FPM worker is a denial of service (T-05), so the
     * timeout must both fire *and* actually kill.
     *
     * @test
     */
    public function aHangingChildIsKilledAtTheDeadline(): void
    {
        $started = \microtime(true);

        try {
            $this->runChild(['--sleep'], 0.5);

            self::fail('A child that outlives its deadline must not return normally.');
        } catch (CliTimeoutException $e) {
            $elapsed = \microtime(true) - $started;

            self::assertGreaterThanOrEqual(0.4, $elapsed, 'The timeout fired early.');
            self::assertLessThan(3.0, $elapsed, 'The timeout did not fire promptly.');
            self::assertStringContainsString('timed out', $e->getMessage());
        }
    }

    /**
     * The regression test for the defect this class was rewritten around.
     *
     * The original implementation used `['pipe','w']` for stdout and stderr with
     * `stream_set_blocking(false)`. **That call has no effect on `proc_open`
     * pipes on Windows** — they stay blocking — so `fread()` on a stream the
     * child never writes to blocks until the child exits, while a child blocked
     * writing to a full stdout pipe never exits. The result was a deadlock that
     * held the worker for the entire timeout and then reported a timeout for a
     * process that had already finished its work.
     *
     * Measured before the fix: 3 MB of output did not complete within 25 s.
     * After: 0.08 s. The generous bound below is deliberate — it is asserting
     * "does not deadlock", not a performance target, so it must not become
     * flaky on a loaded CI box.
     *
     * If this test starts timing out, someone has reverted stdout/stderr to
     * pipes.
     *
     * @test
     */
    public function alargeOutputDoesNotDeadlock(): void
    {
        $started = \microtime(true);

        $result = $this->runChild(['--flood'], 30.0);

        self::assertSame(0, $result->exitCode());
        self::assertLessThan(
            20.0,
            \microtime(true) - $started,
            'Reading a few megabytes took long enough to suggest the pipe deadlock is back.'
        );
    }

    /**
     * A binary that prints without bound must not be able to exhaust memory
     * through us (T-05).
     *
     * @test
     */
    public function outputIsCappedAtTheDocumentedLimit(): void
    {
        $result = $this->runChild(['--flood'], 30.0);

        self::assertSame(Process::MAX_OUTPUT_BYTES, \strlen($result->stdout()));
    }

    /**
     * A child that reads stdin must get EOF rather than hanging: nothing is
     * ever written to it, so the pipe is closed immediately.
     *
     * @test
     */
    public function aChildReadingStdinGetsEofRatherThanHanging(): void
    {
        $result = $this->runChild(['--read-stdin'], 5.0);

        self::assertSame(0, $result->exitCode());
        self::assertStringContainsString('stdin-eof', $result->stdout());
    }

    /** @test */
    public function anUnspawnableBinaryThrowsRatherThanReturning(): void
    {
        $process = new Process();

        $this->expectException(CliProcessException::class);

        $process->run(
            [$this->dir . '/does-not-exist-at-all'],
            $this->dir,
            ['PATH' => (string) \getenv('PATH')],
            5.0
        );
    }

    /** @test */
    public function anEmptyArgvIsRefused(): void
    {
        $process = new Process();

        $this->expectException(CliProcessException::class);

        $process->run([], $this->dir, [], 5.0);
    }

    /**
     * A deadline already past is a configuration error, not a hung binary.
     *
     * Left unchecked, the first poll kills the child and reports a timeout — a
     * "the binary outlived its deadline" story about a binary that never got to
     * start. Whoever reads that log goes looking for the wrong problem.
     *
     * @dataProvider nonPositiveTimeouts
     *
     * @test
     */
    public function anonPositiveTimeoutIsRefused(float $timeoutSec): void
    {
        $process = new Process();

        $this->expectException(\InvalidArgumentException::class);

        $process->run([\PHP_BINARY, '-r', 'echo 1;'], $this->dir, [], $timeoutSec);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nonPositiveTimeouts(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-1.0],
        ];
    }

    /**
     * The temp files the implementation uses for stdout and stderr are an
     * internal detail, and must not outlive the call — on success or on
     * timeout (T-14: a predictable leftover is both a leak and a target).
     *
     * @test
     */
    public function noTemporaryFileSurvivesARun(): void
    {
        $before = $this->temporaryFileCount();

        $this->runChild(['--echo', 'x']);

        try {
            $this->runChild(['--sleep'], 0.5);
        } catch (CliTimeoutException $e) {
            // Expected; the point is the cleanup below.
        }

        self::assertSame($before, $this->temporaryFileCount());
    }

    /**
     * @param list<string> $arguments
     */
    private function runChild(array $arguments, float $timeoutSec = 10.0): \TailwindPhp\Compiler\Cli\ProcessResult
    {
        $process = new Process();

        $argv = \array_merge([\PHP_BINARY, $this->fixture], $arguments);

        return $process->run($argv, $this->dir, ['PATH' => (string) \getenv('PATH')], $timeoutSec);
    }

    /**
     * How many of our temp files are currently in the system temp directory.
     */
    private function temporaryFileCount(): int
    {
        $found = \glob(\sys_get_temp_dir() . '/twphp*');

        return $found === false ? 0 : \count($found);
    }

    /**
     * A child that can be told to behave in each way the tests need.
     *
     * Written as PHP rather than a shell script so one fixture serves both
     * platforms. Large output is produced in a few big writes: thirty thousand
     * small ones take twelve seconds on Windows by themselves and would make
     * the deadlock test measure the fixture rather than the class.
     */
    private function fixtureSource(): string
    {
        return <<<'PHP'
            <?php

            $args = $argv;
            \array_shift($args);

            if (\in_array('--echo', $args, true)) {
                $echoed = [];

                foreach ($args as $arg) {
                    if ($arg !== '--echo') {
                        $echoed[] = $arg;
                    }
                }

                echo \json_encode($echoed), "\n";
            }

            if (\in_array('--read-stdin', $args, true)) {
                $in = \stream_get_contents(\STDIN);
                echo $in === '' || $in === false ? "stdin-eof\n" : "stdin-data\n";
            }

            if (\in_array('--stderr', $args, true)) {
                \fwrite(\STDERR, "Error: something broke\n");
            }

            if (\in_array('--flood', $args, true)) {
                for ($i = 0; $i < 30; ++$i) {
                    echo \str_repeat('x', 100000);
                }
            }

            if (\in_array('--sleep', $args, true)) {
                \sleep(30);
            }

            if (\in_array('--fail', $args, true)) {
                exit(2);
            }

            exit(0);
            PHP;
    }
}
