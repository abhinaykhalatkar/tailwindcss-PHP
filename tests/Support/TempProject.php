<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

/**
 * A throwaway project on disk: the fixture the integration suite drives
 * (design doc 16 §Integration test conventions).
 *
 * It exists because the interesting behaviour of this library is *temporal* —
 * a file changed, a manifest went stale, an artefact was pruned — and none of
 * that can be exercised against a fixture directory committed to the repo:
 * committed mtimes are whatever the last checkout made them, and a test that
 * rewrites files under `tests/` leaves the working tree dirty. So every test
 * gets its own tree under the system temp directory, mutates it freely, and
 * throws it away.
 *
 * The layout is the smallest thing that is still a *project*: an entry
 * stylesheet that imports Tailwind, one template carrying utility classes for
 * the scanner to find, an empty cache directory, and a config file that returns
 * the array it was constructed with. Nothing here is Tailwind-specific beyond
 * those two files — the point is a realistic set of paths, not realistic CSS.
 *
 * mtimes are set explicitly through {@see self::touchFile()} rather than by
 * letting the clock supply them: filesystem mtime granularity is one second on
 * several of the platforms in the CI matrix, so a test that writes twice in the
 * same second and expects "changed" is a coin flip. Offsets are relative to now
 * so a test can put a file deliberately in the past and then bump it forward.
 *
 * Removal is delegated to {@see TempTree}, which knows about the Windows
 * junction traps; do not reimplement it here.
 *
 * **Not yet present:** `request($route, $headers = [])` returning a `Response`.
 * The HTTP layer it would call into arrives in wave 3, and a helper that cannot
 * be exercised is a helper that rots, so it is added with the tests that need
 * it.
 */
final class TempProject
{
    /**
     * The entry stylesheet the bundles in the tests point at.
     */
    private const ENTRY = 'assets/styles/main.css';

    /**
     * One template, holding a handful of utilities so the scanner has something
     * to find without the corpus growing a personality of its own.
     */
    private const TEMPLATE = 'templates/index.twig';

    /**
     * Where the pipeline writes artefacts and manifests. Created empty: "cold
     * cache" is the state most tests start from, and a missing directory is a
     * different scenario from an empty one.
     */
    private const CACHE_DIR = 'cache/tailwind';

    /**
     * @var string Absolute, forward slashes, no trailing separator.
     */
    private $root;

    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @param array<string, mixed> $config Written verbatim to
     *                                     `config/tailwind.php`; the file
     *                                     returns exactly this array.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->root = TempTree::make('tailwindphp-project');

        // Backup for a fatal that skips tearDown(); remove() is idempotent, so
        // the ordinary path still cleans up immediately.
        TempTree::registerShutdownCleanup($this->root);

        $this->build();
    }

    /**
     * Named constructor, for tests that read better without `new`.
     *
     * @param array<string, mixed> $config
     */
    public static function make(array $config = []): self
    {
        return new self($config);
    }

    /**
     * @return string Absolute path of the project root, forward slashes.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * The array this project was built from — the same one `config/tailwind.php`
     * returns, so a test can assert against it without re-reading the file.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Stamp a file's mtime relative to now, creating it when absent.
     *
     * @param string $rel Project-relative path, forward slashes.
     * @param int $mtimeOffset Seconds; negative puts the file in the past.
     */
    public function touchFile(string $rel, int $mtimeOffset): void
    {
        $path = $this->path($rel);

        if (!\is_file($path)) {
            // touch() would create it, but only if the parent exists; writeFile
            // makes the intermediate directories on the way.
            $this->writeFile($rel, '');
        }

        \touch($path, \time() + $mtimeOffset);

        // One test can stat a file, restamp it, and stat it again; without this
        // the second answer is the first one (design doc 05 §Layer 1).
        \clearstatcache(true, $path);
    }

    /**
     * Write a file, creating any missing intermediate directories.
     *
     * @param string $rel Project-relative path, forward slashes.
     */
    public function writeFile(string $rel, string $contents): void
    {
        $path = $this->path($rel);
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }

        \file_put_contents($path, $contents);
        \clearstatcache(true, $path);
    }

    /**
     * Remove a file. A file that is already gone is not an error — several
     * tests delete the same path twice by way of a shared setup step.
     *
     * @param string $rel Project-relative path, forward slashes.
     */
    public function deleteFile(string $rel): void
    {
        $path = $this->path($rel);

        if (\is_file($path)) {
            \unlink($path);
        }

        \clearstatcache(true, $path);
    }

    /**
     * Where the manifest for $bundle lives, whether or not it exists yet.
     */
    public function manifestPath(string $bundle): string
    {
        return $this->root . '/' . self::CACHE_DIR . '/' . $bundle . '.meta.json';
    }

    /**
     * The manifest as decoded JSON.
     *
     * @return array<mixed>|null Null when the manifest is absent, unreadable or
     *                           not valid JSON — all three are states a test
     *                           deliberately creates (cold cache, corrupt
     *                           manifest), so they are reported rather than
     *                           thrown.
     */
    public function manifest(string $bundle): ?array
    {
        $path = $this->manifestPath($bundle);

        if (!\is_file($path)) {
            return null;
        }

        $raw = \file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Delete the whole tree. Safe to call twice.
     */
    public function remove(): void
    {
        TempTree::remove($this->root);
    }

    /**
     * Lay down the initial project. Kept in one place so every test starts from
     * the same shape and a test that needs something else says so explicitly.
     */
    private function build(): void
    {
        $this->writeFile(self::ENTRY, "@import \"tailwindcss\";\n");

        $this->writeFile(
            self::TEMPLATE,
            "<div class=\"flex p-4 text-sm\">\n"
            . "    <span class=\"font-bold text-gray-700\">hello</span>\n"
            . "</div>\n"
        );

        $cacheDir = $this->path(self::CACHE_DIR);

        if (!\is_dir($cacheDir)) {
            \mkdir($cacheDir, 0777, true);
        }

        $this->writeConfig();
    }

    /**
     * Write `config/tailwind.php` as a file that *returns* the config array.
     *
     * var_export() rather than JSON because that is the shape the library
     * loads: a PHP file returning an array, so the fixture exercises the real
     * loader instead of a test-only format.
     */
    private function writeConfig(): void
    {
        $php = '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'return ' . \var_export($this->config, true) . ';' . "\n";

        $this->writeFile('config/tailwind.php', $php);
    }

    /**
     * Project-relative to absolute. Leading slashes and Windows separators are
     * tolerated so a test may write the path however it reads best.
     */
    private function path(string $rel): string
    {
        $normalized = \ltrim(\str_replace('\\', '/', $rel), '/');

        return $this->root . '/' . $normalized;
    }
}
