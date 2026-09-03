<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Exception\DriverUnavailableException;

/**
 * Decides which {@see CompilerInterface} this request gets (design doc 06
 * §Resolution, ADR-015).
 *
 * An interface because resolution touches the environment — `function_exists`,
 * `disable_functions`, a `stat` and possibly a SHA-256 of the CLI binary — and
 * that has to be substitutable in tests (design doc 18 §Design rules).
 *
 * It runs on **every** request, so the contract is built around being cheap:
 * the caller passes in the previous run's probe from the manifest, and a
 * resolver that finds it still valid may skip re-hashing the binary entirely
 * (design doc 06 §Availability probe).
 */
interface DriverResolverInterface
{
    /**
     * @param array<string, mixed> $cliConfig The `cli` section of the configuration
     *                                        (`binary`, `verify`, `timeout`, …). A plain
     *                                        array rather than a config object: `Compiler`
     *                                        only needs these few values, and taking the
     *                                        whole object would tie the two together for
     *                                        no gain (design doc 17 §Layering).
     * @param array<string, mixed>|null $cachedProbe The manifest's `driver_probe`
     *                                               (`{binary, m, s, sha256, version}`), or
     *                                               null when there is nothing cached.
     *
     * @throws DriverUnavailableException When the configuration demands `cli` and
     *                                    the binary is missing, unverified or
     *                                    unusable. In `auto` mode this never
     *                                    throws — it falls back to the PHP engine
     *                                    and records why in
     *                                    {@see DriverResolution::reason()}.
     */
    public function resolve(array $cliConfig, ?array $cachedProbe): DriverResolution;
}
