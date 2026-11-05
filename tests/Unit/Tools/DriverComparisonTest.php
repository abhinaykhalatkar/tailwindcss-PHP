<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Tests\Conformance\Support\ComparisonResult;
use TailwindPhp\Tests\Conformance\Support\CssComparator;
use TailwindPhp\Tests\Support\TempProject;
use TailwindPhp\Tools\DriverComparison;

/**
 * `tools/compare-drivers.php` (TASK-0054): the same project through the PHP engine and through the official
 * CLI, compared with the conformance comparator. The unit test needs no binary: both compilers are injected, the
 * fixture project is the repository's own `tests/Fixtures/project-basic`, and nothing is written into it.
 */
final class DriverComparisonTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/DriverComparison.php';
    }

    // ------------------------------------------------------------ arguments

    /** @test */
    public function aRootAloneIsEnough(): void
    {
        $options = DriverComparison::parseArguments([self::fixture()]);

        self::assertSame(self::fixture(), $options['root']);
        self::assertNull($options['entry']);
        self::assertFalse($options['minify']);
    }

    /** @test */
    public function anEntryAndTheMinifyFlagAreRead(): void
    {
        $options = DriverComparison::parseArguments(['--minify', self::fixture(), '--entry=assets/styles/main.css']);

        self::assertSame('assets/styles/main.css', $options['entry']);
        self::assertTrue($options['minify']);
    }

    /** @test */
    public function backslashesInAnEntryBecomeSlashes(): void
    {
        $options = DriverComparison::parseArguments([self::fixture(), '--entry=assets\\styles\\main.css']);

        self::assertSame('assets/styles/main.css', $options['entry']);
    }

    /**
     * @test
     *
     * @dataProvider badArguments
     *
     * @param list<string> $arguments
     */
    public function aBadCommandLineIsRefusedWithAPlainMessage(array $arguments, string $message): void
    {
        try {
            DriverComparison::parseArguments($arguments);

            self::fail('The arguments were accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertLessThan(200, \strlen($exception->getMessage()), 'a short message');
        }
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function badArguments(): array
    {
        $fixture = self::fixture();

        return [
            'nothing at all' => [[], 'project root is missing'],
            'a directory that does not exist' => [[$fixture . '/nope'], 'No such directory'],
            'a file instead of a directory' => [[$fixture . '/templates/home.twig'], 'is a file'],
            'two roots' => [[$fixture, $fixture], 'Only one project root'],
            'an unknown option' => [[$fixture, '--verbose'], 'Unknown option'],
            'an empty entry' => [[$fixture, '--entry='], 'entry'],
            'an entry that climbs' => [[$fixture, '--entry=../outside.css'], 'entry'],
            'an entry that climbs in the middle' => [[$fixture, '--entry=assets/../../x.css'], 'entry'],
            'an entry that climbs with backslashes' => [[$fixture, '--entry=assets\\..\\..\\x.css'], 'entry'],
            'an absolute entry' => [[$fixture, '--entry=/etc/passwd'], 'entry'],
            'a drive letter' => [[$fixture, '--entry=C:/x.css'], 'entry'],
            'a drive letter without a slash' => [[$fixture, '--entry=C:x.css'], 'entry'],
            'a unc path' => [[$fixture, '--entry=\\\\host\\share\\x.css'], 'entry'],
            'a control byte' => [[$fixture, "--entry=a\0b.css"], 'entry'],
        ];
    }

    /** @test */
    public function aLongOffendingValueIsCutInTheMessage(): void
    {
        try {
            DriverComparison::parseArguments(['--' . \str_repeat('x', 500)]);

            self::fail('The arguments were accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertLessThan(150, \strlen($exception->getMessage()));
        }
    }

    // ------------------------------------------------------------- rendering

    /** @test */
    public function identicalStylesheetsPassAndExitZero(): void
    {
        $css = self::layer("  .flex {\n    display: flex;\n  }\n");
        $result = DriverComparison::compare($css, $css, ['flex']);
        $text = DriverComparison::render($result, 'php (compat-x)', 'cli 4.1.18', 1);

        self::assertSame(DriverComparison::EXIT_IDENTICAL, DriverComparison::exitCode($result));
        self::assertStringContainsString('php (compat-x) against cli 4.1.18', $text);
        self::assertStringContainsString('1 candidate', $text);
        self::assertStringContainsString('pass rate 1/1 (100.00 %)', $text);
        self::assertStringContainsString('Identical', $text);
    }

    /** @test */
    public function aDifferenceIsListedWithItsClassAndBothDeclarations(): void
    {
        $cli = self::layer("  .flex {\n    display: flex;\n  }\n  .p-4 {\n    padding: 1rem;\n  }\n");
        $php = self::layer("  .flex {\n    display: flex;\n  }\n  .p-4 {\n    padding: 2rem;\n  }\n");
        $result = DriverComparison::compare($php, $cli, ['flex', 'p-4']);
        $text = DriverComparison::render($result, 'php', 'cli', 2);

        self::assertSame(DriverComparison::EXIT_DIFFERENT, DriverComparison::exitCode($result));
        self::assertStringContainsString('FAIL_VALUE p-4', $text);
        self::assertStringContainsString('cli  ', $text);
        self::assertStringContainsString('padding:1rem', $text);
        self::assertStringContainsString('php  ', $text);
        self::assertStringContainsString('padding:2rem', $text);
        self::assertStringNotContainsString('Identical', $text);
    }

    /** @test */
    public function aMissingRuleIsAFailureToo(): void
    {
        $cli = self::layer("  .flex {\n    display: flex;\n  }\n");
        $result = DriverComparison::compare('', $cli, ['flex']);

        self::assertSame(DriverComparison::EXIT_DIFFERENT, DriverComparison::exitCode($result));
        self::assertStringContainsString('FAIL_MISSING flex', DriverComparison::render($result, 'php', 'cli', 1));
    }

    /** @test */
    public function aStylesheetThatDoesNotParseIsAFailureWithItsDiagnostics(): void
    {
        $result = DriverComparison::compare('.a { color: ', ".a {\n  color: red;\n}\n", ['a']);
        $text = DriverComparison::render($result, 'php', 'cli', 1);

        self::assertSame(DriverComparison::EXIT_DIFFERENT, DriverComparison::exitCode($result));
        self::assertStringContainsString(ComparisonResult::ERROR_PARSE . ' actual: ', $text, 'the diagnostic is shown');
    }

    /** @test */
    public function aLongListOfFailuresIsCutAndSaysSo(): void
    {
        $cli = '';
        $php = '';
        $candidates = [];

        for ($i = 0; $i < 70; ++$i) {
            $candidates[] = 'p-' . $i;
            $cli .= '  .p-' . $i . " {\n    padding: 1px;\n  }\n";
            $php .= '  .p-' . $i . " {\n    padding: 2px;\n  }\n";
        }

        $result = DriverComparison::compare(self::layer($php), self::layer($cli), $candidates);
        $text = DriverComparison::render($result, 'php', 'cli', 70);

        self::assertStringContainsString('FAIL_VALUE p-0', $text);
        self::assertStringContainsString('and 20 more', $text);
        self::assertStringNotContainsString('FAIL_VALUE p-69', $text);
    }

    // ------------------------------------------------------------- running

    /** @test */
    public function theFixtureProjectIsCompiledByBothDriversAndComparedWithoutWritingAnything(): void
    {
        $before = self::listing(self::fixture());
        $phpLog = new \stdClass();
        $cliLog = new \stdClass();
        $css = self::layer("  .flex {\n    display: flex;\n  }\n");
        $php = self::driver($phpLog, 'php', $css);
        $cli = self::driver($cliLog, 'cli', $css);

        $lines = [];
        $code = DriverComparison::execute(
            [self::fixture()],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            static function (string $line) use (&$lines): void {
                $lines[] = 'ERR ' . $line;
            },
            $php,
            $cli
        );

        self::assertSame(DriverComparison::EXIT_IDENTICAL, $code, \implode('', $lines));
        self::assertSame(1, $phpLog->calls);
        self::assertSame(1, $cliLog->calls);
        self::assertSame($phpLog->candidates, $cliLog->candidates, 'both drivers got the same candidates');
        self::assertContains('flex', $phpLog->candidates);
        self::assertContains('items-center', $phpLog->candidates);
        self::assertSame('assets/styles/main.css', $phpLog->entryRelPath);
        self::assertStringContainsString('@import "tailwindcss"', $phpLog->entryCss);
        self::assertSame($before, self::listing(self::fixture()), 'nothing was written into the project');
        self::assertStringNotContainsString(self::fixture(), \implode('', $lines), 'no path in the output');
    }

    /**
     * The CLI's minifier rewrites values in ways the comparator does not canonicalise, so a comparison is plain
     * unless `--minify` is given, whatever the project's own `minify` setting is.
     *
     * @test
     */
    public function theOutputIsPlainUnlessMinifyIsGiven(): void
    {
        $plain = new \stdClass();
        $minified = new \stdClass();
        $css = self::layer("  .flex {\n    display: flex;\n  }\n");
        $noop = static function (string $line): void {
        };

        $cli = self::driver(new \stdClass(), 'cli', $css);

        DriverComparison::execute([self::fixture()], $noop, $noop, self::driver($plain, 'php', $css), $cli);
        DriverComparison::execute(
            [self::fixture(), '--minify'],
            $noop,
            $noop,
            self::driver($minified, 'php', $css),
            self::driver(new \stdClass(), 'cli', $css)
        );

        self::assertFalse($plain->minify);
        self::assertTrue($minified->minify);
    }

    /** @test */
    public function theScratchDirectoryIsRemovedWhateverTheOutcome(): void
    {
        $pattern = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'tailwind-php-compare-*';
        $before = (array) \glob($pattern);
        $noop = static function (string $line): void {
        };
        $css = self::layer("  .flex {\n    display: flex;\n  }\n");

        $php = self::driver(new \stdClass(), 'php', $css);
        $cli = self::driver(new \stdClass(), 'cli', $css);

        DriverComparison::execute([self::fixture()], $noop, $noop, $php, $cli);
        DriverComparison::execute([self::fixture(), '--entry=nope.css'], $noop, $noop, $php, $cli);
        DriverComparison::execute([self::fixture(), '--entry=../x'], $noop, $noop);

        self::assertSame($before, (array) \glob($pattern));
    }

    /** @test */
    public function anEntryBeyondTheConfiguredSizeLimitIsRefusedNotTruncated(): void
    {
        $project = TempProject::make(['limits' => ['max_total_css' => 1024]]);

        try {
            $entry = "@import \"tailwindcss\";\n/* " . \str_repeat('x', 2000) . " */\n";
            $project->writeFile('assets/styles/main.css', $entry);
            $project->writeFile('templates/a.twig', '<div class="flex"></div>');
            $php = new \stdClass();
            $errors = [];

            $code = DriverComparison::execute(
                [$project->root()],
                static function (string $line): void {
                },
                static function (string $line) use (&$errors): void {
                    $errors[] = $line;
                },
                self::driver($php, 'php', ''),
                self::driver(new \stdClass(), 'cli', '')
            );

            self::assertSame(DriverComparison::EXIT_USAGE, $code);
            self::assertStringContainsString('max_total_css', \implode('', $errors));
            self::assertSame(0, $php->calls, 'nothing was compiled');
        } finally {
            $project->remove();
        }
    }

    /** @test */
    public function differentOutputsExitOneAndNameTheCandidate(): void
    {
        $php = self::driver(new \stdClass(), 'php', self::layer("  .flex {\n    display: flex;\n  }\n"));
        $cli = self::driver(new \stdClass(), 'cli', self::layer("  .flex {\n    display: block;\n  }\n"));
        $lines = [];

        $code = DriverComparison::execute(
            [self::fixture()],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            $php,
            $cli
        );

        self::assertSame(DriverComparison::EXIT_DIFFERENT, $code);
        self::assertStringContainsString('FAIL_VALUE flex', \implode('', $lines));
    }

    /** @test */
    public function aDriverThatReportsErrorsIsAFailure(): void
    {
        $php = self::driver(new \stdClass(), 'php', '', true);
        $cli = self::driver(new \stdClass(), 'cli', '');
        $lines = [];

        $code = DriverComparison::execute(
            [self::fixture()],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            $php,
            $cli
        );

        self::assertSame(DriverComparison::EXIT_DIFFERENT, $code);
        self::assertStringContainsString('php reported errors', \implode('', $lines));
        self::assertStringContainsString('E_CSS_SYNTAX', \implode('', $lines));
    }

    /** @test */
    public function anEntryNoBundleHasIsUsageAndReadsNothing(): void
    {
        $phpLog = new \stdClass();
        $php = self::driver($phpLog, 'php', '');
        $cli = self::driver(new \stdClass(), 'cli', '');
        $errors = [];

        $code = DriverComparison::execute(
            [self::fixture(), '--entry=templates/home.twig'],
            static function (string $line): void {
            },
            static function (string $line) use (&$errors): void {
                $errors[] = $line;
            },
            $php,
            $cli
        );

        self::assertSame(DriverComparison::EXIT_USAGE, $code);
        self::assertSame(0, $phpLog->calls);
        $message = \implode('', $errors);

        self::assertStringContainsString('assets/styles/main.css', $message, 'the known entries are listed');
    }

    /** @test */
    public function aBadCommandLineExitsTwoWithTheUsage(): void
    {
        $errors = [];

        $code = DriverComparison::execute(
            [],
            static function (string $line): void {
            },
            static function (string $line) use (&$errors): void {
                $errors[] = $line;
            }
        );

        self::assertSame(DriverComparison::EXIT_USAGE, $code);
        self::assertStringContainsString('Usage: php tools/compare-drivers.php', \implode('', $errors));
    }

    /** @test */
    public function theComparatorSeesTheCliAsTheReference(): void
    {
        // The CLI is the golden (expected), the PHP engine the actual: an extra rule of the engine is FAIL_EXTRA.
        $php = self::layer("  .flex {\n    display: flex;\n  }\n  .extra {\n    color: red;\n  }\n");
        $cli = self::layer("  .flex {\n    display: flex;\n  }\n");
        $direct = (new CssComparator())->compare($cli, $php, ['flex', 'extra']);
        $result = DriverComparison::compare($php, $cli, ['flex', 'extra']);

        self::assertSame($direct->statuses(), $result->statuses());
        self::assertSame(ComparisonResult::FAIL_EXTRA, $result->statuses()['extra']);
    }

    private static function layer(string $rules): string
    {
        return "@layer utilities {\n" . $rules . "}\n";
    }

    private static function fixture(): string
    {
        return \str_replace('\\', '/', (string) \realpath(\dirname(__DIR__, 2) . '/Fixtures/project-basic'));
    }

    /**
     * @return list<string>
     */
    private static function listing(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $name = \substr($file->getPathname(), \strlen($root));
                $files[] = $name . ':' . $file->getSize() . ':' . $file->getMTime();
            }
        }

        \sort($files);

        return $files;
    }

    /**
     * A driver that answers with a fixed stylesheet and records what it was given in `$log`.
     *
     * @return CompilerInterface
     */
    private static function driver(\stdClass $log, string $name, string $css, bool $fail = false)
    {
        $log->calls = 0;
        $log->candidates = [];
        $log->entryCss = '';
        $log->entryRelPath = '';
        $log->minify = null;

        return new class ($log, $name, $css, $fail) implements CompilerInterface {
            /** @var \stdClass */
            private $log;

            /** @var string */
            private $name;

            /** @var string */
            private $css;

            /** @var bool */
            private $fail;

            public function __construct(\stdClass $log, string $name, string $css, bool $fail)
            {
                $this->log = $log;
                $this->name = $name;
                $this->css = $css;
                $this->fail = $fail;
            }

            public function name(): string
            {
                return $this->name;
            }

            public function version(): string
            {
                return $this->name === 'cli' ? '4.1.18' : '1';
            }

            public function compile(
                string $entryCss,
                ImportResolverInterface $imports,
                array $candidates,
                CompileOptions $options
            ): CompileResult {
                ++$this->log->calls;
                $this->log->candidates = $candidates;
                $this->log->entryCss = $entryCss;
                $this->log->entryRelPath = $options->entryRelPath();
                $this->log->minify = $options->minify();

                $bag = new DiagnosticBag();

                if ($this->fail) {
                    $bag->add(new Diagnostic(
                        Diagnostic::ERROR,
                        Diagnostic::E_CSS_SYNTAX,
                        'The fake driver failed on purpose.'
                    ));
                }

                return new CompileResult($this->css, $bag, $this->name, $this->version(), 1.0, [], []);
            }
        };
    }
}
