<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Engine;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Version;
use TailwindPhp\Tests\Support\TempProject;

/**
 * Isolated-green is not deployment-safe (TASK-0034 criterion 9): the fixture project
 * `tests/Fixtures/project-basic` — an entry importing Tailwind and a partial, two
 * templates — compiled end to end through the production facade with `driver: php`,
 * as a host without the binary runs it: scan, compile with the real engine, write
 * the artefact and manifest, serve. A second request is a cache hit.
 */
final class EngineLifecycleTest extends TestCase
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

    /** @test */
    public function theFixtureProjectIsCompiledByTheRealEngineAndCached(): void
    {
        $project = $this->project();
        $first = $project->tailwind()->resolve('app');

        self::assertSame('compiled', $first->source(), self::diagnostics($first->diagnostics()->toArray()));
        self::assertFalse($first->diagnostics()->hasErrors());

        $path = (string) $first->path();
        $css = (string) \file_get_contents($path);

        $bag = new DiagnosticBag();
        $selectors = self::selectors(Parser::parse($css, $bag)->nodes);
        self::assertSame([], $bag->toArray(), 'the served stylesheet parses clean');

        // Real CSS for the templates' classes, the authored CSS and the partial.
        $expected = ['.flex', '.items-center', '.gap-2', '.p-4', '.text-sm', '.font-medium', '.entry', '.from-partial'];

        foreach ($expected as $selector) {
            self::assertContains($selector, $selectors);
        }

        self::assertStringContainsString('calc(var(--spacing) * 4)', $css);
        self::assertStringNotContainsString('stub', $css);

        $manifest = $project->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame('php', $manifest['driver'] ?? null);
        self::assertSame(Version::COMPAT, $manifest['driver_version'] ?? null);

        $second = $project->tailwind()->resolve('app');

        self::assertNotSame('compiled', $second->source(), 'the second request is served from the cache');
        self::assertSame($first->contentHash(), $second->contentHash());
    }

    /**
     * T-01 (the TASK-0033 review): the processor hands every specifier to the
     * resolver; with the real `FilesystemImportResolver` a root-absolute, drive
     * or URL specifier and a relative one that climbs out of the project read
     * nothing, and a relative one inside the project is read from the entry's
     * directory.
     *
     * @test
     */
    public function theRealResolverConfinesEveryImport(): void
    {
        $project = $this->project();
        $root = $project->root();
        $outside = \dirname($root) . '/outside-' . \basename($root) . '.css';
        \file_put_contents($outside, '.leaked { color: red; }');

        try {
            $project->writeFile('assets/styles/near.css', '.near { color: blue; }');
            $resolver = new \TailwindPhp\Engine\Import\FilesystemImportResolver(
                $root,
                [$root],
                new \TailwindPhp\Support\Filesystem(),
                1048576
            );
            $entry = "@import \"/etc/passwd\";\n@import \"C:/Windows/win.ini\";\n@import \"file:///etc/passwd\";\n"
                . '@import "../../../' . \basename($outside) . "\";\n@import \"./near.css\";\n";
            $output = (new \TailwindPhp\Engine\Compiler())->compile(new \TailwindPhp\Engine\CompileRequest(
                $entry,
                $resolver,
                [],
                false,
                true,
                \TailwindPhp\Engine\CompileRequest::DEFAULT_MAX_CANDIDATES,
                'assets/styles/main.css'
            ));
        } finally {
            \unlink($outside);
        }

        self::assertStringNotContainsString('.leaked', $output->css);
        self::assertStringNotContainsString('root:', $output->css);
        self::assertStringContainsString('.near {', $output->css, 'read from the entry\'s directory');
        self::assertSame(['assets/styles/near.css'], $output->imports, 'project-relative identities only');

        $codes = [];

        foreach ($output->diagnostics->all() as $diagnostic) {
            $codes[] = $diagnostic->code();
            self::assertStringNotContainsString($root, $diagnostic->message(), 'no absolute path in a message');
        }

        $notFound = \TailwindPhp\Engine\Diagnostic\Diagnostic::E_IMPORT_NOT_FOUND;
        self::assertSame([$notFound], $codes, 'the climbing import');
    }

    private function project(): TempProject
    {
        if ($this->project === null) {
            $this->project = TempProject::make();
            $fixture = \dirname(__DIR__, 2) . '/Fixtures/project-basic';

            $files = [
                'assets/styles/main.css',
                'assets/styles/partials/base.css',
                'templates/home.twig',
                'templates/list.twig',
            ];

            foreach ($files as $rel) {
                $this->project->writeFile($rel, (string) \file_get_contents($fixture . '/' . $rel));
            }
        }

        return $this->project;
    }

    /**
     * Every selector of a stylesheet, at any depth.
     *
     * @param list<Node> $nodes
     *
     * @return list<string>
     */
    private static function selectors(array $nodes): array
    {
        $selectors = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $selectors[] = $node->selector;
                $selectors = \array_merge($selectors, self::selectors($node->nodes));
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $selectors = \array_merge($selectors, self::selectors($node->nodes));
            }
        }

        return $selectors;
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     */
    private static function diagnostics(array $diagnostics): string
    {
        $lines = [];

        foreach ($diagnostics as $diagnostic) {
            $lines[] = (string) ($diagnostic['code'] ?? '') . ' ' . (string) ($diagnostic['message'] ?? '');
        }

        return \implode("\n", $lines);
    }
}
