<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Http\HttpDate;
use TailwindPhp\Http\Response;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Tests\Support\TempProject;

/**
 * The HTTP half of doc 16's lifecycle matrix, end to end through the facade
 * (TASK-0018 acceptance 6; doc 04 §5 and §8, doc 10).
 *
 * Everything below the facade is real here — a real cache directory, the real
 * scanner, the stub PHP engine through the real `DriverResolver`. That is the
 * point: `LifecycleTest` already pins the pipeline's decisions, so what these
 * rows add is the wiring, and wiring bugs are exactly the ones a unit test with
 * a double cannot see.
 *
 * Three of them are invariants rather than preferences:
 *
 * - **I5** the fingerprint `url()` links to is the one the response validates
 *   with, because a page that links to a URL the server will redirect costs
 *   every visitor an extra round trip.
 * - **I6** the plain route is never `immutable` — its content changes without
 *   its URL changing.
 * - **I7** debug never sends a validator and never compresses, so a stylesheet
 *   that is rebuilt on every request can never be answered with a 304.
 *
 * @covers-requirement FR-PIPE-1
 * @covers-requirement FR-HTTP-3
 */
final class HttpLifecycleTest extends TestCase
{
    private const ROUTE = 'assets/styles.css';

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
     * @test
     */
    public function aColdPlainRouteBuildsAndServesTheStylesheet(): void
    {
        $response = $this->project()->request(self::ROUTE);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('text/css; charset=utf-8', $response->headerValue('Content-Type'));
        self::assertSame('nosniff', $response->headerValue('X-Content-Type-Options'));
        self::assertSame('Accept-Encoding', $response->headerValue('Vary'));

        $path = $response->bodyPath();
        self::assertNotNull($path);
        self::assertFileExists($path);

        $manifest = $this->project()->manifest('app');
        self::assertNotNull($manifest);
        self::assertSame('"' . $manifest['fingerprint'] . '"', $response->headerValue('ETag'));
    }

    /**
     * I6.
     *
     * @test
     */
    public function thePlainRouteRevalidatesAndIsNeverImmutable(): void
    {
        $response = $this->project()->request(self::ROUTE);

        self::assertNotNull($response);
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
        self::assertStringNotContainsString('immutable', (string) $response->headerValue('Cache-Control'));
    }

