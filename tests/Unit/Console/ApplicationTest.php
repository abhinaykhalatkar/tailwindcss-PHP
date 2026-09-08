<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Console\Application;
use TailwindPhp\Exception\ConsoleException;

/**
 * Dispatch and exit codes (TASK-0019 acceptance 1; doc 21).
 *
 * The exit codes are the contract a CI script branches on, so each of the four
 * is demonstrated here rather than described:
 *
 * | 0 | it worked | 1 | the operation failed |
 * | 2 | usage or configuration | 3 | the environment prevents it |
 *
 * Telling 1 from 2 is what lets a pipeline retry a flaky build without
 * retrying a typo, and 3 exists so a deploy can distinguish "no network" from
 * "wrong arguments".
 *
 * @covers-requirement FR-CONSOLE-1
 */
final class ApplicationTest extends TestCase
{
    /**
     * @test
     */
    public function noArgumentsPrintsUsageAndSucceeds(): void
    {
        [$code, $out] = $this->invoke(['bin']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage: tailwind-php', $out);
    }

    /**
     * A help screen that omits a command has a command nobody discovers.
     *
     * @test
     */
    public function helpListsEveryRegisteredCommand(): void
    {
        [$code, $out] = $this->invoke(['bin', '--help']);

        self::assertSame(0, $code);

        foreach (['alpha', 'beta'] as $name) {
            self::assertStringContainsString($name, $out);
        }
    }

    /**
     * @test
     */
    public function theRealApplicationListsItsCommands(): void
    {
        $application = new Application();

        [$code, $out] = $this->invoke(['bin', '--help'], $application);

        self::assertSame(0, $code);

        foreach (['build', 'clear', 'url', 'candidates'] as $name) {
            self::assertStringContainsString($name, $out, 'the usage screen omits ' . $name);
        }
    }

    /**
     * @test
     */
    public function anUnknownCommandIsAUsageErrorWithASuggestion(): void
    {
        [$code, $out, $err] = $this->invoke(['bin', 'alfa']);

        self::assertSame(2, $code);
        self::assertStringContainsString('alfa', $err);
        self::assertStringContainsString('alpha', $err, 'expected a "did you mean"');
        self::assertSame('', $out, 'errors belong on stderr');
    }

    /**
     * @dataProvider exitCodes
     *
     * @test
     */
    public function aCommandsExitCodeIsPassedThrough(string $command, int $expected): void
    {
        [$code] = $this->invoke(['bin', $command]);

        self::assertSame($expected, $code);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function exitCodes(): array
    {
        return [
            'success is 0' => ['alpha', 0],
            'failure is 1' => ['fails', 1],
            'environment is 3' => ['environment', 3],
        ];
    }

    /**
     * A `ConsoleException` carries its own code (2) and its message reaches the
     * user — it is the type used for "you called this wrong".
     *
     * @test
     */
    public function aConsoleExceptionBecomesExitTwo(): void
    {
        [$code, $out, $err] = $this->invoke(['bin', 'usage']);

        self::assertSame(2, $code);
        self::assertStringContainsString('that is not how', $err);
        self::assertSame('', $out);
    }

    /**
     * Any other throwable is an operation failure, and its message is scrubbed
     * on the way out — console output ends up in CI logs and issue reports
     * (threat T-06).
     *
     * @test
     */
    public function anUnexpectedThrowableIsScrubbedAndExitsOne(): void
    {
        [$code, $out, $err] = $this->invoke(['bin', 'explodes']);

        self::assertSame(1, $code);
        self::assertStringNotContainsString('/etc/secret', $err, 'the path was not scrubbed');
        self::assertStringNotContainsString('#0 ', $err, 'a stack trace must never be printed');
        self::assertSame('', $out);
    }

    /**
     * An option the command did not declare is refused before the command
     * runs, so a typo cannot produce a half-configured run.
     *
     * @test
     */
    public function anUndeclaredOptionIsRefusedBeforeTheCommandRuns(): void
    {
        [$code, $out, $err] = $this->invoke(['bin', 'alpha', '--nope']);

        self::assertSame(2, $code);
        self::assertStringContainsString('--nope', $err);
        self::assertSame('', $out, 'the command must not have run');
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(array $argv, ?Application $application = null): array
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $application = $application ?? new Application([
            new FakeCommand('alpha', 0),
            new FakeCommand('beta', 0),
            new FakeCommand('fails', 1),
            new FakeCommand('environment', 3),
            new FakeCommand('usage', 0, new ConsoleException('that is not how this works')),
            new FakeCommand('explodes', 0, new \RuntimeException('boom in /etc/secret/config.php')),
        ]);

        $code = $application->run($argv, $stdout, $stderr);

        \rewind($stdout);
        \rewind($stderr);

        return [$code, (string) \stream_get_contents($stdout), (string) \stream_get_contents($stderr)];
    }
}
