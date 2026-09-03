<?php

declare(strict_types=1);

namespace TailwindPhp\Cache;

use TailwindPhp\Exception\ManifestCorruptException;
use TailwindPhp\Support\Regex;

/**
 * Schema check for a decoded `<bundle>.meta.json` (design doc 05 §Manifest,
 * ADR-014).
 *
 * The manifest is the one file this library reads back and *acts on*: its
 * `files` map drives freshness, its `fingerprint` becomes a filename, its
 * `input` becomes a path. On a shared host the cache directory is often
 * writable by more than the PHP user, so every one of those values is treated
 * as untrusted input rather than as something we wrote (threats T-07/T-08).
 *
 * Two rules follow from that and are worth stating outright:
 *
 * - **Every path field must be relative.** A leading `/`, a `C:` drive letter,
 *   a UNC `\\server\share` or a `..` segment is rejected here, before any
 *   caller can join it onto a root (ADR-014, T-02).
 * - **Failure is a rejection, never a repair.** A manifest that does not
 *   validate is thrown away and the bundle rebuilds. Guessing at a half-valid
 *   manifest is how a corrupt cache becomes a permanent one.
 *
 * {@see Manifest::load()} catches what this throws and turns it into a
 * `W_MANIFEST_CORRUPT` diagnostic, so the exception never reaches the user.
 */
final class ManifestValidator
{
    /**
     * The only schema version this release understands. A different number is
     * not an error in the file — it is a manifest from another version of the
     * package, and rebuilding is the correct response.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * `candidates_count` above this cannot have come from any valid
     * configuration: it is the upper bound of the `limits.max_candidates`
     * range in {@see \TailwindPhp\Config\Schema} (decision CCP-A6). The
     * validator cannot see the current config — it is handed a decoded array —
     * so it enforces the ceiling that no config could exceed.
     */
    public const MAX_CANDIDATES_CEILING = 10000000;

    /**
     * Mirrors the `warnings` cap of design doc 05 §Manifest.
     */
    public const MAX_WARNINGS = 200;

    /**
     * Every pattern carries the `D` modifier. Without it PCRE lets `$` match
     * immediately before a final newline, so `"app\n"` would pass as a bundle
     * name and `"0123abcd\n"` as a fingerprint — and both of those go on to
     * become filenames.
     */
    private const BUNDLE_PATTERN = '#^[a-z0-9_-]{1,40}$#D';
    private const FINGERPRINT_PATTERN = '#^[a-f0-9]{8,16}$#D';
    private const PREFIXED_HASH_PATTERN = '#^sha256:[a-f0-9]{64}$#D';
    private const BARE_HASH_PATTERN = '#^[a-f0-9]{64}$#D';

    /**
     * Scalar fields and the check each one gets. Keeping them in a table rather
     * than in a wall of `if`s is what makes it obvious that none is missing.
     */
    private const STRING_FIELDS = ['package_version', 'driver_version', 'input'];

    /**
     * Fields stored with the `sha256:` prefix (decision CSF-A3 — bare hex in
     * PHP, prefixed in the manifest).
     */
    private const PREFIXED_HASH_FIELDS = ['config_hash', 'content_hash', 'candidates_hash', 'safelist_hash'];

    private const INT_FIELDS = ['schema', 'built_at', 'candidates_count'];

    private const ROOT_KINDS = ['input', 'watch', 'sources'];

