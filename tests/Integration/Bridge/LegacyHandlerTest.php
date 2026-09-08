<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Bridge;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Bridge\LegacyHandler;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The legacy front-controller adapter (TASK-0018 acceptance 7; doc 22, decisions
 * HBC-A7/A8/A9).
 *
 * Every row runs with the working directory inside a temp project, because the
 * class takes a cwd-relative path by contract — that is the whole point of the
 * adapter, and testing it any other way would test something else. The
 * directory is always restored in a `finally`: a test that leaves the process
 * chdir-ed elsewhere breaks every test that runs after it, in ways that look
 * like unrelated failures.
 *
 * @covers-requirement FR-PIPE-3
 */
final class LegacyHandlerTest extends TestCase
{
    /**
     * @var string|null
     */
    private $root;

    /**
     * @var string|false
     */
    private $previousCwd = false;

    protected function setUp(): void
    {
        $this->root = TempTree::make('tailwindphp-legacy');
        $this->previousCwd = \getcwd();

        \mkdir($this->root . '/assets/styles', 0777, true);
        \mkdir($this->root . '/templates', 0777, true);
        $this->write('assets/styles/main.css', "@import \"tailwindcss\";\n");
        $this->write('templates/index.twig', "<div class=\"flex p-4\"></div>\n");
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== false) {
            \chdir($this->previousCwd);
            $this->previousCwd = false;
        }

        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }
    }

    /**
     * @test
     */
    public function theConstructorCompilesAndTheGetterIsPure(): void
    {
        $handler = $this->handler();

        $first = $handler->getContent();
        $second = $handler->getContent();

        self::assertNotSame('', $first);
        self::assertSame($first, $second, 'getContent() must be a pure getter');
        self::assertFileExists($this->root . '/cache/tailwind/app.meta.json');
    }

    /**
     * The bytes are buffered in the constructor, so a second `getContent()`
     * cannot reach the filesystem however many times a template calls it.
     *
     * Proven by taking the artefact away between calls: a getter that still
     * read from disk would now fail or come back empty, and a buffered one
     * cannot notice. (The first version of this row compared cache-directory
     * mtimes, which reads never change — it would have passed against a getter
     * that re-read the file every time.)
     *
     * @test
     */
    public function theSecondCallReadsNothing(): void
    {
        $handler = $this->handler();
        $first = $handler->getContent();

        self::assertNotSame('', $first);

        TempTree::remove($this->root . '/cache');
        self::assertDirectoryDoesNotExist($this->root . '/cache');

        self::assertSame($first, $handler->getContent(), 'a pure getter must not re-read the artefact');
        self::assertSame($first, $handler->getContent());
    }

    /**
     * @test
     */
    public function theContentTypeIsExact(): void
    {
        self::assertSame('text/css; charset=utf-8', $this->handler()->getContentType());
    }

    /**
     * `limits.max_total_css` bounds the **entry** stylesheet the compiler
     * reads, never the CSS it produces, and `Filesystem::read()` truncates
     * silently at whatever cap it is given. Reading the artefact with that
     * limit served a stylesheet cut off mid-rule under a 200 — wrong in a way
     * nothing logs and only a large project would notice.
     *
     * The row lowers the limit to the schema floor and makes the *output* far
     * larger than it, so a capped read would come back short.
     *
     * @test
     */
    public function theWholeArtefactIsServedWhateverTheEntryLimitIs(): void
    {
        \mkdir($this->root . '/config', 0777, true);
        $this->write('config/tailwind.php', "<?php\n\nreturn ['limits' => ['max_total_css' => 1024]];\n");

        // Enough distinct utilities that the generated CSS comfortably exceeds
        // the 1024-byte entry cap.
        $classes = [];

        for ($i = 0; $i < 120; $i++) {
            $classes[] = 'p-' . $i;
            $classes[] = 'm-' . $i;
        }

        $this->write('templates/index.twig', '<div class="' . \implode(' ', $classes) . "\"></div>\n");

        $content = $this->handler()->getContent();

        $artefact = $this->artefactPath();
        self::assertNotNull($artefact);

        $expected = (string) \file_get_contents($artefact);

        self::assertGreaterThan(
            1024,
            \strlen($expected),
            'the build must exceed the entry cap for this row to mean anything'
        );
        self::assertSame($expected, $content, 'the served CSS was truncated');
    }

    /**
     * Doc 22's fallback: a project with an `.scss` entry needs no configuration
     * to be recognised.
     *
     * @test
     */
    public function anScssEntryIsFoundAndPreProcessed(): void
    {
        if (!\class_exists('ScssPhp\ScssPhp\Compiler')) {
            self::markTestSkipped('scssphp is not installed');
        }

        \unlink($this->root . '/assets/styles/main.css');
        $this->write('assets/styles/_vars.scss', "\$c: red;\n");
        $this->write(
            'assets/styles/main.scss',
            "@import \"vars\";\n@import \"tailwindcss\";\n.a { color: \$c; }\n"
        );

        $content = $this->handler()->getContent();

        self::assertNotSame('', $content);

        // The assertion that can fail. "No `$c` in the output" is true of the
        // stub engine's output whether or not SCSS ran at all, so it proved
        // nothing; what proves the stage ran is that the partial was resolved
        // and recorded as an import, and that the class was actually loaded.
        $manifest = $this->manifest();
        self::assertNotNull($manifest);
        self::assertContains(
            'assets/styles/_vars.scss',
            $manifest['imports'],
            'the SCSS partial should have been resolved and recorded'
        );
        self::assertTrue(
            \class_exists('TailwindPhp\PreProcessor\ScssPreProcessor', false),
            'the SCSS pre-processor should have been loaded'
        );
    }

    /**
     * A configured plain-CSS entry is never handed to the scss stage just
     * because a `main.scss` happens to sit beside it. The test project has
     * exactly this layout (Bootstrap's `main.scss` next to a Tailwind
     * `tailwind.css` named in `config/tailwind.php`), and the first live build
     * failed with scssphp rejecting `@import … layer(theme)`.
     *
     * @covers-requirement FR-PIPE-3 FR-PIPE-4
     *
     * @test
     */
    public function aConfiguredEntryIsNotPreprocessedBecauseAMainScssExists(): void
    {
        \unlink($this->root . '/assets/styles/main.css');
        $this->write('assets/styles/main.scss', "\$c: red;\n.a { color: \$c; }\n");
        $this->write(
            'assets/styles/tailwind.css',
            "@layer theme, base, components, utilities;\n@import \"tailwindcss/theme\" layer(theme);\n"
        );
        $this->write(
            'config/tailwind.php',
            "<?php\n\nreturn ['input' => 'assets/styles/tailwind.css', 'log_level' => 'emergency'];\n"
        );

        $content = $this->handler()->getContent();

        $manifest = $this->manifest();
        self::assertNotNull($manifest, 'the configured entry must build');
        self::assertNull($manifest['last_error'] ?? null);
        self::assertStringNotContainsString('build failed', $content);
        self::assertStringNotContainsString('plain CSS', $content);
    }

    /**
     * HBC-A9 / doc 11: `config/tailwind.php` wins over the file beside the
     * stylesheets. Both are planted, and only one can supply the safelist.
     *
     * @test
     */
    public function theProjectConfigWinsOverTheOneBesideTheStylesheets(): void
    {
        \mkdir($this->root . '/config', 0777, true);
        $this->write('config/tailwind.php', "<?php\n\nreturn ['safelist' => ['from-config-dir']];\n");
        $this->write('assets/tailwind.php', "<?php\n\nreturn ['safelist' => ['from-beside']];\n");

        $this->handler();

        $manifest = $this->manifest();

        self::assertNotNull($manifest);
        self::assertNotSame(
            '',
            (string) $manifest['safelist_hash'],
            'a safelist should have been applied'
        );

        // The two safelists differ, so the hash tells us which file was read.
        $besideOnly = $this->safelistHashWithout('config/tailwind.php');

        self::assertNotSame($besideOnly, $manifest['safelist_hash'], 'the config/ file should have won');
    }

    /**
     * @test
     */
    public function theFileBesideTheStylesheetsIsUsedWhenThereIsNoProjectConfig(): void
    {
        $this->write('assets/tailwind.php', "<?php\n\nreturn ['safelist' => ['from-beside']];\n");

        $this->handler();
        $manifest = $this->manifest();

        self::assertNotNull($manifest);
        self::assertNotSame('', (string) $manifest['safelist_hash']);
    }

    /**
     * Threat T-08: a config file an attacker planted in the cache directory is
     * never executed. The planted file would create a side-effect file if it
     * ran, so the assertion is about the filesystem rather than about a return
     * value.
     *
     * @test
     */
    public function aConfigFileInsideTheCacheDirectoryIsNeverExecuted(): void
    {
        \mkdir($this->root . '/cache/tailwind', 0777, true);
        $marker = $this->root . '/pwned.txt';
        $this->write(
            'cache/tailwind/tailwind.php',
            "<?php\n\n\\file_put_contents('" . \addslashes($marker) . "', 'x');\n\nreturn [];\n"
        );

        // The handler never looks here, but assert the outcome rather than the
        // absence of a code path.
        $this->handler();

        self::assertFileDoesNotExist($marker);
    }

    /**
     * @test
     */
    public function theDefaultSourcesAreFilteredToTheOnesThatExist(): void
    {
        \mkdir($this->root . '/assets/pages', 0777, true);

        $this->handler();
        $manifest = $this->manifest();

        self::assertNotNull($manifest);

        $sources = $manifest['roots']['sources'];

        self::assertContains('templates', $sources);
        self::assertContains('assets/pages', $sources);
        self::assertNotContains('assets/templates', $sources, 'a missing directory must not be watched');
        self::assertNotContains('assets/scripts', $sources);
    }

    /**
     * HBC-A8, debug half: the page still gets parseable CSS, with the reason
     * visible in it.
     *
     * @test
     */
    public function aFailedBuildInDebugReturnsTheOverlay(): void
    {
        $this->breakTheBuild();

        $content = (new LegacyHandler(true, 'assets/styles'))->getContent();

        self::assertStringContainsString('html::before', $content);
    }

    /**
     * HBC-A8, production half: one fixed comment, no path, no detail.
     *
     * @test
     */
    public function aFailedBuildInProductionReturnsTheConstantBody(): void
    {
        $this->breakTheBuild();

        $content = (new LegacyHandler(false, 'assets/styles'))->getContent();

        self::assertSame(ErrorRenderer::productionBody(), $content);
        self::assertStringNotContainsString((string) $this->root, $content);
    }

    /**
     * HBC-A8, the case worth having: with a warm cache, a failed rebuild serves
     * the previous stylesheet rather than an error body. The site stays styled.
     *
     * @test
     */
    public function aFailedRebuildWithACacheServesTheStaleStylesheet(): void
    {
        \chdir((string) $this->root);
        $good = (new LegacyHandler(false, 'assets/styles'))->getContent();

        $this->breakTheBuild();

        $content = (new LegacyHandler(false, 'assets/styles'))->getContent();

        self::assertSame($good, $content);
    }

    /**
     * The config file wins over the caller's flag, all the way to the failure
     * body: caller says production, the file says debug, the page gets the
     * overlay.
     *
     * **What this row does not reach**, stated because the round-2 review asked
     * for the catch-all's debug source to be fixed: this failure is handled by
     * the *pipeline*, which fails soft and returns an inline overlay, so the
     * handler's own `catch (\Throwable)` never runs.
     * `AssetPipeline::resolve()` catches `\Throwable` and rethrows only
     * `ConfigurationException` — which the handler rethrows unchanged — so that
     * catch is reachable only if the artefact disappears between `resolve()`
     * returning and the read. There is no seam to stage that from outside the
     * constructor, and a mutation on the flag inside it therefore survives; see
     * the report.
     *
     * @test
     */
    public function theFailureBodyFollowsTheConfiguredDebugFlagNotTheCallerFlag(): void
    {
        $this->breakTheBuild(['debug' => true]);

        // Caller says production; the config file says debug. The config file
        // wins, so the overlay is the right answer.
        $content = (new LegacyHandler(false, 'assets/styles'))->getContent();

        self::assertStringContainsString('html::before', $content);
    }

    /**
     * A misconfiguration is not failed soft: an unstyled page with no
     * explanation is worse than an exception a developer can read.
     *
     * @test
     */
    public function aMisconfiguredProjectThrows(): void
    {
        \unlink($this->root . '/assets/styles/main.css');

        $this->expectException(ConfigurationException::class);

        \chdir((string) $this->root);
        new LegacyHandler(false, 'assets/styles');
    }

    /**
     * The config file is a full array, not a fragment: keys the handler does
     * not infer are honoured as written.
     *
     * @test
     */
    public function theConfigFileMayOverrideAnInferredKey(): void
    {
        \mkdir($this->root . '/config', 0777, true);
        \mkdir($this->root . '/other', 0777, true);
        $this->write('config/tailwind.php', "<?php\n\nreturn ['sources' => ['other']];\n");

        $this->handler();
        $manifest = $this->manifest();

        self::assertNotNull($manifest);
        self::assertSame(['other'], $manifest['roots']['sources']);
    }

    private function handler(bool $debug = false, string $path = 'assets/styles'): LegacyHandler
    {
        \chdir((string) $this->root);

        return new LegacyHandler($debug, $path);
    }

    /**
     * Make the next build fail for a documented reason: an entry larger than
     * the smallest `limits.max_total_css` the schema allows.
     */
    /**
     * @param array<string, mixed> $extra Merged into the planted config file.
     */
    private function breakTheBuild(array $extra = []): void
    {
        // The handler resolves everything from the working directory, so a row
        // that constructs one directly still has to be standing in the project.
        \chdir((string) $this->root);
        \mkdir($this->root . '/config', 0777, true);

        $config = \array_merge(['limits' => ['max_total_css' => 1024], 'log_level' => 'emergency'], $extra);

        $this->write(
            'config/tailwind.php',
            "<?php\n\nreturn " . \var_export($config, true) . ";\n"
        );
        $this->write(
            'assets/styles/main.css',
            "@import \"tailwindcss\";\n" . \str_repeat("/* padding */\n", 200)
        );
    }

    /**
     * @return array<mixed>|null
     */
    private function manifest(): ?array
    {
        $path = $this->root . '/cache/tailwind/app.meta.json';

        if (!\is_file($path)) {
            return null;
        }

        $decoded = \json_decode((string) \file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Build once more with the named config file removed, to see which of two
     * candidates the handler had been reading.
     */
    private function safelistHashWithout(string $relative): string
    {
        \unlink($this->root . '/' . $relative);
        TempTree::remove($this->root . '/cache');

        $this->handler();
        $manifest = $this->manifest();

        return $manifest === null ? '' : (string) $manifest['safelist_hash'];
    }

    /**
     * The fingerprinted artefact the last build wrote.
     */
    private function artefactPath(): ?string
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return null;
        }

        $path = $this->root . '/cache/tailwind/app.' . $manifest['fingerprint'] . '.css';

        return \is_file($path) ? $path : null;
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }

        \file_put_contents($path, $contents);
    }
}
