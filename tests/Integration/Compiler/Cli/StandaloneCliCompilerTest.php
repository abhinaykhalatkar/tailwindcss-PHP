<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Compiler\Cli\StandaloneCliCompiler;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Exception\CliProcessException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\InMemoryImportResolver;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The standalone-binary driver, end to end (design doc 06, ADR-002; threats
 * T-03, T-05, T-14).
 *
 * Integration with a **real child process**, for the same reason
 * {@see ProcessTest} is: every property worth pinning here is a property of
 * what actually crossed the process boundary. A double would assert that the
 * test author remembered the design, not that the design happens.
 *
 * The temp directory is gone by the time `compile()` returns — that is the
 * point of the `finally` — so the assertions read
 * `tests/Fixtures/bin/fake-tailwindcss`'s record instead. The fixture writes it
 * *beside* `--cwd` as `<cacheDir>/<name>.argv.json`, which is both why cleanup
 * still succeeds and why the record outlives it.
 *
 * Two platform notes that cost real time to find:
 *
 * - The cache directory is scanned with `scandir()`, never `glob()`: the temp
 *   directory is `.cli-<pid>-<8 hex>` and the record is `.cli-….argv.json`, and
 *   `glob()` does not match dot-prefixed names — a `glob()` assertion here
 *   passes while measuring nothing.
 * - On Windows the child needs `SystemRoot` or `cmd.exe` never starts. It comes
 *   from `Environment::childEnvironment()`, the one place in `src/` allowlisted
 *   to read `getenv()` (static rule R3) — which is why these tests inject the
 *   real `Environment` rather than a substitute: a fake would describe a
 *   different child world than the one production gets.
 */
final class StandaloneCliCompilerTest extends TestCase
{
    /**
     * How long the fixture is given. Generous on purpose: nothing in this class
     * is a timing assertion, and a loaded CI box must not turn one into a
     * failure. The deadline itself is exercised by `T05CliTimeoutTest`.
     */
    private const TIMEOUT_SEC = 20.0;

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

        $this->root = TempTree::make('tailwindphp-cli-compiler');
        $this->cacheDir = $this->root . '/cache';

        \mkdir($this->cacheDir, 0777, true);

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // ----------------------------------------------------------- happy path

    /** @test */
    public function theCompiledStylesheetCarriesARuleForEveryCandidate(): void
    {
        $result = $this->compileEntry(".entry{color:red}\n", ['flex', 'underline', 'text-red-500']);

        self::assertFalse($result->hasErrors());
        self::assertSame('cli', $result->driver());
        self::assertStringContainsString('.flex{--fake:1}', $result->css());
        self::assertStringContainsString('.underline{--fake:1}', $result->css());
        self::assertStringContainsString('.text-red-500{--fake:1}', $result->css());
    }

    /**
     * Decision CCP-A10: the candidate list is **one space-separated line**, not
     * one candidate per line. The binary's `@source` reads it as a word list, so
     * newlines would change the meaning rather than merely the formatting.
     *
     * @test
     */
    public function candidatesAreHandedOverAsOneSpaceSeparatedLine(): void
    {
        $this->compileEntry(".entry{}\n", ['flex', 'underline', 'text-red-500']);

        // One line, so the whole file is the list and a trailing terminator is
        // the only newline it may contain.
        $candidates = \rtrim($this->recordedString('candidates'), "\r\n");

        self::assertSame('flex underline text-red-500', $candidates);
        self::assertStringNotContainsString("\n", $candidates);
        self::assertStringNotContainsString("\r", $candidates);
    }

    /**
     * `@source none;` first, then the candidate file: the binary must scan
     * exactly what we handed it and nothing it finds for itself, or the
     * stylesheet stops being a function of the candidate union.
     *
     * @test
     */
    public function theGeneratedInputPinsTheCandidateFileAsTheOnlySource(): void
    {
        $this->compileEntry(".entry{}\n");

        self::assertStringStartsWith(
            "@source none;\n@source \"./candidates.txt\";\n",
            $this->recordedString('input')
        );
    }

    // -------------------------------------------------------------- imports

    /** @test */
    public function aRelativeImportIsInlinedAndRecordedInTheImportGraph(): void
    {
        $result = $this->compileEntry(
            "@import \"./partial.css\";\n.entry{}\n",
            ['flex'],
            false,
            ['partial.css' => ".partial{--from-partial:1}\n"]
        );

        $input = $this->recordedString('input');

        self::assertStringContainsString('.partial{--from-partial:1}', $input);
        self::assertStringNotContainsString('@import "./partial.css"', $input);
        self::assertSame(['partial.css'], $result->imports());
    }

