<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Config\ConfigBuilder;
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
