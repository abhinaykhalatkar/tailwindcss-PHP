<?php

declare(strict_types=1);

namespace TailwindPhp\Freshness;

/**
 * Everything besides the source tree that can change the compiled output
 * (FR-FRESH-8, design doc 04 §4.2): the package version, the effective
 * config, and which compiler produced the last build.
 *
 * Every field is an opaque string compared for exact equality — this class
 * does not know or care how `$configHash` was computed or whether
 * `$driverVersion` is a compat string or a CLI `--version` line (design
 * decision CSF-A19); it only detects that *something* changed. `Freshness`
 * never imports `Config` (design doc 17 layering), so the caller resolves
 * every value first.
 */
final class EnvironmentFingerprint
{
    /**
     * @var string
     */
    private $packageVersion;

    /**
     * @var string
     */
    private $configHash;

    /**
     * @var string
     */
    private $driver;

    /**
     * @var string
     */
    private $driverVersion;

    public function __construct(string $packageVersion, string $configHash, string $driver, string $driverVersion)
    {
        $this->packageVersion = $packageVersion;
        $this->configHash = $configHash;
        $this->driver = $driver;
        $this->driverVersion = $driverVersion;
    }

    public function packageVersion(): string
    {
        return $this->packageVersion;
    }

    public function configHash(): string
    {
        return $this->configHash;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function driverVersion(): string
    {
        return $this->driverVersion;
    }

    /**
     * Whether a manifest's recorded environment still matches this one. Any
     * mismatch is reported as ENV_CHANGED by the pipeline
     * (`Pipeline\FreshnessDecision`, design decision CSF-A13).
     *
     * @param array<string, mixed> $manifest Decoded manifest document; only
     *                                       the four matching keys are read.
     */
    public function matchesManifest(array $manifest): bool
    {
        return ($manifest['package_version'] ?? null) === $this->packageVersion
            && ($manifest['config_hash'] ?? null) === $this->configHash
            && ($manifest['driver'] ?? null) === $this->driver
            && ($manifest['driver_version'] ?? null) === $this->driverVersion;
    }

    /**
     * @return array{package_version: string, config_hash: string, driver: string, driver_version: string}
     */
    public function toArray(): array
    {
        return [
            'package_version' => $this->packageVersion,
            'config_hash' => $this->configHash,
            'driver' => $this->driver,
            'driver_version' => $this->driverVersion,
        ];
    }
}