    /**
     * A bare specifier is the binary's own business (`tailwindcss` and its
     * subpaths). Rewriting it would break the one thing the CLI is better at
     * than we are, so the line is passed through untouched and never becomes a
     * watched input.
     *
     * @test
     */
    public function aBareImportIsLeftForTheBinaryToResolve(): void
    {
        $result = $this->compileEntry("@import \"tailwindcss\";\n.entry{}\n");

        self::assertStringContainsString('@import "tailwindcss";', $this->recordedString('input'));
        self::assertSame([], $result->imports());
    }

    /**
     * A cycle is a mistake worth surviving, not a fatal: the walk terminates and
     * every file is inlined exactly once, whichever way round it was reached.
     *
     * @test
     */
    public function anImportCycleTerminatesAndInlinesEachFileOnce(): void
    {
        $result = $this->compileEntry(
            "@import \"./a.css\";\n.entry{}\n",
            ['flex'],
            false,
            [
                'a.css' => "@import \"./b.css\";\n.cycle-a{--a:1}\n",
                'b.css' => "@import \"./a.css\";\n.cycle-b{--b:1}\n",
            ]
        );

        $input = $this->recordedString('input');

        self::assertSame(1, \substr_count($input, '.cycle-a{--a:1}'));
        self::assertSame(1, \substr_count($input, '.cycle-b{--b:1}'));
        self::assertStringNotContainsString('@import "./a.css"', $input);
        self::assertStringNotContainsString('@import "./b.css"', $input);
        self::assertSame(['a.css', 'b.css'], $result->imports());
    }

    // -------------------------------------------------------------- options

    /** @test */
    public function minifyReachesTheCommandLineOnlyWhenItWasAskedFor(): void
    {
        $this->compileEntry(".entry{}\n", ['flex'], true);

        self::assertContains('--minify', $this->recordedArgv());
        self::assertTrue($this->recordedMinify());

        $this->compileEntry(".entry{}\n", ['flex'], false);

        self::assertNotContains('--minify', $this->recordedArgv());
        self::assertFalse($this->recordedMinify());
    }

    // ------------------------------------------------------------------ T-03

    /**
     * **T-03, stated directly.** The argument vector is a closed set: the binary,
     * a fixed list of literal flags, and three absolute paths generated moments
     * earlier inside our own temp directory. Nothing configurable and — above
     * all — no candidate text ever appears in it.
     *
     * The candidates below are shell metacharacters on purpose. They must arrive
     * at the child *through `candidates.txt`*, which is the whole reason
     * CCP-A10 puts them in a file: an argument vector that cannot vary in shape
     * makes injection a non-question rather than a question answered carefully.
     *
     * @test
     */
    public function argvIsOnlyTheBinaryLiteralFlagsAndPathsWeGenerated(): void
    {
        $hostile = ['a;id', '$(id)', '`id`', 'a&b', 'a|b'];

        $this->compileEntry("/* config-sentinel: tailwind.config.js */\n.entry{}\n", $hostile);

        $argv = $this->recordedArgv();
        $directory = $this->recordedString('cwd');

        // argv[0] is the binary as the *child* sees it — on Windows that is the
        // PHP script the `.cmd` shim runs rather than the shim. Everything after
        // it is what this class chose, and that is what is pinned.
        self::assertSame(
            ['-i', $directory . '/input.css', '-o', $directory . '/output.css', '--cwd', $directory],
            \array_slice($argv, 1)
        );
        self::assertStringStartsWith($this->cacheDir . '/.cli-', $directory);

        foreach ($argv as $argument) {
            foreach ($hostile as $candidate) {
                self::assertStringNotContainsString($candidate, $argument);
            }

            self::assertStringNotContainsString('config-sentinel', $argument);
            self::assertStringNotContainsString('tailwind.config.js', $argument);
        }

        // They did reach the binary, intact, by the route that was designed for
        // them. Trailing terminator trimmed: CCP-A10 fixes the separator, not
        // whether the single line ends in a newline.
        self::assertSame(
            \implode(' ', $hostile),
            \rtrim($this->recordedString('candidates'), "\r\n")
        );
    }

