<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tests\Support\TempTree;

/**
 * **T-03 — command injection into the Tailwind CLI** (design doc 12; design doc
 * 06 §The CLI driver).
 *
 * The control is not careful quoting. It is that **argv is a closed set**: the
 * confined binary path, a fixed list of literal flags, and absolute paths
 * `StandaloneCliCompiler` generated moments earlier inside its own temp
 * directory. Nothing that varies with the project — no candidate, no stylesheet
 * text, no configuration — can appear there at all, which is what turns
 * injection from a question answered carefully into a question that cannot be
 * asked (decision CCP-A10).
 *
 * So the assertions here are about *shape*, not about escaping. Every element of
 * the vector the child actually received is checked against that closed set, and
 * the hostile strings are then found where they are supposed to be — inside
 * `candidates.txt`, which is a file and not a command line.
 *
 * The same reasoning covers the environment: the child gets the four entries the
 * compiler sets plus whatever the host demands, and inherits nothing from this
 * process. That is asserted against a marker variable set with `\putenv()`
 * immediately before the call, because "it does not leak" is only convincing if
 * something was there to leak.
 *
 * Read from the fixture's `<cacheDir>/<name>.argv.json` record: the temp
 * directory is removed in a `finally`, so by the time `compile()` returns there
 * is nothing left to inspect except what the child wrote down.
 */
final class T03CliArgvTest extends TestCase
{
    /**
     * Generous: nothing here is a timing assertion.
     */
    private const TIMEOUT_SEC = 20.0;

    /**
     * Set with `\putenv()` before every compile. If the child can see it, the
     * child is inheriting this process's environment (threat T-09).
     */
    private const MARKER = 'TAILWINDPHP_T03_MARKER';

    /**
     * The only literals the compiler is allowed to put on the command line.
     *
     * @var list<string>
     */
    private const FLAGS = ['-i', '-o', '--cwd', '--minify'];

    /**
     * Shell metacharacters as *candidates*. They come from template files, which
     * is the one genuinely project-controlled input a compile has.
     *
     * @var list<string>
     */
    private const HOSTILE_CANDIDATES = [
        'a;id',
        '$(id)',
        '`id`',
        'a&b',
        'a|b',
        'a>b',
        'a"b',
        "a'b",
        'two words',
        '*',
    ];

    /**
     * A string that appears nowhere but the entry stylesheet, so finding it in
     * argv would be unambiguous.
     */
    private const ENTRY_SENTINEL = 'T03-ENTRY-SENTINEL';

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

        $this->root = TempTree::make('tailwindphp-t03');
        $this->cacheDir = $this->root . '/cache';

        \mkdir($this->cacheDir, 0777, true);

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        \putenv(self::MARKER);

        TempTree::remove($this->root);

