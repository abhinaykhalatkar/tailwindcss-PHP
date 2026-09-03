<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

use Psr\Log\LoggerInterface;
use TailwindPhp\Exception\CacheException;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\ClockInterface;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Path;
use TailwindPhp\Support\Regex;
use TailwindPhp\Support\SystemClock;

/**
 * Everything the library keeps on disk, and the only place that decides what a
 * cache file is called (design doc 05 §Artefacts, doc 04 §4.9, FR-CACHE-6/7/8).
 *
 * Centralising the names is a security control, not tidiness. A filename here
 * is assembled **only** from two validated tokens — a bundle matching
 * `^[a-z0-9_-]{1,40}$` and a fingerprint matching `^[a-f0-9]{8,16}$` — plus a
 * literal suffix. Nothing else from a request, a config file or a manifest ever
 * reaches the filesystem as part of a path, so there is no input that can make
 * this write outside the cache directory (threats T-02, T-13). Every path is
 * additionally run through {@see Path::confine()} before it is returned: the
 * tokens make an escape impossible and the confinement makes it impossible
 * *twice*, which is the right ratio for the one component that writes files.
 *
 * The artefact set per bundle `<b>` is closed (NFR-SEC-4, ADR-003):
 *
 * | `<b>.meta.json`       | the build manifest                              |
 * | `<b>.<fp>.css`        | the built stylesheet — what `path()` serves     |
 * | `<b>.<fp>.css.gz`     | its pre-compressed twin                         |
 * | `<b>.css` / `.css.gz` | copies under a stable name, for web-server rules |
 * | `<b>.lock`            | 0 bytes, `flock` target, never deleted          |
 * | `<b>.checked`         | 0 bytes, `check_interval` stamp                 |
 * | `.htaccess`, `index.html` | directory guards                            |
 *
 * `clear()` never removes the last three rows: deleting the lock is how two
 * processes end up locking different inodes with the same name, and a cache
 * directory without its guards is exposed until the next build (T-07).
 *
 * Directory listing uses `scandir()` directly: `Support\Filesystem` has no
 * iteration API by design, and `prune()`/`clear()` are the only callers that
 * need one.
 */
final class CacheStore
{
    /**
     * The `D` modifier is required, not stylistic: PCRE's `$` otherwise matches
     * immediately before a final newline, so `"app\n"` would be accepted as a
     * bundle name and travel into a filename.
     */
    private const BUNDLE_PATTERN = '#^[a-z0-9_-]{1,40}$#D';

    private const HTACCESS_RESOURCE = '/../../resources/cache-htaccess';

    /**
     * Names permitted inside a `.cli-<pid>-<8hex>` temp directory (decision
     * CCP-A2). `clear()` removes only these, so a directory holding anything
     * else is left alone rather than emptied.
     */
    private const CLI_TEMP_NAMES = ['input.css', 'candidates.txt', 'output.css'];

    /**
     * How old a leftover temp file must be before `prune()` removes it
     * (decision CCP-A28). Generously above any plausible write, because
     * removing one mid-write would break the write that owns it.
     */
    private const STALE_TEMP_SECONDS = 3600;

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var list<string>
     */
    private $allowedRoots;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var int
     */
    private $dirMode;

    /**
     * @var int
     */
    private $fileMode;

    /**
     * @var bool
     */
    private $gzip;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var AtomicWriter
     */
    private $writer;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var bool
     */
    private $reportedStaleCopy = false;