    // ------------------------------------------------- failure, and its kind

    /**
     * Decision CCP-A11: a stylesheet with a syntax mistake is the user's problem
     * to see. `Error:` lines become diagnostics on an ordinary result and the
     * orchestrator decides what to do — nothing is thrown.
     *
     * @test
     */
    public function aCompileErrorBecomesDiagnosticsRatherThanAnException(): void
    {
        $result = $this->compileEntry(":root{--fake:'fake: fail 2'}\n.entry{}\n");

        self::assertTrue($result->hasErrors());
        self::assertSame('', $result->css());
        self::assertCount(2, $result->diagnostics()->all());

        foreach ($result->diagnostics()->all() as $diagnostic) {
            self::assertSame(Diagnostic::ERROR, $diagnostic->level());
            self::assertSame(Diagnostic::E_CLI_FAILED, $diagnostic->code());
        }
    }

    /**
     * Non-zero with nothing a user could act on is infrastructure, not CSS —
     * there is no diagnostic to return, so it throws.
     *
     * @test
     */
    public function unparseableStderrIsAnInfrastructureFailure(): void
    {
        $this->expectException(CliProcessException::class);

        $this->compileEntry(":root{--fake:'fake: fail-silent'}\n.entry{}\n");
    }

    /**
     * Exit zero and no stylesheet: the binary did not do the one thing it was
     * asked to, and an empty result must not be mistaken for an empty
     * stylesheet.
     *
     * @test
     */
    public function anExitZeroWithNoStylesheetIsAnInfrastructureFailure(): void
    {
        $this->expectException(CliProcessException::class);

        $this->compileEntry(":root{--fake:'fake: garbage'}\n.entry{}\n");
    }

    // -------------------------------------------------------------- cleanup

    /**
     * The `finally` in full. A directory left behind under a predictable name in
     * a writable location is both a disclosure and a target (T-14), so all four
     * outcomes are checked in one test: nothing about the exit path may change
     * the answer.
     *
     * Counted with `scandir()`, not `glob()` — the directory is dot-prefixed and
     * `glob()` would silently match nothing at all. Only entries that are
     * directories count: the fixture's `.argv.json` records are meant to survive.
     *
     * @test
     */
    public function theTemporaryDirectoryIsRemovedWhateverTheOutcome(): void
    {
        $this->compileEntry(".entry{}\n");

        self::assertSame([], $this->directoriesInCache(), 'after a successful compile');

        $this->compileEntry(":root{--fake:'fake: fail 2'}\n.entry{}\n");

        self::assertSame([], $this->directoriesInCache(), 'after a compile error');

        try {
            $this->compileEntry(":root{--fake:'fake: fail-silent'}\n.entry{}\n");

            self::fail('Unparseable stderr must not return normally.');
        } catch (\TailwindPhp\Exception\CliProcessException $e) {
            // Expected; the directory below is what this test is about.
        }

        self::assertSame([], $this->directoriesInCache(), 'after unparseable stderr');

        try {
            $this->compileEntry(":root{--fake:'fake: garbage'}\n.entry{}\n");

            self::fail('A missing output file must not return normally.');
        } catch (\TailwindPhp\Exception\CliProcessException $e) {
            // Expected.
        }

        self::assertSame([], $this->directoriesInCache(), 'after a missing output file');
    }

    /**
     * 0700 (T-17): the input carries the project's class names and the output
     * its stylesheet, and neither is for other accounts on a shared host.
     *
     * @test
     */
    public function theTemporaryDirectoryIsPrivateToItsOwner(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped(
                'POSIX modes do not apply on Windows: the fixture records 0777 there whatever mkdir() was asked '
                . 'for, so the assertion would measure the platform rather than the compiler.'
            );
        }

        $this->compileEntry(".entry{}\n");