        parent::tearDown();
    }

    /**
     * The closed set, element by element. `--minify` is asked for so the longest
     * form of the vector is the one under test.
     *
     * @test
     */
    public function everyArgumentIsTheBinaryALiteralFlagOrAPathWeGenerated(): void
    {
        $this->runHostileCompile();

        $argv = $this->recordedArgv();
        $directory = $this->recordedString('cwd');

        self::assertStringStartsWith($this->cacheDir . '/.cli-', $directory);

        // argv[0] is the binary as the *child* sees itself: on Windows the `.cmd`
        // shim re-invokes PHP on the fixture script, so it is the script rather
        // than the shim. Either way it is an absolute path inside the fixture
        // directory and nothing the project could influence.
        self::assertSame(
            self::comparablePath(\dirname(__DIR__, 1) . '/Fixtures/bin'),
            self::comparablePath(\dirname($argv[0])),
            'argv[0] is not the fixture binary.'
        );

        // The temp directory itself (`--cwd`) or something inside it — and the
        // temp directory is inside the cache directory, asserted above.
        $prefix = self::comparablePath($directory);

        if ($prefix === '') {
            self::fail('The fixture recorded an empty working directory.');
        }

        foreach (\array_slice($argv, 1) as $offset => $argument) {
            if (\in_array($argument, self::FLAGS, true)) {
                continue;
            }

            self::assertStringStartsWith(
                $prefix,
                self::comparablePath($argument),
                \sprintf('argv[%d] is neither a literal flag nor a path we generated.', $offset + 1)
            );
        }

        // And the shape itself, so a new argument cannot be added unnoticed.
        self::assertSame(
            ['-i', $directory . '/input.css', '-o', $directory . '/output.css', '--cwd', $directory, '--minify'],
            \array_slice($argv, 1)
        );
    }

    /**
     * The property CCP-A10 exists for: candidate text travels in a file, so the
     * argument vector keeps the same shape whatever a template contains.
     *
     * @test
     */
    public function candidateTextNeverBecomesAnArgument(): void
    {
        $this->runHostileCompile();

        $argv = $this->recordedArgv();

        foreach (self::HOSTILE_CANDIDATES as $candidate) {
            foreach ($argv as $offset => $argument) {
                self::assertStringNotContainsString(
                    $candidate,
                    $argument,
                    \sprintf('Candidate text reached argv[%d].', $offset)
                );
            }
        }

        // It did reach the child — through the route designed for it.
        $candidates = \rtrim($this->recordedString('candidates'), "\r\n");

        self::assertSame(\implode(' ', self::HOSTILE_CANDIDATES), $candidates);
    }

    /**
     * The stylesheet is the other project-controlled input; it travels in
     * `input.css` for the same reason.
     *
     * @test
     */
    public function entryStylesheetTextNeverBecomesAnArgument(): void
    {
        $this->runHostileCompile();

        foreach ($this->recordedArgv() as $offset => $argument) {
            self::assertStringNotContainsString(
                self::ENTRY_SENTINEL,
                $argument,
                \sprintf('Stylesheet text reached argv[%d].', $offset)
            );
        }

        self::assertStringContainsString(self::ENTRY_SENTINEL, $this->recordedString('input'));
    }

    /**
     * The child inherits nothing implicitly (T-09). A compiler that inherited the
     * web server's environment would inherit its secrets, so the whole
     * environment is constructed rather than filtered.
     *
     * The Windows allowance is `cmd.exe`'s, not ours: a `.cmd` binary (CCP-A23)
     * is started through the shell, which puts `COMSPEC` and `PROMPT` into the
     * block it hands on. Neither is inherited from this process — they are
     * invented by the shell — and the marker below proves the distinction.
     *
     * @test
     */
    public function theChildInheritsNoVariableFromThisProcess(): void
    {
        $this->runHostileCompile();

        $env = $this->recordedEnv();
        $directory = $this->recordedString('cwd');

        self::assertArrayNotHasKey(self::MARKER, $env, 'The child inherited this process\'s environment.');

        self::assertSame([], \array_values(\array_diff(\array_keys($env), $this->permittedEnvironmentKeys())));

        self::assertSame('1', $env['NO_COLOR'] ?? '');
        self::assertSame($directory, $env['HOME'] ?? '');
        self::assertSame($directory, $env['TMPDIR'] ?? '');
    }

    // --------------------------------------------------------------- driving

    /**
     * One compile carrying every hostile input at once, with the marker variable
     * live in this process while it runs.
     */
    private function runHostileCompile(): void
    {
        \putenv(self::MARKER . '=leaked-into-the-child');

        try {
            $this->compiler()->compile(
                \sprintf("/* %s */\n.t03-entry{color:red}\n", self::ENTRY_SENTINEL),
                new InMemoryImportResolver([]),
                self::HOSTILE_CANDIDATES,
                new CompileOptions(true, false)
            );
        } finally {
            \putenv(self::MARKER);
        }
    }

    private function compiler(): StandaloneCliCompiler
    {
        return new StandaloneCliCompiler(
            \dirname(__DIR__, 1)
                . '/Fixtures/bin/fake-tailwindcss'
                . (\DIRECTORY_SEPARATOR === '\\' ? '.cmd' : ''),
            '4.1.18',
            $this->cacheDir,
            self::TIMEOUT_SEC,
            new Process(),
            new Filesystem(),
            $this->root,
            new AtomicWriter($this->root),
            new Environment()
        );
    }


    /**
     * The four the compiler always sets, plus — on Windows only — the two
     * machine variables `Environment` carries through so `cmd.exe` can start a
     * `.cmd` binary at all, plus the two `cmd.exe` invents for itself.
     *
     * `COMSPEC` and `PROMPT` are measured, not assumed: they are created by the
     * shell, not inherited from this process, which is why their presence does
     * not contradict the inheritance assertion.
     *
     * @return list<string>
     */
    private function permittedEnvironmentKeys(): array
    {
        $keys = ['PATH', 'HOME', 'TMPDIR', 'NO_COLOR'];

        if (\DIRECTORY_SEPARATOR === '\\') {
            $keys[] = 'SystemRoot';
            $keys[] = 'PATHEXT';
            $keys[] = 'COMSPEC';
            $keys[] = 'PROMPT';
        }

        return $keys;
    }

    // ------------------------------------------------------------ the record

    /**
     * Comparable across platforms: forward slashes, and case-insensitive where
     * the filesystem is.
     */
    private static function comparablePath(string $path): string
    {
        $slashed = \str_replace('\\', '/', $path);

        return \DIRECTORY_SEPARATOR === '\\' ? \strtolower($slashed) : $slashed;
    }

    /**
     * `scandir`, never `glob`: both the temp directory and the record are
     * dot-prefixed, and `glob()` does not match those — a `glob`-based lookup
     * here finds nothing and the test passes while measuring nothing.
     *
     * @return array<string, mixed>
     */
    private function record(): array
    {
        $entries = \scandir($this->cacheDir);

        if ($entries === false) {
            self::fail('The cache directory could not be read.');
        }

        $path = null;

        foreach (\array_diff($entries, ['.', '..']) as $entry) {
            if (\substr($entry, -10) === '.argv.json') {
                $path = $this->cacheDir . '/' . $entry;
            }
        }

        if ($path === null) {
            self::fail('The fixture wrote no argv record, so the binary was never reached.');
        }

        $decoded = \json_decode((string) \file_get_contents($path), true);

        if (!\is_array($decoded)) {
            self::fail('The fixture argv record is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function recordedArgv(): array
    {
        $record = $this->record();
        $raw = \array_key_exists('argv', $record) ? $record['argv'] : null;

        if (!\is_array($raw) || $raw === []) {
            self::fail('The fixture recorded no argv.');
        }

        $argv = [];

        foreach ($raw as $value) {
            if (!\is_string($value)) {
                self::fail('An argv element reached the child as something other than a string.');
            }

            $argv[] = $value;
        }

        return $argv;
    }

    /**
     * @return array<string, string>
     */
    private function recordedEnv(): array
    {
        $record = $this->record();
        $raw = \array_key_exists('env', $record) ? $record['env'] : null;

        if (!\is_array($raw)) {
            self::fail('The fixture recorded no environment.');
        }

        $env = [];

        foreach ($raw as $key => $value) {
            if (!\is_string($value)) {
                self::fail('An environment value reached the child as something other than a string.');
            }

            $env[(string) $key] = $value;
        }

        return $env;
    }

    private function recordedString(string $key): string
    {
        $record = $this->record();
        $value = \array_key_exists($key, $record) ? $record[$key] : null;

        if (!\is_string($value)) {
            self::fail(\sprintf('The fixture recorded no string under "%s".', $key));
        }

        return $value;
    }
}
