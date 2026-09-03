<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

/**
 * The per-compile output settings handed to {@see CompilerInterface::compile()}
 * (design doc 06 §The contract).
 *
 * A value object rather than three loose parameters so the drivers keep one
 * signature as the set grows, and so the settings can be passed straight
 * through the pipeline without being unpacked and re-packed at every layer.
 *
 * Immutable: the flags are set once in the constructor and exposed through
 * getters named after the property (design doc 18 §Design rules, §Naming).
 */
final class CompileOptions
{
    /**
     * @var bool
     */
    private $minify;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var bool
     */
    private $sourceMap;

    /**
     * @param bool $minify Emit compact CSS (the engine's serializer, or `--minify` for the CLI).
     * @param bool $debug Collect the extra detail the debug overlay renders.
     * @param bool $sourceMap Reserved (Q12, ADR-deferred): **M1 ignores this
     *                        flag entirely**. It exists so adding source maps
     *                        after 1.0 is not a signature change; until then
     *                        every caller leaves it false and every driver
     *                        behaves as if it were.
     */
    public function __construct(bool $minify, bool $debug, bool $sourceMap = false)
    {
        $this->minify = $minify;
        $this->debug = $debug;
        $this->sourceMap = $sourceMap;
    }

    public function minify(): bool
    {
        return $this->minify;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    /**
     * Reserved for post-1.0 source-map support; no driver acts on it in M1.
     */
    public function sourceMap(): bool
    {
        return $this->sourceMap;
    }
}