    /**
     * No I/O here (design doc 18): the warm request path constructs this object
     * and may never touch the disk beyond reading one manifest.
     *
     * @param list<string> $allowedRoots Absolute confinement roots (decision
     *                                   CCP-A27). `Config::allowedRoots()`
     *                                   already includes the project root.
     *                                   Passing the cache directory itself here
     *                                   would make the T-14 check vacuous — the
     *                                   escape target is then always "inside"
     *                                   the resolved cache directory.
     * @param string $projectRoot Used **only** to keep paths in messages
     *                            project-relative (ADR-014, T-06).
     */
    public function __construct(
        string $cacheDir,
        array $allowedRoots,
        string $projectRoot,
        int $dirMode,
        int $fileMode,
        bool $gzip,
        FilesystemInterface $fs,
        AtomicWriter $writer,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null
    ) {
        // Listing the cache directory among its own allowed roots re-creates the
        // exact bug CCP-A27 fixed: `isSymlinkEscape()` resolves its roots, so a
        // junctioned cache directory would resolve "inside" itself and the T-14
        // check would pass for the one case it exists to catch. Refused here,
        // where it is a programming error, rather than silently disarming the
        // guard at run time.
        $normalizedCacheDir = self::comparableRoot($cacheDir);

        foreach ($allowedRoots as $root) {
            if (\is_string($root) && self::comparableRoot($root) === $normalizedCacheDir) {
                throw new CacheException(
                    'The cache directory may not be listed among its own allowed roots: '
                    . 'that would make the symlink-escape check vacuous.'
                );
            }
        }

        $this->cacheDir = $cacheDir;
        $this->allowedRoots = \array_values($allowedRoots);
        $this->projectRoot = $projectRoot;
        $this->dirMode = $dirMode;
        $this->fileMode = $fileMode;
        $this->gzip = $gzip;
        $this->fs = $fs;
        $this->writer = $writer;
        $this->logger = $logger;
        $this->clock = $clock ?? new SystemClock();
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    public function gzipEnabled(): bool
    {
        return $this->gzip;
    }

    /**
     * @throws PathException When $bundle is not a valid token.
     */
    public function manifestPath(string $bundle): string
    {
        return $this->path($this->requireBundle($bundle) . '.meta.json');
    }

    /**
     * The stable copy. Serving happens from the fingerprinted file (CCP-A17);
     * this exists so a web server can be pointed at one unchanging name.
     *
     * @throws PathException When $bundle is not a valid token.
     */
    public function cssPath(string $bundle): string
    {
        return $this->path($this->requireBundle($bundle) . '.css');
    }

    /**
     * @throws PathException When $bundle is not a valid token.
     */
    public function gzPath(string $bundle): string
    {
        return $this->path($this->requireBundle($bundle) . '.css.gz');
    }

    /**
     * @throws PathException When either token is invalid.
     */
    public function fingerprintedCssPath(string $bundle, string $fingerprint): string
    {
        return $this->path($this->requireBundle($bundle) . '.' . $this->requireFingerprint($fingerprint) . '.css');
    }

    /**
     * @throws PathException When either token is invalid.
     */
    public function fingerprintedGzPath(string $bundle, string $fingerprint): string
    {
        return $this->fingerprintedCssPath($bundle, $fingerprint) . '.gz';
    }

    /**
     * @throws PathException When $bundle is not a valid token.
     */
    public function lockPath(string $bundle): string
    {
        return $this->path($this->requireBundle($bundle) . '.lock');
    }

    /**
     * The `check_interval` stamp: a 0-byte file whose mtime says when this
     * bundle was last checked for staleness (design doc 05 §Layer 0).
     *
     * @throws PathException When $bundle is not a valid token.
     */
    public function stampPath(string $bundle): string
    {
        return $this->path($this->requireBundle($bundle) . '.checked');
    }

    /**
     * Create the cache directory and its guards.
     *
     * Idempotent and race-tolerant: two workers arriving together must both
     * succeed, so an existing directory is not an error. The guards are written
     * only when missing — an operator who has customised `.htaccess` keeps their
     * version.
     *
     * @throws CacheException When the directory cannot be created or escapes.
     */
    public function ensureDirectory(): void
    {
        // T-14, before anything is created. The roots come from the caller and
        // deliberately exclude the cache directory itself: passing it made the
        // predicate vacuous, because `isSymlinkEscape()` resolves its roots, so
        // a junctioned cache dir always landed "inside" its own resolved self
        // and every guard and artefact was written outside the project
        // (decision CCP-A27).
        if (Path::isSymlinkEscape($this->cacheDir, $this->allowedRoots)) {
            throw new CacheException('The cache directory resolves outside the permitted roots.');
        }

        if (!$this->fs->isDir($this->cacheDir)) {
            try {
                $this->fs->mkdir($this->cacheDir, $this->dirMode);
            } catch (\RuntimeException $e) {
                // The facade reports an absolute path; that must not reach a log
                // (T-06) and the docblock promises CacheException.
                $relative = $this->relative($this->cacheDir);

                throw new CacheException(
                    \sprintf('The cache directory "%s" could not be created.', $relative),
                    [],
                    self::scrubbedCause('mkdir', $relative)
                );
            }
        }

        $this->writeGuardIfMissing($this->path('.htaccess'), $this->htaccessContents());
        $this->writeGuardIfMissing($this->path('index.html'), '');
    }

    /**
     * Step ① and ③ of the build write order (design doc 05 §1.4): the
     * fingerprinted artefact first, then the stable copy. That order matters —
     * a reader following the *previous* manifest must never find the plain file
     * updated while its fingerprinted target is still missing.
     *
     * @throws \TailwindPhp\Exception\CacheWriteException
     * @throws PathException
     */
    public function writeCss(string $bundle, string $fingerprint, string $css): void
    {
        $this->writeArtefact($this->fingerprintedCssPath($bundle, $fingerprint), $css);
        $this->writeStableCopy($this->cssPath($bundle), $css);
    }

    /**
     * Step ② and ③. A no-op when `gzip` is off, so callers do not have to ask.
     *
     * @throws \TailwindPhp\Exception\CacheWriteException
     * @throws PathException
     */
    public function writeGz(string $bundle, string $fingerprint, string $gzBytes): void
    {
        if (!$this->gzip) {
            return;
        }

        $this->writeArtefact($this->fingerprintedGzPath($bundle, $fingerprint), $gzBytes);
        $this->writeStableCopy($this->gzPath($bundle), $gzBytes);
    }

    /**
     * A fingerprinted artefact. Failure here is fatal: this is the file the
     * manifest will name and the pipeline will serve (CCP-A17).
     *
     * Skipped when the file already there has the same **truncated content
     * hash** (it is in the name) **and** the same byte length. That pair is not
     * a proof of identical bytes, but the bound is comfortable: the fingerprint
     * is 8–16 hex characters, so a collision needs a second CSS document whose
     * SHA-256 shares the first 32–64 bits *and* whose length matches exactly.
     * Against that, rewriting the file on every build churns an artefact another
     * request may be streaming, for no change (decision CCP-A26).
     *
     * @throws \TailwindPhp\Exception\CacheWriteException
     */
    private function writeArtefact(string $path, string $bytes): void
    {
        $stat = $this->fs->stat($path);

        if ($stat !== null && $stat['size'] === \strlen($bytes)) {
            return;
        }

        $this->writer->write($path, $bytes, $this->fileMode);
    }

    /**
     * A stable convenience copy (`<b>.css`, `<b>.css.gz`).
     *
     * Non-fatal by decision CCP-A26. On Windows a reader holding this file open
     * makes every rename attempt fail, and that must not fail the build: serving
     * uses the fingerprinted artefact, so a stale copy under the stable name
     * costs nothing but a web-server alias pointing at older bytes. Logged once
     * per instance — a busy cold cache would otherwise repeat it per write.
     */
    private function writeStableCopy(string $path, string $bytes): void
    {
        try {
            $this->writer->write($path, $bytes, $this->fileMode);
        } catch (\TailwindPhp\Exception\CacheWriteException $e) {
            if ($this->logger !== null && !$this->reportedStaleCopy) {
                $this->reportedStaleCopy = true;

                $this->logger->warning(\sprintf(
                    'The stable copy "%s" could not be replaced and still holds the previous build; '
                    . 'the fingerprinted artefact is current. This usually means a reader is holding it open.',
                    $this->relative($path)
                ));
            }
        }
    }

    /**
     * Whether the artefact a manifest names is actually there (CCP-A17).
     *
     * @throws PathException When either token is invalid.
     */
    public function has(string $bundle, string $fingerprint): bool
    {
        return $this->fs->isFile($this->fingerprintedCssPath($bundle, $fingerprint));
    }

    /**
     * Move the artefacts' mtime forward without rewriting them — the "touched"
     * outcome of design doc 04 §4.8, where templates changed but the candidate
     * set did not. Only `Last-Modified` needs to move; the bytes are identical.
     *
     * @throws PathException When either token is invalid.
     */
    public function touchCss(string $bundle, string $fingerprint): void
    {
        $paths = [
            $this->fingerprintedCssPath($bundle, $fingerprint),
            $this->cssPath($bundle),
        ];

        if ($this->gzip) {
            $paths[] = $this->fingerprintedGzPath($bundle, $fingerprint);
            $paths[] = $this->gzPath($bundle);
        }

        foreach ($paths as $path) {
            if (!$this->fs->isFile($path)) {
                continue;
            }

            try {
                $this->fs->touch($path);
            } catch (\RuntimeException $e) {
                // The facade reports an absolute path (T-06). A failed touch
                // only means Last-Modified did not move, so the build is still
                // correct — but it is reported, not swallowed.
                $relative = $this->relative($path);

                throw new CacheException(
                    \sprintf('The cache artefact "%s" could not be touched.', $relative),
                    [],
                    self::scrubbedCause('touch', $relative)
                );
            }
        }
    }

    /**
     * Remove a bundle's artefacts (decision HBC-A11).
     *
     * Keeps the directory, the guards and `<b>.lock`; removes the manifest, the
     * stamp, the stable copies, every fingerprinted pair, and any stale
     * `.cli-*` temp directory left by a CLI compile that died before its
     * `finally`.
     *
     * @return int Number of entries removed.
     *
     * @throws PathException When $bundle is not a valid token.
     */
    public function clear(string $bundle): int
    {
        $bundle = $this->requireBundle($bundle);
        $removed = 0;

        $stable = [
            $this->manifestPath($bundle),
            $this->stampPath($bundle),
            $this->cssPath($bundle),
            $this->gzPath($bundle),
        ];

        foreach ($stable as $path) {
            $removed += $this->removeFile($path);
        }

        foreach ($this->fingerprintEntries($bundle) as $fingerprint => $ignored) {
            $removed += $this->removePair($bundle, (string) $fingerprint);
        }

        // Decision CCP-A28: a temp file is left behind whenever a process dies
        // between `fopen('xb')` and the rename. Nothing else ever removes them,
        // so they accumulate under predictable names in a writable directory.
        $removed += $this->removeTemporaryFiles($bundle, null);
        $removed += $this->removeCliTempDirectories();

        return $removed;
    }

    /**
     * Drop old builds, keeping `$keepVersions` in total: the current one plus
     * the `$keepVersions - 1` most recent others by mtime (FR-CACHE-8).
     *
     * Old versions are kept deliberately — a request in flight when a deploy
     * lands is still holding the previous fingerprint in its HTML, and deleting
     * it immediately turns a rolling deploy into a burst of 404s.
     *
     * `.css` and `.css.gz` are removed as a pair, so no request can find a
     * stylesheet whose compressed twin has just vanished (T-13). Fingerprints
     * named by the live manifest are never touched, whatever their mtime.
     *
     * @param list<string> $protectedFingerprints Referenced by a manifest; never removed.
     *
     * @return int Number of files removed.
     *
     * @throws PathException When a token is invalid.
     */
    public function prune(
        string $bundle,
        string $currentFingerprint,
        int $keepVersions,
        array $protectedFingerprints = []
    ): int {
        $bundle = $this->requireBundle($bundle);
        $current = $this->requireFingerprint($currentFingerprint);

        if ($keepVersions < 1) {
            $keepVersions = 1;
        }

        $keep = [$current => true];

        foreach ($protectedFingerprints as $protected) {
            if (Fingerprint::isValid($protected)) {
                $keep[$protected] = true;
            }
        }

        $entries = $this->fingerprintEntries($bundle);
        unset($entries[$current]);

        // Newest first; the tie-break on the fingerprint keeps the outcome
        // deterministic when a build writes several files in the same second.
        \uksort($entries, static function (string $a, string $b) use ($entries): int {
            if ($entries[$a] === $entries[$b]) {
                return \strcmp($a, $b);
            }

            return $entries[$b] <=> $entries[$a];
        });

        $slots = $keepVersions - 1;
        $removed = 0;

        foreach ($entries as $fingerprint => $mtime) {
            $fingerprint = (string) $fingerprint;

            if (isset($keep[$fingerprint])) {
                continue;
            }

            if ($slots > 0) {
                --$slots;

                continue;
            }

            $removed += $this->removePair($bundle, $fingerprint);
        }

        // CCP-A28: a temp file older than an hour cannot belong to a live
        // write, so it is debris from a killed process. The cutoff is generous
        // on purpose — removing one mid-write would break the write that owns it.
        $removed += $this->removeTemporaryFiles($bundle, $this->clock->now() - self::STALE_TEMP_SECONDS);

        return $removed;
    }

    /**
     * Every fingerprint with a `<b>.<fp>.css` **or** an orphaned
     * `<b>.<fp>.css.gz` on disk, mapped to the mtime of whichever exists.
     *
     * Enumerating only `.css` left an orphaned `.gz` behind forever when its
     * plain twin had already gone — HBC-A11 says the gz files go too.
     *
     * @return array<string, int>
     */
    private function fingerprintEntries(string $bundle): array
    {
        $prefix = $bundle . '.';
        $entries = [];

        foreach ($this->scan() as $name) {
            if (\strncmp($name, $prefix, \strlen($prefix)) !== 0) {
                continue;
            }

            $suffix = \substr($name, -7) === '.css.gz' ? '.css.gz' : '.css';

            if (\substr($name, -\strlen($suffix)) !== $suffix) {
                continue;
            }

            $middle = \substr($name, \strlen($prefix), \strlen($name) - \strlen($prefix) - \strlen($suffix));

            // `<b>.css` has an empty middle and `<b>.meta.json` fails the
            // suffix test, so only genuine fingerprints survive this.
            if (!Fingerprint::isValid($middle)) {
                continue;
            }

            if (isset($entries[$middle])) {
                continue;
            }

            $stat = $this->fs->stat($this->confined($name));
            $entries[$middle] = $stat === null ? 0 : $stat['mtime'];
        }

        return $entries;
    }

    /**
     * Sweep `<b>.*.tmp.<pid>.<8hex>` left by a process killed between the
     * exclusive create and the rename (decision CCP-A28).
     *
     * @param int|null $olderThan Only remove files with an mtime at or before
     *                            this; null removes them all.
     */
    private function removeTemporaryFiles(string $bundle, ?int $olderThan): int
    {
        // No quoting needed, and `preg_quote` would break rule R1 anyway: the
        // bundle has already been validated against `^[a-z0-9_-]{1,40}$`, none
        // of which is a metacharacter outside a character class.
        $pattern = '#^' . $bundle . '\.[^/\\\\]*\.tmp\.[0-9]{1,10}\.[a-f0-9]{8}$#D';
        $removed = 0;

        foreach ($this->scan() as $name) {
            if (!Regex::match($pattern, $name)) {
                continue;
            }

            $path = $this->confined($name);

            if ($olderThan !== null) {
                $stat = $this->fs->stat($path);

                if ($stat === null || $stat['mtime'] > $olderThan) {
                    continue;
                }
            }

            $removed += $this->removeFile($path);
        }

        return $removed;
    }

    /**
     * Remove `<b>.<fp>.css` and `<b>.<fp>.css.gz` together.
     */
    private function removePair(string $bundle, string $fingerprint): int
    {
        $removed = 0;

        // The compressed file goes first: a window with a `.css` and no `.gz`
        // degrades to serving uncompressed, while the reverse would serve a
        // gzip body whose plain original no longer exists.
        $pair = [
            $this->fingerprintedGzPath($bundle, $fingerprint),
            $this->fingerprintedCssPath($bundle, $fingerprint),
        ];

        foreach ($pair as $path) {
            $removed += $this->removeFile($path);
        }

        return $removed;
    }

    /**
     * Unlink one file, reporting how many went.
     *
     * The facade throws with an **absolute** path, which must not reach a log
     * (T-06). A file that has already gone is success, not a failure — a
     * concurrent sweep winning the race is the normal case here.
     */
    private function removeFile(string $path): int
    {
        if (!$this->fs->isFile($path)) {
            return 0;
        }

        try {
            return $this->fs->unlink($path) ? 1 : 0;
        } catch (\RuntimeException $e) {
            $relative = $this->relative($path);

            throw new CacheException(
                \sprintf('The cache artefact "%s" could not be removed.', $relative),
                [],
                self::scrubbedCause('unlink', $relative)
            );
        }
    }

    /**
     * Sweep `.cli-<pid>-<8hex>` directories left behind by a CLI compile that
     * was killed before its `finally` ran (decision CCP-A2/HBC-A11).
     */
    private function removeCliTempDirectories(): int
    {
        $removed = 0;

        foreach ($this->scan() as $name) {
            if (!Regex::match('#^\.cli-[0-9]{1,10}-[a-f0-9]{8}$#D', $name)) {
                continue;
            }

            $dir = $this->confined($name);

            // `is_link()` is **false** for a Windows junction, so it was no
            // guard at all: a junction wearing a temp directory's name led the
            // sweep straight out of the cache directory and deleted the target's
            // files. This is the same control the guards use (T-14).
            if (Path::isSymlinkEscape($dir, [$this->cacheDir]) || !$this->fs->isDir($dir)) {
                continue;
            }

            if ($this->removeCliTempDirectory($dir)) {
                ++$removed;
            }
        }

        return $removed;
    }

    private function removeCliTempDirectory(string $dir): bool
    {
        foreach ($this->scanDirectory($dir) as $name) {
            if (!\in_array($name, self::CLI_TEMP_NAMES, true)) {
                // Something we did not put there. Leave the whole directory
                // alone rather than delete around it.
                return false;
            }
        }

        foreach (self::CLI_TEMP_NAMES as $name) {
            $path = $dir . '/' . $name;

            // Confined a second time: this path is one level below the cache
            // directory, and the docblock promises every path we touch is
            // checked, not merely assembled from safe pieces.
            try {
                $this->removeFile(Path::confine($path, $this->cacheDir));
            } catch (PathException $e) {
                return false;
            }
        }

        // Suppressed and immediately checked: a concurrent sweep may have won,
        // and losing that race is success, not an error (design doc 18).
        return @\rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private function scan(): array
    {
        return $this->scanDirectory($this->cacheDir);
    }

    /**
     * `scandir()` is called natively: the filesystem facade has no iteration
     * API, because `prune()` and `clear()` are its only consumers.
     *
     * @return list<string>
     */
    private function scanDirectory(string $dir): array
    {
        // Suppressed and immediately checked: an absent or unreadable cache
        // directory means "nothing to sweep", which is a normal cold state.
        $names = @\scandir($dir);

        if ($names === false) {
            return [];
        }

        $result = [];

        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $result[] = $name;
            }
        }

        return $result;
    }

