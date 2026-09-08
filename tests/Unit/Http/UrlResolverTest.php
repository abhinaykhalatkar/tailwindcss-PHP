<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Config\Config;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Http\UrlResolver;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\RecordingFilesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The `<link href>` helper (TASK-0016 acceptance 7; doc 10 §URL helper,
 * FR-HTTP-8, ADR-004, decision HBC-A15).
 *
 * The property that matters most here is a **cost** rather than a value: this
 * runs while a page is being rendered, so it must read the manifest and stop.
 * A freshness check here would put the CSS pipeline on the critical path of
 * every HTML response in the application, so `reads() === 1` is asserted as
 * firmly as the string itself.
 *
 * @covers-requirement FR-HTTP-8
 */
final class UrlResolverTest extends TestCase
{
    private const FINGERPRINT = '0123456789ab';

    /**
     * @var string|null
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = TempTree::make('tailwindphp-url');
        $root = (string) $this->root;

        \mkdir($root . '/assets/styles', 0777, true);
        \mkdir($root . '/templates', 0777, true);
        \mkdir($root . '/cache/tailwind', 0777, true);
        \file_put_contents($root . '/assets/styles/main.css', "@import \"tailwindcss\";\n");
        \file_put_contents($root . '/templates/index.twig', "<div class=\"flex\"></div>\n");
    }

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            TempTree::remove($this->root);
            $this->root = null;
        }
    }

    /**
     * @test
     */
    public function theManifestFingerprintIsInsertedIntoTheRoute(): void
    {
        $this->writeManifest();

        self::assertSame('assets/styles.' . self::FINGERPRINT . '.css', $this->resolver()->url('app'));
    }

    /**
     * @dataProvider prefixes
     *
     * @test
     */
    public function thePrefixIsPassedThroughUntouched(string $prefix, string $expected): void
    {
        $this->writeManifest();

        self::assertSame($expected, $this->resolver()->url('app', $prefix));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function prefixes(): array
    {
        return [
            'none' => ['', 'assets/styles.' . self::FINGERPRINT . '.css'],
            'root' => ['/', '/assets/styles.' . self::FINGERPRINT . '.css'],
            'a sub-directory' => ['/app/', '/app/assets/styles.' . self::FINGERPRINT . '.css'],
            'an absolute origin' => [
                'https://cdn.test/',
                'https://cdn.test/assets/styles.' . self::FINGERPRINT . '.css',
            ],
        ];
    }

    /**
     * Doc 10: before the first build there is no fingerprint to name, and the
     * plain route is the right answer because requesting it is what triggers
     * the build.
     *
     * @test
     */
    public function withoutAManifestThePlainRouteIsReturned(): void
    {
        self::assertSame('assets/styles.css', $this->resolver()->url('app'));
    }

    /**
     * @test
     */
    public function aCorruptManifestFallsBackToThePlainRoute(): void
    {
        \file_put_contents($this->manifestPath(), '{ not json');

        self::assertSame('assets/styles.css', $this->resolver()->url('app'));
    }

    /**
     * HBC-A15: the switch affects this helper and nothing else — the artefacts
     * on disk keep their fingerprints, the pages just stop linking to them.
     *
     * @test
     */
    public function fingerprintingCanBeSwitchedOff(): void
    {
        $this->writeManifest();

        self::assertSame('assets/styles.css', $this->resolver(['fingerprint' => false])->url('app'));
    }

    /**
     * With fingerprinting off there is nothing to read, so nothing is read.
     *
     * @test
     */
    public function switchingItOffCostsNoIo(): void
    {
        $this->writeManifest();

        $fs = new RecordingFilesystem(new Filesystem());
        $this->resolver(['fingerprint' => false], $fs)->url('app');

        self::assertSame([], $fs->calls());
    }

    /**
     * The cost half of doc 10 §URL helper: one manifest read, and no stat of
     * any template.
     *
     * @test
     */
    public function itCostsOneManifestReadAndNoFreshnessCheck(): void
    {
        $this->writeManifest();

        $fs = new RecordingFilesystem(new Filesystem());
        $this->resolver([], $fs)->url('app');

        self::assertSame(1, $fs->reads(), 'more than the manifest was read');
        self::assertSame(1, $fs->stats(), 'the manifest stat is the only one permitted');
    }

    /**
     * Invariant I5, at this end of the pair: the fingerprint the page links to
     * is the manifest's, and the responder builds its `ETag` from the same
     * field, so the URL and the validator cannot disagree.
     *
     * @test
     */
    public function theLinkedFingerprintIsTheManifestFingerprint(): void
    {
        $this->writeManifest('beefbeefbeef');

        self::assertSame('assets/styles.beefbeefbeef.css', $this->resolver()->url('app'));
    }

    /**
     * A manifest is our own file, so a malformed fingerprint in one is close to
     * impossible — but the value goes into an HTML attribute, and "close to
     * impossible" is not the standard for that.
     *
     * @dataProvider malformedFingerprints
     *
     * @test
     */
    public function aMalformedFingerprintIsNotLinkedTo(string $fingerprint): void
    {
        $this->writeManifest($fingerprint);

        self::assertSame('assets/styles.css', $this->resolver()->url('app'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFingerprints(): array
    {
        return [
            'a quote' => ['0123456"89ab'],
            'a path separator' => ['0123456/89ab'],
            'a newline' => ["0123456789ab\n"],
            'uppercase' => ['0123456789AB'],
            'too short' => ['0123abc'],
        ];
    }

    /**
     * @test
     */
    public function anUnknownBundleIsARefusalRatherThanAGuess(): void
    {
        $this->expectException(\TailwindPhp\Exception\ConfigurationException::class);

        $this->resolver()->url('nope');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolver(array $overrides = [], ?\TailwindPhp\Support\FilesystemInterface $fs = null): UrlResolver
    {
        $config = $this->config($overrides);
        $fs = $fs === null ? new Filesystem() : $fs;

        $store = new CacheStore(
            $config->cacheDir(),
            $config->allowedRoots(),
            $config->projectRoot(),
            $config->cacheDirMode(),
            $config->cacheFileMode(),
            $config->gzip(),
            $fs,
            new AtomicWriter($config->projectRoot())
        );

        return new UrlResolver($config, $store, $fs);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = []): Config
    {
        return ConfigBuilder::fromArray($overrides + [
            'project_root' => (string) $this->root,
            'driver' => 'php',
            'input' => 'assets/styles/main.css',
            'sources' => ['templates'],
            'cache_dir' => 'cache/tailwind',
            'bundles' => ['app' => []],
        ]);
    }

    /**
     * Reuses the committed valid-manifest fixture rather than hand-rolling
     * another one, so a schema change breaks one file and not two.
     */
    private function writeManifest(string $fingerprint = self::FINGERPRINT): void
    {
        $json = (string) \file_get_contents(__DIR__ . '/../../Fixtures/manifests/valid.json');
        $data = \json_decode($json, true);
        $data['fingerprint'] = $fingerprint;

        \file_put_contents($this->manifestPath(), (string) \json_encode($data));
    }

    private function manifestPath(): string
    {
        return $this->root . '/cache/tailwind/app.meta.json';
    }
}
