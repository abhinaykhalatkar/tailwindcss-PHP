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
use TailwindPhp\Tools\DataImporter;

/**
 * The importers that turn the pinned CLI's own output into `Engine\Data\DefaultTheme`
 * and `Engine\Data\Preflight` (decision ENG-A5; threats T-01, T-04, T-08, T-18).
 *
 * The generator takes its compile step as a callable, so almost everything is
 * asserted against a hand-written double that returns banner-bearing CSS. Two
 * rows use more: one wires the real {@see CliRunner} to the fixture binary (which
 * prints no banner, so the importer must refuse it), and one regenerates the
 * **committed** data files from the **committed goldens** — which are exactly the
 * CLI's output for the same inputs — and requires them to match byte for byte.
 * That is the offline proof that the files in `src/Engine/Data/` are what the
 * importer produces, without needing a Tailwind binary.
 *
 * The generated files are loaded by rewriting their namespace, so each test can
 * `require` a private copy without redeclaring the real class.
 */
final class ImportersTest extends TestCase
{
    private const VERSION = '4.1.18';

    private const BANNER = "/*! tailwindcss v4.1.18 | MIT License | https://tailwindcss.com */\n";

    private const THEME_CSS = <<<'CSS'
        :root, :host {
          --font-sans: ui-sans-serif, system-ui,
            sans-serif;
          --color-red-500: oklch(63.7% 0.237 25.331);
          --spacing: 0.25rem;
          --breakpoint-md: 48rem;
        }
        @keyframes spin {
          to {
            transform: rotate(360deg);
          }
        }
        @keyframes bounce {
          0%, 100% {
            transform: translateY(-25%);
          }
          50% {
            transform: none;
          }
        }

        CSS;

    private const PREFLIGHT_CSS = <<<'CSS'
        *, ::after, ::before {
          box-sizing: border-box;
          margin: 0;
        }
        html, :host {
          line-height: 1.5;
          font-family: ui-sans-serif, system-ui, sans-serif;
        }
        [hidden]:where(:not([hidden='until-found'])) {
          display: none !important;
        }
        @supports (not (-webkit-appearance: -apple-pay-button)) {
          ::placeholder {
            color: color-mix(in oklab, currentcolor 50%, transparent);
          }
        }

        CSS;

    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $output = '';

    /**
     * @var list<array{input: string, candidates: string, files: array<string, string>, minify: bool}>
     */
    private $calls = [];

    /**
     * @var int
     */
    private static $namespaces = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadTools();

        $this->root = TempTree::make('tailwindphp-importers');
        $this->output = self::BANNER . self::THEME_CSS;
        $this->calls = [];

        TempTree::registerShutdownCleanup($this->root);
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // ----------------------------------------------------------- the theme

    /** @test */
    public function theThemeImporterAsksTheCliForEveryStaticVariable(): void
    {
        $this->importer()->theme();

        self::assertCount(1, $this->calls);
        self::assertSame("@import \"tailwindcss/theme.css\" theme(static);\n", $this->calls[0]['input']);
        self::assertSame('', $this->calls[0]['candidates']);
        self::assertSame([], $this->calls[0]['files']);
        self::assertFalse($this->calls[0]['minify']);
    }

    /** @test */
    public function itWritesToAFixedPathUnderEngineData(): void
    {
        $result = $this->importer()->theme();

        self::assertSame('src/Engine/Data/DefaultTheme.php', $result['file']);
        self::assertSame('src/Engine/Data/DefaultTheme.php', DataImporter::THEME_FILE);
        self::assertSame('src/Engine/Data/Preflight.php', DataImporter::PREFLIGHT_FILE);
    }

    /** @test */
    public function variablesKeepSourceOrderAndAWrappedValueBecomesOneLine(): void
    {
        $class = $this->load($this->importer()->theme()['contents']);

        self::assertSame(
            [
                '--font-sans' => 'ui-sans-serif, system-ui, sans-serif',
                '--color-red-500' => 'oklch(63.7% 0.237 25.331)',
                '--spacing' => '0.25rem',
                '--breakpoint-md' => '48rem',
            ],
            $class::vars()
        );
        self::assertSame(self::VERSION, $class::VERSION);
    }

