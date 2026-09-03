<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

use TailwindPhp\Exception\ConfigurationException;

/**
 * The effective, validated configuration (design doc 11).
 *
 * Immutable and built only by {@see ConfigBuilder}: by the time an instance
 * exists every value has been type-checked, every path resolved and confined,
 * and every problem reported — so the rest of the library can read it without
 * defending against bad input.
 *
 * Paths come in absolute/relative pairs. Absolute is what the filesystem needs;
 * project-relative is what goes into cache artefacts, so a deployment that moves
 * the project does not invalidate its cache or leak the server's layout
 * (ADR-014, threat T-06).
 *
 * `debug` is only ever what the caller put in the array. Nothing here reads
 * `$_GET`, `$_POST`, `$_COOKIE`, `$_SERVER` or the environment, which is what
 * makes debug mode unreachable from a request (NFR-SEC-5, threat T-09) — and is
 * enforced by static rule R3 rather than left to review.
 */
final class Config
{
    /**
     * @var array<string, mixed>
     */
    private $values;

    /**
     * @var array<string, BundleConfig>
     */
    private $bundles;

    /**
     * @var string
     */
    private $configHash;

    /**
     * @param array<string, mixed> $values
     * @param array<string, BundleConfig> $bundles
     * @param string $configHash Bare lowercase hex (CSF-A3).
     */
    public function __construct(array $values, array $bundles, string $configHash)
    {
        $this->values = $values;
        $this->bundles = $bundles;
        $this->configHash = $configHash;
    }

    /**
     * Fingerprint of everything that can change the produced CSS or the
     * candidate set (decision CSF-A4).
     *
     * Bare lowercase hex; the manifest writer prefixes it with `sha256:`
     * (CSF-A3). Stable across a project move because every path in the hash
     * input is project-relative.
     */
    public function configHash(): string
    {
        return $this->configHash;
    }

    // ------------------------------------------------------------- bundles

    /**
     * @return array<string, BundleConfig>
     */
    public function bundles(): array
    {
        return $this->bundles;
    }

    /**
     * @throws ConfigurationException When no bundle of that name exists.
     */
    public function bundle(string $name): BundleConfig
    {
        if (!isset($this->bundles[$name])) {
            throw new ConfigurationException([
                \sprintf(
                    'Unknown bundle "%s"; configured bundles are: %s.',
                    // The name comes from the caller and lands in a log, so a
                    // newline in it would forge a log line (T-06). The *known*
                    // names need no escaping: they were validated against
                    // `^[a-z0-9_-]{1,40}$` when the config was built.
                    ConfigBuilder::safe($name),
                    \implode(', ', \array_keys($this->bundles))
                ),
            ]);
        }

        return $this->bundles[$name];
    }

    // --------------------------------------------------------------- paths

    public function projectRoot(): string
    {
        return (string) $this->values['project_root'];
    }

    public function cacheDir(): string
    {
        return (string) $this->values['cache_dir'];
    }

    public function cacheDirRel(): string
    {
        return (string) $this->values['cache_dir_rel'];
    }

    /**
     * Absolute roots every configured path is confined to: the project root plus
     * each resolved `allowed_roots` entry (decision CSF-A2).
     *
     * @return list<string>
     */
    public function allowedRoots(): array
    {
        /** @var list<string> $value */
        $value = $this->values['allowed_roots'];

        return $value;
    }

    /**
     * @return list<string>
     */
    public function allowedRootsRel(): array
    {
        /** @var list<string> $value */
        $value = $this->values['allowed_roots_rel'];

        return $value;
    }

    // ------------------------------------------------------------ scalars

    public function debug(): bool
    {
        return (bool) $this->values['debug'];
    }

    public function driver(): string
    {
        return (string) $this->values['driver'];
    }

    public function fingerprint(): bool
    {
        return (bool) $this->values['fingerprint'];
    }

    public function fingerprintLength(): int
    {
        return (int) $this->values['fingerprint_length'];
    }

    public function keepVersions(): int
    {
        return (int) $this->values['keep_versions'];
    }

    public function cacheDirMode(): int
    {
        return (int) $this->values['cache_dir_mode'];
    }

    public function cacheFileMode(): int
    {
        return (int) $this->values['cache_file_mode'];
    }

    public function freeze(): bool
    {
        return (bool) $this->values['freeze'];
    }

    public function checkInterval(): int
    {
        return (int) $this->values['check_interval'];
    }

    public function checkStore(): string
    {
        return (string) $this->values['check_store'];
    }

    public function lock(): string
    {
        return (string) $this->values['lock'];
    }

    public function lockTimeout(): int
    {
        return (int) $this->values['lock_timeout'];
    }

    public function gzip(): bool
    {
        return (bool) $this->values['gzip'];
    }

    public function logLevel(): string
    {
        return (string) $this->values['log_level'];
    }

    /**
     * @return mixed A PSR-3 logger or null; typed loosely because psr/log is
     *               interface-only and the value is passed straight through.
     */
    public function logger()
    {
        return $this->values['logger'];
    }

    // -------------------------------------------------------------- groups

    /**
     * @return array{binary: string|null, binary_rel: string|null, verify: bool, timeout: int}
     */
    public function cli(): array
    {
        /** @var array{binary: string|null, binary_rel: string|null, verify: bool, timeout: int} $value */
        $value = $this->values['cli'];

        return $value;
    }

    /**
     * @return array<string, int>
     */
    public function limits(): array
    {
        /** @var array<string, int> $value */
        $value = $this->values['limits'];

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function http(): array
    {
        /** @var array<string, mixed> $value */
        $value = $this->values['http'];

        return $value;
    }

    /**
     * @return array{prepass: list<string>}
     */
    public function scanner(): array
    {
        /** @var array{prepass: list<string>} $value */
        $value = $this->values['scanner'];

        return $value;
    }
}
