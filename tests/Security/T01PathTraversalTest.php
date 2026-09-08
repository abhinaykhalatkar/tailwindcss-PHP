<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Threat T-01 — path traversal via configuration, exercised through the layer a
 * user actually touches.
 *
 * `Support\Path` has its own table of unit cases; this class asserts that the
 * config builder routes **every** configurable path through it, that a rejection
 * surfaces as a `ConfigurationException` the user can act on rather than a
 * warning or a silently wrong path, and that T-14 (a cache directory that
 * resolves outside the project through a link) is caught here rather than at
 * first write.
 *
 * @covers-requirement NFR-SEC-1
 */
final class T01PathTraversalTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var list<string>
     */
    private $junctions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-t01');

        \mkdir($this->root . '/assets/styles', 0777, true);
        \mkdir($this->root . '/templates', 0777, true);
        \file_put_contents($this->root . '/assets/styles/main.css', '@import "tailwindcss";');
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->junctions) as $junction) {
            if (TempTree::entryExists($junction)) {
                \rmdir($junction);
            }
        }

        $this->junctions = [];

        TempTree::remove($this->root);

        parent::tearDown();
    }

    /**
     * @test
     *
     * @dataProvider traversalAttempts
     */
    public function aHostileConfigPathIsRejected(string $key, string $value): void
    {
        $problems = $this->problemsFor([$key => $value]);

        self::assertNotSame(
            [],
            $problems,
            \sprintf('Key "%s" accepted the hostile value.', $key)
        );
    }

    /**
     * Each row is a real shape from design doc 12 T-01, applied to each of the
     * path-valued keys.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function traversalAttempts(): array
    {
        $cases = [];

        $values = [
            'climbing out' => '../../etc/passwd',
            'deep climb' => '../../../../../../etc/passwd',
            'phar wrapper' => 'phar://evil.phar/x.css',
            'file wrapper' => 'file:///etc/passwd',
            'http wrapper' => 'http://example.com/x.css',
            'data wrapper' => 'data:text/css,body{}',
            'NUL byte' => "assets/styles/main.css\0.png",
            'absolute outside' => '/etc/passwd',
        ];

        foreach (['input', 'cache_dir'] as $key) {
            foreach ($values as $label => $value) {
                $cases[$key . ' — ' . $label] = [$key, $value];
            }
        }

        return $cases;
    }

    /**
     * A wrapper in a *source* root is rejected too — sources are a list, so the
     * per-entry path has to be checked as carefully as the scalar ones.
     *
     * @test
     */
    public function aHostileSourceEntryIsRejected(): void
    {
        self::assertNotSame([], $this->problemsFor(['sources' => ['../../etc']]));
        self::assertNotSame([], $this->problemsFor(['sources' => ['phar://evil.phar']]));
        self::assertNotSame([], $this->problemsFor(['sources' => [['path' => '../../etc']]]));
    }

    /** @test */
    public function aHostileWatchEntryIsRejected(): void
    {
        self::assertNotSame([], $this->problemsFor(['watch' => ['../../etc']]));
    }

    /** @test */
    public function aHostileAllowedRootIsRejected(): void
    {
        self::assertNotSame([], $this->problemsFor(['allowed_roots' => ['phar://evil.phar']]));
        self::assertNotSame([], $this->problemsFor(['allowed_roots' => ["/tmp\0"]]));
    }

    /**
     * Every hostile path is reported, not just the first — otherwise fixing a
     * config with several would take several rounds.
     *
     * @test
     */
    public function severalHostilePathsAreAllReported(): void
    {
        $problems = $this->problemsFor([
            'input' => '../../etc/passwd',
            'sources' => ['../../etc'],
            'watch' => ['phar://evil.phar'],
        ]);

        self::assertGreaterThanOrEqual(3, \count($problems), \implode("\n", $problems));
    }

    /**
     * Threat T-06: a rejection message must not echo the resolved absolute path
     * back to whoever triggered it.
     *
     * @test
     */
    public function aRejectionDoesNotLeakAnAbsolutePath(): void
    {
        $problems = $this->problemsFor(['input' => '/etc/passwd']);

        $joined = \implode(' | ', $problems);

        self::assertNotSame([], $problems);
        self::assertStringNotContainsString('/etc/passwd', $joined);
    }

    /**
     * T-14 — the cache directory reached through a link that leaves the project.
     * Every artefact we write would land outside it, so this is caught at
     * configuration time rather than at the first write.
     *
     * @test
     */
    public function aCacheDirectoryEscapingThroughALinkIsRejected(): void
    {
        $outside = TempTree::make('tailwindphp-t01-outside');

        try {
            $link = $this->root . '/linked-cache';

            if (!$this->makeLink($link, $outside)) {
                self::markTestSkipped('This host cannot create a directory link.');
            }

            $problems = $this->problemsFor(['cache_dir' => 'linked-cache']);

            self::assertNotSame([], $problems, 'A symlinked cache directory escaped the project.');
        } finally {
            TempTree::remove($outside);
        }
    }

    /** @test */
    public function aCacheDirectoryLinkedInsideTheProjectIsAccepted(): void
    {
        \mkdir($this->root . '/var', 0777, true);

        $link = $this->root . '/linked-cache';

        if (!$this->makeLink($link, $this->root . '/var')) {
            self::markTestSkipped('This host cannot create a directory link.');
        }

        $config = ConfigBuilder::fromArray([
            'project_root' => $this->root,
            'cache_dir' => 'linked-cache',
        ]);

        self::assertNotSame('', $config->cacheDir());
    }

    /**
     * Create a directory link: a junction on Windows (no elevation needed), a
     * symlink elsewhere.
     */
    private function makeLink(string $link, string $target): bool
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            return \symlink($target, $link);
        }

        $command = \sprintf(
            'mklink /J "%s" "%s"',
            \str_replace('/', '\\', $link),
            \str_replace('/', '\\', $target)
        );

        $output = [];
        $status = 0;

        // Test-only helper; the library never spawns a process here.
        \exec('cmd /c ' . $command . ' 2>&1', $output, $status);

        if ($status !== 0) {
            return false;
        }

        $this->junctions[] = $link;

        return \is_dir($link);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return list<string>
     */
    private function problemsFor(array $user): array
    {
        $user['project_root'] = $this->root;

        try {
            ConfigBuilder::fromArray($user);
        } catch (ConfigurationException $e) {
            return $e->problems();
        }

        return [];
    }
}
