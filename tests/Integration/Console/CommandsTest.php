<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Console\Application;
use TailwindPhp\Console\Command\BuildCommand;
use TailwindPhp\Console\Command\CandidatesCommand;
use TailwindPhp\Console\Command\ClearCommand;
use TailwindPhp\Console\Command\UrlCommand;
use TailwindPhp\Console\ConfigLocator;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The four config-driven commands, against a real project (TASK-0019
 * acceptance 7).
 *
 * Everything below the command is real — the facade, the pipeline, the cache —
 * because a console command is almost entirely wiring, and wiring is what a
 * double cannot check. The commands are constructed with an explicit working
 * directory rather than `chdir()`: a test that changes the process's directory
 * breaks whichever test runs next in ways that look unrelated.
 *
 * @covers-requirement FR-CONSOLE-1
 * @covers-requirement FR-CACHE-7
 */
final class CommandsTest extends TestCase
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

    // ------------------------------------------------------------------ build

    /**
     * @test
     */
    public function buildCompilesAndReportsTheFields(): void
    {
        [$code, $out] = $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        self::assertSame(0, $code);

        foreach (['bundle', 'source', 'driver', 'fingerprint', 'output', 'size', 'duration'] as $field) {
            self::assertStringContainsString($field, $out, 'the summary omits ' . $field);
        }

        self::assertNotNull($this->project()->manifest('app'));
    }

    /**
     * Doc 21: a second build of an unchanged project is a no-op, which is what
     * makes running this in a deploy step twice harmless.
     *
     * @test
     */
    public function aSecondBuildIsCached(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);
        [$code, $out] = $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        self::assertSame(0, $code);
        self::assertStringContainsString('cached', $out);
    }

    /**
     * `--force` removes the manifest, so the next resolve is a cold one. The
     * assertion is that the source changes from `cached` to `compiled`.
     *
     * @test
     */
    public function forceRecompilesAFreshCache(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        [$cachedCode, $cachedOut] = $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);
        [$forcedCode, $forcedOut] = $this->invoke(new BuildCommand($this->root()), ['bin', 'build', '--force']);

        self::assertSame(0, $cachedCode);
        self::assertSame(0, $forcedCode);
        self::assertStringContainsString('cached', $cachedOut);
        self::assertStringContainsString('compiled', $forcedOut, '--force did not force a rebuild');
    }

    /**
     * @test
     */
    public function buildEmitsOneJsonObjectUnderJson(): void
    {
        [$code, $out] = $this->invoke(new BuildCommand($this->root()), ['bin', 'build', '--json']);

        self::assertSame(0, $code);

        $decoded = \json_decode($out, true);

        self::assertIsArray($decoded, 'stdout was not a single JSON object: ' . $out);
        self::assertArrayHasKey('fingerprint', $decoded);
        self::assertSame('app', $decoded['bundle']);
    }

    /**
     * A failed build is exit 1 with the reason on stderr, and stdout stays
     * empty so a `$(...)` capture gets nothing rather than half an answer.
     *
     * @test
     */
    public function aFailedBuildExitsOneWithTheReasonOnStderr(): void
    {
        $project = $this->project();
        $project->writeFile(
            'assets/styles/main.css',
            "@import \"tailwindcss\";\n" . \str_repeat("/* padding */\n", 200)
        );
        $this->writeConfig(['limits' => ['max_total_css' => 1024], 'log_level' => 'emergency']);

        [$code, $out, $err] = $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        self::assertSame(1, $code);
        self::assertNotSame('', $err);
        self::assertSame('', $out);
    }

    // ------------------------------------------------------------------ clear

    /**
     * FR-CACHE-7 and the part that matters: what `clear` must **not** remove.
     *
     * @test
     */
    public function clearRemovesArtefactsButNeverTheGuards(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        $cache = $this->root() . '/cache/tailwind';
        \file_put_contents($cache . '/.htaccess', "Deny from all\n");
        \file_put_contents($cache . '/index.html', '');
        \file_put_contents($cache . '/app.lock', '');
        \file_put_contents($cache . '/app.checked', '');

        [$code, $out] = $this->invoke(new ClearCommand($this->root()), ['bin', 'clear']);

        self::assertSame(0, $code);
        self::assertStringContainsString('removed', $out);

        self::assertFileExists($cache . '/.htaccess', 'the T-07 guard was deleted');
        self::assertFileExists($cache . '/index.html', 'the T-07 guard was deleted');
        self::assertFileExists($cache . '/app.lock', 'the lock was deleted');
        self::assertDirectoryExists($cache, 'the cache directory itself was deleted');

        self::assertFileDoesNotExist($cache . '/app.meta.json');
        self::assertFileDoesNotExist($cache . '/app.checked', 'the stamp should be cleared (HBC-A11)');
        self::assertNull($this->project()->manifest('app'));
    }

    // -------------------------------------------------------------------- url

    /**
     * @test
     */
    public function urlExitsOneWithoutAManifest(): void
    {
        [$code, $out, $err] = $this->invoke(new UrlCommand($this->root()), ['bin', 'url']);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('build', $err);
    }

    /**
     * @test
     */
    public function urlPrintsTheFingerprintedRouteAfterABuild(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        [$code, $out] = $this->invoke(new UrlCommand($this->root()), ['bin', 'url']);

        $manifest = $this->project()->manifest('app');

        self::assertSame(0, $code);
        self::assertNotNull($manifest);
        self::assertSame('assets/styles.' . $manifest['fingerprint'] . ".css\n", $out);
    }

    /**
     * @test
     */
    public function urlCanBuildFirst(): void
    {
        [$code, $out] = $this->invoke(new UrlCommand($this->root()), ['bin', 'url', '--build', '--prefix=/']);

        self::assertSame(0, $code);
        self::assertStringStartsWith('/assets/styles.', $out);
        self::assertNotNull($this->project()->manifest('app'));
    }

    // ------------------------------------------------------------- candidates

    /**
     * @test
     */
    public function candidatesListsTheUnionFromTheManifest(): void
    {
        $this->invoke(new BuildCommand($this->root()), ['bin', 'build']);

        [$code, $out] = $this->invoke(new CandidatesCommand($this->root()), ['bin', 'candidates']);

        self::assertSame(0, $code);
        self::assertStringContainsString('flex', $out);
    }

    /**
     * @test
     */
    public function candidatesExitsOneWithoutAManifest(): void
    {
        [$code, , $err] = $this->invoke(new CandidatesCommand($this->root()), ['bin', 'candidates']);

        self::assertSame(1, $code);
        self::assertStringContainsString('build', $err);
    }

    /**
     * `--file` re-extracts live, so it sees an edit the scanner has not.
     *
     * @test
     */
    public function candidatesFileReExtractsLive(): void
    {
        $this->project()->writeFile('templates/fresh.twig', "<div class=\"only-here-now\"></div>\n");

        [$code, $out] = $this->invoke(
            new CandidatesCommand($this->root()),
            ['bin', 'candidates', '--file=templates/fresh.twig']
        );

        self::assertSame(0, $code);
        self::assertStringContainsString('only-here-now', $out);
    }

    /**
     * Threat T-02: the argument becomes a path, so it is confined first.
     *
     * @test
     */
    public function candidatesFileRefusesAPathOutsideTheProject(): void
    {
        // Asserted through the exit code rather than the exception, because
        // `Application` is what a user meets: it catches everything and turns
        // it into a code. A `PathException` escaping to a stack trace would be
        // a T-06 leak in its own right.
        [$code, $out, $err] = $this->invoke(
            new CandidatesCommand($this->root()),
            ['bin', 'candidates', '--file=../../../etc/passwd']
        );

        self::assertSame(1, $code);
        self::assertSame('', $out, 'nothing outside the project may be printed');
        self::assertStringNotContainsString('root:', $err);
    }

    // ---------------------------------------------------------- ConfigLocator

    /**
     * @test
     */
    public function theLocatorPrefersTheCwdFileOverTheConfigDirectory(): void
    {
        $root = $this->root();
        $this->writeConfig(['safelist' => ['from-cwd']]);
        $this->ensureDirectory($root . '/config');
        \file_put_contents($root . '/config/tailwind.php', "<?php\n\nreturn ['safelist' => ['from-config-dir']];\n");

        self::assertSame($root . '/tailwind.php', ConfigLocator::locate(null, $root));
    }

    /**
     * @test
     */
    public function theLocatorFallsBackToTheConfigDirectory(): void
    {
        $root = $this->root();
        \unlink($root . '/tailwind.php');
        $this->ensureDirectory($root . '/config');
        \file_put_contents($root . '/config/tailwind.php', "<?php\n\nreturn [];\n");

        self::assertSame($root . '/config/tailwind.php', ConfigLocator::locate(null, $root));
    }

    /**
     * An explicit `--config` that does not exist is an error, not a fall
     * through: someone who names a file means that file.
     *
     * @test
     */
    public function anExplicitConfigThatIsMissingIsAUsageError(): void
    {
        $this->expectException(ConsoleException::class);

        ConfigLocator::locate('nope/tailwind.php', $this->root());
    }

    /**
     * @test
     */
    public function noConfigAnywhereIsAUsageError(): void
    {
        $root = $this->root();

        // `TempProject` writes `config/tailwind.php` of its own, so both
        // conventional locations have to go for this row to mean anything.
        \unlink($root . '/tailwind.php');
        \unlink($root . '/config/tailwind.php');

        try {
            ConfigLocator::locate(null, $root);
            self::fail('expected a ConsoleException');
        } catch (ConsoleException $e) {
            self::assertSame(2, $e->exitCode());
            self::assertStringContainsString('--config', $e->getMessage());
        }
    }

    /**
     * Threat T-08. A config file inside the cache directory is refused, and the
     * side effect it would have had must not happen — asserted on the
     * filesystem rather than on a return value.
     *
     * @test
     */
    public function aConfigInsideTheCacheDirectoryIsRefused(): void
    {
        $root = $this->root();
        $marker = $root . '/pwned.txt';

        $this->ensureDirectory($root . '/cache/tailwind');
        \file_put_contents(
            $root . '/cache/tailwind/tailwind.php',
            "<?php\n\n\\file_put_contents('" . \addslashes($marker) . "', 'x');\n\nreturn [];\n"
        );

        $thrown = null;

        try {
            ConfigLocator::load('cache/tailwind/tailwind.php', $root);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'a config inside the cache directory must be refused');
        self::assertFileDoesNotExist($marker, 'the planted config executed');
    }

    // ----------------------------------------------------------------- harness

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
     * `TempProject` already lays down some of these, so creating one
     * unconditionally emits a warning PHPUnit reports as an issue.
     */
    private function ensureDirectory(string $path): void
    {
        if (!\is_dir($path)) {
            \mkdir($path, 0777, true);
        }
    }

    /**
     * Write the project's `tailwind.php`, which is what the commands locate.
     *
     * @param array<string, mixed> $extra
     */
    private function writeConfig(array $extra): void
    {
        $root = $this->project === null ? null : $this->project->root();

        if ($root === null) {
            return;
        }

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
