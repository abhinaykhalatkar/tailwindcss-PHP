<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

/**
 * How each configuration key is validated, and two flags that decide what it
 * takes part in (design doc 11 §Resolution and validation).
 *
 * Per key:
 *  - `type`     — `bool`, `int`, `string`, `list`, `map` or `mixed`
 *  - `nullable` — whether null is accepted in addition to `type`
 *  - `enum`     — the permitted values, for `string` keys that are enums
 *  - `range`    — `[min, max]` inclusive, for `int` keys
 *  - `nested`   — the sub-table, for `map` keys
 *  - `hash`     — takes part in {@see Config::configHash()} (decision CSF-A4)
 *  - `bundle`   — may be overridden per bundle (decision CSF-A20)
 *
 * **`hash` is the load-bearing flag.** The rule is: hash a key if and only if
 * changing it can change the produced CSS bytes or the candidate set. Marking
 * too many keys makes an operational tweak — a longer `check_interval`, a
 * different `cache_dir` — invalidate every cache in the fleet; marking too few
 * serves stale CSS after a real change, which is the exact failure
 * `config_hash` exists to prevent. Neither shows up as a test failure, so the
 * flag is set here once and asserted directly.
 */
final class Schema
{
    private function __construct()
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function table(): array
    {
        return [
            'debug' => self::spec('bool'),
            'project_root' => self::spec('string', ['nullable' => true]),
            'input' => self::spec('string', ['hash' => true, 'bundle' => true]),
            'watch' => self::spec('list', ['hash' => true, 'bundle' => true]),
            'input_extensions' => self::spec('list', ['hash' => true, 'bundle' => true]),
            // Entries are either a string or ['path' => …, 'extensions' => [...]].
            'sources' => self::spec('list', ['hash' => true, 'bundle' => true]),
            'source_extensions' => self::spec('list', ['hash' => true, 'bundle' => true]),
            'ignore' => self::spec('list', ['hash' => true, 'bundle' => true]),
            'allowed_roots' => self::spec('list', ['hash' => true]),
            'safelist' => self::spec('list', ['hash' => true, 'bundle' => true]),
            'blocklist' => self::spec('list', ['hash' => true, 'bundle' => true]),
            'public_route' => self::spec('string', ['bundle' => true]),
            'fingerprint' => self::spec('bool'),
            'fingerprint_length' => self::spec('int', ['range' => [8, 16], 'hash' => true]),
            'keep_versions' => self::spec('int', ['range' => [1, 100]]),
            'cache_dir' => self::spec('string'),
            'cache_dir_mode' => self::spec('int', ['range' => [0, 0777]]),
            'cache_file_mode' => self::spec('int', ['range' => [0, 0777]]),
            'freeze' => self::spec('bool'),
            'check_interval' => self::spec('int', ['range' => [0, 86400]]),
            'check_store' => self::spec('string', ['enum' => ['file', 'apcu']]),
            'lock' => self::spec('string', ['enum' => ['flock', 'none']]),
            'lock_timeout' => self::spec('int', ['range' => [0, 300]]),
            'gzip' => self::spec('bool'),
            'driver' => self::spec('string', ['enum' => Driver::all(), 'hash' => true]),
            'minify' => self::spec('bool', ['nullable' => true, 'hash' => true, 'bundle' => true]),
            'cli' => self::spec('map', ['nested' => [
                'binary' => self::spec('string', ['nullable' => true, 'hash' => true]),
                'verify' => self::spec('bool'),
                'timeout' => self::spec('int', ['range' => [1, 600]]),
            ]]),
            'engine' => self::spec('map', ['bundle' => true, 'nested' => [
                'prefix' => self::spec('string', ['nullable' => true, 'hash' => true]),
                'important' => self::spec('bool', ['hash' => true]),
                'preflight' => self::spec('bool', ['nullable' => true, 'hash' => true]),
            ]]),
            'preprocessor' => self::spec('string', ['nullable' => true, 'hash' => true, 'bundle' => true]),
            'preprocessor_options' => self::spec('map', ['hash' => true, 'bundle' => true, 'nested' => []]),
            'limits' => self::spec('map', ['nested' => [
                'max_file_size' => self::spec('int', ['range' => [1024, 268435456]]),
                'max_files' => self::spec('int', ['range' => [1, 1000000]]),
                // The only limit that changes output when it bites: candidates
                // beyond it are dropped, so the CSS differs (CSF-A4, CSF-A10).
                'max_candidates' => self::spec('int', ['range' => [1, 10000000], 'hash' => true]),
                'max_import_depth' => self::spec('int', ['range' => [1, 128]]),
                'max_total_css' => self::spec('int', ['range' => [1024, 268435456]]),
                'manifest_soft_bytes' => self::spec('int', ['range' => [1024, 268435456]]),
            ]]),
            'http' => self::spec('map', ['nested' => [
                'immutable_max_age' => self::spec('int', ['range' => [0, 31536000]]),
                'extra_headers' => self::spec('map', ['nested' => []]),
                'handle_options' => self::spec('bool'),
            ]]),
            'scanner' => self::spec('map', ['nested' => [
                'prepass' => self::spec('list', ['hash' => true]),
            ]]),
            'logger' => self::spec('mixed', ['nullable' => true]),
            'log_level' => self::spec('string', ['enum' => [
                'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug',
            ]]),
            // Free-form map of bundle name => partial config; validated against
            // the bundle-flagged keys of this same table.
            'bundles' => self::spec('map', ['hash' => true, 'nested' => []]),
        ];
    }

    /**
     * Keys that take part in the configuration hash (CSF-A4).
     *
     * @return list<string>
     */
    public static function hashedKeys(): array
    {
        $keys = [];

        foreach (self::table() as $key => $spec) {
            if ($spec['hash'] === true) {
                $keys[] = $key;

                continue;
            }

            // A map may be unhashed as a whole while some members are hashed —
            // `cli.binary` matters, `cli.timeout` does not.
            if ($spec['type'] === 'map' && $spec['nested'] !== []) {
                foreach ($spec['nested'] as $childKey => $childSpec) {
                    if ($childSpec['hash'] === true) {
                        $keys[] = $key . '.' . $childKey;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Keys a bundle may override (CSF-A20).
     *
     * @return list<string>
     */
    public static function bundleKeys(): array
    {
        $keys = [];

        foreach (self::table() as $key => $spec) {
            if ($spec['bundle'] === true) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function spec(string $type, array $overrides = []): array
    {
        return \array_merge(
            [
                'type' => $type,
                'nullable' => false,
                'enum' => null,
                'range' => null,
                'nested' => [],
                'hash' => false,
                'bundle' => false,
            ],
            $overrides
        );
    }
}
