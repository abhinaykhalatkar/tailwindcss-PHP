<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Http\Responder;
use TailwindPhp\Http\ResponseBuilder;
use TailwindPhp\Http\RouteMatcher;
use TailwindPhp\Pipeline\BuildResult;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\RecordingFilesystem;
use TailwindPhp\Tests\Support\StaticBuildResolver;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Threat T-02 — a request must never be able to name a file.
 *
 * This library is mounted at a URL and hands back a file from a cache
 * directory, which is the shape of every path-traversal bug ever written. The
 * control is not a filter but an **absence**: no byte of the request is ever
 * concatenated into a path. The route produces at most two tokens — a bundle
 * *name* that must already be in the configured set, and a fingerprint that
 * must match `^[a-f0-9]{8,16}$` — and the filename is then built from those two
 * tokens by `CacheStore` and confined to the cache directory before it is
 * opened.
 *
 * So the assertions here are mostly about **what did not happen**: for a
 * hostile route, no stat, no read, no compile. A traversal attempt that is
 * merely refused after being resolved is a bug waiting for the next refactor;
 * one that never reaches the filesystem cannot be.
 *
 * The `$_SERVER`-based half of this threat (a header naming a path) lives in
 * {@see T11RequestParsingTest}; the confinement primitive itself is covered by
 * {@see T01PathTraversalTest}.
 *
 * @covers-requirement NFR-SEC-9
 */
final class T02RouteTraversalTest extends TestCase
{
    private const ROUTE = 'assets/styles.css';
    private const FINGERPRINT = 'a1b2c3d4e5f6';

    /**
     * @var string
     */
    private $root = '';

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
        $this->root = TempTree::make('tailwindphp-t02');

        \mkdir($this->root . '/cache', 0777, true);
        \file_put_contents($this->root . '/cache/app.' . self::FINGERPRINT . '.css', '.flex{display:flex}');