    /** @test */
    public function keyframesAreWholeBlocksKeyedByName(): void
    {
        $class = $this->load($this->importer()->theme()['contents']);

        self::assertSame(['spin', 'bounce'], \array_keys($class::keyframes()));
        self::assertSame(
            "@keyframes spin {\n  to {\n    transform: rotate(360deg);\n  }\n}",
            $class::keyframes()['spin']
        );
        self::assertStringContainsString('0%, 100% {', $class::keyframes()['bounce']);
    }

    /** @test */
    public function theGeneratedThemeFileRecordsItsProvenance(): void
    {
        $source = $this->importer()->theme()['contents'];

        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $source);
        self::assertStringContainsString("\nnamespace TailwindPhp\\Engine\\Data;\n", $source);
        self::assertStringContainsString('GENERATED FILE', $source);
        self::assertStringContainsString('tools/import-default-theme.php', $source);
        self::assertStringContainsString('4.1.18', $source);
        self::assertStringContainsString('@import "tailwindcss/theme.css" theme(static);', $source);
        self::assertStringContainsString('candidates  none', $source);
        self::assertStringContainsString('-i input.css -o output.css', $source);

        // Every pinned platform digest, never the one this host happened to run:
        // a header that named only the local binary would differ between a
        // Windows laptop and the Linux CI job that checks it.
        foreach ($this->digests() as $platform => $digest) {
            self::assertStringContainsString($platform, $source);
            self::assertStringContainsString($digest, $source);
        }

