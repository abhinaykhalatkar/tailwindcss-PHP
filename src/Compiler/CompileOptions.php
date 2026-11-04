<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Support\Path;

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
     * @var string
     */
    private $entryRelPath;
    /**
     * @param bool $minify Emit compact CSS (the engine's serializer, or `--minify` for the CLI).
     * @param bool $debug Collect the extra detail the debug overlay renders.
     * @param bool $sourceMap Reserved (Q12, ADR-deferred): **M1 ignores this
     *                        flag entirely**. It exists so adding source maps
     *                        after 1.0 is not a signature change; until then
     *                        every caller leaves it false and every driver
     *                        behaves as if it were.
     * @param string $entryRelPath The entry stylesheet's project-relative path (ADR-014), so a
     *                             driver resolves the entry's relative `@import`s against its
     *                             directory; `''` (the default) means the project root.
     *
     * @throws \InvalidArgumentException When $entryRelPath is not a plain project-relative path:
     *                                   absolute, a drive or stream path, a `..` segment, or a NUL
     *                                   byte (T-01 — the resolver confines too, this refuses early).
     */
    public function __construct(bool $minify, bool $debug, bool $sourceMap = false, string $entryRelPath = '')
    {
        if (!self::isProjectRelative($entryRelPath)) {
            throw new \InvalidArgumentException('The entry path must be project-relative.');
        }

        $this->minify = $minify;
        $this->debug = $debug;
        $this->sourceMap = $sourceMap;
        $this->entryRelPath = $entryRelPath;
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

    /**
     * The entry's project-relative path, `''` for the project root.
     */
    public function entryRelPath(): string
    {
        return $this->entryRelPath;
    }

    private static function isProjectRelative(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (Path::hasNul($path) || Path::isWrapper($path) || $path[0] === '/' || $path[0] === '\\') {
            return false;
        }

        $drive = \strlen($path) >= 2 && $path[1] === ':'
            && \stripos('abcdefghijklmnopqrstuvwxyz', $path[0]) !== false;

        if ($drive) {
            return false;
        }

        foreach (\explode('/', \str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
