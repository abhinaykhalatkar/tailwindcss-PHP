<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Http\Responder;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\RouteMatcher;
use TailwindPhp\Pipeline\BuildResult;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\RecordingFilesystem;
use TailwindPhp\Tests\Support\StaticBuildResolver;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The request path end to end, minus the pipeline (TASK-0016 acceptance 6;
 * design doc 04 §5, doc 10; threats T-02, T-06).
 *
 * `StaticBuildResolver` stands in for the pipeline so every branch here is
 * exercised as a property of the {@see BuildResult} it is handed, rather than
 * by arranging a compiler and a cache that between them happen to produce one.
 * The cache directory is real, because what this class does with a path is the
 * thing worth testing.
 *
 * Two orderings are asserted directly rather than implied, because both are
 * security properties:
 *
 * - a route that is not ours costs **zero** filesystem calls;
 * - a method we do not answer is refused **before** `resolve()` is called, so
 *   an unauthenticated `DELETE` cannot spend a site's CPU on a compile.
 *
 * @covers-requirement FR-HTTP-3
 * @covers-requirement FR-HTTP-4
 * @covers-requirement FR-HTTP-5
 */
final class ResponderTest extends TestCase
{
    private const ROUTE = 'assets/styles.css';
    private const CURRENT = 'a1b2c3d4e5f6';
    private const RETAINED = '0123abcd';
    private const CSS = "/* built */\n.flex{display:flex}\n";

    /**
     * @var string|null
     */
    private $root;

    /**
     * @var RecordingFilesystem|null
     */
    private $fs;

    /**
     * @var StaticBuildResolver|null
     */
    private $resolver;

    protected function setUp(): void
    {
        $this->root = TempTree::make('tailwindphp-responder');
        \mkdir($this->cacheDir(), 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }

        $this->fs = null;
        $this->resolver = null;
    }

    /**
     * @test
     */
    public function aRouteThatIsNotOursCostsNothing(): void
    {
        $responder = $this->responder();

        self::assertNull($responder->handle('favicon.ico', []));
        self::assertNull($responder->handle('assets/styles.css/../../etc/passwd', []));
        self::assertSame([], self::fs($this->fs)->calls(), 'a foreign route must not touch the filesystem');
        self::assertSame([], self::resolver($this->resolver)->calls(), 'a foreign route must not reach the pipeline');
    }

    /**
     * @test
     */
    public function thePlainRouteServesTheCurrentArtefact(): void
    {
        $response = $this->responder()->handle('/assets/styles.css', []);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertSame('"' . self::CURRENT . '"', $response->headerValue('ETag'));
        self::assertSame($this->cssPath(self::CURRENT), $response->bodyPath());
        self::assertSame((string) \strlen(self::CSS), $response->headerValue('Content-Length'));
    }

    /**
     * @test
     */
    public function theFingerprintedRouteServesTheSameBytesImmutably(): void
    {
        $response = $this->responder()->handle('assets/styles.' . self::CURRENT . '.css', []);

        self::assertNotNull($response);
        self::assertSame('public, max-age=31536000, immutable', $response->headerValue('Cache-Control'));
        self::assertSame($this->cssPath(self::CURRENT), $response->bodyPath());
    }

    /**
     * Doc 10 §Stale fingerprints step 1: a browser that loaded the page moments
     * before a deploy asked for exactly the right file.
     *
     * @test
     */
    public function aRetainedVersionIsStillServed(): void
    {
        $this->write($this->cssPath(self::RETAINED), '/* old */');

        $response = $this->responder()->handle('assets/styles.' . self::RETAINED . '.css', []);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame($this->cssPath(self::RETAINED), $response->bodyPath());
        // Its own validator, not the current one — the two files differ.
        self::assertSame('"' . self::RETAINED . '"', $response->headerValue('ETag'));
        self::assertSame('public, max-age=31536000, immutable', $response->headerValue('Cache-Control'));
        self::assertSame('9', $response->headerValue('Content-Length'));
    }

