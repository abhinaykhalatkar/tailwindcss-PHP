<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

use TailwindPhp\Config\Config;

/**
 * The plain array `DriverResolverInterface::resolve()` reads.
 *
 * `Compiler` takes arrays rather than `Config` objects (layering, TASK-0014
 * acceptance 10), so somebody has to flatten the configuration into the shape
 * the resolver documents: the driver choice, the confinement roots, and the
 * `cli.*` map. It is built in exactly one place because the round-4 verifier
 * of TASK-0015 found the pipeline and the orchestrator each passing
 * `Config::cli()` alone — which carries neither `driver` nor a root — so every
 * resolution silently became `auto` with no binary in reach. A tiny class
 * rather than a method on the orchestrator, so the warm path does not autoload
 * `CompileOrchestrator` just to build an array.
 */
final class DriverResolverInput
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed> Keys: `driver`, `projectRoot`, `allowedRoots`,
     *                              `cacheDir`, plus `binary`, `binary_rel`,
     *                              `verify`, `timeout` from `cli.*`.
     */
    public static function from(Config $config): array
    {
        return [
            'driver' => $config->driver(),
            'projectRoot' => $config->projectRoot(),
            'allowedRoots' => $config->allowedRoots(),
            'cacheDir' => $config->cacheDir(),
        ] + $config->cli();
    }
}
