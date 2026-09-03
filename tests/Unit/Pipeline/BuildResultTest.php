<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Pipeline\BuildResult;

/**
 * The two shapes of a build outcome (design doc 04 §4.10 and §6, CCP-A1/A16).
 */
final class BuildResultTest extends TestCase
{
    /** @test */
    public function classIsFinalAndHasNoPublicConstructor(): void
    {
        $reflection = new ReflectionClass(BuildResult::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate(), 'Use the named constructors.');
    }

    /** @test */
    public function theFileShapeRoundTripsEveryField(): void
    {
        $bag = new DiagnosticBag([new Diagnostic(Diagnostic::WARNING, Diagnostic::W_UNKNOWN_UTILITY, 'x')]);

        $result = BuildResult::file(
            '/srv/cache/app.abcdef01.css',
            '/srv/cache/app.abcdef01.css.gz',
            \str_repeat('a', 64),
            'abcdef01',
            1756857600,
            'compiled',
            $bag
        );

        self::assertFalse($result->isInline());
        self::assertSame('/srv/cache/app.abcdef01.css', $result->path());
        self::assertSame('/srv/cache/app.abcdef01.css.gz', $result->gzPath());
        self::assertSame(\str_repeat('a', 64), $result->contentHash());
        self::assertSame('abcdef01', $result->fingerprint());
        self::assertSame(1756857600, $result->lastModified());
        self::assertSame('compiled', $result->source());
        self::assertSame($bag, $result->diagnostics());
        self::assertSame(200, $result->status());
        self::assertSame('', $result->body());
        self::assertNull($result->cause());
    }

    /**
     * The ETag is the fingerprint in double quotes (ADR-004) — the same value
     * `Fingerprint::toETag()` produces, so the two cannot drift.
     *
     * @test
     */
    public function theEtagIsTheQuotedFingerprint(): void
    {
        $result = $this->fileResult('abcdef01');

        self::assertSame('"abcdef01"', $result->etag());
    }

    /** @test */
    public function theContentTypeIsFixed(): void
    {
        self::assertSame('text/css; charset=utf-8', $this->fileResult('abcdef01')->contentType());
        self::assertSame('text/css; charset=utf-8', $this->inlineResult()->contentType());
    }

    /** @test */
    public function aFileResultMayHaveNoCompressedTwin(): void
    {
        $result = BuildResult::file(
            '/srv/cache/app.abcdef01.css',
            null,
            \str_repeat('a', 64),
            'abcdef01',
            1,
            'cached',
            new DiagnosticBag()
        );

        self::assertNull($result->gzPath());
    }

    /** @test */
    public function theInlineShapeRoundTripsEveryField(): void
    {
        $bag = new DiagnosticBag();
        $cause = new \RuntimeException('compilation failed');

        $result = BuildResult::inline(500, '/* failed */', 'error', $cause, $bag);

        self::assertTrue($result->isInline());
        self::assertSame(500, $result->status());
        self::assertSame('/* failed */', $result->body());
        self::assertSame('error', $result->source());
        self::assertSame($cause, $result->cause());
        self::assertSame($bag, $result->diagnostics());
    }

    /**
     * An inline result has no artefact, and `path()` must say so rather than
     * hand back an empty string a caller might join onto a root.
     *
     * @test
     */
    public function anInlineResultHasNoPath(): void
    {
        $result = $this->inlineResult();

        self::assertNull($result->path());
        self::assertNull($result->gzPath());
    }

    /** @test */
    public function anInlineResultMayHaveNoCause(): void
    {
        $result = BuildResult::inline(200, '/* overlay */', 'error', null, new DiagnosticBag());

        self::assertNull($result->cause());
    }

    /**
     * @test
     *
     * @dataProvider knownSources
     */
    public function everyDocumentedSourceIsAccepted(string $source): void
    {
        self::assertSame($source, $this->fileResult('abcdef01', $source)->source());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function knownSources(): array
    {
        $cases = [];

        foreach (BuildResult::sources() as $source) {
            $cases[$source] = [$source];
        }

        return $cases;
    }

    /** @test */
    public function theDocumentedSourcesAreExactlyTheFiveOfCcpA15(): void
    {
        self::assertSame(
            ['cached', 'touched', 'compiled', 'stale-fallback', 'error'],
            BuildResult::sources()
        );
    }

    /**
     * A typo in `source` would otherwise reach the debug overlay and a log as
     * if it were a real outcome.
     *
     * @test
     */
    public function anUnknownSourceIsRejectedByTheFileConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fileResult('abcdef01', 'nonsense');
    }

    /** @test */
    public function anUnknownSourceIsRejectedByTheInlineConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BuildResult::inline(500, '/* x */', 'nonsense', null, new DiagnosticBag());
    }

    private function fileResult(string $fingerprint, string $source = 'cached'): BuildResult
    {
        return BuildResult::file(
            '/srv/cache/app.' . $fingerprint . '.css',
            null,
            \str_repeat('a', 64),
            $fingerprint,
            1756857600,
            $source,
            new DiagnosticBag()
        );
    }

    private function inlineResult(): BuildResult
    {
        return BuildResult::inline(200, '/* overlay */', 'error', null, new DiagnosticBag());
    }
}
