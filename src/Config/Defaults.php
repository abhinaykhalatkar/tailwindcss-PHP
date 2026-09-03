<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

/**
 * Every configuration key and its default value (design doc 11 §Full schema).
 *
 * The table is the single source of both the defaults and the set of legal
 * top-level keys: {@see ConfigBuilder} merges a user array over this and rejects
 * anything that is not present here, so adding a key means adding it in exactly
 * one place — here and in {@see Schema}, which describes how to validate it.
 *
 * `scanner.php_strip_php` is deliberately absent: `scanner.prepass` is the only
 * switch for pre-passes (decision CSF-A9).
 */
final class Defaults
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function table(): array
    {
        return [
            'debug' => false,
            'project_root' => null,
            'input' => 'assets/styles/main.css',
            'watch' => [],
            'input_extensions' => ['css', 'scss'],
            'sources' => ['templates'],
            'source_extensions' => [
                'twig', 'html', 'htm', 'php', 'phtml', 'blade.php',
                'js', 'mjs', 'ts', 'jsx', 'tsx', 'vue', 'svelte',
                'md', 'mdx', 'xml', 'svg', 'json',
            ],
            'ignore' => ['.git', 'node_modules', 'vendor', 'cache'],
            'allowed_roots' => [],
            'safelist' => [],
            'blocklist' => [],
            'public_route' => 'assets/styles.css',
            'fingerprint' => true,
            'fingerprint_length' => 12,
            'keep_versions' => 3,
            'cache_dir' => 'cache/tailwind',
            'cache_dir_mode' => 0755,
            'cache_file_mode' => 0644,
            'freeze' => false,
            'check_interval' => 0,
            'check_store' => 'file',
            'lock' => 'flock',
            'lock_timeout' => 10,
            'gzip' => true,
            'driver' => Driver::AUTO,
            'minify' => null,
            'cli' => [
                'binary' => null,
                'verify' => true,
                'timeout' => 20,
            ],
            'engine' => [
                'prefix' => null,
                'important' => false,
                'preflight' => null,
            ],
            'preprocessor' => null,
            'preprocessor_options' => [],
            'limits' => [
                'max_file_size' => 2097152,
                'max_files' => 20000,
                'max_candidates' => 200000,
                'max_import_depth' => 16,
                'max_total_css' => 4194304,
                'manifest_soft_bytes' => 2097152,
            ],
            'http' => [
                'immutable_max_age' => 31536000,
                'extra_headers' => [],
                'handle_options' => false,
            ],
            'scanner' => [
                'prepass' => ['twig'],
            ],
            'logger' => null,
            'log_level' => 'warning',
            'bundles' => [],
        ];
    }
}