    /**
     * Step 2. Serving the *current* stylesheet under the *old* URL would write
     * today's bytes into a cache entry marked immutable for a year under
     * yesterday's name, which is why this is a redirect and not a 200.
     *
     * @test
     */
    public function anUnknownVersionRedirectsToTheCurrentOne(): void
    {
        $response = $this->responder()->handle('assets/styles.beefbeefbeef.css', []);

        self::assertNotNull($response);
        self::assertSame(302, $response->status());
        // Relative (HBC-A4), so a sub-directory deployment redirects within it.
        self::assertSame('styles.' . self::CURRENT . '.css', $response->headerValue('Location'));
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertNull($response->bodyPath());
    }

    /**
     * The pipeline named an artefact that is no longer on disk — a prune or a
     * deploy landing between the build and the response.
     *
     * Serving it anyway is the worst of the options: `Content-Length: 0` with
     * an unreadable body, which on the fingerprinted route caches an **empty
     * stylesheet for a year** under an immutable URL. On the plain route the
     * honest answer is to point at the current version.
     *
     * @test
     */
    public function aVanishedArtefactOnThePlainRouteRedirects(): void
    {
        $responder = $this->responder();
        \unlink($this->cssPath(self::CURRENT));

        $response = $responder->handle(self::ROUTE, []);

        self::assertNotNull($response);
        self::assertSame(302, $response->status());
        self::assertSame('styles.' . self::CURRENT . '.css', $response->headerValue('Location'));
        self::assertNull($response->bodyPath());
    }

