<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The procedural template helper (TASK-0018 acceptance 4; decision HBC-A10).
 *
 * Two things are worth pinning about a function that Composer autoloads into
 * every request of every project that installs this library: that it is guarded
 * against redeclaration, and that it holds no state of its own. The facade is
 * an explicit argument precisely so there is no registry to get wrong.
 *
 * @covers-requirement FR-HTTP-8
 */
final class FunctionsTest extends TestCase
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
     * Composer's `autoload.files` is what makes this available; if the entry
     * were missing the function would simply not exist.
     *
     * @test
     */
    public function theHelperIsAutoloaded(): void
    {
        self::assertTrue(\function_exists('TailwindPhp\url'));
    }

    /**
     * @test
     */
    public function itDelegatesToTheFacade(): void
    {
        $project = $this->project();
        $tailwind = $project->tailwind();

        self::assertSame($tailwind->url(), \TailwindPhp\url($tailwind));
        self::assertSame($tailwind->url('/app/'), \TailwindPhp\url($tailwind, '/app/'));
    }

    /**
     * @test
     */
    public function itReflectsTheInstanceItIsGiven(): void
    {
        $project = $this->project();
        $project->request('assets/styles.css');

        $plain = $project->tailwind(['fingerprint' => false]);
        $fingerprinted = $project->tailwind();

        self::assertSame('assets/styles.css', \TailwindPhp\url($plain));
        self::assertNotSame('assets/styles.css', \TailwindPhp\url($fingerprinted));
    }

    /**
     * Loading the file twice must not fatal — a project may reach it through
     * both the autoloader and its own bootstrap.
     *
     * @test
     */
    public function theFileIsSafeToLoadTwice(): void
    {
        require \dirname(__DIR__, 3) . '/src/functions.php';

        self::assertTrue(\function_exists('TailwindPhp\url'));
    }

    private function project(): TempProject
    {
        if ($this->project === null) {
            $this->project = new TempProject();
        }

        return $this->project;
    }
}