    private const DIAGNOSTIC_KEYS = ['level', 'code', 'message', 'file', 'line', 'candidate'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $manifest A `Json::decode()` result.
     * @param string $relPath Project-relative path of the manifest (or its bare
     *                        file name when the project root is not known), so
     *                        the exception and the diagnostic can say *which*
     *                        manifest was rejected. Never absolute (T-06).
     *
     * @throws ManifestCorruptException On the first rule the manifest breaks.
     */
    public static function validate(array $manifest, string $relPath = ''): void
    {
        // The path is attached here rather than threaded through fifty-odd
        // `corrupt()` call sites: each of those knows the rule that failed, and
        // none of them needs to know where the file came from.
        try {
            self::validateVersion($manifest);
            self::validateScalars($manifest);
            self::validateDriver($manifest);
            self::validateDriverProbe($manifest);
            self::validatePaths($manifest);
            self::validateRoots($manifest);
            self::validateFiles($manifest);
            self::validateInlineSources($manifest);
            self::validateWarnings($manifest);
            self::validateLastError($manifest);
        } catch (\TailwindPhp\Exception\ManifestCorruptException $e) {
            if ($relPath === '') {
                throw $e;
            }

            throw new ManifestCorruptException($e->reason(), $relPath, $e);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validateVersion(array $manifest): void
    {
        if (!\array_key_exists('schema', $manifest)) {
            throw self::corrupt('missing key "schema"');
        }

        if ($manifest['schema'] !== self::SCHEMA_VERSION) {
            throw self::corrupt(\sprintf(
                'schema version is not %d',
                self::SCHEMA_VERSION
            ));
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validateScalars(array $manifest): void
    {
        foreach (self::INT_FIELDS as $key) {
            self::requireInt($manifest, $key);
        }

        foreach (self::STRING_FIELDS as $key) {
            self::requireString($manifest, $key);
        }

        foreach (self::PREFIXED_HASH_FIELDS as $key) {
            $value = self::requireString($manifest, $key);

            if (!Regex::match(self::PREFIXED_HASH_PATTERN, $value)) {
                throw self::corrupt(\sprintf('key "%s" is not a "sha256:" digest', $key));
            }
        }

        $bundle = self::requireString($manifest, 'bundle');

        if (!Regex::match(self::BUNDLE_PATTERN, $bundle)) {
            throw self::corrupt('key "bundle" is not a valid bundle name');
        }

        $fingerprint = self::requireString($manifest, 'fingerprint');

        if (!Regex::match(self::FINGERPRINT_PATTERN, $fingerprint)) {
            throw self::corrupt('key "fingerprint" is not a valid fingerprint');
        }

        if (!\array_key_exists('minified', $manifest) || !\is_bool($manifest['minified'])) {
            throw self::corrupt('key "minified" is not a boolean');
        }

        // JSON has one number type: a whole-numbered build_ms decodes to int.
        if (
            !\array_key_exists('build_ms', $manifest)
            || (!\is_float($manifest['build_ms']) && !\is_int($manifest['build_ms']))
        ) {
            throw self::corrupt('key "build_ms" is not a number');
        }

        if ($manifest['candidates_count'] < 0) {
            throw self::corrupt('key "candidates_count" is negative');
        }

        if ($manifest['candidates_count'] > self::MAX_CANDIDATES_CEILING) {
            // CCP-A6: no configuration can permit this many, so the value did
            // not come from a build of ours.
            throw self::corrupt('key "candidates_count" exceeds the configurable maximum');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validateDriver(array $manifest): void
    {
        $driver = self::requireString($manifest, 'driver');

        if ($driver !== 'php' && $driver !== 'cli') {
            throw self::corrupt('key "driver" is neither "php" nor "cli"');
        }
    }

    /**
     * `driver_probe` records the CLI binary we verified last time so the next
     * request can skip re-hashing it (design doc 06 §BinaryVerifier). A forged
     * probe would let an unverified binary look verified, so its shape is
     * checked as strictly as the rest.
     *
     * @param array<string, mixed> $manifest
     */
    private static function validateDriverProbe(array $manifest): void
    {
        if (!\array_key_exists('driver_probe', $manifest)) {
            throw self::corrupt('missing key "driver_probe"');
        }

        $probe = $manifest['driver_probe'];

        if ($probe === null) {
            return;
        }

        if (!\is_array($probe)) {
            throw self::corrupt('key "driver_probe" is neither null nor an object');
        }

        // CCP-A3: project-relative, or null when the binary lies outside the
        // project root — in which case only the checksum identifies it.
        if (!\array_key_exists('binary', $probe)) {
            throw self::corrupt('missing key "driver_probe.binary"');
        }

        if ($probe['binary'] !== null) {
            if (!\is_string($probe['binary'])) {
                throw self::corrupt('key "driver_probe.binary" is neither null nor a string');
            }

            self::requireRelativePath($probe['binary'], 'driver_probe.binary');
        }

        foreach (['m', 's'] as $key) {
            if (!\array_key_exists($key, $probe) || !\is_int($probe[$key])) {
                throw self::corrupt(\sprintf('key "driver_probe.%s" is not an integer', $key));
            }
        }

        if (
            !\array_key_exists('sha256', $probe)
            || !\is_string($probe['sha256'])
            || !Regex::match(self::BARE_HASH_PATTERN, $probe['sha256'])
        ) {
            throw self::corrupt('key "driver_probe.sha256" is not a sha-256 digest');
        }

        // CCP-A4.
        if (!\array_key_exists('version', $probe) || !\is_string($probe['version'])) {
            throw self::corrupt('key "driver_probe.version" is not a string');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validatePaths(array $manifest): void
    {
        self::requireRelativePath((string) $manifest['input'], 'input');

        $imports = self::requireList($manifest, 'imports');

        foreach ($imports as $index => $import) {
            if (!\is_string($import)) {
                throw self::corrupt(\sprintf('entry %d of "imports" is not a string', $index));
            }

            self::requireRelativePath($import, 'imports');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validateRoots(array $manifest): void
    {
        if (!\array_key_exists('roots', $manifest) || !\is_array($manifest['roots'])) {
            throw self::corrupt('key "roots" is not an object');
        }

        foreach (self::ROOT_KINDS as $kind) {
            if (!\array_key_exists($kind, $manifest['roots']) || !\is_array($manifest['roots'][$kind])) {
                throw self::corrupt(\sprintf('key "roots.%s" is not a list', $kind));
            }

            foreach ($manifest['roots'][$kind] as $root) {
                if (!\is_string($root)) {
                    throw self::corrupt(\sprintf('key "roots.%s" contains a non-string', $kind));
                }

                self::requireRelativePath($root, 'roots.' . $kind);
            }
        }
    }

    /**
     * The `files` map is the freshness snapshot: `k` = kind (`i` input, `s`
     * source), `m` = mtime, `s` = size, `c` = the candidate list extracted from
     * a source file (design doc 05 §Layer 1).
     *
     * @param array<string, mixed> $manifest
     */
    private static function validateFiles(array $manifest): void
    {
        if (!\array_key_exists('files', $manifest) || !\is_array($manifest['files'])) {
            throw self::corrupt('key "files" is not an object');
        }

        foreach ($manifest['files'] as $path => $entry) {
            // A JSON object key is always a string, but PHP silently converts a
            // numeric one to int — so a manifest with "12" as a filename would
            // reach requireRelativePath() as an integer.
            if (!\is_string($path)) {
                throw self::corrupt('key "files" has a non-string path');
            }

            self::requireRelativePath($path, 'files');

            if (!\is_array($entry)) {
                throw self::corrupt('an entry of "files" is not an object');
            }

            if (!\array_key_exists('k', $entry) || ($entry['k'] !== 'i' && $entry['k'] !== 's')) {
                throw self::corrupt('an entry of "files" has an unknown kind');
            }

            foreach (['m', 's'] as $key) {
                if (!\array_key_exists($key, $entry) || !\is_int($entry[$key])) {
                    throw self::corrupt(\sprintf('an entry of "files" has a non-integer "%s"', $key));
                }
            }

            if (!\array_key_exists('c', $entry)) {
                continue;
            }

            if ($entry['k'] !== 's') {
                throw self::corrupt('an input entry of "files" carries candidates');
            }

            if (!\is_array($entry['c'])) {
                throw self::corrupt('an entry of "files" has a non-list "c"');
            }

            foreach ($entry['c'] as $candidate) {
                if (!\is_string($candidate)) {
                    throw self::corrupt('an entry of "files" has a non-string candidate');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validateInlineSources(array $manifest): void
    {
        $sources = self::requireList($manifest, 'inline_sources');

        foreach ($sources as $source) {
            if (!\is_string($source)) {
                throw self::corrupt('key "inline_sources" contains a non-string');
            }
        }
    }

    /**
     * `warnings[]` rows are exactly `Diagnostic::toArray()` (decision CCP-A25),
     * so the six keys are required and no others are tolerated.
     *
     * @param array<string, mixed> $manifest
     */
    private static function validateWarnings(array $manifest): void
    {
        $warnings = self::requireList($manifest, 'warnings');

        if (\count($warnings) > self::MAX_WARNINGS) {
            throw self::corrupt('key "warnings" holds more rows than the cap');
        }

        foreach ($warnings as $warning) {
            if (!\is_array($warning)) {
                throw self::corrupt('an entry of "warnings" is not an object');
            }

            foreach (self::DIAGNOSTIC_KEYS as $key) {
                if (!\array_key_exists($key, $warning)) {
                    throw self::corrupt(\sprintf('an entry of "warnings" is missing "%s"', $key));
                }
            }

            if (\count($warning) !== \count(self::DIAGNOSTIC_KEYS)) {
                throw self::corrupt('an entry of "warnings" has unexpected keys');
            }

            foreach (['level', 'code', 'message'] as $key) {
                if (!\is_string($warning[$key])) {
                    throw self::corrupt(\sprintf('an entry of "warnings" has a non-string "%s"', $key));
                }
            }

            if ($warning['file'] !== null) {
                if (!\is_string($warning['file'])) {
                    throw self::corrupt('an entry of "warnings" has a non-string "file"');
                }

                self::requireRelativePath($warning['file'], 'warnings.file');
            }

            if ($warning['line'] !== null && !\is_int($warning['line'])) {
                throw self::corrupt('an entry of "warnings" has a non-integer "line"');
            }

            if ($warning['candidate'] !== null && !\is_string($warning['candidate'])) {
                throw self::corrupt('an entry of "warnings" has a non-string "candidate"');
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validateLastError(array $manifest): void
    {
        if (!\array_key_exists('last_error', $manifest)) {
            throw self::corrupt('missing key "last_error"');
        }

        $error = $manifest['last_error'];

        if ($error === null) {
            return;
        }

        if (!\is_array($error)) {
            throw self::corrupt('key "last_error" is neither null nor an object');
        }

        foreach (['code', 'message_short'] as $key) {
            if (!\array_key_exists($key, $error) || !\is_string($error[$key])) {
                throw self::corrupt(\sprintf('key "last_error.%s" is not a string', $key));
            }
        }

        if (!\array_key_exists('at', $error) || !\is_int($error['at'])) {
            throw self::corrupt('key "last_error.at" is not an integer');
        }
    }

    /**
     * The path rule, in one place: relative, forward slashes, no traversal.
     *
     * `..` is rejected outright rather than normalised away — a manifest we
     * wrote never contains one, so its presence means the file was edited by
     * something else and the whole document is suspect (T-02).
     */
    private static function requireRelativePath(string $path, string $field): void
    {
        if ($path === '') {
            throw self::corrupt(\sprintf('key "%s" holds an empty path', $field));
        }

        // Every control byte, not just NUL. A path ending in "\n" passed all of
        // the checks below — none of them is an anchored pattern — and would
        // then be joined into a filename and echoed into a log (T-02, T-06).
        for ($i = 0, $length = \strlen($path); $i < $length; ++$i) {
            if ($path[$i] < "\x20" || $path[$i] === "\x7F") {
                throw self::corrupt(\sprintf('key "%s" holds a path with a control character', $field));
            }
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            throw self::corrupt(\sprintf('key "%s" holds an absolute path', $field));
        }

        if (\strpos($path, '\\') !== false) {
            throw self::corrupt(\sprintf('key "%s" holds a backslash', $field));
        }

        // "C:/x" and "C:x" both address a drive on Windows.
        if (\strlen($path) >= 2 && $path[1] === ':') {
            throw self::corrupt(\sprintf('key "%s" holds a drive-qualified path', $field));
        }

        // `php://filter/...` and `phar://x.phar/y` are neither absolute nor
        // drive-qualified, so every check above passes them — and a wrapper
        // reaching a file operation is the whole of T-01/T-08.
        if (Regex::match('#^[a-z][a-z0-9+.-]*:#iD', $path)) {
            throw self::corrupt(\sprintf('key "%s" holds a stream wrapper', $field));
        }

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw self::corrupt(\sprintf('key "%s" holds a traversal segment', $field));
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function requireString(array $manifest, string $key): string
    {
        if (!\array_key_exists($key, $manifest)) {
            throw self::corrupt(\sprintf('missing key "%s"', $key));
        }

        if (!\is_string($manifest[$key])) {
            throw self::corrupt(\sprintf('key "%s" is not a string', $key));
        }

        return $manifest[$key];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function requireInt(array $manifest, string $key): void
    {
        if (!\array_key_exists($key, $manifest)) {
            throw self::corrupt(\sprintf('missing key "%s"', $key));
        }

        if (!\is_int($manifest[$key])) {
            throw self::corrupt(\sprintf('key "%s" is not an integer', $key));
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<int, mixed>
     */
    private static function requireList(array $manifest, string $key): array
    {
        if (!\array_key_exists($key, $manifest) || !\is_array($manifest[$key])) {
            throw self::corrupt(\sprintf('key "%s" is not a list', $key));
        }

        $value = $manifest[$key];

        // A JSON object decodes to an array too; a list has 0..n-1 keys.
        if ($value !== [] && \array_keys($value) !== \range(0, \count($value) - 1)) {
            throw self::corrupt(\sprintf('key "%s" is an object, not a list', $key));
        }

        return $value;
    }

    private static function corrupt(string $reason): ManifestCorruptException
    {
        return new ManifestCorruptException($reason);
    }
}
