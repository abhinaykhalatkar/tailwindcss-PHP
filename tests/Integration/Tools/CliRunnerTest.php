<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\Hash;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\CliRunner;

/**
 * The maintainer-side wrapper around the pinned Tailwind binary
 * (decisions ENG-A1 and ENG-A11; threats T-03, T-04, T-18).
 *
 * Runs against `tests/Fixtures/bin/fake-tailwindcss`, which records what
 * actually crossed the process boundary, so every assertion about argv and the
 * child environment is about the real `proc_open` call and not about a double.
 * The fixture writes its record beside `--cwd`; handing the runner this test's
 * own work root is what keeps those records out of the machine's temp directory.
 */
final class CliRunnerTest extends TestCase
{
    private const VERSION = '4.1.18';

    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        self::loadTools();

        $this->root = TempTree::make('tailwindphp-cli-runner');

        \mkdir($this->root . '/work', 0777, true);

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // ----------------------------------------------------------- the process

    /** @test */
    public function itRunsTheBinaryWithAFixedArgvAndReturnsTheStylesheet(): void
    {
        $css = $this->runner()->compile("/* entry */\n", 'flex underline', [], false);

        self::assertStringContainsString('.flex{--fake:1}', $css);
        self::assertStringContainsString('.underline{--fake:1}', $css);

        $argv = $this->record()['argv'];

        // [0] is the script itself; what follows is the whole vector.
        self::assertCount(7, $argv);
        self::assertSame('-i', $argv[1]);
        self::assertSame('input.css', \basename($argv[2]));
        self::assertSame('-o', $argv[3]);
        self::assertSame('output.css', \basename($argv[4]));
        self::assertSame('--cwd', $argv[5]);
        self::assertSame(\dirname($argv[2]), $argv[6]);
    }

    /** @test */
    public function minifyIsOneMoreLiteralFlag(): void
    {
        $css = $this->runner()->compile("/* entry */\n", 'flex', [], true);

        $argv = $this->record()['argv'];

        self::assertSame('--minify', $argv[7]);
        self::assertCount(8, $argv);
        self::assertStringNotContainsString("\n", $css);
    }

    /** @test */
    public function theInputAndTheCandidateLineReachTheBinaryVerbatim(): void
    {
        $this->runner()->compile("@import \"tailwindcss\" source(none);\n", 'p-4 md:flex', [], false);

        $record = $this->record();

        self::assertSame("@import \"tailwindcss\" source(none);\n", $record['input']);
        self::assertSame('p-4 md:flex', \rtrim($record['candidates'], "\r\n"));
    }

    /** @test */
    public function theChildInheritsNothingFromTheParentEnvironment(): void
    {
        \putenv('TWPHP_RUNNER_PROBE=leaked');

        try {
            $this->runner()->compile("/* entry */\n", 'flex', [], false);
        } finally {
            \putenv('TWPHP_RUNNER_PROBE');
        }

        $env = $this->record()['env'];

        self::assertArrayNotHasKey('TWPHP_RUNNER_PROBE', $env);
        self::assertSame('1', $env['NO_COLOR']);
        self::assertSame($this->record()['cwd'], $env['HOME']);
    }

    /** @test */
    public function theTemporaryProjectIsRemovedAfterwards(): void
    {
        $this->runner()->compile("/* entry */\n", 'flex', [], false);

        $directories = [];

        foreach ((array) \scandir($this->root . '/work') as $entry) {
            if ($entry !== '.' && $entry !== '..' && \is_dir($this->root . '/work/' . $entry)) {
                $directories[] = $entry;
            }
        }

        self::assertSame([], $directories);
    }

    // --------------------------------------------------------------- failure

    /** @test */
    public function aNonZeroExitSurfacesTheBinariesOwnMessage(): void
    {
        try {
            $this->runner()->compile("/* fake: fail 2 */\n", 'flex', [], false);
            self::fail('A failing binary must not look like success.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exited with status 2', $e->getMessage());
            self::assertStringContainsString('Unexpected token', $e->getMessage());
        }
    }

    /** @test */
    public function aBinaryThatWritesNothingIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wrote no stylesheet');

