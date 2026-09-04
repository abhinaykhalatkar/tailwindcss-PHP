<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

/**
 * Directory-level ignore predicate for {@see FileFinder} (design doc 05
 * §Layer 1, design doc 13 #9, design decision CSF-A6).
 *
 * Used as the callback of a `RecursiveCallbackFilterIterator`: returning
 * false for a directory excludes it and everything under it without ever
 * descending, which is what keeps `node_modules`/`vendor` out of the walk
 * entirely rather than merely out of the result (design doc 13 §Snapshot and
 * scan #9). Files are never matched — extension filtering happens afterwards
 * in {@see FileFinder} itself (CSF-A6).
 *
 * Matching (CSF-A6): an ignore entry containing a glob metacharacter
 * (`*`, `?`, `[`) is matched with `\fnmatch()`; anything else is matched
 * exactly. An entry containing `/` is compared against the directory's path
 * relative to the scan root; otherwise against the directory's basename.
 * `.git` and `node_modules` are always skipped; `vendor` is skipped unless
 * the scan root itself sits inside it (a `sources: ['vendor/acme/theme']`
 * root must still be walkable); the cache directory is always skipped.
 */
final class IgnoreFilter
{
    /**
     * @var string
     */
    private $rootAbs;

    /**
     * @var string
     */
    private $cacheDirAbs;

    /**
     * @var list<string>
     */
    private $ignore;

    /**
     * @param string $rootAbs Absolute path of the scan root being walked, no
     *                        trailing separator.
     * @param string $cacheDirAbs Absolute path of the configured cache
     *                            directory, no trailing separator.
     * @param list<string> $ignore Directory names or root-relative glob
     *                             patterns (design doc 11 `ignore`).
     */
    public function __construct(string $rootAbs, string $cacheDirAbs, array $ignore)
    {
        $this->rootAbs = \rtrim($rootAbs, '/');
        $this->cacheDirAbs = \rtrim($cacheDirAbs, '/');
        $this->ignore = $ignore;
    }

    /**
     * Callback signature expected by `RecursiveCallbackFilterIterator`.
     *
     * @param mixed $key Unused; present to match the expected callback arity.
     * @param mixed $iterator Unused; present to match the expected callback arity.
     */
    public function accept(\SplFileInfo $current, $key, $iterator): bool
    {
        if (!$current->isDir()) {
            return true;
        }

        $abs = \rtrim(\str_replace('\\', '/', $current->getPathname()), '/');
        $basename = $current->getFilename();

        if ($basename === '.git' || $basename === 'node_modules') {
            return false;
        }

        if ($basename === 'vendor' && !$this->isAncestorOrSelf($abs)) {
            return false;
        }

        if ($abs === $this->cacheDirAbs) {
            return false;
        }

        foreach ($this->ignore as $entry) {
            if (\strpos($entry, '/') !== false) {
                $relativeToRoot = $this->relativeToRoot($abs);

                if ($relativeToRoot !== null && self::matches($entry, $relativeToRoot)) {
                    return false;
                }

                continue;
            }

            if (self::matches($entry, $basename)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $abs is $this->rootAbs itself or one of its ancestors — the
     * "unless the root is inside it" exception for a `vendor` directory.
     */
    private function isAncestorOrSelf(string $abs): bool
    {
        return $this->rootAbs === $abs
            || \strncmp($this->rootAbs, $abs . '/', \strlen($abs) + 1) === 0;
    }

    private function relativeToRoot(string $abs): ?string
    {
        if ($abs === $this->rootAbs) {
            return '';
        }

        $prefix = $this->rootAbs . '/';

        if (\strncmp($abs, $prefix, \strlen($prefix)) !== 0) {
            return null;
        }

        return \substr($abs, \strlen($prefix));
    }

    private static function matches(string $pattern, string $subject): bool
    {
        if (\strpbrk($pattern, '*?[') !== false) {
            return \fnmatch($pattern, $subject);
        }

        return $pattern === $subject;
    }
}