    /**
     * @test
     */
    public function theFingerprintedRouteIsImmutable(): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);

        $response = $project->request('assets/styles.' . $this->fingerprintOf($first) . '.css');

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('public, max-age=31536000, immutable', $response->headerValue('Cache-Control'));
    }

    /**
     * I5: the URL a page links to and the validator the response carries come
     * from the same manifest field, so they cannot drift.
     *
     * @test
     */
    public function theLinkedUrlCarriesTheServedFingerprint(): void
    {
        $project = $this->project();
        $project->request(self::ROUTE);

        $url = $project->tailwind()->url();
        $response = $project->request(self::ROUTE);

        self::assertNotNull($response);

        $etag = \trim((string) $response->headerValue('ETag'), '"');

        self::assertSame('assets/styles.' . $etag . '.css', $url);
    }

    /**
     * @dataProvider matchingValidators
     *
     * @test
     */
    public function aMatchingValidatorIsAnswered304(string $header): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);

        $etag = (string) $first->headerValue('ETag');
        $response = $project->request(self::ROUTE, [
            'HTTP_IF_NONE_MATCH' => \str_replace('{etag}', $etag, $header),
        ]);

        self::assertNotNull($response);
        self::assertSame(304, $response->status());
        self::assertNull($response->bodyPath());
        self::assertNull($response->body());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function matchingValidators(): array
    {
        return [
            'exact' => ['{etag}'],
            'weak' => ['W/{etag}'],
            'wildcard' => ['*'],
            'a list' => ['"0123abcd", {etag}, "beefbeef"'],
        ];
    }

    /**
     * @test
     */
    public function anIfModifiedSinceAloneIsAnswered304(): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);

        $path = (string) $first->bodyPath();
        \clearstatcache(true, $path);

        $response = $project->request(self::ROUTE, [
            'HTTP_IF_MODIFIED_SINCE' => HttpDate::format((int) \filemtime($path) + 60),
        ]);

        self::assertNotNull($response);
        self::assertSame(304, $response->status());
    }

    /**
     * RFC 9110: a present `If-None-Match` decides alone, so a stale one is a
     * 200 even when the date would have said "not modified".
     *
     * @test
     */
    public function ifNoneMatchTakesPrecedenceOverIfModifiedSince(): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);

        $path = (string) $first->bodyPath();
        \clearstatcache(true, $path);

        $response = $project->request(self::ROUTE, [
            'HTTP_IF_NONE_MATCH' => '"0123abcd"',
            'HTTP_IF_MODIFIED_SINCE' => HttpDate::format((int) \filemtime($path) + 60),
        ]);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
    }

    /**
     * @test
     */
    public function headRepeatsTheHeadersWithNoBody(): void
    {
        $project = $this->project();
        $get = $project->request(self::ROUTE);
        $head = $project->request(self::ROUTE, ['REQUEST_METHOD' => 'HEAD']);

        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertSame($get->headers(), $head->headers());
        self::assertNull($head->bodyPath());
        self::assertNull($head->body());
    }

    /**
     * @test
     */
    public function anUnknownFingerprintRedirectsRelatively(): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);

        $response = $project->request('assets/styles.beefbeefbeef.css');

        self::assertNotNull($response);
        self::assertSame(302, $response->status());
        self::assertSame(
            'styles.' . $this->fingerprintOf($first) . '.css',
            $response->headerValue('Location')
        );
        self::assertSame('no-cache', $response->headerValue('Cache-Control'));
    }

    /**
     * Doc 10 §Stale fingerprints step 1: a visitor who loaded the page moments
     * before a deploy asked for exactly the right file.
     *
     * @test
     */
    public function aRetainedFingerprintIsStillServedImmutably(): void
    {
        $project = $this->project(['keep_versions' => 3]);

        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);
        $old = $this->fingerprintOf($first);

        // A new candidate changes the CSS, so the next build has a new
        // fingerprint and the old artefact is retained rather than pruned.
        $project->writeFile('templates/extra.twig', "<div class=\"gap-8\"></div>\n");

        $second = $project->request(self::ROUTE);
        self::assertNotNull($second);
        self::assertNotSame($old, $this->fingerprintOf($second), 'the second build should differ');

        $response = $project->request('assets/styles.' . $old . '.css');

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('public, max-age=31536000, immutable', $response->headerValue('Cache-Control'));
        self::assertSame('"' . $old . '"', $response->headerValue('ETag'));
    }

    /**
     * HBC-A6: the method is checked before anything is built, so an
     * unauthenticated POST cannot spend a site's CPU on a compile. The cache
     * directory is deliberately absent — if any I/O happened it would appear.
     *
     * @test
     */
    public function aPostIsRefusedBeforeAnyWork(): void
    {
        $project = $this->project();

        $response = $project->request(self::ROUTE, ['REQUEST_METHOD' => 'POST']);

        self::assertNotNull($response);
        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->headerValue('Allow'));
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertNull($project->manifest('app'), 'a refused method must not have triggered a build');
    }

    /**
     * @test
     */
    public function optionsIsLeftToTheIntegratorUnlessConfigured(): void
    {
        $project = $this->project();

        self::assertNull($project->request(self::ROUTE, ['REQUEST_METHOD' => 'OPTIONS']));

        $handled = $project->request(
            self::ROUTE,
            ['REQUEST_METHOD' => 'OPTIONS'],
            ['http' => ['handle_options' => true]]
        );

        self::assertNotNull($handled);
        self::assertSame(204, $handled->status());
        self::assertSame('GET, HEAD, OPTIONS', $handled->headerValue('Allow'));
    }

    /**
     * @test
     */
    public function theCompressedTwinIsServedWhenNegotiated(): void
    {
        $project = $this->project();
        $project->request(self::ROUTE);

        $response = $project->request(self::ROUTE, ['HTTP_ACCEPT_ENCODING' => 'gzip']);

        self::assertNotNull($response);

        if (!\extension_loaded('zlib')) {
            self::assertNull($response->headerValue('Content-Encoding'), 'no zlib, so no twin to serve');

            return;
        }

        self::assertSame('gzip', $response->headerValue('Content-Encoding'));
        self::assertStringEndsWith('.gz', (string) $response->bodyPath());
    }

    /**
     * @test
     */
    public function compressionIsNotUsedWhenTheClientDoesNotWantIt(): void
    {
        $project = $this->project();
        $project->request(self::ROUTE);

        $response = $project->request(self::ROUTE, ['HTTP_ACCEPT_ENCODING' => 'identity']);

        self::assertNotNull($response);
        self::assertNull($response->headerValue('Content-Encoding'));
        self::assertStringEndsWith('.css', (string) $response->bodyPath());
    }

    /**
     * @test
     */
    public function compressionIsNotUsedWhenItIsSwitchedOff(): void
    {
        $project = $this->project(['gzip' => false]);
        $project->request(self::ROUTE, [], ['gzip' => false]);

        $response = $project->request(self::ROUTE, ['HTTP_ACCEPT_ENCODING' => 'gzip'], ['gzip' => false]);

        self::assertNotNull($response);
        self::assertNull($response->headerValue('Content-Encoding'));
    }

    /**
     * I7, all three halves of it.
     *
     * @test
     */
    public function debugSendsNoValidatorsStoresNothingAndNeverCompresses(): void
    {
        $project = $this->project();

        $response = $project->request(
            self::ROUTE,
            ['HTTP_ACCEPT_ENCODING' => 'gzip'],
            ['debug' => true]
        );

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertNull($response->headerValue('ETag'));
        self::assertNull($response->headerValue('Last-Modified'));
        self::assertNull($response->headerValue('Content-Encoding'));
    }

    /**
     * A debug request carrying a validator still gets the stylesheet: with no
     * ETag to compare, there is nothing that could produce a 304.
     *
     * @test
     */
    public function debugNeverAnswers304(): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE, [], ['debug' => true]);
        self::assertNotNull($first);

        $second = $project->request(
            self::ROUTE,
            ['HTTP_IF_NONE_MATCH' => '*'],
            ['debug' => true]
        );

        self::assertNotNull($second);
        self::assertSame(200, $second->status());
    }

    /**
     * Doc 15: a build that throws in debug still renders something a browser
     * can parse, so the page is styled enough to read the banner.
     *
     * @test
     */
    public function aFailedBuildInDebugServesTheOverlay(): void
    {
        $project = $this->project();
        $project->writeFile('assets/styles/main.css', self::oversizedEntry());

        $response = $project->request(self::ROUTE, [], self::tinyLimit() + ['debug' => true]);

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertStringContainsString('html::before', (string) $response->body());
        self::assertNull($response->bodyPath());
    }

    /**
     * Doc 15 in production: one fixed comment, and never stored.
     *
     * @test
     */
    public function aFailedBuildWithoutACacheServesTheConstantBody(): void
    {
        $project = $this->project();
        $project->writeFile('assets/styles/main.css', self::oversizedEntry());

        $response = $project->request(self::ROUTE, [], self::tinyLimit());

        self::assertNotNull($response);
        self::assertSame(500, $response->status());
        self::assertSame('no-store', $response->headerValue('Cache-Control'));
        self::assertSame(ErrorRenderer::productionBody(), $response->body());
    }

    /**
     * With a cache behind it, a failed rebuild serves the previous stylesheet
     * rather than an error: the site keeps working and the detail goes to the
     * manifest for `doctor` to find.
     *
     * @test
     */
    public function aFailedRebuildWithACacheServesTheStaleArtefact(): void
    {
        $project = $this->project();
        $first = $project->request(self::ROUTE);
        self::assertNotNull($first);

        $project->writeFile('assets/styles/main.css', self::oversizedEntry());

        $response = $project->request(self::ROUTE, [], self::tinyLimit());

        self::assertNotNull($response);
        self::assertSame(200, $response->status());
        self::assertSame($first->headerValue('ETag'), $response->headerValue('ETag'));

        $manifest = $project->manifest('app');
        self::assertNotNull($manifest);
        self::assertIsArray($manifest['last_error']);
        self::assertNotSame('', (string) $manifest['last_error']['code']);
    }

    /**
     * A route that is not ours costs nothing at all — not a stat, not a
     * manifest read, and certainly not a build.
     *
     * @test
     */
    public function aForeignRouteIsDeclinedWithoutTouchingAnything(): void
    {
        $project = $this->project();

        // Buffered and accounted for explicitly: `respond()` returning false is
        // only half the promise. The other half is that it wrote nothing and
        // closed no buffer of the caller's — a declined route must leave the
        // integrator's output stack exactly as it found it.
        $before = \ob_get_level();
        \ob_start();
        $served = $project->tailwind()->respond('favicon.ico', []);
        $output = (string) \ob_get_clean();

        self::assertFalse($served);
        self::assertSame('', $output, 'a declined route must emit nothing');
        self::assertSame($before, \ob_get_level(), 'a declined route must not touch the output stack');
        self::assertNull($project->manifest('app'), 'a foreign route must not have built anything');
    }

    /**
     * `respond()` puts the artefact on the wire, and discards exactly one
     * buffer level doing it (doc 10 §Streaming).
     *
     * The `$headerSink` seam is **not reachable through the facade** — it is a
     * `Responder` constructor argument and the facade builds its own — so the
     * "exactly once" half of `ob_end_clean()` is proven here by accounting
     * rather than by a recorder: two levels are opened, the inner one is the
     * one `emit()` discards, and the level afterwards must be exactly one lower
     * than before. If `emit()` ever cleaned twice, the captured body would be
     * lost and the level would come back short.
     *
     * @test
     */
    public function respondEmitsTheBodyAndDiscardsExactlyOneBufferLevel(): void
    {
        $project = $this->project();
        $response = $project->request(self::ROUTE);
        self::assertNotNull($response);

        $expected = (string) \file_get_contents((string) $response->bodyPath());

        $before = \ob_get_level();
        \ob_start();
        \ob_start();
        $served = $project->tailwind()->respond(self::ROUTE, ['REQUEST_METHOD' => 'GET']);
        $levelDuring = \ob_get_level();
        $body = (string) \ob_get_clean();

        self::assertTrue($served);
        self::assertSame($expected, $body, 'the artefact bytes should have been streamed');
        self::assertSame($before + 1, $levelDuring, 'emit() must discard exactly one buffer level');
        self::assertSame($before, \ob_get_level());
    }

    /**
     * An entry stylesheet that is valid CSS but larger than the smallest
     * `limits.max_total_css` the schema permits (1024).
     *
     * The rows below use it with `['limits' => ['max_total_css' => 1024]]` to
     * make a build fail for a documented reason. A missing `@import` was the
     * obvious first choice and is not one: the import resolver reports it as a
     * diagnostic and the build succeeds, which is deliberate — one unreachable
     * partial should not take a site's stylesheet down.
     */
    private static function oversizedEntry(): string
    {
        return "@import \"tailwindcss\";\n" . \str_repeat("/* padding */\n", 200);
    }

    /**
     * The configuration that turns {@see self::oversizedEntry()} into a failed
     * build.
     *
     * `log_level` is raised rather than a null logger being injected: these
     * rows are *supposed* to log an error, the facade's default
     * `ErrorLogLogger` is the thing under test, and swapping it out would stop
     * exercising the wiring. Raising the threshold keeps the real logger in the
     * graph and keeps three expected failures out of the suite's stderr.
     *
     * @return array<string, mixed>
     */
    private static function tinyLimit(): array
    {
        return [
            'limits' => ['max_total_css' => 1024],
            'log_level' => 'emergency',
        ];
    }

    /**
     * The lock factory is wired from `Config::lock()`.
     *
     * Worth an explicit row because the two implementations are
     * indistinguishable from the outside — a build succeeds either way — so the
     * only visible difference is whether `FlockBuildLock` created its file.
     * Without this, wiring `NullLock` unconditionally would pass every other
     * test in the suite while removing the protection ADR-006 exists for.
     *
     * @dataProvider lockModes
     *
     * @test
     */
    public function theConfiguredLockIsTheOneUsed(string $mode, bool $expectFile): void
    {
        $project = $this->project();
        $project->request(self::ROUTE, [], ['lock' => $mode]);

        self::assertNotNull($project->manifest('app'), 'the build should have happened either way');
        self::assertSame(
            $expectFile,
            \is_file($project->root() . '/cache/tailwind/app.lock'),
            \sprintf('lock: %s produced the wrong artefact', $mode)
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function lockModes(): array
    {
        return [
            'flock writes a lock file' => ['flock', true],
            'none writes nothing' => ['none', false],
        ];
    }

    private function fingerprintOf(Response $response): string
    {
        return \trim((string) $response->headerValue('ETag'), '"');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function project(array $config = []): TempProject
    {
        if ($this->project === null) {
            $this->project = new TempProject($config);
        }

        return $this->project;
    }
}