        $this->runner()->compile("/* fake: garbage */\n", 'flex', [], false);
    }

    // ------------------------------------------------------------ T-04 / T-18

    /** @test */
    public function aBinaryWithNoPinnedDigestIsNeverRun(): void
    {
        $runner = $this->runner(Checksums::fromTable([]));

        try {
            $runner->compile("/* entry */\n", 'flex', [], false);
            self::fail('An unrecognised binary must not be executed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not match the digest pinned for 4.1.18', $e->getMessage());
        }

        // Refused before anything was spawned: the fixture records every run.
        self::assertSame([], $this->records());
    }

    /**
     * A digest that is pinned — but for another release — is still the wrong
     * binary for these goldens.
     *
     * @test
     */
    public function aDigestPinnedForADifferentVersionIsRefused(): void
    {
        $other = Checksums::fromTable([
            '4.1.17' => [(new Environment())->platform() => Hash::sha256File(self::fixtureBinary())],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match the digest pinned for 4.1.18');

        $this->runner($other)->verify();
    }

    /** @test */
    public function theCorrectDigestForTheRequestedVersionIsAccepted(): void
    {
        $this->runner()->verify();

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function aMissingBinaryIsAClearErrorThatNamesTheInstallCommandAndDownloadsNothing(): void
    {
        $repo = $this->root . '/repo';

        \mkdir($repo . '/tools/.cache', 0777, true);

        self::assertNull(CliRunner::locate($repo));

        try {
            CliRunner::forRepository($repo, self::VERSION);
            self::fail('A missing binary must be reported.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('install-cli', $e->getMessage());
            self::assertStringContainsString('--version=4.1.18', $e->getMessage());
            self::assertStringContainsString('tools/.cache/', $e->getMessage());
        }

        self::assertSame(['.', '..'], \scandir($repo . '/tools/.cache'), 'Nothing may be fetched or created.');
    }

    /** @test */
    public function locateFindsTheBinaryUnderTheCacheDirectory(): void
    {
        $repo = $this->root . '/repo';
        $name = \DIRECTORY_SEPARATOR === '\\' ? 'tailwindcss.exe' : 'tailwindcss';

        \mkdir($repo . '/tools/.cache', 0777, true);
        \file_put_contents($repo . '/tools/.cache/' . $name, 'x');
        \chmod($repo . '/tools/.cache/' . $name, 0755);

        self::assertSame($repo . '/tools/.cache/' . $name, CliRunner::locate($repo));
    }

    // ----------------------------------------------------- hostile file names

    /**
     * Extra files become files in the temporary project; their names must never
     * be able to leave it or collide with the three the runner owns (T-01).
     *
     * @test
     *
     * @dataProvider hostileFileNames
     */
    public function aHostileExtraFileNameIsRejectedBeforeAnythingIsWritten(string $name): void
    {
        try {
            $this->runner()->compile("/* entry */\n", 'flex', [$name => 'x'], false);
            self::fail('The name "' . $name . '" must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not an acceptable file name', $e->getMessage());
        }

        self::assertSame([], $this->records());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileFileNames(): array
    {
        return [
            'parent traversal' => ['../escape.css'],
            'nested path' => ['sub/dir.css'],
            'windows separator' => ['sub\\dir.css'],
            'absolute path' => ['/etc/passwd'],
            'drive letter' => ['C:evil.css'],
            'hidden file' => ['.env'],
            'reserved input' => ['input.css'],
            'reserved output' => ['output.css'],
            'reserved candidates' => ['candidates.txt'],
            'empty' => [''],
            'nul byte' => ["a\0.css"],
            'space' => ['a b.css'],
        ];
    }

    /** @test */
    public function anOrdinaryPartialNameIsAccepted(): void
    {
        $css = $this->runner()->compile("/* entry */\n", 'flex', ['import-partial.css' => ".card{}\n"], false);

        self::assertStringContainsString('.flex{--fake:1}', $css);
    }

    // ---------------------------------------------------------------- helpers

    private function runner(?Checksums $checksums = null): CliRunner
    {
        $environment = new Environment();

        return new CliRunner(
            self::fixtureBinary(),
            self::VERSION,
            $checksums ?? Checksums::fromTable([
                self::VERSION => [$environment->platform() => Hash::sha256File(self::fixtureBinary())],
            ]),
            $environment,
            new Process(),
            new Filesystem(),
            $this->root . '/work',
            20.0
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        $records = $this->records();

        self::assertCount(1, $records, 'Exactly one run should have been recorded.');

        return $records[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        $records = [];

        foreach ((array) \scandir($this->root . '/work') as $entry) {
            if (\substr((string) $entry, -10) !== '.argv.json') {
                continue;
            }

            $decoded = \json_decode((string) \file_get_contents($this->root . '/work/' . $entry), true);

            if (\is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    private static function fixtureBinary(): string
    {
        $script = \dirname(__DIR__, 2) . '/Fixtures/bin/fake-tailwindcss';

        if (\DIRECTORY_SEPARATOR === '\\') {
            return \str_replace('\\', '/', $script) . '.cmd';
        }

        if (!\is_executable($script)) {
            // A checkout made on Windows has no executable bit; the fixture is
            // ours, so it is made runnable rather than depended upon.
            \chmod($script, 0755);
        }

        return $script;
    }

    /**
     * `tools/` is maintainer tooling: not autoloaded, required by path
     * (design doc 17).
     */
    private static function loadTools(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/CliRunner.php';
    }
}
