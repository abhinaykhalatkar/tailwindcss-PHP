<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\Fingerprint;
use TailwindPhp\Http\RouteMatcher;

/**
 * Route recognition (TASK-0016 acceptance 1; design doc 10 §Routes, threat T-02).
 *
 * This is the library's front door: every request the integrator hands over
 * arrives here first, and what it returns decides whether any I/O happens at
 * all. So the tests are weighted heavily towards the strings that must **not**
 * match. A false positive here is not a wrong stylesheet — it is a request
 * string that has been accepted as a route and gone on to become a filename.
 *
 * The matcher never touches the filesystem, so every row below is a pure
 * function of its input.
 *
 * @covers-requirement FR-HTTP-1
 */
final class RouteMatcherTest extends TestCase
{
    /**
     * The two configured bundles used throughout, in config order.
     */
    private const ROUTES = [
        'app' => 'assets/styles.css',
        'admin' => 'admin/panel.css',
    ];

    /**
     * @dataProvider plainRoutes
     *
     * @test
     */
    public function thePlainRouteMatchesWithoutAFingerprint(string $path, string $bundle): void
    {
        $match = (new RouteMatcher(self::ROUTES))->match($path);

        self::assertNotNull($match, 'expected a match for ' . $path);
        self::assertSame($bundle, $match->bundle());
        self::assertSame(self::ROUTES[$bundle], $match->publicRoute());
        self::assertNull($match->fingerprint());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function plainRoutes(): array
    {
        return [
            'bare' => ['assets/styles.css', 'app'],
            // The integrator passes whatever their front controller has; both
            // forms are the same route (doc 10 §Routes "leading / optional").
            'with a leading slash' => ['/assets/styles.css', 'app'],
            // Doc 10: the query string is ignored for matching, which is what
            // keeps `?v=` cache-busting working on the plain route.
            'with a query string' => ['assets/styles.css?v=123', 'app'],
            'with an empty query string' => ['assets/styles.css?', 'app'],
            'the second bundle' => ['admin/panel.css', 'admin'],
        ];
    }

    /**
     * @dataProvider fingerprintedRoutes
     *
     * @test
     */
    public function theFingerprintedRouteCapturesTheFingerprint(string $path, string $fingerprint): void
    {
        $match = (new RouteMatcher(self::ROUTES))->match($path);

        self::assertNotNull($match, 'expected a match for ' . $path);
        self::assertSame('app', $match->bundle());
        self::assertSame($fingerprint, $match->fingerprint());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fingerprintedRoutes(): array
    {
        return [
            'the default 12 characters' => ['assets/styles.a1b2c3d4e5f6.css', 'a1b2c3d4e5f6'],
            'the shortest permitted' => ['assets/styles.0123abcd.css', '0123abcd'],
            'the longest permitted' => ['assets/styles.0123456789abcdef.css', '0123456789abcdef'],
            'all digits' => ['/assets/styles.000000000000.css', '000000000000'],
            'with a query string' => ['assets/styles.a1b2c3d4e5f6.css?v=2', 'a1b2c3d4e5f6'],
        ];
    }

    /**
     * @dataProvider hostileAndUnknownRoutes
     *
     * @test
     */
    public function nothingElseMatches(string $path): void
    {
        self::assertNull((new RouteMatcher(self::ROUTES))->match($path));
    }

    /**
     * The rows named in the task, plus the near-misses that a looser pattern
     * would let through.
     *
     * @return array<string, array{string}>
     */
    public static function hostileAndUnknownRoutes(): array
    {
        return [
            // Percent-decoded once, so `%2e` becomes `.` — and `styles...css`
            // is not the route. Matching *before* decoding would be worse: the
            // encoded form would reach a filename.
            'percent-encoded dots' => ['assets/styles.%2e%2e.css'],
            'literal traversal' => ['assets/styles.../../x.css'],
            'traversal in the directory part' => ['assets/../assets/styles.css'],
            // Decoded once only: `%252e` becomes `%2e`, never `.`.
            'double-encoded dots' => ['assets/styles.%252e.css'],
            // The row that actually pins "once". Decoded twice this spells
            // `assets/styles.abcdef01.css` and matches; decoded once it is
            // still full of percent signs and does not. Without it, a matcher
            // that decoded twice would pass every other row here.
            'a double-encoded fingerprint' => [
                'assets/styles.%2561%2562%2563%2564%2565%2566%2530%2531.css',
            ],
            'encoded separator in the fingerprint slot' => ['assets/styles.%2f.css'],
            // The fingerprint alphabet is lowercase hex, exactly 8-16 of them.
            'uppercase hex' => ['assets/styles.A1B2C3D4E5F6.css'],
            'seven hex characters' => ['assets/styles.0123abc.css'],
            'seventeen hex characters' => ['assets/styles.0123456789abcdef0.css'],
            'non-hex characters' => ['assets/styles.g1b2c3d4e5f6.css'],
            'an empty fingerprint' => ['assets/styles..css'],
            'two fingerprints' => ['assets/styles.0123abcd.0123abcd.css'],
            // Anchoring, both ends.
            'a trailing slash' => ['assets/styles.css/'],
            'a leading segment' => ['x/assets/styles.css'],
            'a trailing segment' => ['assets/styles.css/x'],
            'a suffix' => ['assets/styles.css.map'],
            // Case-sensitive: a case-insensitive match would make two URLs for
            // one artefact, and on a case-insensitive filesystem both would
            // work, which is how a cache gets poisoned.
            'a capitalised directory' => ['Assets/styles.css'],
            'a capitalised extension' => ['assets/styles.CSS'],
            // Only one leading slash is stripped.
            'a doubled leading slash' => ['//assets/styles.css'],
            'a backslash separator' => ['assets\\styles.css'],
            'an absolute Windows path' => ['C:/assets/styles.css'],
            // Control bytes never appear in a route we generated.
            'an encoded NUL' => ['assets/styles.css%00'],
            'a raw NUL' => ["assets/styles.css\0"],
            'an encoded newline' => ['assets/styles.css%0a'],
            'a raw newline in the fingerprint' => ["assets/styles.0123abcd\n.css"],
            // Nothing to do with us.
            'another file entirely' => ['favicon.ico'],
            'the empty string' => [''],
            'a lone slash' => ['/'],
            'a query string only' => ['?v=1'],
            // A fragment is not part of a real REQUEST_URI, but it is trivially
            // sendable, and it must not be mistaken for part of the filename.
            'a fragment' => ['assets/styles.css#x'],
            'a scheme and host' => ['http://example.test/assets/styles.css'],
        ];
    }

    /**
     * The 9 KB row of acceptance 1, kept separate because the point is the
     * bound rather than the shape: an over-long path is refused before it is
     * decoded, so a megabyte of percent-escapes costs one `strlen`.
     *
     * @test
     */
    public function anOverLongPathIsRefused(): void
    {
        $matcher = new RouteMatcher(self::ROUTES);

        self::assertNull($matcher->match(\str_repeat('a', 9216)));
        self::assertNull($matcher->match(\str_repeat('%2e', 3072)));
        // Padding a real route past the bound must not rescue it.
        self::assertNull($matcher->match('assets/styles.css?' . \str_repeat('v', 9216)));
    }

    /**
     * A query string is stripped before the length bound is applied, so the
     * cache-busting form of a real route still works however long it is.
     *
     * @test
     */
    public function aLongQueryStringDoesNotHideARealRoute(): void
    {
        $match = (new RouteMatcher(self::ROUTES))->match('assets/styles.css?v=' . \str_repeat('9', 500));

        self::assertNotNull($match);
        self::assertSame('app', $match->bundle());
    }

    /**
     * Whatever arrives, the matcher either returns null or returns one of the
     * two shapes it knows — it never throws, and it never invents a bundle or a
     * fingerprint that would not survive validation on the way to a filename.
     *
     * The seed is fixed so a failure is reproducible; a fuzz test that cannot
     * be replayed only tells you that something once went wrong.
     *
     * @test
     */
    public function fuzzingNeverProducesAnUnexpectedShape(): void
    {
        $matcher = new RouteMatcher(self::ROUTES);
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEF0123456789./-_%\\:?#& ' . "\t\n\r\0";
        $hex = '0123456789abcdef';

        \mt_srand(20260908);
        $matched = 0;

        for ($i = 0; $i < 2000; $i++) {
            switch ($i % 3) {
                case 0:
                    // Arbitrary bytes: the "is it ours at all" question.
                    $path = self::randomString($alphabet, \mt_rand(0, 64));

                    break;
                case 1:
                    // Arbitrary bytes in the fingerprint slot of a real route.
                    $path = 'assets/styles.' . self::randomString($alphabet, \mt_rand(0, 24)) . '.css';

                    break;
                default:
                    // Hex of a random length either side of the 8-16 bound.
                    // Without this family the fuzz never once produces a match
                    // — random bytes essentially never spell 8 to 16 hex
                    // characters — and a reject-only fuzz proves nothing about
                    // what gets accepted.
                    $path = 'assets/styles.' . self::randomString($hex, \mt_rand(1, 20)) . '.css';
            }

            $match = $matcher->match($path);

            if ($match === null) {
                continue;
            }

            $matched++;

            self::assertArrayHasKey($match->bundle(), self::ROUTES, 'invented a bundle from: ' . \rawurlencode($path));

            $fingerprint = $match->fingerprint();

            if ($fingerprint !== null) {
                self::assertTrue(
                    Fingerprint::isValid($fingerprint),
                    'captured an invalid fingerprint from: ' . \rawurlencode($path)
                );
            }
        }

        // The loop only asserts on iterations that matched, so a matcher that
        // returned null for everything would sail through it. This is the
        // control: with half the inputs built from a real route, the fuzz must
        // land on the fingerprinted shape sometimes.
        self::assertGreaterThan(0, $matched, 'the fuzz never reached the matching branch');
    }

    private static function randomString(string $alphabet, int $length): string
    {
        $last = \strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[\mt_rand(0, $last)];
        }

        return $out;
    }

    /**
     * Routes are matched in configuration order, and a bundle whose route is a
     * suffix of another's still gets its own answer.
     *
     * @test
     */
    public function eachBundleGetsItsOwnRoute(): void
    {
        $matcher = new RouteMatcher(self::ROUTES);

        $app = $matcher->match('assets/styles.0123abcd.css');
        $admin = $matcher->match('admin/panel.0123abcd.css');

        self::assertNotNull($app);
        self::assertNotNull($admin);
        self::assertSame('app', $app->bundle());
        self::assertSame('admin', $admin->bundle());
        self::assertSame('admin/panel.css', $admin->publicRoute());
    }

    /**
     * A route without the `.css` suffix cannot be expressed by the two patterns
     * and is a configuration error, not something to guess at.
     *
     * @test
     */
    public function aRouteWithoutTheCssSuffixIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RouteMatcher(['app' => 'assets/styles']);
    }

    /**
     * With nothing configured, nothing is ours.
     *
     * @test
     */
    public function noBundlesMeansNoMatches(): void
    {
        self::assertNull((new RouteMatcher([]))->match('assets/styles.css'));
    }
}