    private function writeGuardIfMissing(string $path, string $contents): void
    {
        if ($this->fs->exists($path)) {
            return;
        }

        if (Path::isSymlinkEscape($path, [$this->cacheDir])) {
            // A link planted at the guard's name; writing through it would put
            // our bytes wherever it points (T-14).
            throw new CacheException('A cache guard file resolves outside the cache directory.');
        }

        $this->writer->write($path, $contents, $this->fileMode);
    }

    private function htaccessContents(): string
    {
        $resource = __DIR__ . self::HTACCESS_RESOURCE;

        // Shipped with the package and read with a generous cap; if it is
        // missing from a broken install, a minimal deny is better than none.
        try {
            return $this->fs->read($resource, 65536);
        } catch (\RuntimeException $e) {
            if ($this->logger !== null) {
                $this->logger->warning('The bundled cache-htaccess resource is missing; writing a minimal one.');
            }

            return "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    <IfModule mod_access_compat.c>\n"
                . "        Order allow,deny\n        Deny from all\n    </IfModule>\n</IfModule>\n";
        }
    }

    /**
     * @throws PathException
     */
    private function requireBundle(string $bundle): string
    {
        if (!Regex::match(self::BUNDLE_PATTERN, $bundle)) {
            // The value is not echoed: it is attacker-controlled on the request
            // path and would travel into a log (T-06).
            throw new PathException('Bundle name contains characters that are not permitted.');
        }

        return $bundle;
    }

