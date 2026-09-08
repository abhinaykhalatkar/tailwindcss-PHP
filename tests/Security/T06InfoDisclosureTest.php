<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Http\Responder;
use TailwindPhp\Http\Response;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\RouteMatcher;
use TailwindPhp\Pipeline\BuildResult;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\StaticBuildResolver;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Version;

/**
 * Threat T-06 — the HTTP layer must not tell an anonymous visitor anything
 * about the server.
 *
 * Two kinds of leak are in scope here, and they leak differently:
 *
 * - **Paths.** Every response this library sends is *about* a file. The
 *   filename, its directory, the project root and the cache directory are all
 *   in scope at the moment the response is built, and exactly none of them may
 *   appear in a header or a body. A single absolute path tells an attacker the
 *   deployment layout, the username, and often the framework.
 * - **Versions.** `Server`-style headers and a package version turn a general
 *   scan into a targeted one. This library never sends either, and the
 *   production failure body is a fixed comment for the same reason.
 *
 * The rows below cover every response shape that exists **without** a
 * successful build, since those are the ones with something to say: 302, 405,
 * the 500 and the debug overlay. The successful 200 is included as a control.
 *
 * @covers-requirement NFR-SEC-5
 *
 * @covers-requirement FR-ERR-3
 */
final class T06InfoDisclosureTest extends TestCase
{
    private const ROUTE = 'assets/styles.css';
    private const FINGERPRINT = 'a1b2c3d4e5f6';

    /**
     * Header names this library must never send. `Server` and `X-Powered-By`
     * name the software; `Content-Security-Policy` is the page's business and
     * a stylesheet route silently setting one would break pages it knows
     * nothing about.
     *
     * @var list<string>
     */
    private const FORBIDDEN = ['Server', 'X-Powered-By', 'X-AspNet-Version', 'Content-Security-Policy'];

    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        // A root whose name is distinctive enough that finding it in a response
        // cannot be a coincidence.
        $this->root = TempTree::make('tailwindphp-t06-secretdir');

