<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Exception\ManifestCorruptException;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Json;

/**
 * The build record `<bundle>.meta.json` (design doc 05 §Manifest, ADR-014).
 *
 * One manifest per bundle describes the last successful build: what was
 * compiled, from which files at which mtime and size, which candidates came
 * out, and which artefact the result landed in. The whole freshness decision
 * (design doc 04 §4) is a comparison between this object and the filesystem,
 * which is why the warm path reads exactly one file.
 *
 * **Loading never throws.** A manifest is data we found on disk, and every
 * reason it might be unusable — missing, truncated, hand-edited, written by a
 * different version, larger than the read cap — has the same correct answer:
 * treat the cache as cold and rebuild. {@see self::load()} therefore returns
 * `null` and hands back a `W_MANIFEST_CORRUPT` diagnostic instead of
 * propagating an exception to someone rendering a page.
 *
 * **It is never `include`d or `unserialize`d.** JSON only, decoded with a depth
 * limit; the cache directory is writable by the web server, so a manifest that
 * could execute would be a remote code execution primitive (threat T-08).
 *
 * Immutable: {@see self::withFiles()} and {@see self::withLastError()} return
 * new instances (design doc 18).
 */
final class Manifest
{
    /**
     * Hard ceiling on a manifest read (decision CCP-A6). Far above the 2 MB
     * `limits.manifest_soft_bytes` warning threshold, because the soft limit is
     * advice about cache hygiene while this one bounds the memory a single
     * request can be made to allocate (threat T-05).
     */
    public const HARD_READ_CAP = 16777216;

    /**
     * Decode depth. A manifest nests three levels at most (`files` → entry →
     * `c`); anything deeper is either corrupt or an attempt to burn stack.
     */
    private const DECODE_DEPTH = 16;

    /**
     * @var array<string, mixed> The validated document, kept verbatim so that
     *                           toArray() round-trips byte-identically.
     */
    private $data;