        // The prize: a file outside the cache directory, in the project root,
        // with a name a crafted route might try to reach.
        \file_put_contents($this->root . '/secret.txt', 'TOP SECRET');
        \file_put_contents($this->root . '/cache/app.meta.json', '{"schema":1}');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            TempTree::remove($this->root);
            $this->root = '';
        }

        $this->fs = null;
        $this->resolver = null;
    }

    /**
     * Every one of these is a route-shaped string built to escape the cache
     * directory, to name a file we do not serve, or to smuggle a byte past the
     * fingerprint pattern. None may produce a response, and none may cost a
     * single filesystem call.
     *
     * @dataProvider hostileRoutes
     *
     * @test
     */
    public function noHostileRouteIsRecognisedOrCostsAnyIo(string $route): void
    {
        $responder = $this->responder();

        $response = $responder->handle($route, ['REQUEST_METHOD' => 'GET']);

        self::assertNull($response, 'recognised as a route: ' . \rawurlencode($route));
        self::assertSame(
            [],
            self::fs($this->fs)->calls(),
            'touched the filesystem for: ' . \rawurlencode($route)
        );
        self::assertSame(
            [],
            self::resolver($this->resolver)->calls(),
            'reached the pipeline for: ' . \rawurlencode($route)
        );
    }

    /**
     * Forty-plus rows, grouped by the trick each one is trying.
     *
     * @return array<string, array{string}>
     */
    public static function hostileRoutes(): array
    {
        $rows = [
            // Classic traversal, in the directory part and in the fingerprint
            // slot.
            'dot-dot in the directory' => ['../assets/styles.css'],
            'dot-dot inside the path' => ['assets/../assets/styles.css'],
            'dot-dot as the fingerprint' => ['assets/styles.../../secret.txt.css'],
            'deep traversal' => ['assets/styles.../../../../../../etc/passwd.css'],
            'trailing traversal' => ['assets/styles.css/../secret.txt'],
            'backslash traversal' => ['assets\\..\\secret.txt'],
            'dot segment' => ['./assets/styles.css'],
            'dot segment inside' => ['assets/./styles.css'],

            // Percent-encoding, single and double. Decoding happens exactly
            // once, so neither form can become a separator that matches.
            'encoded dots' => ['assets/styles.%2e%2e.css'],
            'encoded traversal' => ['%2e%2e/assets/styles.css'],
            'encoded slash in the fingerprint' => ['assets/styles.%2fsecret.css'],
            'encoded backslash' => ['assets/styles.%5csecret.css'],
            'double-encoded dots' => ['assets/styles.%252e%252e.css'],
            'double-encoded slash' => ['assets/styles.%252fsecret.css'],
            // The canonical double-decode escape: decoded twice this spells a
            // valid fingerprint and would be accepted, then turned into a
            // filename. Decoded once — which is what happens — it is not a
            // route at all.
            'a double-encoded fingerprint' => [
                'assets/styles.%2561%2562%2563%2564%2565%2566%2530%2531.css',
            ],
            'overlong utf-8 slash' => ['assets/styles.%c0%af.css'],
            'encoded NUL in the fingerprint' => ['assets/styles.a1b2c3d4%00.css'],

            // Absolute and network paths.
            'absolute unix path' => ['/etc/passwd'],
            'absolute path as fingerprint' => ['assets/styles./etc/passwd.css'],
            'windows drive' => ['C:/Windows/win.ini'],
            'windows drive as fingerprint' => ['assets/styles.C:\\Windows\\win.ini.css'],
            'unc path' => ['//server/share/x.css'],
            'unc as fingerprint' => ['assets/styles.//server/share.css'],

            // Stream wrappers: PHP resolves these in file functions, so a route
            // that carried one would be a read primitive rather than a path bug.
            'php filter wrapper' => ['assets/styles.php://filter/resource=secret.txt.css'],
            'data wrapper' => ['assets/styles.data://text/plain,x.css'],
            'file wrapper' => ['file:///etc/passwd'],
            'http wrapper' => ['http://example.test/assets/styles.css'],
            'phar wrapper' => ['assets/styles.phar://x.phar/y.css'],

            // Cache-directory internals: real filenames, none of them routes.
            'the manifest' => ['assets/app.meta.json'],
            'the manifest via the route base' => ['assets/styles.meta.json'],
            'the lock file' => ['assets/app.lock'],
            'the check stamp' => ['assets/app.checked'],
            'a temp file' => ['assets/app.tmp.1234.abcdef12'],
            'the gzip twin directly' => ['assets/styles.css.gz'],
            'a fingerprinted twin directly' => ['assets/styles.a1b2c3d4e5f6.css.gz'],

            // Fingerprint-pattern edge cases.
            'uppercase fingerprint' => ['assets/styles.A1B2C3D4E5F6.css'],
            'seven hex characters' => ['assets/styles.a1b2c3d.css'],
            'seventeen hex characters' => ['assets/styles.a1b2c3d4e5f6a1b2c.css'],
            'non-hex fingerprint' => ['assets/styles.zzzzzzzzzzzz.css'],
            'empty fingerprint' => ['assets/styles..css'],
            'fingerprint with a space' => ['assets/styles.a1b2 c3d4.css'],
            'two fingerprints' => ['assets/styles.a1b2c3d4.a1b2c3d4.css'],

            // Control bytes, which is where header splitting and C-string
            // truncation would come from.
            'raw NUL' => ["assets/styles.a1b2c3d4e5f6.css\0"],
            'NUL inside the fingerprint' => ["assets/styles.a1b2\0c3d4.css"],
            'CR LF' => ["assets/styles.a1b2c3d4e5f6.css\r\nX-Injected: 1"],
            'raw newline' => ["assets/styles.a1b2c3d4e5f6\n.css"],

            // Case and shape.
            'capitalised directory' => ['Assets/styles.css'],
            'capitalised extension' => ['assets/styles.Css'],
            'trailing slash' => ['assets/styles.css/'],
            'trailing dot' => ['assets/styles.css.'],
            'doubled slash' => ['//assets/styles.css'],
            'prefixed route' => ['x/assets/styles.css'],
        ];

        // Length: a route that is nothing but padding, and one that hides a
        // real route behind it.
        $rows['a very long path'] = [\str_repeat('a/', 5000) . 'styles.css'];
        $rows['a very long fingerprint'] = ['assets/styles.' . \str_repeat('a1', 5000) . '.css'];

        return $rows;
    }

    /**
     * The positive control. Without it, every row above would also pass on a
     * matcher that returns null for everything, which would prove nothing.
     *
     * @test
     */
    public function theRealRouteStillWorks(): void
    {
        $response = $this->responder()->handle(self::ROUTE, ['REQUEST_METHOD' => 'GET']);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame(
            $this->root . '/cache/app.' . self::FINGERPRINT . '.css',
            $response->bodyPath()
        );
    }

    /**
     * Whatever path a response does name, it is inside the cache directory —
     * asserted over the hostile rows that *do* reach a response, which is the
     * fingerprinted-route family.
     *
     * @test
     */
    public function noResponseEverNamesAPathOutsideTheCacheDirectory(): void
    {
        $responder = $this->responder();
        $cacheDir = \realpath($this->root . '/cache');

        self::assertIsString($cacheDir);

        $routes = [
            self::ROUTE,
            'assets/styles.' . self::FINGERPRINT . '.css',
            // A syntactically valid fingerprint we do not hold: this one does
            // reach `CacheStore::has()`, so it is the row where a filename is
            // genuinely built from request-derived bytes.
            'assets/styles.deadbeefdead.css',
            'assets/styles.00000000.css',
            'assets/styles.ffffffffffffffff.css',
        ];

        foreach ($routes as $route) {
            $response = $responder->handle($route, ['REQUEST_METHOD' => 'GET']);

            self::assertNotNull($response, $route);

            $path = $response->bodyPath();

            if ($path === null) {
                continue;
            }

            $real = \realpath($path);

            self::assertIsString($real, $route);
            self::assertStringStartsWith(
                $cacheDir . \DIRECTORY_SEPARATOR,
                $real,
                'served a path outside the cache directory for: ' . $route
            );
        }
    }

    /**
     * The one row that does build a filename from request bytes must still not
     * be able to reach the file next door.
     *
     * @test
     */
    public function anUnknownFingerprintRedirectsRatherThanProbing(): void
    {
        $response = $this->responder()->handle('assets/styles.deadbeefdead.css', ['REQUEST_METHOD' => 'GET']);

        self::assertNotNull($response);
        self::assertSame(302, $response->status());
        self::assertSame('styles.' . self::FINGERPRINT . '.css', $response->headerValue('Location'));
        self::assertNull($response->bodyPath());
    }

    private function responder(): Responder
    {
        $this->fs = new RecordingFilesystem(new Filesystem());

        $store = new CacheStore(
            $this->root . '/cache',
            [$this->root],
            $this->root,
            0755,
            0644,
            false,
            $this->fs,
            new AtomicWriter($this->root)
        );

        $this->resolver = new StaticBuildResolver([
            'app' => BuildResult::file(
                $this->root . '/cache/app.' . self::FINGERPRINT . '.css',
                null,
                \str_repeat('ab', 32),
                self::FINGERPRINT,
                1700000000,
                'cached',
                new DiagnosticBag()
            ),
        ]);

        $responder = new Responder(
            new RouteMatcher(['app' => self::ROUTE]),
            $this->resolver,
            $store,
            new ResponseBuilder([], 31536000, false),
            $this->fs
        );

        $this->fs->reset();

        return $responder;
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