        // The hash of the stylesheet the CLI printed, banner removed and LF.
        self::assertStringContainsString(Hash::sha256(self::THEME_CSS), $source);
        self::assertStringContainsString('4 variables, 2 keyframes', $source);
        self::assertSame(0, \substr_count($source, "\r"));
    }

    /** @test */
    public function theHeaderReproducesTheUpstreamLicenceVerbatim(): void
    {
        $source = $this->importer()->theme()['contents'];

        self::assertStringContainsString('Copyright (c) Tailwind Labs, Inc.', $source);
        self::assertStringContainsString('https://github.com/tailwindlabs/tailwindcss', $source);

        foreach (self::noticeLicenceLines() as $line) {
            self::assertStringContainsString($line === '' ? "\n *\n" : "\n * " . $line . "\n", $source, $line);
        }
    }

    /** @test */
    public function runningTwiceProducesTheSameBytes(): void
    {
        self::assertSame($this->importer()->theme()['contents'], $this->importer()->theme()['contents']);
    }

    /**
     * T-08: the generated file holds string literals only. A stylesheet value
     * that looks like code must come out as the same string it went in as.
     *
     * @test
     */
    public function valuesThatLookLikeCodeStayStringLiterals(): void
    {
        $this->output = self::BANNER . ":root, :host {\n"
            . "  --a: '<?php echo 1; ?>';\n"
            . "  --b: \"it's\" \$x ?>;\n"
            . "  --c: url(data:text/html;base64,PD9waHA=);\n"
            . "  --d: \\31 0;\n"
            . "}\n";

        $class = $this->load($this->importer()->theme()['contents']);

        self::assertSame("'<?php echo 1; ?>'", $class::vars()['--a']);
        self::assertSame("\"it's\" \$x ?>", $class::vars()['--b']);
        self::assertSame('url(data:text/html;base64,PD9waHA=)', $class::vars()['--c']);
        self::assertSame('\\31 0', $class::vars()['--d']);
    }

    /**
     * @test
     *
     * @dataProvider unacceptableThemes
     */
    public function aThemeThatIsNotJustVariablesAndKeyframesIsRefused(string $css): void
    {
        $this->output = self::BANNER . $css;

        $this->expectException(\RuntimeException::class);

        $this->importer()->theme();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unacceptableThemes(): array
    {
        return [
            'no variables at all' => [":root, :host {\n}\n"],
            'a different selector' => ["html {\n  --a: 1;\n}\n"],
            'a real property' => [":root, :host {\n  --a: 1;\n  color: red;\n}\n"],
            'an important variable' => [":root, :host {\n  --a: 1 !important;\n}\n"],
            'a nested rule' => [":root, :host {\n  --a: 1;\n  .x {\n    --b: 2;\n  }\n}\n"],
            'a duplicate variable' => [":root, :host {\n  --a: 1;\n  --a: 2;\n}\n"],
            'an extra style rule' => [":root, :host {\n  --a: 1;\n}\n.x {\n  color: red;\n}\n"],
            'an unexpected at-rule' => [":root, :host {\n  --a: 1;\n}\n@media print {\n  .x {\n    --b: 1;\n  }\n}\n"],
            'a keyframes block without a body' => [":root, :host {\n  --a: 1;\n}\n@keyframes spin;\n"],
            'a repeated keyframes name' => [
                ":root, :host {\n  --a: 1;\n}\n@keyframes s {\n  to {\n    --b: 1;\n  }\n}\n"
                . "@keyframes s {\n  to {\n    --b: 2;\n  }\n}\n",
            ],
            'a keyframes name that is not an identifier' => [
                ":root, :host {\n  --a: 1;\n}\n@keyframes x'y {\n  to {\n    --b: 1;\n  }\n}\n",
            ],
            'nothing' => [''],
        ];
    }

    // ------------------------------------------------------------ preflight

    /** @test */
    public function thePreflightImporterAsksTheCliForTheBundle(): void
    {
        $this->output = self::BANNER . self::PREFLIGHT_CSS;

        $result = $this->importer()->preflight();

        self::assertCount(1, $this->calls);
        self::assertSame("@import \"tailwindcss/preflight.css\";\n", $this->calls[0]['input']);
        self::assertSame('src/Engine/Data/Preflight.php', $result['file']);
    }

    /** @test */
    public function preflightCssIsTheCliOutputVerbatimWithoutTheBanner(): void
    {
        $this->output = self::BANNER . self::PREFLIGHT_CSS;

        $class = $this->load($this->importer()->preflight()['contents']);

        self::assertSame(self::PREFLIGHT_CSS, $class::css());
        self::assertSame(self::VERSION, $class::VERSION);
    }

    /** @test */
    public function preflightAstIsPlainArraysWithoutPositions(): void
    {
        $this->output = self::BANNER . self::PREFLIGHT_CSS;

        $class = $this->load($this->importer()->preflight()['contents']);
        $ast = $class::ast();

        self::assertCount(4, $ast);
        self::assertSame(
            [
                'type' => 'rule',
                'selector' => '*, ::after, ::before',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'box-sizing', 'value' => 'border-box', 'important' => false],
                    ['type' => 'decl', 'property' => 'margin', 'value' => '0', 'important' => false],
                ],
            ],
            $ast[0]
        );
        self::assertSame(
            ['type' => 'decl', 'property' => 'display', 'value' => 'none', 'important' => true],
            $ast[2]['nodes'][0]
        );
        self::assertSame('at-rule', $ast[3]['type']);
        self::assertSame('supports', $ast[3]['name']);
        self::assertSame('(not (-webkit-appearance: -apple-pay-button))', $ast[3]['params']);
        self::assertSame('::placeholder', $ast[3]['nodes'][0]['selector']);
    }

    /** @test */
    public function theGeneratedPreflightFileRecordsItsProvenance(): void
    {
        $this->output = self::BANNER . self::PREFLIGHT_CSS;

        $source = $this->importer()->preflight()['contents'];

        self::assertStringContainsString('GENERATED FILE', $source);
        self::assertStringContainsString('tools/import-preflight.php', $source);
        self::assertStringContainsString('@import "tailwindcss/preflight.css";', $source);
        self::assertStringContainsString(Hash::sha256(self::PREFLIGHT_CSS), $source);
        self::assertStringContainsString('Copyright (c) Tailwind Labs, Inc.', $source);
        self::assertStringContainsString('4 top-level nodes', $source);

        foreach ($this->digests() as $digest) {
            self::assertStringContainsString($digest, $source);
        }
    }

    /**
     * @test
     *
     * @dataProvider unacceptablePreflights
     */
    public function aPreflightThatIsNotJustRulesIsRefused(string $css): void
    {
        $this->output = self::BANNER . $css;

        $this->expectException(\RuntimeException::class);

        $this->importer()->preflight();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unacceptablePreflights(): array
    {
        return [
            'nothing' => [''],
            'a bare declaration' => ["color: red;\n"],
            'an import' => ["@import 'x.css';\n"],
            'a charset' => ["@charset 'utf-8';\n"],
            'a parse failure' => ["a {\n  color: red;\n"],
        ];
    }

    // --------------------------------------------------- writing and --check

    /** @test */
    public function settleReportsWrittenUnchangedDriftAndMissing(): void
    {
        $path = $this->root . '/out/DefaultTheme.php';

        self::assertSame('missing', DataImporter::settle($path, "a\n", true));
        self::assertFileDoesNotExist($path);

        self::assertSame('written', DataImporter::settle($path, "a\n", false));
        self::assertSame("a\n", \file_get_contents($path));

        self::assertSame('unchanged', DataImporter::settle($path, "a\n", false));
        self::assertSame('unchanged', DataImporter::settle($path, "a\n", true));

        self::assertSame('drift', DataImporter::settle($path, "b\n", true));
        self::assertSame("a\n", \file_get_contents($path), '--check must not write.');

        self::assertSame('written', DataImporter::settle($path, "b\n", false));
        self::assertSame("b\n", \file_get_contents($path));
    }

    // ------------------------------------------------- binary and banner

    /** @test */
    public function aStylesheetFromAnotherReleaseIsRefused(): void
    {
        $this->output = "/*! tailwindcss v4.2.0 | MIT License | https://tailwindcss.com */\n" . self::THEME_CSS;

        try {
            $this->importer()->theme();
            self::fail('Data from another release must not be imported.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('4.2.0', $e->getMessage());
        }
    }

    /** @test */
    public function throughTheRealRunnerTheFixtureBinarysBannerlessOutputIsRefused(): void
    {
        $environment = new Environment();
        $runner = new CliRunner(
            self::fixtureBinary(),
            self::VERSION,
            Checksums::fromTable([
                self::VERSION => [$environment->platform() => Hash::sha256File(self::fixtureBinary())],
            ]),
            $environment,
            new Process(),
            new Filesystem(),
            $this->root,
            20.0
        );

        $importer = new DataImporter([$runner, 'compile'], self::VERSION, $this->digests());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('banner');

        $importer->theme();
    }

    // ---------------------------------------------------------- the front end

    /**
     * @test
     *
     * @dataProvider badArguments
     *
     * @param list<string> $arguments
     */
    public function theFrontEndRejectsAnythingItDoesNotKnow(array $arguments): void
    {
        $this->prepareRepository();

        [$status, $stderr] = $this->main('theme', $arguments);

        self::assertSame(2, $status);
        self::assertNotSame('', $stderr);
        self::assertFileDoesNotExist($this->root . '/src/Engine/Data/DefaultTheme.php');
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function badArguments(): array
    {
        return [
            'an unknown option' => [['--bogus']],
            'a binary that is not there' => [['--binary=tools/.cache/nothing-here']],
            'a binary outside the repository' => [['--binary=../outside']],
            'an absolute binary path' => [['--binary=/etc/passwd']],
            'an empty binary' => [['--binary=']],
            'no binary installed at all' => [['--check']],
        ];
    }

    // ------------------------------------------- the committed data files

    /**
     * The goldens are exactly the CLI's output for the same two inputs, so the
     * committed data files must be reproducible from them with no binary at all.
     *
     * @test
     */
    public function theCommittedDataFilesAreWhatTheImporterProducesFromTheGoldens(): void
    {
        $root = \dirname(__DIR__, 3);
        $importer = new DataImporter(
            static function (): string {
                throw new \LogicException('No binary is needed to regenerate from a golden.');
            },
            self::VERSION,
            self::pinned()
        );

        $theme = (string) \file_get_contents($root . '/tests/Conformance/golden/directives/import-theme-static.css');
        $preflight = (string) \file_get_contents($root . '/tests/Conformance/golden/directives/import-preflight.css');

        self::assertSame(
            (string) \file_get_contents($root . '/' . DataImporter::THEME_FILE),
            $importer->themeFromCss($theme)
        );
        self::assertSame(
            (string) \file_get_contents($root . '/' . DataImporter::PREFLIGHT_FILE),
            $importer->preflightFromCss($preflight)
        );
    }

    /** @test */
    public function theCommittedFilesNameTheHashOfTheGoldenTheyCameFrom(): void
    {
        $root = \dirname(__DIR__, 3);

        foreach (
            [
                DataImporter::THEME_FILE => 'import-theme-static',
                DataImporter::PREFLIGHT_FILE => 'import-preflight',
            ] as $file => $golden
        ) {
            $css = (string) \file_get_contents($root . '/tests/Conformance/golden/directives/' . $golden . '.css');

            self::assertStringContainsString(
                Hash::sha256($css),
                (string) \file_get_contents($root . '/' . $file),
                $file . ' does not carry the hash of ' . $golden . '.css'
            );
        }
    }

    /** @test */
    public function theLicenceTextTheImporterEmbedsIsTheOneInTheNoticeFile(): void
    {
        self::assertNotSame([], self::noticeLicenceLines());
        self::assertSame(
            \implode("\n", self::noticeLicenceLines()),
            \implode("\n", \explode("\n", DataImporter::LICENCE))
        );
    }

    // ---------------------------------------------------------------- helpers

    private function importer(): DataImporter
    {
        $compile = function (string $input, string $candidates, array $files, bool $minify): string {
            $this->calls[] = [
                'input' => $input,
                'candidates' => $candidates,
                'files' => $files,
                'minify' => $minify,
            ];

            return $this->output;
        };

        return new DataImporter($compile, self::VERSION, $this->digests());
    }

    /**
     * @return array<string, string>
     */
    private function digests(): array
    {
        return [
            'linux-x64' => \str_repeat('a', 64),
            'windows-x64.exe' => \str_repeat('b', 64),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function pinned(): array
    {
        $pinned = Checksums::load()->forVersion(self::VERSION);

        self::assertNotNull($pinned, 'The shipped checksum table does not pin ' . self::VERSION . '.');

        return $pinned;
    }

    /**
     * Load a generated file under a private namespace so the real class is not
     * redeclared, and return the name of the class it defines.
     *
     * @return class-string
     */
    private function load(string $source): string
    {
        ++self::$namespaces;

        $namespace = 'TailwindPhp\\Tests\\Generated\\Run' . self::$namespaces;
        $rewritten = \str_replace(
            'namespace TailwindPhp\\Engine\\Data;',
            'namespace ' . $namespace . ';',
            $source,
            $count
        );

        self::assertSame(1, $count, 'The generated file declares exactly one namespace.');

        $file = $this->root . '/generated-' . self::$namespaces . '.php';

        \file_put_contents($file, $rewritten);

        require $file;

        $class = \preg_match('/^final class (\w+)$/m', $source, $match) === 1 ? $match[1] : '';

        self::assertNotSame('', $class);

        /** @var class-string $qualified */
        $qualified = $namespace . '\\' . $class;

        return $qualified;
    }

    /**
     * The MIT licence block of NOTICE, de-indented, one entry per line.
     *
     * @return list<string>
     */
    private static function noticeLicenceLines(): array
    {
        $notice = (string) \file_get_contents(\dirname(__DIR__, 3) . '/NOTICE');
        $lines = [];
        $inside = false;

        foreach (\explode("\n", \str_replace("\r\n", "\n", $notice)) as $line) {
            if (!$inside) {
                $inside = $line === '    MIT License';

                if (!$inside) {
                    continue;
                }
            }

            $lines[] = \substr($line, 0, 4) === '    ' ? \substr($line, 4) : \rtrim($line);

            if (\strpos($line, 'SOFTWARE.') !== false && \strpos($line, 'THE SOFTWARE') === false) {
                break;
            }
        }

        return $lines;
    }

    private function prepareRepository(): void
    {
        \mkdir($this->root . '/tests/Conformance/golden', 0777, true);
        \mkdir($this->root . '/tools/.cache', 0777, true);
        \file_put_contents($this->root . '/tests/Conformance/golden/VERSION', self::VERSION . "\n");
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function main(string $kind, array $arguments): array
    {
        $out = \fopen('php://memory', 'w+b');
        $err = \fopen('php://memory', 'w+b');

        self::assertIsResource($out);
        self::assertIsResource($err);

        $status = DataImporter::main($kind, \array_merge(['script.php'], $arguments), $this->root, $out, $err);

        \rewind($err);

        return [$status, (string) \stream_get_contents($err)];
    }

    private static function fixtureBinary(): string
    {
        $script = \dirname(__DIR__, 2) . '/Fixtures/bin/fake-tailwindcss';

        if (\DIRECTORY_SEPARATOR === '\\') {
            return \str_replace('\\', '/', $script) . '.cmd';
        }

        if (!\is_executable($script)) {
            \chmod($script, 0755);
        }

        return $script;
    }

    private static function loadTools(): void
    {
        $tools = \dirname(__DIR__, 3) . '/tools';

        require_once $tools . '/CliRunner.php';
        require_once $tools . '/GoldenGenerator.php';
        require_once $tools . '/DataImporter.php';
    }
}