    /**
     * On the *current* fingerprinted route a redirect would point at the URL we
     * are already on, so the only non-looping answer is the fail-soft body —
     * and it must not be stored.
     *
     * @test
     */
    public function aVanishedArtefactOnItsOwnFingerprintedRouteFailsSoft(): void
    {
        $responder = $this->responder();
        \unlink($this->cssPath(self::CURRENT));

        $response = $responder->handle('assets/styles.' . self::CURRENT . '.css', []);

        self::assertNotNull($response);
        self::assertSame(500, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertSame('/* tailwindcss-php: build failed */', $response->body());
        self::assertNull($response->bodyPath());
        self::assertNull($response->headerValue('ETag'));
    }

    /**
     * Threat T-06, the one leak the verifier found: `readfile()` on a missing
     * file emits a PHP warning, and with `display_errors` on that warning is
     * written *into the stylesheet* carrying two absolute paths. `emit()` stats
     * first, so the call never happens.
     *
     * @test
     */
    public function emitNeverWritesAWarningForAMissingBody(): void
    {
        $sent = [];
        $responder = $this->responder(['headerSink' => static function (string $line) use (&$sent): void {
            $sent[] = $line;
        }]);

        $response = $responder->handle(self::ROUTE, []);
        self::assertNotNull($response);
        self::assertNotNull($response->bodyPath());

        // Delete it *after* the response was built, so `emit()` is the only
        // thing standing between a missing file and the client.
        \unlink($this->cssPath(self::CURRENT));

        $display = \ini_get('display_errors');
        \ini_set('display_errors', '1');

        try {
            \ob_start();
            \ob_start();
            $responder->emit($response);
            $body = (string) \ob_get_clean();
        } finally {
            \ini_set('display_errors', $display === false ? '0' : $display);
        }

        self::assertSame('', $body, 'emit() wrote something for a body that is not there');
        self::assertStringNotContainsString((string) $this->root, $body);
    }

    /**
     * Threat T-02's last line of defence. A `BuildResult` naming a path outside
     * the cache directory is a programming error in the pipeline, not a
     * request, so it is refused loudly rather than failed soft — a silent 500
     * here would hide the bug that produced it.
     *
     * @test
     */
    public function anArtefactPathOutsideTheCacheDirectoryIsRefused(): void
    {
        $outside = $this->root . '/outside.css';
        $this->write($outside, '.leaked{}');

        $responder = $this->responder([
            'result' => BuildResult::file(
                $outside,
                null,
                \str_repeat('ab', 32),
                self::CURRENT,
                1700000000,
                'cached',
                new DiagnosticBag()
            ),
        ]);

        $this->expectException(PathException::class);

        $responder->handle(self::ROUTE, []);
    }

    /**
     * CCP-A28 at the redirect end: the fingerprint reaching `Location` is the
     * manifest's, so a bad one is a bug — but it is still the value that would
     * become a header, and `styles.../../etc/passwd.css` carries no control
     * byte for the header validator to catch.
     *
     * @test
     */
    public function aBuildResultWithABogusFingerprintNeverBecomesALocation(): void
    {
        $responder = $this->responder([
            'result' => BuildResult::file(
                $this->cssPath(self::CURRENT),
                null,
                \str_repeat('ab', 32),
                '../../etc/passwd',
                1700000000,
                'cached',
                new DiagnosticBag()
            ),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        // A request on a *different* fingerprint, so the redirect branch runs.
        $responder->handle('assets/styles.beefbeefbeef.css', []);
    }

    /**
     * @dataProvider refusedMethods
     *
     * @test
     */
    public function anUnsupportedMethodIsRefusedBeforeAnyWork(string $method): void
    {
        $response = $this->responder()->handle(self::ROUTE, ['REQUEST_METHOD' => $method]);

        self::assertNotNull($response);
        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->headerValue('Allow'));
        self::assertSame([], self::resolver($this->resolver)->calls(), '405 must precede resolve()');
        self::assertSame([], self::fs($this->fs)->calls(), '405 must precede any I/O');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
            'PATCH' => ['PATCH'],
            'TRACE' => ['TRACE'],
            'lower case' => ['delete'],
            'nonsense' => ['../../etc/passwd'],
        ];
    }

    /**
     * Default: the integrator's CORS layer owns `OPTIONS`, so we return null
     * and their routing continues (HBC-A6).
     *
     * @test
     */
    public function optionsIsLeftAloneByDefault(): void
    {
        self::assertNull($this->responder()->handle(self::ROUTE, ['REQUEST_METHOD' => 'OPTIONS']));
        self::assertSame([], self::resolver($this->resolver)->calls());
    }

    /**
     * @test
     */
    public function optionsIsAnsweredWhenConfigured(): void
    {
        $response = $this->responder(['handleOptions' => true])
            ->handle(self::ROUTE, ['REQUEST_METHOD' => 'OPTIONS']);

        self::assertNotNull($response);
        self::assertSame(204, $response->status());
        self::assertSame('GET, HEAD, OPTIONS', $response->headerValue('Allow'));
        self::assertSame([], self::resolver($this->resolver)->calls(), 'OPTIONS must not trigger a build');
    }

    /**
     * @test
     */
    public function headSendsTheHeadersOfTheGetAndNoBody(): void
    {
        $get = $this->responder()->handle(self::ROUTE, []);
        $head = $this->responder()->handle(self::ROUTE, ['REQUEST_METHOD' => 'HEAD']);

        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertSame($get->headers(), $head->headers());
        self::assertNull($head->bodyPath());
        self::assertNull($head->body());
    }

    /**
     * @test
     */
    public function theCompressedTwinIsServedWhenItExistsAndIsAccepted(): void
    {
        $this->write($this->cssPath(self::CURRENT) . '.gz', 'gzipped-bytes');

        $response = $this->responder()->handle(self::ROUTE, ['HTTP_ACCEPT_ENCODING' => 'gzip']);

        self::assertNotNull($response);
        self::assertSame('gzip', $response->headerValue('Content-Encoding'));
        self::assertSame($this->cssPath(self::CURRENT) . '.gz', $response->bodyPath());
        self::assertSame('13', $response->headerValue('Content-Length'));
    }

    /**
     * @test
     */
    public function noTwinMeansNoCompression(): void
    {
        $response = $this->responder()->handle(self::ROUTE, ['HTTP_ACCEPT_ENCODING' => 'gzip']);

        self::assertNotNull($response);
        self::assertNull($response->headerValue('Content-Encoding'));
        self::assertSame($this->cssPath(self::CURRENT), $response->bodyPath());
    }

    /**
     * With gzip switched off in config the twin is not even looked for, so a
     * `.gz` left behind by an earlier configuration cannot be served.
     *
     * @test
     */
    public function gzipDisabledMeansTheTwinIsNeverConsulted(): void
    {
        $this->write($this->cssPath(self::CURRENT) . '.gz', 'gzipped-bytes');

        $response = $this->responder(['gzip' => false])->handle(self::ROUTE, ['HTTP_ACCEPT_ENCODING' => 'gzip']);

        self::assertNotNull($response);
        self::assertNull($response->headerValue('Content-Encoding'));
        // One stat, for the stylesheet. A second would mean the twin was
        // looked for, and a file that is never looked for cannot be served by
        // mistake.
        self::assertSame(1, self::fs($this->fs)->stats(), 'the twin was stat-ed despite gzip being off');
    }

    /**
     * The fail-soft shapes of doc 15 pass through with their own status and are
     * never cached.
     *
     * @dataProvider inlineResults
     *
     * @test
     */
    public function anInlineResultIsServedAsItIs(int $status, string $body): void
    {
        $responder = $this->responder([
            'result' => BuildResult::inline($status, $body, 'error', new \RuntimeException('x'), new DiagnosticBag()),
        ]);

        $response = $responder->handle(self::ROUTE, []);

        self::assertNotNull($response);
        self::assertSame($status, $response->status());
        self::assertSame($body, $response->body());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertNull($response->headerValue('ETag'));
        self::assertNull($response->bodyPath());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function inlineResults(): array
    {
        return [
            'the debug overlay' => [200, '/* overlay */html::before{content:"x"}'],
            'the production failure body' => [500, '/* tailwindcss-php: build failed */'],
        ];
    }

    /**
     * An inline result is served whichever route asked for it — a stale
     * fingerprint in the URL must not turn a failed build into a redirect loop.
     *
     * @test
     */
    public function anInlineResultIsServedOnTheFingerprintedRouteToo(): void
    {
        $responder = $this->responder([
            'result' => BuildResult::inline(500, 'x', 'error', null, new DiagnosticBag()),
        ]);

        $response = $responder->handle('assets/styles.beefbeefbeef.css', []);

        self::assertNotNull($response);
        self::assertSame(500, $response->status());
    }

    /**
     * Threat T-02. Every one of these is a route-shaped string built to reach a
     * file; none of them may cause a single filesystem call, because none of
     * them is a route.
     *
     * @dataProvider hostileRoutes
     *
     * @test
     */
    public function hostileRoutesNeverReachTheFilesystem(string $route): void
    {
        $responder = $this->responder();

        self::assertNull($responder->handle($route, []));
        self::assertSame([], self::fs($this->fs)->calls(), 'touched the filesystem for: ' . \rawurlencode($route));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileRoutes(): array
    {
        return [
            'parent segments' => ['assets/styles.../../../../etc/passwd.css'],
            'encoded parent segments' => ['assets/styles.%2e%2e%2f%2e%2e%2fpasswd.css'],
            'an absolute path as the fingerprint' => ['assets/styles./etc/passwd.css'],
            'a Windows path' => ['assets/styles.C:\\Windows\\win.ini.css'],
            'a NUL terminator' => ["assets/styles.a1b2c3d4e5f6.css\0.png"],
            'a stream wrapper' => ['assets/styles.php://filter.css'],
            'a UNC path' => ['assets/styles.//server/share.css'],
            'an over-long fingerprint' => ['assets/styles.' . \str_repeat('a', 4096) . '.css'],
            'the manifest itself' => ['assets/app.meta.json'],
            'the lock file' => ['assets/app.lock'],
        ];
    }

    /**
     * `emit()` is the only writer in `src/` outside `Console/`. The header sink
     * is injected because `headers_list()` does not exist in the CLI SAPI; the
     * body is captured with an extra buffer level, since `emit()` discards
     * exactly one (doc 10 §Streaming).
     *
     * @test
     */
    public function emitStreamsTheFileAndSendsTheHeaders(): void
    {
        $sent = [];
        $responder = $this->responder(['headerSink' => static function (string $line) use (&$sent): void {
            $sent[] = $line;
        }]);

        $response = $responder->handle(self::ROUTE, []);
        self::assertNotNull($response);

        \ob_start();
        \ob_start();
        $responder->emit($response);
        $body = (string) \ob_get_clean();

        self::assertSame(self::CSS, $body);
        self::assertContains('Content-Type: text/css; charset=utf-8', $sent);
        self::assertContains('ETag: "' . self::CURRENT . '"', $sent);
        self::assertContains('Content-Length: ' . \strlen(self::CSS), $sent);
        self::assertSame(200, \http_response_code());
    }

    /**
     * @test
     */
    public function emitWritesAnInlineBody(): void
    {
        $sent = [];
        $responder = $this->responder([
            'result' => BuildResult::inline(500, '/* failed */', 'error', null, new DiagnosticBag()),
            'headerSink' => static function (string $line) use (&$sent): void {
                $sent[] = $line;
            },
        ]);

        $response = $responder->handle(self::ROUTE, []);
        self::assertNotNull($response);

        \ob_start();
        \ob_start();
        $responder->emit($response);
        $body = (string) \ob_get_clean();

        self::assertSame('/* failed */', $body);
        self::assertContains('Cache-Control: no-store', $sent);
        self::assertSame(500, \http_response_code());
    }

    /**
     * A body-less response writes nothing at all — a 304 with a body would be
     * a protocol violation, not a cosmetic issue.
     *
     * @test
     */
    public function emitWritesNoBodyForA304(): void
    {
        $sent = [];
        $responder = $this->responder(['headerSink' => static function (string $line) use (&$sent): void {
            $sent[] = $line;
        }]);

        $response = $responder->handle(self::ROUTE, ['HTTP_IF_NONE_MATCH' => '"' . self::CURRENT . '"']);
        self::assertNotNull($response);
        self::assertSame(304, $response->status());

        \ob_start();
        \ob_start();
        $responder->emit($response);
        $body = (string) \ob_get_clean();

        self::assertSame('', $body);
        self::assertSame(304, \http_response_code());
    }

    /**
     * Build a responder over the temp cache directory.
     *
     * @param array<string, mixed> $options
     */
    private function responder(array $options = []): Responder
    {
        $root = (string) $this->root;
        $gzip = isset($options['gzip']) ? (bool) $options['gzip'] : true;
        $fs = new RecordingFilesystem(new Filesystem());
        $this->fs = $fs;

        $store = new CacheStore(
            $this->cacheDir(),
            [$root],
            $root,
            0755,
            0644,
            $gzip,
            $fs,
            new AtomicWriter($root)
        );

        $result = $options['result'] ?? $this->currentResult();
        $this->resolver = new StaticBuildResolver(['app' => $result]);

        $responder = new Responder(
            new RouteMatcher(['app' => self::ROUTE]),
            $this->resolver,
            $store,
            new ResponseBuilder([], 31536000, false),
            $fs,
            isset($options['handleOptions']) ? (bool) $options['handleOptions'] : false,
            $options['headerSink'] ?? null
        );

        // The artefact the pipeline claims to have built, written after the
        // recorder exists so setup does not show up in the call counts.
        $this->write($this->cssPath(self::CURRENT), self::CSS);
        $fs->reset();

        return $responder;
    }

    private function currentResult(): BuildResult
    {
        return BuildResult::file(
            $this->cssPath(self::CURRENT),
            null,
            \str_repeat('ab', 32),
            self::CURRENT,
            1700000000,
            'cached',
            new DiagnosticBag()
        );
    }

    private function cacheDir(): string
    {
        return $this->root . '/cache';
    }

    private function cssPath(string $fingerprint): string
    {
        return $this->cacheDir() . '/app.' . $fingerprint . '.css';
    }

    private function write(string $path, string $contents): void
    {
        \file_put_contents($path, $contents);
    }

    private static function fs(?RecordingFilesystem $fs): RecordingFilesystem
    {
        self::assertNotNull($fs);

        return $fs;
    }

    private static function resolver(?StaticBuildResolver $resolver): StaticBuildResolver
    {
        self::assertNotNull($resolver);

        return $resolver;
    }
}
