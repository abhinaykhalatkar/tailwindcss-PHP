<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Import;

/**
 * One successfully resolved `@import`: where it came from and what it holds
 * (CCP-A8).
 *
 * The path is **project-relative**, because it goes on to become a key in the
 * manifest's `files` map and an entry in `CompileResult::imports()` — both of
 * which are written to disk and must survive the project being moved or
 * deployed under a release directory (ADR-014).
 */
final class ResolvedImport
{
    /**
     * @var string
     */
    private $relPath;

    /**
     * @var string
     */
    private $css;

    /**
     * @param string $relPath Project-relative, forward slashes.
     * @param string $css The file's contents, already read.
     */
    public function __construct(string $relPath, string $css)
    {
        $this->relPath = $relPath;
        $this->css = $css;
    }

    public function relPath(): string
    {
        return $this->relPath;
    }

    public function css(): string
    {
        return $this->css;
    }
}
