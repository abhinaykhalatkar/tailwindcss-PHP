<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Tailwind;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The facade's contract, at the edges (TASK-0018 acceptance 7; HBC-A3, A15).
 *
 * The interesting behaviour lives in the integration rows, because the facade
 * is wiring and wiring is only true against real collaborators. What is worth
 * pinning here is the part that must hold *before* anything is wired: a bad
 * configuration fails immediately, and a request for a route we do not serve
 * costs nothing at all.
 *
 * @covers-requirement FR-PIPE-1
 */
final class TailwindTest extends TestCase
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

    /**
     * Configuration is validated at the front door, not on the first request:
     * a typo should stop a deploy, not surface as an odd stylesheet later.
     *
     * @test
     */
    public function aBadConfigurationIsRefusedImmediately(): void
    {
        $this->expectException(ConfigurationException::class);

        Tailwind::fromArray(['project_root' => __DIR__, 'input' => 'nope/missing.css']);
    }

    /**
     * @test
     */
    public function anUnknownBundleIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->project()->tailwind()->bundle('nope');
    }

    /**
     * HBC-A15: the switch changes what pages link to and nothing else.
     *
     * @test
     */
    public function fingerprintingOffReturnsThePlainRoute(): void
    {
        $project = $this->project();
        $project->request('assets/styles.css');

        self::assertSame('assets/styles.css', $project->tailwind(['fingerprint' => false])->url());
        self::assertStringStartsWith('assets/styles.', $project->tailwind()->url());
    }

    /**
     * Before the first build there is no fingerprint to name, and the plain
     * route is the right answer because requesting it is what builds.
     *
     * @test
     */
    public function urlFallsBackToThePlainRouteWithoutAManifest(): void
    {
        self::assertSame('assets/styles.css', $this->project()->tailwind()->url());
    }

    /**
     * @test
     */
    public function thePrefixIsPassedThrough(): void
    {
        self::assertSame('/app/assets/styles.css', $this->project()->tailwind()->url('/app/'));
    }

    /**
     * `build => true` is for a deploy step that renders templates before the
     * first request has ever arrived.
     *
     * @test
     */
    public function urlCanBuildFirst(): void
    {
        $project = $this->project();

        $url = $project->tailwind()->url('', ['build' => true]);

        self::assertNotSame('assets/styles.css', $url, 'the build should have produced a fingerprint');
        self::assertNotNull($project->manifest('app'));
    }

    /**
     * The zero-I/O guarantee: a site that mounts this library at one URL must
     * not pay for it on every other request it serves.
     *
     * @test
     */
    public function aForeignRouteIsDeclinedWithoutBuildingAnything(): void
    {
        $project = $this->project();
        $tailwind = $project->tailwind();

        self::assertNull($tailwind->handle('favicon.ico', []));

        // Buffered so the "wrote nothing" half is asserted rather than assumed;
        // without this the row is merely output-free by luck, and PHPUnit marks
        // it risky for exactly that reason.
        $level = \ob_get_level();
        \ob_start();
        $served = $tailwind->respond('favicon.ico', []);
        $output = (string) \ob_get_clean();

        self::assertFalse($served);
        self::assertSame('', $output);
        self::assertSame($level, \ob_get_level());
        self::assertNull($project->manifest('app'));
        self::assertFileDoesNotExist($project->root() . '/cache/tailwind/app.css');
    }

    /**
     * @test
     */
    public function resolveDefaultsToTheFirstBundle(): void
    {
        $result = $this->project()->tailwind()->resolve();

        self::assertSame('compiled', $result->source());
        self::assertNotSame('', $result->fingerprint());
    }

    /**
     * Two facades in one process are independent — there is no static state in
     * `src/`, and the tests rely on that constantly.
     *
     * @test
     */
    public function twoInstancesDoNotShareState(): void
    {
        $project = $this->project();

        $a = $project->tailwind();
        $b = $project->tailwind(['debug' => true]);

        self::assertFalse($a->config()->debug());
        self::assertTrue($b->config()->debug());
    }

    private function project(): TempProject
    {
        if ($this->project === null) {
            $this->project = new TempProject();
        }

        return $this->project;
    }
}
