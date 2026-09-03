<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Import;

use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Path;

/**
 * Resolves `@import` specifiers against the real filesystem (CCP-A8).
 *
 * This is a confinement boundary, not a convenience. The specifier is read out
 * of a stylesheet — a file the project owns, but often copied from somewhere —
 * so `@import "../../../../etc/passwd"` is an input this class must answer, not
 * an input it can assume away.
 *
 * Three rules carry the safety, and each is a `null` rather than an exception,
 * because "leave the `@import` for the driver" is always a valid outcome and an
 * exception here would turn a stray specifier into a broken build:
 *
 * - **Bare specifiers are not ours.** `"tailwindcss"`, `"tailwindcss/utilities"`
 *   name packages the compiler resolves itself. Only `./`, `../` and paths
 *   containing a slash-or-extension we can see are treated as files. Getting
 *   this backwards would make us try to read `tailwindcss` off the disk.
 * - **Every candidate goes through {@see Path::resolve()}** with the allowed
 *   roots, `mustExist = true`. That is the single implementation of "is this
 *   path inside somewhere I may read", including the symlink walk (T-01, T-14).
 * - **Wrappers never reach the filesystem.** `php://filter/...` is neither
 *   absolute nor traversing, so it would pass a naive check; it is refused
 *   before anything else (T-08).
 *
 * Reads go through the facade with a byte cap, so one enormous file cannot be
 * turned into a memory exhaustion (T-05).
 *
 * **Depth and cycles belong to the caller** — see {@see ImportResolverInterface}.
 */
final class FilesystemImportResolver implements ImportResolverInterface
{
    /**
     * Suffix forms tried in order. The bare spec first (`x.css` as written),
     * then `.css` appended (`x` → `x.css`), and the underscore-prefixed partial
     * last, because that is the least-expected form and should not shadow a
     * real file of the same name.
     */
    private const PARTIAL_PREFIX = '_';

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var list<string>
     */
    private $allowedRoots;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var int
     */
    private $maxBytes;

    /**
     * @param string $projectRoot Absolute; the root every returned path is
     *                            expressed relative to (ADR-014).
     * @param list<string> $allowedRoots Absolute confinement roots. A resolved
     *                                   file must lie inside one of them.
     * @param int $maxBytes Read cap per imported file (T-05).
     */
    public function __construct(
        string $projectRoot,
        array $allowedRoots,
        FilesystemInterface $fs,
        int $maxBytes
    ) {
        $this->projectRoot = $projectRoot;
        $this->allowedRoots = \array_values($allowedRoots);
        $this->fs = $fs;
        $this->maxBytes = $maxBytes;
    }

    public function resolve(string $spec, string $fromRelPath): ?ResolvedImport
    {
        if (!$this->looksLikeAPath($spec)) {
            return null;
        }

        // The importing file's directory. `dirname()` of a bare name is '.',
        // which joins harmlessly below.
        $fromDir = \dirname($fromRelPath);
        $base = $fromDir === '.' || $fromDir === '' ? $this->projectRoot : $this->projectRoot . '/' . $fromDir;

        foreach ($this->candidatesFor($spec) as $candidate) {
            $absolute = $this->resolveWithin($base, $candidate);

            if ($absolute === null) {
                continue;
            }

            if (!$this->fs->isFile($absolute)) {
                continue;
            }

            return new ResolvedImport(
                $this->relative($absolute),
                $this->fs->read($absolute, $this->maxBytes)
            );
        }

        return null;
    }

    /**
     * Whether the specifier addresses a file rather than a package.
     *
     * A bare word with no slash and no extension — `tailwindcss` — is a package
     * name the driver resolves; trying to read it off disk would at best fail
     * and at worst pick up an unrelated file that happens to share the name.
     */
    private function looksLikeAPath(string $spec): bool
    {
        if ($spec === '' || Path::hasNul($spec) || Path::isWrapper($spec)) {
            return false;
        }

        if (\strncmp($spec, './', 2) === 0 || \strncmp($spec, '../', 3) === 0) {
            return true;
        }

        // A package subpath such as "tailwindcss/utilities" also contains a
        // slash, so a slash alone is not enough — but a `.css` extension is
        // unambiguous, and a relative path without either is indistinguishable
        // from a package name, so it is left to the driver.
        return \substr($spec, -4) === '.css';
    }

    /**
     * The spellings to try, in order: as written, then with `.css` appended,
     * then the `_partial.css` form.
     *
     * @return list<string>
     */
    private function candidatesFor(string $spec): array
    {
        if (\substr($spec, -4) === '.css') {
            $dir = \dirname($spec);
            $file = \basename($spec);

            return [
                $spec,
                ($dir === '.' ? '' : $dir . '/') . self::PARTIAL_PREFIX . $file,
            ];
        }

        $dir = \dirname($spec);
        $file = \basename($spec);
        $prefix = $dir === '.' ? '' : $dir . '/';

        return [
            $spec . '.css',
            $prefix . self::PARTIAL_PREFIX . $file . '.css',
        ];
    }

    /**
     * Resolve one candidate and confine it, or answer null.
     *
     * `Path::resolve()` is the single implementation of the confinement rule —
     * it normalises, follows links to a fixpoint and checks containment. A
     * `PathException` here means "outside, missing or malformed", all of which
     * are the same answer to a caller: not ours.
     */
    private function resolveWithin(string $base, string $candidate): ?string
    {
        try {
            return Path::resolve($base, $candidate, $this->allowedRoots, true);
        } catch (PathException $e) {
            return null;
        }
    }

    /**
     * Project-relative, because the result becomes a manifest key (ADR-014).
     */
    private function relative(string $absolute): string
    {
        try {
            return Path::relative($this->projectRoot, $absolute);
        } catch (PathException $e) {
            // Inside an allowed root but outside the project root: there is no
            // project-relative form, so the absolute path is all there is.
            return $absolute;
        }
    }
}