        \mkdir($this->root . '/cache', 0777, true);
        \file_put_contents($this->root . '/cache/app.' . self::FINGERPRINT . '.css', '.flex{display:flex}');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            TempTree::remove($this->root);
            $this->root = '';
        }
    }

    /**
     * @dataProvider responseShapes
     *
     * @test
     */
    public function noHeaderCarriesAPathOrAVersion(string $shape): void
    {
        $response = $this->produce($shape);

        foreach ($response->headers() as $name => $value) {
            self::assertStringNotContainsString($this->root, $value, $name . ' leaked the project root');
            // The temp directory's own name, which is distinctive enough that
            // finding it in a header cannot be a coincidence. Matching on the
            // bare word "cache" would not work: `Cache-Control: no-cache` is a
            // header this library is required to send.
            self::assertStringNotContainsString(
                \basename($this->root),
                $value,
                $name . ' leaked a directory name'
            );
            self::assertStringNotContainsString(Version::VERSION, $value, $name . ' leaked the package version');
            self::assertStringNotContainsString('tailwindcss-php', $value, $name . ' named the package');

            // Whatever the value is, it must not look like an absolute path in
            // either syntax.
            self::assertDoesNotMatchRegularExpression(
                '#(^|[ =;,])(/|[A-Za-z]:[\\\\/])#',
                $value,
                $name . ' looks like an absolute path: ' . $value
            );
        }
    }

    /**
     * @dataProvider responseShapes
     *
     * @test
     */
    public function noResponseAdvertisesTheSoftware(string $shape): void
    {
        $headers = $this->produce($shape)->headers();

        foreach (self::FORBIDDEN as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $headers, $shape . ' sent ' . $forbidden);
        }
    }

    /**
     * Only two shapes have an inline body — the failure comment and the debug
     * overlay. The others are the file or nothing at all, which is why they are
     * not in this provider rather than being skipped inside the test.
     *
     * @dataProvider responsesWithABody
     *
     * @test
     */
    public function noBodyCarriesAnAbsolutePath(string $shape): void
    {
        $body = (string) $this->produce($shape)->body();

        self::assertNotSame('', $body, 'expected an inline body for ' . $shape);
        self::assertStringNotContainsString($this->root, $body);
        self::assertStringNotContainsString(\DIRECTORY_SEPARATOR . 'cache', $body);
        self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $body, 'a Windows path in the body');
    }

    /**
     * Data-set names are prefixed rather than bare status codes: PHP casts a
     * numeric string key to an int, which would silently make this an
     * `array<int, …>`.
     *
     * @return array<string, array{string}>
     */
    public static function responseShapes(): array
    {
        return [
            'a 200' => ['ok'],
            'a 304' => ['notModified'],
            'a 302' => ['redirect'],
            'a 405' => ['methodNotAllowed'],
        ] + self::responsesWithABody();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function responsesWithABody(): array
    {
        return [
            'a 500' => ['failure'],
            'the debug overlay' => ['overlay'],
        ];
    }

    /**
     * FR-ERR-2: the production body is one fixed comment, byte for byte. Not
     * "a body containing no path" — a *constant*, so there is no input that
     * can change it.
     *
     * @test
     */
    public function theProductionFailureBodyIsConstant(): void
    {
        $first = $this->produce('failure')->body();

        // A second failure whose exception is stuffed with everything an
        // attacker would like to see.
        $second = $this->responder(BuildResult::inline(
            500,
            ErrorRenderer::productionBody(),
            'error',
            new \RuntimeException($this->root . '/config/database.php line 42: password=hunter2'),
            new DiagnosticBag()
        ))->handle(self::ROUTE, ['REQUEST_METHOD' => 'GET']);

        self::assertNotNull($second);
        self::assertSame('/* tailwindcss-php: build failed */', $first);
        self::assertSame($first, $second->body());
    }

    /**
     * The overlay is the one body that deliberately says something, so it is
     * the one worth pinning: it is served **only** in debug, and even then the
     * message has been through `LogSafety` and carries no path.
     *
     * @test
     */
    public function theDebugOverlayIsScrubbed(): void
    {
        $overlay = ErrorRenderer::debugOverlayCss(
            new \RuntimeException('could not read ' . $this->root . '/assets/styles/main.css'),
            'app',
            'php'
        );

        $responder = $this->responder(BuildResult::inline(200, $overlay, 'error', null, new DiagnosticBag()));
        $response = $responder->handle(self::ROUTE, ['REQUEST_METHOD' => 'GET']);

        self::assertNotNull($response);

        $body = (string) $response->body();

        self::assertStringNotContainsString($this->root, $body);
        self::assertStringContainsString('tailwindcss-php', $body, 'the overlay should still be recognisable');
    }

    /**
     * A 302's `Location` is built from the configured route and a validated
     * fingerprint — never from the request and never from a filesystem path.
     *
     * @test
     */
    public function theRedirectTargetIsRelativeAndPathFree(): void
    {
        $location = (string) $this->produce('redirect')->headerValue('Location');

        self::assertSame('styles.' . self::FINGERPRINT . '.css', $location);
        self::assertStringNotContainsString('/', $location);
    }

    private function produce(string $shape): Response
    {
        switch ($shape) {
            case 'notModified':
                $response = $this->responder()->handle(self::ROUTE, [
                    'REQUEST_METHOD' => 'GET',
                    'HTTP_IF_NONE_MATCH' => '"' . self::FINGERPRINT . '"',
                ]);

                break;
            case 'redirect':
                $response = $this->responder()->handle('assets/styles.deadbeefdead.css', [
                    'REQUEST_METHOD' => 'GET',
                ]);

                break;
            case 'methodNotAllowed':
                $response = $this->responder()->handle(self::ROUTE, ['REQUEST_METHOD' => 'DELETE']);

                break;
            case 'failure':
                $response = $this->responder(BuildResult::inline(
                    500,
                    ErrorRenderer::productionBody(),
                    'error',
                    new \RuntimeException('read of ' . $this->root . '/x.css failed'),
                    new DiagnosticBag()
                ))->handle(self::ROUTE, ['REQUEST_METHOD' => 'GET']);

                break;
            case 'overlay':
                $overlay = ErrorRenderer::debugOverlayCss(
                    new \RuntimeException('boom in ' . $this->root . '/main.css'),
                    'app',
                    'php'
                );
                $inline = BuildResult::inline(200, $overlay, 'error', null, new DiagnosticBag());
                $response = $this->responder($inline)->handle(self::ROUTE, ['REQUEST_METHOD' => 'GET']);

                break;
            default:
                $response = $this->responder()->handle(self::ROUTE, ['REQUEST_METHOD' => 'GET']);
        }

        self::assertNotNull($response, 'no response for shape ' . $shape);

        return $response;
    }

    private function responder(?BuildResult $result = null): Responder
    {
        $fs = new Filesystem();

        $store = new CacheStore(
            $this->root . '/cache',
            [$this->root],
            $this->root,
            0755,
            0644,
            false,
            $fs,
            new AtomicWriter($this->root)
        );

        if ($result === null) {
            $result = BuildResult::file(
                $this->root . '/cache/app.' . self::FINGERPRINT . '.css',
                null,
                \str_repeat('ab', 32),
                self::FINGERPRINT,
                1700000000,
                'cached',
                new DiagnosticBag()
            );
        }

        return new Responder(
            new RouteMatcher(['app' => self::ROUTE]),
            new StaticBuildResolver(['app' => $result]),
            $store,
            new ResponseBuilder([], 31536000, false),
            $fs
        );
    }
}