        self::assertSame('0700', $this->recordedString('cwd_mode'));
    }

    // --------------------------------------------------------------- driving

    /**
     * @param list<string> $candidates
     * @param array<string, string> $importMap Project-relative path => CSS.
     */
    /**
     * `candidates.txt` is one line whatever a template contains (CCP-A10).
     *
     * A candidate carrying LF used to be written verbatim and split the file, so
     * a crafted template decided how the binary read the rest of it. Control
     * bytes are dropped rather than escaped: no real Tailwind class name has
     * one, so nothing legitimate is lost.
     *
     * @test
     */
    public function candidatesWithControlBytesCannotSplitTheFile(): void
    {
        $this->compileEntry(
            '@import "tailwindcss";',
            ['flex', "a\nb", "c\x00d", 'p-4', "e\x7Ff"]
        );

        $candidates = $this->recordedString('candidates');

        self::assertSame(1, \substr_count(\rtrim($candidates, "\n"), "\n") + 1, 'candidates.txt is not one line.');
        self::assertStringNotContainsString("\x00", $candidates);
        self::assertSame('flex p-4', \trim($candidates), 'The clean candidates did not survive intact.');
    }

    /**
     * A path-like `@import` the resolver refused is removed, not forwarded
     * (CCP-A29).
     *
     * Forwarding it would let the binary resolve `../../../../etc/passwd` from
     * `--cwd`, with none of the confinement this class performs — and the bytes
     * it inlined would be served as CSS. Confinement that the one failing
     * specifier is then handed past is not confinement.
     *
     * @test
     */
    public function arefusedPathImportIsRemovedAndReported(): void
    {
        $result = $this->compileEntry("@import \"../../../../etc/passwd\";\n@import \"./missing.css\";\n");

        self::assertTrue($result->diagnostics()->hasErrors());
        self::assertStringNotContainsString('etc/passwd', $this->recordedString('input'));
        self::assertStringNotContainsString('missing.css', $this->recordedString('input'));
    }

    /**
     * A bare package specifier still passes through — otherwise the test above
     * could be satisfied by dropping every unresolved import.
     *
     * @test
     */
    public function abarePackageImportIsStillForwardedToTheBinary(): void
    {
        $this->compileEntry('@import "tailwindcss";');

        self::assertStringContainsString('@import "tailwindcss";', $this->recordedString('input'));
    }

    /**
     * `@source` in the entry is stripped and reported (CCP-A30).
     *
     * The preamble is only *prepended*, so a later `@source "/"` silently won
     * and pointed the binary at the whole disk.
     *
     * @test
     */
    public function asourceDirectiveInTheEntryIsStrippedAndWarned(): void
    {
        $result = $this->compileEntry("@source \"/\";\n@SOURCE \"../..\";\n.a{color:red}\n");

        $input = $this->recordedString('input');

        // The two the preamble writes, and no others.
        self::assertSame(2, \substr_count($input, '@source'), 'An entry @source reached the binary.');
        self::assertStringNotContainsString('"/"', $input);

        $codes = [];

        foreach ($result->diagnostics()->all() as $diagnostic) {
            $codes[] = $diagnostic->code();
        }

        self::assertSame(
            ['W_SOURCE_IGNORED', 'W_SOURCE_IGNORED'],
            $codes,
            'Each stripped directive should be reported once.'
        );
    }

    /**
     * Case and spacing are the author's choice, not a contract — and an import
     * this class fails to see is a dependency the cache never records, which is
     * how a stylesheet goes stale without anything looking wrong.
     *
     * @test
     */
    public function importsAreRecognisedRegardlessOfCaseAndSpacing(): void
    {
        $result = $this->compileEntry(
            "@IMPORT \"./upper.css\";\n@import\"./tight.css\";\n",
            ['flex'],
            false,
            ['upper.css' => '.u{}', 'tight.css' => '.t{}']
        );

        self::assertSame(['upper.css', 'tight.css'], $result->imports());
    }

    /**
     * A commented-out import is not a dependency and must not be expanded.
     *
     * @test
     */
    public function anImportInsideAcommentIsNotExpanded(): void
    {
        $result = $this->compileEntry(
            "/* @import \"./ghost.css\"; */\n.a{color:red}\n",
            ['flex'],
            false,
            ['ghost.css' => '.g{}']
        );

        self::assertSame([], $result->imports());
    }

    /**
     * Binary stderr is capped and stripped of control bytes before it becomes a
     * diagnostic, and ANSI colour is removed *before* the `Error:` match so a
     * colourising binary's compile error is still classified as one (CCP-A11).
     *
     * @test
     */
    public function acolouredErrorLineIsStillAcompileError(): void
    {
        $result = $this->compileEntry(":root{--fake:'fake: fail-ansi 2'}\n.a{}\n");

        $codes = [];

        foreach ($result->diagnostics()->all() as $diagnostic) {
            $codes[] = $diagnostic->code();

            $message = $diagnostic->message();

            self::assertLessThanOrEqual(1024, \strlen($message), 'A diagnostic message was not capped.');

            // Byte loop rather than a regex: `preg_*` belongs to `Support\Regex`
            // in this project, and the property is simple enough to state
            // directly.
            for ($i = 0, $length = \strlen($message); $i < $length; ++$i) {
                $byte = \ord($message[$i]);

                self::assertFalse(
                    $byte < 0x20 || $byte === 0x7F,
                    \sprintf('A control byte (0x%02X) survived into a diagnostic message.', $byte)
                );
            }
        }

        // Three, because the coloured line was recognised as a compile error
        // rather than discarded. Before the strip it produced no diagnostic at
        // all and the whole compile became an infrastructure failure instead.
        self::assertSame(['E_CLI_FAILED', 'E_CLI_FAILED', 'E_CLI_FAILED'], $codes);
    }

    /**
     * A link planted at `output.css` must not be read (T-14, T-06).
     *
     * The binary is a foreign program writing into a directory it was handed, so
     * what sits at that name when it finishes is its choice. If it leaves a link
     * there, the read returns a file from wherever the link points and those
     * bytes are served as CSS and cached. Confinement that stopped at directory
     * creation would be decoration — it has to hold at the moment of the read.
     *
     * A **hard** link on purpose: `is_link()` does not report one, so a check
     * that only asked `is_link()` would pass this test while the hole stayed
     * open.
     *
     * @test
     */
    public function alinkPlantedAtTheOutputNameIsNotRead(): void
    {
        $secret = $this->root . '/secret.css';

        \file_put_contents($secret, ".secret{--leaked:1}\n");

        // The fixture plants the link instead of writing a real output.css.
        $this->expectException(CliProcessException::class);

        $this->compileEntry(\sprintf(":root{--fake:'fake: link-output %s'}\n.a{}\n", $secret));
    }

    /**
     * An at-rule is found wherever CSS lets it sit (CCP-A31).
     *
     * Anchoring the scan at statement start read well and was wrong:
     * `.a{} @import "/../../etc/passwd";` is a perfectly valid stylesheet, and
     * that directive was invisible to the scan, so it travelled to the binary
     * with `--cwd` set and no confinement — the exact hole the confinement
     * exists to close.
     *
     * @dataProvider unanchoredHostileEntries
     *
     * @test
     */
    public function anAtRuleIsCaughtWhereverItSits(string $entry, string $absent): void
    {
        $result = $this->compileEntry($entry);

        self::assertStringNotContainsString($absent, $this->recordedString('input'));
        self::assertNotSame([], $result->diagnostics()->all(), 'The directive was dropped without a word.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unanchoredHostileEntries(): array
    {
        return [
            'import after a rule' => ['.a{} @import "/../../etc/passwd";', 'etc/passwd'],
            'import after a comment' => ['/*x*/ @import "../../secret.css";', 'secret.css'],
            'source after a rule' => ['.b{} @source "/";', '@source "/"'],
            'source after a comment' => ['/*y*/ @source "/";', '@source "/"'],
        ];
    }

    /**
     * Comments are removed before the scan, so a commented-out import is gone
     * with the comment rather than skipped by an anchor (CCP-A31).
     *
     * @test
     */
    public function acommentedOutImportIsRemovedWithItsComment(): void
    {
        $result = $this->compileEntry(
            "/* @import \"./ghost.css\"; */\n.a{color:red}\n",
            ['flex'],
            false,
            ['ghost.css' => '.g{}']
        );

        self::assertSame([], $result->imports());
        self::assertStringNotContainsString('ghost.css', $this->recordedString('input'));
    }

    /**
     * The indented forms still work — the point of CCP-A31 is to catch more, not
     * to stop catching what already worked.
     *
     * @test
     */
    public function anIndentedImportIsStillInlined(): void
    {
        $result = $this->compileEntry(
            "    @import \"./partial.css\";\n.a{}\n",
            ['flex'],
            false,
            ['partial.css' => '.p{--inlined:1}']
        );

        self::assertSame(['partial.css'], $result->imports());
        self::assertStringContainsString('.p{--inlined:1}', $this->recordedString('input'));
    }

    /**
     * Anything that names a filesystem location, in any spelling, is refused
     * rather than forwarded (CCP-A29 amended).
     *
     * @dataProvider pathLikeSpecifiers
     *
     * @test
     */
    public function apathLikeSpecifierIsNeverForwarded(string $specifier): void
    {
        $result = $this->compileEntry(\sprintf('@import "%s";', $specifier));

        self::assertTrue($result->diagnostics()->hasErrors(), 'No diagnostic for a refused path.');
        self::assertStringNotContainsString($specifier, $this->recordedString('input'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathLikeSpecifiers(): array
    {
        return [
            'posix absolute' => ['/etc/passwd'],
            'windows drive' => ['C:/Windows/win.ini'],
            'windows relative' => ['..\..\secret'],
            'dot backslash' => ['.\sibling'],
            'php wrapper' => ['php://input'],
            'data wrapper' => ['data://x'],
        ];
    }

    /**
     * A bare package specifier is still the binary's business — without this the
     * test above could be satisfied by refusing everything.
     *
     * @dataProvider barePackageSpecifiers
     *
     * @test
     */
    public function abarePackageSpecifierStillReachesTheBinary(string $specifier): void
    {
        $this->compileEntry(\sprintf('@import "%s";', $specifier));

        self::assertStringContainsString(
            \sprintf('@import "%s";', $specifier),
            $this->recordedString('input')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function barePackageSpecifiers(): array
    {
        return [
            'package' => ['tailwindcss'],
            'subpath' => ['tailwindcss/utilities'],
            'scoped' => ['@tailwindcss/typography'],
        ];
    }
    /**
     * Drive one compile against the fixture binary.
     *
     * @param list<string> $candidates
     * @param array<string, string> $importMap Relative path => stylesheet text.
     */
    private function compileEntry(
        string $entryCss,
        array $candidates = ['flex'],
        bool $minify = false,
        array $importMap = []
    ): CompileResult {
        $this->forgetRecords();

        return $this->compiler()->compile(
            $entryCss,
            new InMemoryImportResolver($importMap),
            $candidates,
            new CompileOptions($minify, false)
        );
    }

    private function compiler(): StandaloneCliCompiler
    {
        return new StandaloneCliCompiler(
            self::fixtureBinary(),
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
     * The bare script on POSIX (it has a shebang), the `.cmd` shim on Windows —
     * an explicitly supported binary form (decision CCP-A23).
     */
    private static function fixtureBinary(): string
    {
        $script = \dirname(__DIR__, 3) . '/Fixtures/bin/fake-tailwindcss';

        if (\DIRECTORY_SEPARATOR === '\\') {
            return $script . '.cmd';
        }

        if (!\is_executable($script)) {
            // A checkout made on Windows carries no executable bit, and the
            // shebang is useless without one — `proc_open` would fail to spawn
            // and every test here would report an infrastructure failure rather
            // than the thing it is about. The fixture is ours, so it is made
            // runnable rather than depended upon.
            \chmod($script, 0755);
        }

        return $script;
    }


    // ------------------------------------------------------------ the record

    /**
     * @return list<string> Directory entries only — the `.argv.json` records are
     *                      files and are supposed to survive.
     */
    private function directoriesInCache(): array
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
        $entries = \scandir($this->cacheDir);

        if ($entries === false) {
            return [];
        }

        return \array_values(\array_diff($entries, ['.', '..']));
    }

    /**
     * Records survive cleanup by design, so a test that compiles twice would
     * otherwise have to guess which one it is looking at.
     */
    private function forgetRecords(): void
    {
        foreach ($this->cacheEntries() as $entry) {
            if (\substr($entry, -10) === '.argv.json') {
                \unlink($this->cacheDir . '/' . $entry);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        $path = null;

        foreach ($this->cacheEntries() as $entry) {
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

    private function recordedString(string $key): string
    {
        $record = $this->record();
        $value = \array_key_exists($key, $record) ? $record[$key] : null;

        if (!\is_string($value)) {
            self::fail(\sprintf('The fixture recorded no string under "%s".', $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function recordedArgv(): array
    {
        $record = $this->record();
        $raw = \array_key_exists('argv', $record) ? $record['argv'] : null;

        if (!\is_array($raw)) {
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

    private function recordedMinify(): bool
    {
        $record = $this->record();
        $value = \array_key_exists('minify', $record) ? $record['minify'] : null;

        if (!\is_bool($value)) {
            self::fail('The fixture recorded no minify flag.');
        }

        return $value;
    }
}
