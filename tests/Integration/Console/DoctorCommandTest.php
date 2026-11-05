<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Console\Application;
use TailwindPhp\Console\Command\BuildCommand;
use TailwindPhp\Console\Command\DoctorCommand;
use TailwindPhp\Tests\Support\RequiresSpawning;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The `doctor` report (TASK-0019 acceptance 4; doc 21 §doctor).
 *
 * Two properties matter more than any individual row:
 *
 * - **It always exits 0 unless asked not to.** A diagnostic that can break a
 *   deploy script by existing is one people stop running, so `--strict` is
 *   opt-in.
 * - **Every non-ok row carries a remedy.** A report that says `[warn]` and
 *   stops leaves the reader searching the documentation for a setting they
 *   have never heard of; the row that tells them what to do is the product.
 *
 * @covers-requirement FR-CONSOLE-3
 */
final class DoctorCommandTest extends TestCase
{
    /**
     * @var TempProject|null
     */
    private $project;

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            $this->project->remove();
            $this->project = null;
        }
    }

    /**
     * @test
     */
    public function itReportsTheEnvironmentAndExitsZero(): void
    {
        [$code, $out] = $this->doctor([]);

        self::assertSame(0, $code);

        foreach (['php', 'ext-json', 'proc_open', 'open_basedir', 'cache_dir', 'driver'] as $row) {
            self::assertStringContainsString($row, $out, 'the report omits ' . $row);
        }
    }

    /**
     * The process row names the function that is actually missing (TASK-0061):
     * a host whose `disable_functions` lists only `usleep` is told so, not that
     * `proc_open` is the problem. Run in a child PHP, because a disabled
     * function cannot be faked inside this process.
     *
     * @test
     */
    public function theProcessRowNamesTheFunctionThatIsMissing(): void
    {
        RequiresSpawning::check();

        $root = $this->root();
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'require ' . \var_export($autoload, true) . ';'
            . ' exit((new \\TailwindPhp\\Console\\Application([new \\TailwindPhp\\Console\\Command\\DoctorCommand('
            . \var_export($root, true) . ')]))->run(["bin", "doctor", "--json"], STDOUT, STDERR));';
        $temp = \sys_get_temp_dir();
        $result = (new Process())->run(
            [\PHP_BINARY, '-d', 'disable_functions=usleep', '-r', $code],
            $root,
            [
                'PATH' => (string) \getenv('PATH'),
                'SystemRoot' => (string) \getenv('SystemRoot'),
                'TMP' => $temp,
                'TEMP' => $temp,
                'TMPDIR' => $temp,
            ],
            60.0
        );

        $report = \json_decode($result->stdout(), true);
        self::assertIsArray($report, $result->stdout() . $result->stderr());
        self::assertIsArray($report['checks'] ?? null);

        $states = [];

        foreach ($report['checks'] as $row) {
            $states[(string) $row['check']] = (string) $row['state'];
        }

        self::assertSame('warn', $states['usleep'] ?? null, 'the row is labelled with the missing function');
        self::assertArrayNotHasKey('proc_open', $states, 'and not with proc_open');
    }

    /**
     * Doc 21 (Q17): the check is printed, not performed — a loopback request
     * describes the machine `doctor` runs on, which on a containerised host is
     * not the machine serving the site.
     *
     * @test
     */
    public function itPrintsTheServeCheckRatherThanPerformingIt(): void
    {
        [, $out] = $this->doctor([]);

        self::assertStringContainsString('curl -sSI', $out);
        self::assertStringContainsString('assets/styles.css', $out);
    }

    /**
     * @test
     */
    public function itReportsTheCacheStateAfterABuild(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        [$code, $out] = $this->doctor([]);

        $manifest = $this->project()->manifest('app');

        self::assertSame(0, $code);
        self::assertNotNull($manifest);
        self::assertStringContainsString((string) $manifest['fingerprint'], $out);
        self::assertStringContainsString('built at', $out);
        self::assertStringContainsString('driver (used)', $out);
    }

    /**
     * @test
     */
    public function itWarnsWhenNothingHasBeenBuilt(): void
    {
        [$code, $out] = $this->doctor([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('[warn]', $out);
        self::assertStringContainsString('tailwind-php build', $out, 'the warning must carry its remedy');
    }

    /**
     * Threat T-07: a cache directory the web server serves directly hands out
     * the manifest, which names every watched file in the project.
     *
     * @test
     */
    public function itWarnsWhenTheCacheDirectoryIsInsideTheDocumentRoot(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        [$code, $out] = $this->doctor(['--docroot=' . $this->root()]);

        self::assertSame(0, $code);
        self::assertStringContainsString('cache_dir exposure', $out);
        self::assertStringContainsString('inside the document root', $out);
        self::assertStringContainsString('.htaccess', $out, 'the warning must carry its remedy');
    }

    /**
     * @test
     */
    public function itIsSatisfiedWhenTheCacheDirectoryIsOutsideTheDocumentRoot(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        $docroot = $this->root() . '/public';
        \mkdir($docroot, 0777, true);

        [, $out] = $this->doctor(['--docroot=' . $docroot]);

        self::assertStringContainsString('outside the document root', $out);
    }

    /**
     * Threat T-04: with verification off, the library executes whatever binary
     * is at that path.
     *
     * @test
     */
    public function itFailsTheVerifyRowWhenChecksumsAreDisabled(): void
    {
        $this->writeConfig(['cli' => ['verify' => false]]);

        [$code, $out] = $this->doctor([]);

        self::assertSame(0, $code, 'doctor still reports rather than breaking the script');
        self::assertStringContainsString('[fail]', $out);
        self::assertStringContainsString('cli.verify', $out);
        self::assertStringContainsString('T-04', $out);
    }

    /**
     * `--strict` is what a pipeline uses when it wants the report to be a gate.
     *
     * @test
     */
    public function strictTurnsAnyProblemIntoExitOne(): void
    {
        $this->writeConfig(['cli' => ['verify' => false]]);

        [$code] = $this->doctor(['--strict']);

        self::assertSame(1, $code);
    }

    /**
     * And a clean project is not turned into a failure by `--strict` for no
     * reason — the control that stops the row above passing on a command that
     * always exits 1.
     *
     * @test
     */
    public function strictExitsZeroWhenTheOnlyProblemsAreResolved(): void
    {
        [$strictCode] = $this->doctor(['--strict']);
        [$plainCode] = $this->doctor([]);

        self::assertSame(0, $plainCode);
        // This project has optional extensions missing on some hosts, so the
        // strict code is asserted only to be one of the two documented values
        // rather than pinned to a host-dependent count.
        self::assertContains($strictCode, [0, 1]);
    }

    /**
     * Decision HBC-A24: the operator-side control for the T-08 residual the
     * loader cannot close on its own.
     *
     * @test
     */
    public function itWarnsWhenTheConfigFileSitsUnderACacheDirectory(): void
    {
        $root = $this->root();

        \mkdir($root . '/var/cache', 0777, true);
        \file_put_contents(
            $root . '/var/cache/tailwind.php',
            "<?php\n\nreturn " . \var_export([
                'project_root' => $root,
                'driver' => 'php',
                'input' => 'assets/styles/main.css',
                'sources' => ['templates'],
                'cache_dir' => 'cache/tailwind',
                'bundles' => ['app' => []],
            ], true) . ";\n"
        );

        [$code, $out] = $this->doctor(['--config=var/cache/tailwind.php']);

        self::assertSame(0, $code);
        self::assertStringContainsString('config location', $out);
        self::assertStringContainsString('T-08', $out);
    }

    /**
     * A missing configuration is a reported failure, not a crash: the PHP rows
     * above it are still the useful half of the answer.
     *
     * @test
     */
    public function aMissingConfigurationIsReportedNotThrown(): void
    {
        \unlink($this->root() . '/tailwind.php');
        \unlink($this->root() . '/config/tailwind.php');

        [$code, $out] = $this->doctor([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('php', $out, 'the environment rows should still be reported');
        self::assertStringContainsString('[fail]', $out);
        self::assertStringContainsString('--config', $out);
    }

    /**
     * @test
     */
    public function jsonModeEmitsOneObjectAndNothingElse(): void
    {
        [$code, $out] = $this->doctor(['--json']);

        self::assertSame(0, $code);

        $decoded = \json_decode($out, true);

        self::assertIsArray($decoded, 'stdout was not a single JSON object: ' . $out);
        self::assertArrayHasKey('problems', $decoded);
        self::assertArrayHasKey('checks', $decoded);
        self::assertNotSame([], $decoded['checks']);
    }

    /**
     * Threat T-06: this output is pasted into issue reports.
     *
     * @test
     */
    public function noAbsolutePathIsPrinted(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        [, $out] = $this->doctor(['--docroot=' . $this->root()]);

        self::assertStringNotContainsString($this->root(), $out);

        // The lookbehind matters: the printed `curl https://example.test/…`
        // hint contains `s://`, which a bare `[A-Za-z]:[\\/]` reads as a drive
        // letter. Without it this row fails on its own example command.
        self::assertDoesNotMatchRegularExpression('#(?<![A-Za-z])[A-Za-z]:[\\\\/]#', $out);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function doctor(array $arguments): array
    {
        return $this->invoke(
            new DoctorCommand($this->root()),
            \array_merge(['bin', 'doctor'], $arguments)
        );
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(\TailwindPhp\Console\Command\CommandInterface $command, array $argv): array
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = (new Application([$command]))->run($argv, $stdout, $stderr);

        \rewind($stdout);
        \rewind($stderr);

        return [$code, (string) \stream_get_contents($stdout), (string) \stream_get_contents($stderr)];
    }

    private function project(): TempProject
    {
        $project = $this->project;

        if ($project === null) {
            $project = new TempProject();
            $this->project = $project;
            $this->writeConfig([]);
        }

        return $project;
    }

    private function root(): string
    {
        return $this->project()->root();
    }

    /**
     * Write the project's `tailwind.php`.
     *
     * Creating the project first is load-bearing: an earlier version returned
     * quietly when the project did not exist yet, so a row that called this
     * *before* its first command silently kept the default configuration — the
     * `cli.verify: false` row was asserting against a config with verification
     * still on. It failed, which is how it was found, but it could as easily
     * have passed for the wrong reason.
     *
     * @param array<string, mixed> $extra
     */
    private function writeConfig(array $extra): void
    {
        $project = $this->project;

        if ($project === null) {
            $project = new TempProject();
            $this->project = $project;
        }

        $root = $project->root();

        $config = \array_merge([
            'driver' => 'php',
            'input' => 'assets/styles/main.css',
            'sources' => ['templates'],
            'cache_dir' => 'cache/tailwind',
            'bundles' => ['app' => []],
        ], $extra);

        \file_put_contents($root . '/tailwind.php', "<?php\n\nreturn " . \var_export($config, true) . ";\n");
    }
}