    /**
     * @param array<string, mixed> $validated
     */
    private function __construct(array $validated)
    {
        $this->data = $validated;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ManifestCorruptException When $data is not a valid manifest.
     */
    public static function fromArray(array $data): self
    {
        ManifestValidator::validate($data);

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Read and validate a manifest, or report why it is unusable.
     *
     * @param string $absolutePath Absolute path to `<bundle>.meta.json`.
     * @param int $hardCap Read budget in bytes; see self::HARD_READ_CAP.
     * @param Diagnostic|null $diagnostic Out-parameter: set to a
     *                                    `W_MANIFEST_CORRUPT` warning when a
     *                                    manifest exists but cannot be used,
     *                                    left null when there simply is none.
     *
     * @param-out Diagnostic|null $diagnostic
     */
    public static function load(
        string $absolutePath,
        FilesystemInterface $fs,
        int $hardCap = self::HARD_READ_CAP,
        ?Diagnostic &$diagnostic = null
    ): ?self {
        $diagnostic = null;

        // A build in this same request may have just replaced the manifest, and
        // PHP caches stat results per path for the whole request.
        $fs->clearStatCache($absolutePath);

        $stat = $fs->stat($absolutePath);

        if ($stat === null) {
            // A cold cache is the normal first request, not a problem to report.
            return null;
        }

        $relPath = \basename($absolutePath);

        if ($stat['size'] > $hardCap) {
            $diagnostic = self::corruptDiagnostic('the manifest is larger than the read limit', $relPath);

            return null;
        }

        try {
            $bytes = $fs->read($absolutePath, $hardCap);
        } catch (\RuntimeException $e) {
            $diagnostic = self::corruptDiagnostic('the manifest could not be read', $relPath);

            return null;
        }

        // `read()` truncates silently at the cap, and the file may also have
        // grown between the stat and the read. Comparing the two is how a
        // clipped document is told apart from a short one (CCP-A6).
        if (\strlen($bytes) !== $stat['size']) {
            $diagnostic = self::corruptDiagnostic('the manifest changed while it was being read', $relPath);

            return null;
        }

        try {
            $decoded = Json::decode($bytes, self::DECODE_DEPTH);
            ManifestValidator::validate($decoded, $relPath);
        } catch (\TailwindPhp\Exception\ManifestCorruptException $e) {
            $diagnostic = self::corruptDiagnostic($e->reason(), $relPath);

            return null;
        } catch (\JsonException $e) {
            // Json::decode's failure — malformed, too deep, or not an object.
            // The message is not reused: it can quote the offending bytes.
            $diagnostic = self::corruptDiagnostic('the manifest is not valid JSON', $relPath);

            return null;
        }

        return new self($decoded);
    }

    /**
     * Write the manifest atomically. Step ④ of the build write order (design
     * doc 05): it goes last, so a process killed earlier leaves the *previous*
     * manifest in place, still pointing at an artefact that exists.
     *
     * @throws \TailwindPhp\Exception\CacheWriteException When the file cannot be written.
     * @throws \JsonException When the document cannot be encoded.
     */
    public function save(string $absolutePath, AtomicWriter $writer, int $mode = 0644): void
    {
        $writer->write($absolutePath, Json::encode($this->data), $mode);
    }

    /**
     * @param array<string, array<string, mixed>> $files
     *
     * @throws ManifestCorruptException When $files is not a valid files map.
     */
    public function withFiles(array $files): self
    {
        // ksort'ed so a rebuild that changes nothing produces identical bytes
        // (design doc 05 §Manifest) — otherwise every save churns the file.
        \ksort($files);

        $data = $this->data;
        $data['files'] = $files;

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed>|null $lastError `{code, at, message_short}`; null clears it.
     *
     * @throws ManifestCorruptException When $lastError is malformed.
     */
    public function withLastError(?array $lastError): self
    {
        $data = $this->data;
        $data['last_error'] = $lastError;

        return self::fromArray($data);
    }

    public function schema(): int
    {
        return (int) $this->data['schema'];
    }

    public function packageVersion(): string
    {
        return (string) $this->data['package_version'];
    }

    /**
     * @return string With the `sha256:` prefix, as stored (CSF-A3).
     */
    public function configHash(): string
    {
        return (string) $this->data['config_hash'];
    }

    public function bundle(): string
    {
        return (string) $this->data['bundle'];
    }

    /**
     * @return string `php` or `cli`.
     */
    public function driver(): string
    {
        return (string) $this->data['driver'];
    }

    public function driverVersion(): string
    {
        return (string) $this->data['driver_version'];
    }

    /**
     * @return array<string, mixed>|null `{binary, m, s, sha256, version}` — the
     *                                   verified CLI binary, so the next request
     *                                   can skip re-hashing it (CCP-A3/A4).
     */
    public function driverProbe(): ?array
    {
        return $this->data['driver_probe'] === null ? null : (array) $this->data['driver_probe'];
    }

    public function builtAt(): int
    {
        return (int) $this->data['built_at'];
    }

    public function buildMs(): float
    {
        return (float) $this->data['build_ms'];
    }

    /**
     * @return string With the `sha256:` prefix.
     */
    public function contentHash(): string
    {
        return (string) $this->data['content_hash'];
    }

    public function fingerprint(): string
    {
        return (string) $this->data['fingerprint'];
    }

    public function minified(): bool
    {
        return (bool) $this->data['minified'];
    }

    /**
     * @return string With the `sha256:` prefix.
     */
    public function candidatesHash(): string
    {
        return (string) $this->data['candidates_hash'];
    }

    public function candidatesCount(): int
    {
        return (int) $this->data['candidates_count'];
    }

    /**
     * @return string Project-relative path of the entry stylesheet.
     */
    public function input(): string
    {
        return (string) $this->data['input'];
    }

    /**
     * @return list<string> Project-relative paths pulled in by `@import`.
     */
    public function imports(): array
    {
        return \array_values((array) $this->data['imports']);
    }

    /**
     * @return array<string, list<string>> Keyed `input`, `watch`, `sources`.
     */
    public function roots(): array
    {
        return (array) $this->data['roots'];
    }

    /**
     * The freshness snapshot: project-relative path → `{k, m, s, c?}`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function files(): array
    {
        return (array) $this->data['files'];
    }

    /**
     * @return string With the `sha256:` prefix.
     */
    public function safelistHash(): string
    {
        return (string) $this->data['safelist_hash'];
    }

    /**
     * @return list<string>
     */
    public function inlineSources(): array
    {
        return \array_values((array) $this->data['inline_sources']);
    }

    /**
     * @return list<array<string, mixed>> Rows in `Diagnostic::toArray()` shape (CCP-A25).
     */
    public function warnings(): array
    {
        return \array_values((array) $this->data['warnings']);
    }

    /**
     * @return array<string, mixed>|null `{code, at, message_short}`.
     */
    public function lastError(): ?array
    {
        return $this->data['last_error'] === null ? null : (array) $this->data['last_error'];
    }

    /**
     * @param string $relPath Bare file name — `load()` is given an absolute path
     *                        and no project root, and `Diagnostic` refuses
     *                        anything that is not a clean relative path (T-06).
     */
    private static function corruptDiagnostic(string $reason, string $relPath): Diagnostic
    {
        return new Diagnostic(
            Diagnostic::WARNING,
            Diagnostic::W_MANIFEST_CORRUPT,
            \sprintf('The cache manifest was ignored and the bundle will be rebuilt: %s.', $reason),
            $relPath === '' ? null : $relPath
        );
    }
}
