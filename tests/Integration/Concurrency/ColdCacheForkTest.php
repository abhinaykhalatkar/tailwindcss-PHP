<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Concurrency;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempProject;

/**
 * Twenty cold-cache requests at once produce exactly one compile (design doc
 * 23 §M1 gate, doc 04 §8 I3, doc 16 §Integration conventions).
 *
 * Needs pcntl_fork(), so it runs on Linux CI and is skipped with a reason
 * elsewhere; {@see LockHeldTest} pins the same invariant without a fork.
 *
 * @covers-requirement FR-CACHE-3
 * @covers-invariant I3
 */
final class ColdCacheForkTest extends TestCase
{
    private const CHILDREN = 20;

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

    /**
     * Each child builds its own facade (one instance per request, as in
     * production) and resolves the bundle; the parent counts what they saw.
     *
     * What is counted: the `source` every child got (exactly one `compiled`;
     * the rest `cached`, the CCP-A19 waiting-loser outcome, since a cold loser
     * has nothing to fall back on), the fingerprint (identical everywhere) and
     * `built_at` as each child read it from the manifest after its own
     * resolve (identical everywhere: one build, not twenty).
     *
     * @test
     */
    public function twentyColdCacheRequestsCompileExactlyOnce(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl_fork() is not available on this host (Windows or a restricted SAPI).');
        }

        $project = $this->project();
        $resultDir = $project->root() . '/fork-results';
        \mkdir($resultDir, 0777, true);

        $children = [];

        for ($i = 0; $i < self::CHILDREN; $i++) {
            $pid = \pcntl_fork();

            if ($pid === -1) {
                self::fail('pcntl_fork() failed');
            }

            if ($pid === 0) {
                // Child: never return into PHPUnit; report through a file and exit.
                $code = 1;

                try {
                    $result = $project->tailwind(['lock_timeout' => 30])->resolve('app');
                    $manifest = $project->manifest('app');
                    \file_put_contents(
                        $resultDir . '/' . $i . '.txt',
                        $result->source() . '|' . $result->fingerprint() . '|' . ($manifest['built_at'] ?? '?')
                    );
                    $code = $result->isInline() ? 1 : 0;
                } catch (\Throwable $e) {
                    \file_put_contents($resultDir . '/' . $i . '.txt', 'error|' . \get_class($e));
                }

                exit($code);
            }

            $children[] = $pid;
        }

        $exitCodes = [];

        foreach ($children as $pid) {
            $status = 0;
            \pcntl_waitpid($pid, $status);
            $exitCodes[] = \pcntl_wexitstatus($status);
        }

        self::assertSame(\array_fill(0, self::CHILDREN, 0), $exitCodes, 'every child must exit 0');

        $sources = [];
        $fingerprints = [];
        $builtAts = [];

        for ($i = 0; $i < self::CHILDREN; $i++) {
            $line = (string) \file_get_contents($resultDir . '/' . $i . '.txt');
            $parts = \explode('|', $line);
            self::assertCount(3, $parts, 'child ' . $i . ' reported: ' . $line);
            $sources[] = $parts[0];
            $fingerprints[] = $parts[1];
            $builtAts[] = $parts[2];
        }

        $counts = \array_count_values($sources);

        self::assertSame(1, $counts['compiled'] ?? 0, 'exactly one child must compile: ' . \implode(',', $sources));
        self::assertSame(
            self::CHILDREN - 1,
            $counts['cached'] ?? 0,
            'every other child serves the winner: ' . \implode(',', $sources)
        );
        self::assertCount(1, \array_unique($fingerprints), 'one fingerprint for everyone');
        self::assertCount(1, \array_unique($builtAts), 'one build, one built_at');

        $manifest = $project->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame($fingerprints[0], $manifest['fingerprint']);
    }

    private function project(): TempProject
    {
        if ($this->project === null) {
            $this->project = TempProject::make();
        }

        return $this->project;
    }
}