    /**
     * @throws PathException
     */
    private function requireFingerprint(string $fingerprint): string
    {
        if (!Fingerprint::isValid($fingerprint)) {
            throw new PathException('Fingerprint is not a valid cache token.');
        }

        return $fingerprint;
    }

    /**
     * Join a validated leaf onto the cache directory and confine the result.
     *
     * The tokens already make an escape unreachable; this is the second,
     * structural check, so a future caller that forgets to validate still
     * cannot write outside the cache directory.
     *
     * @throws PathException
     */
    private function path(string $leaf): string
    {
        return Path::confine($this->cacheDir . '/' . $leaf, $this->cacheDir);
    }

    /**
     * A path reduced to the form two roots can be compared in: normalised
     * separators, no trailing slash, and case-folded on Windows, where
     * `C:/Cache` and `c:/cache` are the same directory.
     */
    private static function comparableRoot(string $path): string
    {
        $normalized = \rtrim(Path::normalize($path), '/');

        return \DIRECTORY_SEPARATOR === '\\' ? \strtolower($normalized) : $normalized;
    }

    /**
     * Re-wrap a filesystem failure so the chain carries no absolute path.
     *
     * The facade's message names the absolute path. Chaining it verbatim kept
     * the top-level message clean while `getPrevious()->getMessage()` still
     * leaked the server layout to any logger that walks the chain (T-06). The
     * cause is preserved as a fact and a type; only its wording is replaced.
     */
    private static function scrubbedCause(string $operation, string $relativePath): \RuntimeException
    {
        return new \RuntimeException(\sprintf('%s failed for "%s".', $operation, $relativePath));
    }

    /**
     * Confine an entry name that came from `scandir()` rather than from a
     * validated token. The directory listing is data from the filesystem, so it
     * gets the same treatment as anything else we did not construct ourselves.
     *
     * @throws PathException
     */
    private function confined(string $entryName): string
    {
        return Path::confine($this->cacheDir . '/' . $entryName, $this->cacheDir);
    }

    /**
     * Paths in messages are project-relative (ADR-014): an absolute one would
     * leak the server layout into a log (threat T-06).
     */
    private function relative(string $absolute): string
    {
        try {
            return Path::relative($this->projectRoot, $absolute);
        } catch (PathException $e) {
            return \basename($absolute);
        }
    }
}
