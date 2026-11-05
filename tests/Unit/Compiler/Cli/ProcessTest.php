<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Tests\Support\RequiresSpawning;
use TailwindPhp\Tests\Support\TempTree;

/**
 * `Process::run()` on a host without one of the process functions (TASK-0061):
 * an explicit `driver: cli` there must get the library's driver-unavailable
 * exception, never PHP's "Call to undefined function" `Error` — and with
 * `proc_open` present but `proc_get_status` or `proc_terminate` missing, never
 * a child that is spawned and then left unreaped or unkilled. The functions
 * are one list, {@see Environment::PROCESS_FUNCTIONS}, which `auto` asks too.
 *
 * Proven both ways: directly inside the restricted cell, and from an ordinary
 * run by starting a child PHP with `-d disable_functions=<function>` (a
 * separate process, not a stubbed probe — on PHP 8 a disabled function does not
 * exist, and that is exactly the condition the guard checks).
 */
final class ProcessTest extends TestCase
{
    /**
     * @var string
     */
    private $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            TempTree::remove($this->dir);
            $this->dir = '';
        }

        parent::tearDown();
    }

    /** @test */
    public function withoutProcOpenTheLibraryRefusesWithItsOwnException(): void
    {
        if (\function_exists('proc_open')) {
            self::markTestSkipped(
                'proc_open is available here; this row runs in the restricted cell, the next rows cover it here.'
            );
        }

        $this->expectException(DriverUnavailableException::class);
        $this->expectExceptionMessage(self::message('proc_open'));

        (new Process())->run([\PHP_BINARY, '-v'], \sys_get_temp_dir(), [], 5.0);
    }

    /**
     * @test
     *
     * @dataProvider processFunctions
     */
    public function aChildWithoutAProcessFunctionGetsTheLibraryExceptionAndSpawnsNothing(string $function): void
    {
        RequiresSpawning::check();

        $this->dir = TempTree::make('tailwindphp-process-guard');
        $marker = $this->dir . '/spawned';
        // Were the guard missing or late, this child-of-the-child would write the marker.
        $code = 'try { (new \\TailwindPhp\\Compiler\\Cli\\Process())->run([PHP_BINARY, "-r", '
            . \var_export('file_put_contents(' . \var_export($marker, true) . ', "x");', true)
            . '], sys_get_temp_dir(), [], 5.0); echo "no exception"; }'
            . ' catch (\\Throwable $e) { echo get_class($e), "|", $e->getMessage(); }';

        $output = $this->child($function, $code);

        self::assertSame(DriverUnavailableException::class . '|' . self::message($function), $output);
        self::assertFileDoesNotExist($marker, 'nothing was spawned');
    }

    /**
     * `auto` asks the same list, so a host missing any of them falls back to the
     * PHP engine instead of reaching the guard above.
     *
     * @test
     *
     * @dataProvider processFunctions
     */
    public function theEnvironmentAnswersNoWhenAnyProcessFunctionIsMissing(string $function): void
    {
        RequiresSpawning::check();

        $code = '$e = new \\TailwindPhp\\Compiler\\Cli\\Environment();'
            . ' var_export($e->processFunctionAvailable()); echo "|", $e->missingProcessFunction();';

        self::assertSame('false|' . $function, $this->child($function, $code), 'no, and it names the function');
        self::assertTrue((new Environment())->processFunctionAvailable(), 'and yes on this host');
        self::assertNull((new Environment())->missingProcessFunction());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function processFunctions(): array
    {
        $rows = [];

        foreach (Environment::PROCESS_FUNCTIONS as $function) {
            $rows[$function] = [$function];
        }

        return $rows;
    }

    /** @test */
    public function theListIsTheFunctionsASpawnUses(): void
    {
        self::assertSame(
            ['proc_open', 'proc_get_status', 'usleep', 'proc_terminate', 'proc_close'],
            Environment::PROCESS_FUNCTIONS
        );
    }

    /**
     * T-05: an exception after the spawn (here `microtime()` is missing, so
     * the poll loop throws right after `proc_open`) must not leave the child
     * running. The grandchild sleeps, then writes a marker; it was killed, so
     * the marker never appears, even after its sleep has elapsed.
     *
     * @test
     */
    public function aStartedChildDoesNotOutliveAnExceptionFromRun(): void
    {
        RequiresSpawning::check();

        $this->dir = TempTree::make('tailwindphp-process-kill');
        $marker = $this->dir . '/survived';
        $grandchild = 'sleep(3); file_put_contents(' . \var_export($marker, true) . ', "x");';
        $code = 'try { (new \\TailwindPhp\\Compiler\\Cli\\Process())->run([PHP_BINARY, "-r", '
            . \var_export($grandchild, true) . '], sys_get_temp_dir(), ['
            . '"PATH" => (string) getenv("PATH"), "SystemRoot" => (string) getenv("SystemRoot")], 30.0);'
            . ' echo "no exception"; } catch (\\Throwable $e) { echo get_class($e), "|", $e->getMessage(); }';

        // Its own temp directory: without TMP/TEMP a Windows child's sys_get_temp_dir()
        // is not writable and tempnam() fails before the spawn.
        $output = $this->child('microtime', $code, $this->dir);

        self::assertSame('Error|Call to undefined function microtime()', $output);
        \sleep(4);
        self::assertFileDoesNotExist($marker, 'the grandchild was killed before it could write');
    }

    /**
     * T-05: an exception before the spawn (here `hrtime()` is missing) leaves
     * no temporary file behind: the child runs with its own temp directory,
     * which must be empty afterwards.
     *
     * @test
     */
    public function noTemporaryFileSurvivesAnExceptionBeforeTheSpawn(): void
    {
        RequiresSpawning::check();

        $this->dir = TempTree::make('tailwindphp-process-tmp');
        $code = 'try { (new \\TailwindPhp\\Compiler\\Cli\\Process())'
            . '->run([PHP_BINARY, "-v"], sys_get_temp_dir(), [], 5.0);'
            . ' echo "no exception"; } catch (\\Throwable $e) { echo get_class($e), "|", $e->getMessage(); }';

        $output = $this->child('hrtime', $code, $this->dir);

        self::assertSame('Error|Call to undefined function hrtime()', $output);
        self::assertSame([], \array_values(\array_diff((array) \scandir($this->dir), ['.', '..'])));
    }

    /** @test */
    public function theArgumentChecksStillComeFirst(): void
    {
        // A configuration error is reported as one whatever the host can do.
        $this->expectException(\InvalidArgumentException::class);

        (new Process())->run([\PHP_BINARY], \sys_get_temp_dir(), [], 0.0);
    }

    private static function message(string $function): string
    {
        return 'Compiler driver "cli" is not available: ' . $function . ' is unavailable on this host;'
            . ' use driver: php';
    }

    /**
     * Run $code in a child PHP with $function disabled and the autoloader loaded;
     * its trimmed stdout.
     */
    private function child(string $function, string $code, ?string $tempDir = null): string
    {
        $env = ['PATH' => (string) \getenv('PATH'), 'SystemRoot' => (string) \getenv('SystemRoot')];

        if ($tempDir !== null) {
            $env += ['TMP' => $tempDir, 'TEMP' => $tempDir, 'TMPDIR' => $tempDir];
        }

        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $result = (new Process())->run(
            [
                \PHP_BINARY,
                '-d',
                'disable_functions=' . $function,
                '-r',
                'require ' . \var_export($autoload, true) . '; ' . $code,
            ],
            \sys_get_temp_dir(),
            $env,
            60.0
        );

        self::assertSame(0, $result->exitCode(), $result->stderr());

        return \trim($result->stdout());
    }
}
