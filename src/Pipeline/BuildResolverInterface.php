<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

/**
 * The one call the rest of the library makes to get a stylesheet.
 *
 * It exists so the two halves can be built independently: `AssetPipeline`
 * (wave 2) implements it, and `Http\Responder` (wave 2) consumes it, with this
 * interface — and the {@see BuildResult} it returns — as the whole of the
 * contract between them. The console and the framework bridges sit on the same
 * seam.
 *
 * It is also the seam tests substitute at: a double returning a prepared
 * `BuildResult` exercises every responder branch without a compiler, a cache
 * directory or a clock.
 */
interface BuildResolverInterface
{
    /**
     * @param string $bundle The configured bundle name.
     *
     * @throws \TailwindPhp\Exception\ConfigurationException When no such bundle
     *                                                       is configured.
     */
    public function resolve(string $bundle): BuildResult;
}
