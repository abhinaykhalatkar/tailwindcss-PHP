<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Tests\Support\TempProject;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Threat T-09 — debug mode must not be reachable from a request.
 *
 * Debug mode changes what the response body contains: it turns build failures
 * into a visible overlay carrying paths, diagnostics and a stack trace
 * (design doc 15 §Debug overlay). If a visitor could switch it on, that overlay
 * becomes an information-disclosure endpoint (NFR-SEC-5, threat T-06).
 *
 * The control is structural rather than defensive: `debug` is read from the
 * configuration array and nothing else, and static rule R3 forbids `$_GET`,
 * `$_POST`, `$_COOKIE`, `$_REQUEST` and `getenv()` anywhere outside `Console/`
 * and `Http\ServerRequest`. This test is the behavioural half — it poisons every
 * channel an attacker controls and asserts none of them moves the flag.
 */
final class T09DebugFromRequestTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var array<string, mixed>
     */
    private $savedGet = [];

    /**
     * @var array<string, mixed>
     */
    private $savedPost = [];

    /**
     * @var array<string, mixed>
     */
    private $savedCookie = [];

    /**
     * @var array<string, mixed>
     */
    private $savedRequest = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-t09');

        \mkdir($this->root . '/assets/styles', 0777, true);
        \mkdir($this->root . '/templates', 0777, true);
        \file_put_contents($this->root . '/assets/styles/main.css', '@import "tailwindcss";');

        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
        $this->savedCookie = $_COOKIE;
        $this->savedRequest = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
        $_COOKIE = $this->savedCookie;
        $_REQUEST = $this->savedRequest;

        \putenv('TAILWINDCSS_PHP_DEBUG');

        TempTree::remove($this->root);

        parent::tearDown();
    }

    /**
     * Every request-controlled channel says "debug", loudly. The configuration
     * says nothing, so the answer must be the default: off.
     *
     * @test
     */
    public function noRequestChannelCanTurnDebugOn(): void
    {
        $this->poisonEveryChannel();

        $config = ConfigBuilder::fromArray(['project_root' => $this->root]);

        self::assertFalse($config->debug(), 'A request channel switched debug on.');
    }

    /**
     * The mirror: a request must not be able to switch debug *off* either, or a
     * developer's overlay could be suppressed by a crafted link.
     *
     * @test
     */
    public function noRequestChannelCanTurnDebugOff(): void
    {
        $_GET['debug'] = '0';
        $_POST['debug'] = 'false';
        $_COOKIE['debug'] = 'off';
        $_REQUEST['debug'] = '0';
        \putenv('TAILWINDCSS_PHP_DEBUG=0');

        $config = ConfigBuilder::fromArray([
            'project_root' => $this->root,
            'debug' => true,
        ]);

        self::assertTrue($config->debug(), 'A request channel switched debug off.');
    }

    /**
     * Debug also decides `minify`, so the same poisoning must not change the
     * produced CSS either (design doc 11 §10).
     *
     * @test
     */
    public function noRequestChannelCanChangeTheDerivedMinifyFlag(): void
    {
        $clean = ConfigBuilder::fromArray(['project_root' => $this->root]);

        $this->poisonEveryChannel();

        $poisoned = ConfigBuilder::fromArray(['project_root' => $this->root]);

        self::assertSame($clean->bundle('styles')->minify(), $poisoned->bundle('styles')->minify());
        self::assertSame($clean->configHash(), $poisoned->configHash());
    }

    /**
     * The configuration array is the only source, and it is honoured exactly.
     *
     * @test
     */
    public function theConfigurationArrayIsTheOnlySource(): void
    {
        $this->poisonEveryChannel();

        $on = ConfigBuilder::fromArray(['project_root' => $this->root, 'debug' => true]);
        $off = ConfigBuilder::fromArray(['project_root' => $this->root, 'debug' => false]);

        self::assertTrue($on->debug());
        self::assertFalse($off->debug());
    }

    /**
     * The same question asked of the whole request path (TASK-0018
     * acceptance 8).
     *
     * The rows above prove `Config::debug()` cannot be moved. This one proves
     * it is the only thing that decides what a visitor receives: a request
     * carrying every debug-shaped parameter and header anyone has ever tried
     * gets byte-identical treatment to a clean one.
     *
     * The difference is visible rather than subtle — debug sends `no-store` and
     * no `ETag`, production sends `no-cache` and one — so a leak here would show
     * up as a changed header rather than as something needing interpretation.
     *
     * @test
     */
    public function noRequestChannelCanTurnDebugOnThroughTheFacade(): void
    {
        $project = new TempProject();

        try {
            $this->poisonEveryChannel();

            $clean = $project->request('assets/styles.css');
            $poisoned = $project->request('assets/styles.css', [
                'REQUEST_URI' => '/assets/styles.css?debug=1&XDEBUG_TRIGGER=1',
                'QUERY_STRING' => 'debug=1',
                'HTTP_X_DEBUG' => '1',
                'HTTP_X_TAILWIND_DEBUG' => 'true',
                'HTTP_COOKIE' => 'debug=1',
                'HTTP_AUTHORIZATION' => 'Bearer debug',
            ]);

            self::assertNotNull($clean);
            self::assertNotNull($poisoned);

            self::assertSame($clean->status(), $poisoned->status());
            self::assertSame('no-cache', $poisoned->headerValue('Cache-Control'));
            self::assertNotNull($poisoned->headerValue('ETag'), 'production always sends a validator');
            self::assertSame($clean->headerValue('Cache-Control'), $poisoned->headerValue('Cache-Control'));
            self::assertNull($poisoned->body(), 'a debug overlay would have been an inline body');

            // And the control: debug really does look different, so the
            // assertions above are capable of failing.
            $debug = $project->request('assets/styles.css', [], ['debug' => true]);

            self::assertNotNull($debug);
            self::assertSame('no-store', $debug->headerValue('Cache-Control'));
            self::assertNull($debug->headerValue('ETag'));
        } finally {
            $project->remove();
        }
    }

    private function poisonEveryChannel(): void
    {
        $_GET['debug'] = '1';
        $_GET['TAILWIND_DEBUG'] = 'true';
        $_POST['debug'] = '1';
        $_COOKIE['debug'] = '1';
        $_REQUEST['debug'] = '1';

        \putenv('TAILWINDCSS_PHP_DEBUG=1');
    }
}
