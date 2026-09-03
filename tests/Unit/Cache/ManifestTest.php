<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Exception\ManifestCorruptException;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Json;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The manifest value object (design doc 05 §Manifest, ADR-014).
 *
 * Two properties carry the weight here. The getters must be exhaustive — a
 * field with no accessor is a field the pipeline cannot compare, and freshness
 * is nothing but a comparison. And `load()` must be total: for every possible
 * byte sequence on disk it returns either a `Manifest` or `null`, never an
 * exception, because the caller is rendering a page (design doc 15).
 */
final class ManifestTest extends TestCase
{
    /**
     * @var list<string>
     */
    private $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            TempTree::remove($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Manifest::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * Constructed only through the validating factories, so an instance can
     * never hold a document that failed its schema check.
     *
     * @test
     */
    public function cannotBeConstructedDirectly(): void
    {
        $reflection = new ReflectionClass(Manifest::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    // ----------------------------------------------------------------- getters

    /** @test */
    public function everyFieldOfTheSchemaHasAGetterThatReturnsIt(): void
    {
        $manifest = Manifest::fromArray(self::fixture());

        self::assertSame(1, $manifest->schema());
        self::assertSame('0.1.0-dev', $manifest->packageVersion());
        self::assertSame('sha256:' . \str_repeat('a', 64), $manifest->configHash());
        self::assertSame('app', $manifest->bundle());
        self::assertSame('php', $manifest->driver());
        self::assertSame('0.1.0', $manifest->driverVersion());
        self::assertNull($manifest->driverProbe());
        self::assertSame(1756857600, $manifest->builtAt());
        self::assertSame(42.5, $manifest->buildMs());
        self::assertSame('sha256:' . \str_repeat('b', 64), $manifest->contentHash());
        self::assertSame('0123456789ab', $manifest->fingerprint());
        self::assertTrue($manifest->minified());
        self::assertSame('sha256:' . \str_repeat('c', 64), $manifest->candidatesHash());
        self::assertSame(3, $manifest->candidatesCount());
        self::assertSame('resources/css/app.css', $manifest->input());
        self::assertSame(['resources/css/components.css'], $manifest->imports());
        self::assertSame(
            ['input' => ['resources/css'], 'watch' => ['config'], 'sources' => ['templates']],
            $manifest->roots()
        );
        self::assertSame(
            [
                'resources/css/app.css' => ['k' => 'i', 'm' => 1756857000, 's' => 128],
                'templates/home.twig' => ['k' => 's', 'm' => 1756857100, 's' => 512, 'c' => ['flex', 'p-4']],
            ],
            $manifest->files()
        );
        self::assertSame('sha256:' . \str_repeat('d', 64), $manifest->safelistHash());
        self::assertSame(['mt-2'], $manifest->inlineSources());
        self::assertSame([self::warningRow()], $manifest->warnings());
        self::assertNull($manifest->lastError());
    }

    /**
     * `build_ms` is declared a float, but JSON has a single number type: a
     * whole-numbered duration decodes to int and the getter still has to hand
     * back a float (design doc 05 §Manifest).
     *
     * @test
     */
    public function buildMsIsAFloatEvenWhenTheDocumentStoredAnInteger(): void
    {
        $data = self::fixture();
        $data['build_ms'] = 42;

        $manifest = Manifest::fromArray($data);

        self::assertSame(42.0, $manifest->buildMs());
    }

    /**
     * The CLI probe fields of decisions CCP-A3 (project-relative binary, or
     * null when it lives outside the project) and CCP-A4 (`version`).
     *
     * @test
     */
    public function theDriverProbeIsReturnedWithEveryField(): void
    {
        $probe = [
            'binary' => 'vendor/bin/tailwindcss',
            'm' => 1756000000,
            's' => 123456,
            'sha256' => \str_repeat('e', 64),
            'version' => '4.0.0',
        ];

        $data = self::fixture();
        $data['driver'] = 'cli';
        $data['driver_probe'] = $probe;

        self::assertSame($probe, Manifest::fromArray($data)->driverProbe());
    }

    /** @test */
    public function aLastErrorIsReturnedWithEveryField(): void
    {
        $error = ['code' => 'E_CLI_TIMEOUT', 'at' => 1756857700, 'message_short' => 'The CLI timed out.'];

        $data = self::fixture();
        $data['last_error'] = $error;

        self::assertSame($error, Manifest::fromArray($data)->lastError());
    }

    // ------------------------------------------------------------- round trip

    /** @test */
    public function toArrayRoundTripsTheDocumentUnchanged(): void
    {
        $data = self::fixture();

        self::assertSame($data, Manifest::fromArray($data)->toArray());
    }

    /** @test */
    public function fromArrayRejectsAnInvalidDocument(): void
    {
        $data = self::fixture();
        unset($data['fingerprint']);

        $this->expectException(ManifestCorruptException::class);

        Manifest::fromArray($data);
    }

    // ------------------------------------------------------------ immutability

    /** @test */
    public function withFilesReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $original = Manifest::fromArray(self::fixture());
        $files = ['templates/other.twig' => ['k' => 's', 'm' => 1, 's' => 2]];

        $updated = $original->withFiles($files);

        self::assertNotSame($original, $updated);
        self::assertSame($files, $updated->files());
        self::assertSame(self::fixture()['files'], $original->files());
    }

    /**
     * The map is sorted on the way in, so a rebuild that changed nothing
     * produces identical bytes and the manifest does not churn on every save
     * (design doc 05 §Manifest).
     *
     * @test
     */
    public function withFilesSortsTheMapByPath(): void
    {
        $manifest = Manifest::fromArray(self::fixture())->withFiles([
            'templates/zebra.twig' => ['k' => 's', 'm' => 3, 's' => 3],
            'resources/css/app.css' => ['k' => 'i', 'm' => 1, 's' => 1],
            'templates/alpha.twig' => ['k' => 's', 'm' => 2, 's' => 2],
        ]);

        self::assertSame(
            ['resources/css/app.css', 'templates/alpha.twig', 'templates/zebra.twig'],
            \array_keys($manifest->files())
        );
    }

    /** @test */
    public function withFilesRejectsAnInvalidMap(): void
    {
        $manifest = Manifest::fromArray(self::fixture());

        $this->expectException(ManifestCorruptException::class);

        $manifest->withFiles(['/absolute/path.twig' => ['k' => 's', 'm' => 1, 's' => 2]]);
    }

    /** @test */
    public function withLastErrorReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $original = Manifest::fromArray(self::fixture());
        $error = ['code' => 'E_CLI_FAILED', 'at' => 1756857800, 'message_short' => 'Exit 1.'];

        $updated = $original->withLastError($error);

        self::assertNotSame($original, $updated);
        self::assertSame($error, $updated->lastError());
        self::assertNull($original->lastError());
    }

    /** @test */
    public function withLastErrorClearsTheFieldWhenGivenNull(): void
    {
        $data = self::fixture();
        $data['last_error'] = ['code' => 'E_CLI_FAILED', 'at' => 1, 'message_short' => 'x'];

        $manifest = Manifest::fromArray($data)->withLastError(null);

        self::assertNull($manifest->lastError());
    }

    /** @test */
    public function withLastErrorRejectsAMalformedError(): void
    {
        $manifest = Manifest::fromArray(self::fixture());

        $this->expectException(ManifestCorruptException::class);

        $manifest->withLastError(['code' => 'E_CLI_FAILED']);
    }

    // ----------------------------------------------------------------- loading

    /**
     * A cold cache is the normal first request, not a corruption — so no
     * diagnostic is produced. Reporting one would put a warning in the log of
     * every freshly deployed site.
     *
     * @test
     */
    public function loadReturnsNullWithNoDiagnosticWhenThereIsNoManifest(): void
    {
        $diagnostic = null;

        $result = Manifest::load('cache/app.meta.json', self::filesystem(null, ''), 1024, $diagnostic);

        self::assertNull($result);
        self::assertNull($diagnostic, 'A missing manifest must not be reported as corrupt.');
    }

    /** @test */
    public function loadReadsAValidManifest(): void
    {
        $bytes = Json::encode(self::fixture());
        $diagnostic = null;

        $result = Manifest::load(
            'cache/app.meta.json',
            self::filesystem(\strlen($bytes), $bytes),
            1048576,
            $diagnostic
        );

        self::assertInstanceOf(Manifest::class, $result);
        self::assertNull($diagnostic);
        self::assertSame(self::fixture(), $result->toArray());
    }

    /**
     * @test
     *
     * @dataProvider unusableManifests
     */
    public function loadReportsAnUnusableManifestAsCorrupt(?int $statSize, string $body, int $cap): void
    {
        $diagnostic = null;

        $result = Manifest::load(
            'cache/app.meta.json',
            self::filesystem($statSize, $body),
            $cap,
            $diagnostic
        );

        self::assertNull($result);
        self::assertInstanceOf(Diagnostic::class, $diagnostic);
        self::assertSame(Diagnostic::W_MANIFEST_CORRUPT, $diagnostic->code());
        self::assertSame(Diagnostic::WARNING, $diagnostic->level());
    }

    /**
     * @return array<string, array{0: int|null, 1: string, 2: int}>
     */
    public static function unusableManifests(): array
    {
        $valid = Json::encode(self::fixture());
        $invalid = Json::encode(['schema' => 2]);

        return [
            // stat() reports more than the cap, so it is never read at all.
            'larger than the read cap' => [1048577, $valid, 1048576],
            'malformed json' => [\strlen('{ not json'), '{ not json', 1048576],
            'valid json, invalid schema' => [\strlen($invalid), $invalid, 1048576],
            // stat() and strlen() disagree: the file grew or the read clipped.
            'truncated read' => [\strlen($valid) + 100, $valid, 1048576],
            'empty file' => [0, '', 1048576],
            'json array instead of an object' => [2, '[]', 1048576],
        ];
    }

    /**
     * An unreadable file — permissions, or a directory where a file was
     * expected. The filesystem facade throws; `load()` must not.
     *
     * @test
     */
    public function loadReportsAReadFailureAsCorrupt(): void
    {
        $diagnostic = null;

        $filesystem = new class () implements FilesystemInterface {
            public function exists(string $path): bool
            {
                return true;
            }

            public function isFile(string $path): bool
            {
                return true;
            }

            public function isDir(string $path): bool
            {
                return false;
            }

            /**
             * Models an existing but unreadable file, so `read()` is what
             * fails rather than `stat()`. The type stays nullable because
             * return-type covariance is PHP 7.4 and the floor is 7.3 (design
             * doc 14), so the interface's `?array` cannot be narrowed here.
             *
             * @return array{mtime: int, size: int}|null
             */
            public function stat(string $path): ?array
            {
                if ($path === '') {
                    return null;
                }

                return ['mtime' => 1, 'size' => 10];
            }

            public function read(string $path, int $maxBytes): string
            {
                throw new \RuntimeException('Permission denied.');
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
            }

            public function touch(string $path, ?int $mtime = null): void
            {
            }

            public function unlink(string $path): bool
            {
                return true;
            }

            public function clearStatCache(string $path): void
            {
            }
        };

        $result = Manifest::load('cache/app.meta.json', $filesystem, 1024, $diagnostic);

        self::assertNull($result);
        self::assertInstanceOf(Diagnostic::class, $diagnostic);
    }

    /**
     * The diagnostic names *which* manifest was rejected — a site with several
     * bundles otherwise gets an unattributable warning. It must be the bare
     * file name, never the absolute path: this reaches a log and the debug
     * overlay (threat T-06), and `Diagnostic` refuses anything that is not a
     * clean relative path.
     *
     * @test
     */
    public function theCorruptDiagnosticNamesTheManifestWithoutLeakingThePath(): void
    {
        $diagnostic = null;

        Manifest::load('/srv/www/releases/17/cache/app.meta.json', self::filesystem(5, 'nope!'), 1024, $diagnostic);

        self::assertInstanceOf(Diagnostic::class, $diagnostic);
        self::assertSame('app.meta.json', $diagnostic->file());
        self::assertStringNotContainsString('/srv', (string) $diagnostic->file());
        self::assertStringNotContainsString('/', $diagnostic->message());
    }

    /**
     * Totality, stated as a property: whatever the bytes, the answer is a
     * `Manifest` or `null`. The seed is fixed so a failure reproduces.
     *
     * @test
     */
    public function loadNeverThrowsWhateverTheBytes(): void
    {
        \mt_srand(20260903);

        $alphabet = "{}[]\":,0123456789abcdef.\\/ \n\t-_";
        $rejected = 0;
        $parsed = 0;

        for ($i = 0; $i < 200; ++$i) {
            $body = '';
            $result = null;

            for ($n = \mt_rand(0, 60); $n > 0; --$n) {
                $body .= $alphabet[\mt_rand(0, \strlen($alphabet) - 1)];
            }

            $diagnostic = null;

            try {
                $result = Manifest::load(
                    'cache/app.meta.json',
                    self::filesystem(\strlen($body), $body),
                    1048576,
                    $diagnostic
                );
            } catch (\Throwable $e) {
                self::fail(\sprintf(
                    'load() threw %s on iteration %d for %s: %s',
                    \get_class($e),
                    $i,
                    \json_encode($body),
                    $e->getMessage()
                ));
            }

            if ($result === null) {
                ++$rejected;
            } else {
                ++$parsed;
            }
        }

        // Every iteration returned rather than threw — a throw would have hit
        // the fail() above. Counting both outcomes keeps the assertion honest
        // instead of restating load()'s return type back at itself.
        self::assertSame(200, $rejected + $parsed);
    }

    // ------------------------------------------------------------------ saving

    /** @test */
    public function saveWritesTheDocumentAndItReadsBackIdentically(): void
    {
        $root = TempTree::make('tailwindphp-manifest');
        $this->tempDirs[] = $root;

        $manifest = Manifest::fromArray(self::fixture());
        $path = $root . '/app.meta.json';

        $manifest->save($path, new AtomicWriter($root));

        self::assertTrue(\is_file($path));
        self::assertSame($manifest->toArray(), Json::decode((string) \file_get_contents($path), 16));
    }

    /**
     * Saved through `AtomicWriter`, so nothing is left behind and a reader never
     * sees a half-written manifest (threat T-13).
     *
     * @test
     */
    public function saveLeavesNoTemporaryFile(): void
    {
        $root = TempTree::make('tailwindphp-manifest');
        $this->tempDirs[] = $root;

        $manifest = Manifest::fromArray(self::fixture());
        $path = $root . '/app.meta.json';

        $manifest->save($path, new AtomicWriter($root));
        $manifest->withLastError(null)->save($path, new AtomicWriter($root));

        $names = \scandir($root);

        self::assertNotFalse($names);
        self::assertSame(['.', '..', 'app.meta.json'], $names);
    }

    /**
     * A filesystem returning one fixed body, so `load()` can be driven without
     * touching the disk. Anonymous because one class per file is the house rule
     * (design doc 14), and hand-written because the suite has to behave the same
     * on PHPUnit 9 and 11 (design doc 16).
     *
     * @param int|null $statSize Size `stat()` reports; null means "no such file".
     */
    private static function filesystem(?int $statSize, string $body): FilesystemInterface
    {
        return new class ($statSize, $body) implements FilesystemInterface {
            /**
             * @var int|null
             */
            private $statSize;

            /**
             * @var string
             */
            private $body;

            public function __construct(?int $statSize, string $body)
            {
                $this->statSize = $statSize;
                $this->body = $body;
            }

            public function exists(string $path): bool
            {
                return $this->statSize !== null;
            }

            public function isFile(string $path): bool
            {
                return $this->statSize !== null;
            }

            public function isDir(string $path): bool
            {
                return false;
            }

            /**
             * @return array{mtime: int, size: int}|null
             */
            public function stat(string $path): ?array
            {
                if ($this->statSize === null) {
                    return null;
                }

                return ['mtime' => 1756857600, 'size' => $this->statSize];
            }

            public function read(string $path, int $maxBytes): string
            {
                // Truncating like the real facade does, so the "clipped read"
                // case is reproduced rather than described.
                return \substr($this->body, 0, $maxBytes);
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
            }

            public function touch(string $path, ?int $mtime = null): void
            {
            }

            public function unlink(string $path): bool
            {
                return true;
            }

            public function clearStatCache(string $path): void
            {
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return [
            'schema' => 1,
            'package_version' => '0.1.0-dev',
            'config_hash' => 'sha256:' . \str_repeat('a', 64),
            'bundle' => 'app',
            'driver' => 'php',
            'driver_version' => '0.1.0',
            'driver_probe' => null,
            'built_at' => 1756857600,
            'build_ms' => 42.5,
            'content_hash' => 'sha256:' . \str_repeat('b', 64),
            'fingerprint' => '0123456789ab',
            'minified' => true,
            'candidates_hash' => 'sha256:' . \str_repeat('c', 64),
            'candidates_count' => 3,
            'input' => 'resources/css/app.css',
            'imports' => ['resources/css/components.css'],
            'roots' => ['input' => ['resources/css'], 'watch' => ['config'], 'sources' => ['templates']],
            'files' => [
                'resources/css/app.css' => ['k' => 'i', 'm' => 1756857000, 's' => 128],
                'templates/home.twig' => ['k' => 's', 'm' => 1756857100, 's' => 512, 'c' => ['flex', 'p-4']],
            ],
            'safelist_hash' => 'sha256:' . \str_repeat('d', 64),
            'inline_sources' => ['mt-2'],
            'warnings' => [self::warningRow()],
            'last_error' => null,
        ];
    }

    /**
     * A `warnings[]` row is exactly `Diagnostic::toArray()` — the six keys of
     * decision CCP-A25.
     *
     * @return array<string, mixed>
     */
    private static function warningRow(): array
    {
        return [
            'level' => 'warning',
            'code' => 'W_UNKNOWN_UTILITY',
            'message' => 'Unknown utility.',
            'file' => 'templates/home.twig',
            'line' => 12,
            'candidate' => 'bg-nope-500',
        ];
    }
}
