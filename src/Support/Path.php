<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

use TailwindPhp\Exception\PathException;

/**
 * Path normalisation and confinement — the control for design doc 12 threats
 * T-01 (traversal via configuration), T-02 (traversal via HTTP) and T-14
 * (symlink escape).
 *
 * Every path this library reads or writes passes through {@see self::resolve()}
 * or {@see self::confine()} first. The class is static and side-effect free
 * apart from `realpath()`, which is the only filesystem call it makes: that is
 * deliberate, because `realpath()` is what actually follows symlinks, junctions
 * and `..` through the real tree, and any confinement decision made without it
 * is only a decision about a string. (`is_link()` is *not* used: it reports
 * false for a Windows directory junction, which `realpath()` nonetheless
 * follows, so a link check would be a false sense of safety on the one platform
 * where junctions are common.) `realpath()` is always iterated to a fixpoint,
 * because on Windows it resolves one reparse point per call: a chain of
 * junctions would otherwise look confined while a write followed it out.
 *
 * Paths are handled as **bytes** and are returned with forward slashes on every
 * platform, so a value can be compared, hashed and stored in a cache artefact
 * identically on Linux and Windows (ADR-014). Windows drive letters, UNC shares
 * and the `\\?\` extended-length prefix are understood on Windows only
 * (design doc 26 Q13, best-effort) — on POSIX a leading `//` is just a root,
 * because treating it as a share prefix would let `//x/../etc/passwd` escape.
 * Wherever a construct is ambiguous the answer is to reject it rather than to
 * guess: a wrong guess here is a path-traversal vulnerability.
 */
final class Path
{
    /**
     * Replaces a path that must not be echoed back.
     */
    private const NOT_A_COMPONENT = ['', '.', '..'];

    /**
     * Upper bound on {@see self::realpathFixpoint()} iterations.
     *
     * Above the operating system's own limit — Windows follows up to 63 reparse
     * points — so that whatever the OS is willing to traverse, this check
     * traverses too, and the bound is only ever reached by a cycle.
     */
    private const REALPATH_ROUNDS = 128;

    private function __construct()
    {
    }

    /**
     * A NUL byte truncates the path inside any C-level filesystem call, so
     * "safe.css\0../../etc/passwd" would pass a naive suffix check and then open
     * something else entirely (threat T-01).
     */
    public static function hasNul(string $path): bool
    {
        return \strpos($path, "\0") !== false;
    }

    /**
     * True for anything carrying a stream-wrapper scheme (`phar://`, `file://`,
     * `http://`, …) or the schemeless `data:` wrapper, matched
     * case-insensitively because PHP resolves wrappers case-insensitively
     * (threat T-01).
     */
    public static function isWrapper(string $path): bool
    {
        return Regex::match('#^(?:[A-Za-z][A-Za-z0-9+.\-]*://|data:)#i', $path);
    }

    /**
     * Collapse a path lexically: forward slashes, no `.`, no redundant
     * separators, and `..` resolved against the preceding segment.
     *
     * Purely textual — it never touches the filesystem, so it is safe to call on
     * a path that does not exist and it cannot be tricked by a symlink. That
     * also means it is not sufficient on its own for confinement: use
     * {@see self::resolve()}, which additionally consults `realpath`.
     *
     * A `..` that would climb above an absolute root is dropped, matching the
     * kernel's behaviour for `/..`; in a relative path it is kept, because
     * "../sibling" is meaningful until it is joined to a base.
     */
    public static function normalize(string $path): string
    {
        $slashed = \str_replace('\\', '/', $path);

        [$prefix, $remainder, $absolute] = self::splitPrefix($slashed);

        return self::join($prefix, self::collapse($remainder, $absolute), $absolute);
    }

    /**
     * Resolve a configured path and prove it lands inside an allowed root.
     *
     * The order matters and is the T-01 control in full:
     *  1. reject NUL bytes and stream wrappers on the *raw* input, before any
     *     normalisation can hide them;
     *  2. join a relative path to $base;
     *  3. prefer `realpath()`;
     *  4. when the target does not exist yet and the caller allows that,
     *     resolve the **longest existing ancestor** with `realpath()` and
     *     re-append the missing tail lexically — a purely lexical answer would
     *     miss a symlink or junction sitting in the middle of the path, which is
     *     precisely the `cache_dir` case and therefore the only write path
     *     (design doc 11 §5, threat T-01/T-14);
     *  5. confine the result to $allowedRoots.
     *
     * `realpath()` also returns false when `open_basedir` hides the target; with
     * $mustExist that is reported as "does not exist", which is the safe reading.
     *
     * @param string $base Absolute directory a relative $path is joined to.
     * @param string $path The configured path, relative or absolute.
     * @param list<string> $allowedRoots Roots the result must sit inside; the caller
     *                                   is expected to include the project root. An
     *                                   empty list allows nothing.
     * @param bool $mustExist Whether a missing target is an error.
     *
     * @throws PathException When the path is malformed, missing, or escapes the roots.
     */
    public static function resolve(string $base, string $path, array $allowedRoots, bool $mustExist): string
    {
        if (self::hasNul($path) || self::hasNul($base)) {
            throw new PathException('Path contains a NUL byte', self::hasNul($path) ? $path : null);
        }

        if ($path === '') {
            throw new PathException('Path is empty');
        }

        if (self::isWrapper($path)) {
            throw new PathException('Stream wrappers are not allowed', $path);
        }

        $normalizedBase = self::normalize($base);

        if (!self::isAbsolute($normalizedBase)) {
            throw new PathException('Base directory must be an absolute path');
        }

        $normalizedPath = self::normalize($path);

        if (self::isDriveRelative($normalizedPath)) {
            // "C:file" is resolved against a per-drive current directory we have
            // no way to know. Ambiguous, therefore refused.
            throw new PathException('Drive-relative paths are not supported', $path);
        }

        $candidate = self::isAbsolute($normalizedPath)
            ? $normalizedPath
            : self::normalize($normalizedBase . '/' . $normalizedPath);

        // A non-convergent chain throws out of here, so the ancestor walk below
        // can never run for one.
        $real = self::realpathFixpoint($candidate);

        if ($real !== null) {
            $resolved = self::normalize($real);
        } elseif ($mustExist) {
            throw new PathException('Path does not exist or is not readable', $path);
        } else {
            $resolved = self::resolveThroughExistingAncestor($candidate);
        }

        foreach ($allowedRoots as $root) {
            $normalizedRoot = self::normalizeRoot($root);

            if ($normalizedRoot !== null && self::isWithin($resolved, $normalizedRoot)) {
                return $resolved;
            }
        }

        throw new PathException('Path resolves outside the allowed roots', $path);
    }

    /**
     * Assert that an absolute path sits inside $root and return it normalised.
     * Used on the HTTP side once a route has produced a filename (threat T-02).
     *
     * Both arguments are validated: a NUL byte, a stream wrapper, an empty
     * string or a non-absolute value is refused rather than interpreted. In
     * particular an empty root is **not** "allow everything".
     *
     * @throws PathException When either argument is unusable or $abs is outside $root.
     */
    public static function confine(string $abs, string $root): string
    {
        $normalizedPath = self::requireAbsolute($abs, 'Path');
        $normalizedRoot = self::requireAbsolute($root, 'Root');

        if (!self::isWithin($normalizedPath, $normalizedRoot)) {
            throw new PathException('Path is outside the permitted directory', $abs);
        }

        return $normalizedPath;
    }

    /**
     * Express $abs relative to $root, with forward slashes and no leading
     * separator — the form every cache artefact stores (ADR-014), so that a
     * project can be moved or deployed under a release directory without
     * invalidating the cache or leaking the server's layout.
     *
     * Returns '' when $abs is $root itself.
     *
     * @throws PathException When either argument is unusable or $abs is outside $root.
     */
    public static function relative(string $root, string $abs): string
    {
        $normalizedPath = self::confine($abs, $root);
        $normalizedRoot = \rtrim(self::normalize($root), '/');

        if ($normalizedRoot === '') {
            return \ltrim($normalizedPath, '/');
        }

        if (self::pathsEqual($normalizedPath, $normalizedRoot)) {
            return '';
        }

        return \substr($normalizedPath, \strlen($normalizedRoot) + 1);
    }

    /**
     * True when following $abs through the real filesystem lands outside every
     * allowed root — the T-14 control.
     *
     * Resolution walks up to the nearest **existing** ancestor, so a link in the
     * middle of a path whose tail has not been created yet is still caught. If
     * that ancestor escapes the roots the answer is true; only when nothing on
     * the path resolves at all is it false, because then there is no link to
     * follow and existence is {@see self::resolve()}'s business.
     *
     * Malformed input (NUL byte, wrapper, empty, not absolute) answers **true**:
     * this is a predicate guarding a write, so the fail-safe direction is to
     * call it an escape rather than to throw into a boolean context.
     *
     * @param list<string> $allowedRoots
     */
    public static function isSymlinkEscape(string $abs, array $allowedRoots): bool
    {
        if (self::hasNul($abs) || $abs === '' || self::isWrapper($abs)) {
            return true;
        }

        $normalized = self::normalize($abs);

        if (!self::isAbsolute($normalized)) {
            return true;
        }

        [$prefix, $remainder, $absolute] = self::splitPrefix($normalized);
        $segments = $remainder === '' ? [] : \explode('/', $remainder);

        try {
            while (true) {
                $real = self::realpathFixpoint(self::join($prefix, $segments, $absolute));

                if ($real !== null) {
                    return !self::isInsideAny(self::normalize($real), $allowedRoots);
                }

                if ($segments === []) {
                    return false;
                }

                \array_pop($segments);
            }
        } catch (PathException $e) {
            // A chain that will not settle — a cycle, or longer than anything
            // the OS follows. Unknowable means unsafe for a predicate guarding
            // a write.
            return true;
        }
    }

    /**
     * `realpath()` iterated until the answer stops changing.
     *
     * On Windows `realpath()` resolves **one** reparse point per call, so a
     * chain of junctions (`link2 -> link -> outside`) resolves only to `link`
     * and a single call would report the path as still inside the project while
     * a write followed the chain all the way out. Iterating to a fixpoint closes
     * that; the bound stops a pathological cycle from spinning.
     *
     * Non-convergence and non-existence must stay **distinguishable**. Folding
     * them together was a real escape: a chain longer than the bound looked
     * like "does not exist", the caller fell back to the ancestor walk, and the
     * missing tail was re-appended lexically — so a write followed the chain
     * out of the project. Non-existence returns null (the caller decides);
     * non-convergence throws, which fails closed everywhere.
     *
     * @return string|null Null when the path does not exist.
     *
     * @throws PathException When resolution does not settle within the bound.
     */
    private static function realpathFixpoint(string $path): ?string
    {
        $current = \realpath($path);

        if ($current === false) {
            return null;
        }

        for ($round = 0; $round < self::REALPATH_ROUNDS; ++$round) {
            $next = \realpath($current);

            if ($next === false) {
                return null;
            }

            if ($next === $current) {
                return $current;
            }

            $current = $next;
        }

        throw new PathException('Path could not be resolved: too many links to follow');
    }

    /**
     * Resolve the longest existing ancestor of an absolute path and re-append
     * whatever was missing, so a link part-way along the path is still followed.
     */
    private static function resolveThroughExistingAncestor(string $normalizedAbsolute): string
    {
        [$prefix, $remainder, $absolute] = self::splitPrefix($normalizedAbsolute);
        $segments = $remainder === '' ? [] : \explode('/', $remainder);
        $tail = [];

        while (true) {
            $real = self::realpathFixpoint(self::join($prefix, $segments, $absolute));

            if ($real !== null) {
                $resolved = self::normalize($real);

                return $tail === []
                    ? $resolved
                    : \rtrim($resolved, '/') . '/' . \implode('/', $tail);
            }

            if ($segments === []) {
                // Nothing on this path exists; the lexical form is all there is.
                return $normalizedAbsolute;
            }

            \array_unshift($tail, (string) \array_pop($segments));
        }
    }

    /**
     * @param list<string> $allowedRoots
     */
    private static function isInsideAny(string $resolved, array $allowedRoots): bool
    {
        foreach ($allowedRoots as $root) {
            $normalizedRoot = self::normalizeRoot($root);

            if ($normalizedRoot !== null && self::isWithin($resolved, $normalizedRoot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a raw argument and return it normalised.
     *
     * @throws PathException
     */
    private static function requireAbsolute(string $value, string $label): string
    {
        if (self::hasNul($value)) {
            throw new PathException('Path contains a NUL byte', $value);
        }

        if ($value === '') {
            throw new PathException($label . ' is empty');
        }

        if (self::isWrapper($value)) {
            throw new PathException('Stream wrappers are not allowed', $value);
        }

        $normalized = self::normalize($value);

        if (!self::isAbsolute($normalized)) {
            throw new PathException($label . ' must be an absolute path', $value);
        }

        return $normalized;
    }

    /**
     * Resolve `.`, `..` and empty components against a remainder.
     *
     * @return list<string>
     */
    private static function collapse(string $remainder, bool $absolute): array
    {
        $segments = [];

        foreach (\explode('/', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $segments[] = $segment;

                continue;
            }

            $last = \end($segments);

            if ($segments !== [] && $last !== '..') {
                \array_pop($segments);

                continue;
            }

            if (!$absolute) {
                $segments[] = '..';
            }
        }

        return $segments;
    }

    /**
     * @param list<string> $segments
     */
    private static function join(string $prefix, array $segments, bool $absolute): string
    {
        $joined = \implode('/', $segments);

        if ($joined === '') {
            if ($prefix === '') {
                return $absolute ? '/' : '';
            }

            // A drive prefix is not itself a location ("C:" means the drive's
            // current directory), so an absolute drive root keeps its separator.
            return \substr($prefix, -1) === ':' && $absolute ? $prefix . '/' : $prefix;
        }

        if ($prefix !== '') {
            return $absolute ? $prefix . '/' . $joined : $prefix . $joined;
        }

        return $absolute ? '/' . $joined : $joined;
    }

    /**
     * Split a slash-normalised path into [prefix, remainder, isAbsolute].
     *
     * UNC and `\\?\` prefixes are recognised **on Windows only**. On POSIX a
     * leading `//` is an ordinary root: treating it as a share prefix would make
     * the prefix unsplittable by `..` collapsing and let `//x/../etc/passwd`
     * through confinement.
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private static function splitPrefix(string $path): array
    {
        if (self::isWindows()) {
            $windows = self::splitWindowsPrefix($path);

            if ($windows !== null) {
                return $windows;
            }
        }

        // Drive letters are understood on every platform: the lexical rules are
        // platform-independent and a "C:/…" value on POSIX simply will not match
        // any allowed root.
        if (\strlen($path) >= 2 && $path[1] === ':' && self::isAsciiAlpha($path[0])) {
            $rest = \substr($path, 2);
            $absolute = $rest !== '' && $rest[0] === '/';

            return [\strtoupper($path[0]) . ':', \ltrim($rest, '/'), $absolute];
        }

        if ($path !== '' && $path[0] === '/') {
            return ['', \ltrim($path, '/'), true];
        }

        return ['', $path, false];
    }

    /**
     * @return array{0: string, 1: string, 2: bool}|null Null when $path is not UNC/extended.
     */
    private static function splitWindowsPrefix(string $path): ?array
    {
        // Extended-length (\\?\) and device (\\.\) prefixes.
        if (
            \strlen($path) >= 4
            && $path[0] === '/'
            && $path[1] === '/'
            && ($path[2] === '?' || $path[2] === '.')
            && $path[3] === '/'
        ) {
            $rest = \substr($path, 4);
            $marker = '//' . $path[2] . '/';

            if (\strncasecmp($rest, 'UNC/', 4) === 0) {
                $share = self::takeShare(\substr($rest, 4));

                if ($share !== null) {
                    return [$marker . 'UNC/' . $share[0], $share[1], true];
                }

                return null;
            }

            if (\strlen($rest) >= 2 && $rest[1] === ':' && self::isAsciiAlpha($rest[0])) {
                return [$marker . \strtoupper($rest[0]) . ':', \ltrim(\substr($rest, 2), '/'), true];
            }

            return null;
        }

        // UNC share: //server/share/...
        if (\strlen($path) >= 3 && $path[0] === '/' && $path[1] === '/' && $path[2] !== '/') {
            $share = self::takeShare(\substr($path, 2));

            if ($share !== null) {
                return ['//' . $share[0], $share[1], true];
            }
        }

        return null;
    }

    /**
     * Consume "server/share" from the front of a UNC remainder.
     *
     * Returns null when either component is missing, `.` or `..`: those are not
     * host or share names, and accepting them would freeze a traversal sequence
     * into the prefix where `..` collapsing can no longer remove it.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function takeShare(string $rest): ?array
    {
        $parts = \explode('/', $rest);

        $server = \array_shift($parts);

        if ($server === null || \in_array($server, self::NOT_A_COMPONENT, true)) {
            return null;
        }

        $share = \array_shift($parts);

        if ($share === null || $share === '') {
            return [$server, \implode('/', $parts)];
        }

        if (\in_array($share, self::NOT_A_COMPONENT, true)) {
            return null;
        }

        return [$server . '/' . $share, \implode('/', $parts)];
    }

    /**
     * A normalised path is absolute when it starts at a POSIX root, a UNC share
     * or a drive followed by a separator.
     */
    private static function isAbsolute(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if ($normalized[0] === '/') {
            return true;
        }

        return \strlen($normalized) >= 3
            && $normalized[1] === ':'
            && $normalized[2] === '/'
            && self::isAsciiAlpha($normalized[0]);
    }

    /**
     * "C:file" — a drive with no separator, meaning "relative to that drive's
     * own current directory".
     */
    private static function isDriveRelative(string $normalized): bool
    {
        return \strlen($normalized) >= 2
            && $normalized[1] === ':'
            && self::isAsciiAlpha($normalized[0])
            && !self::isAbsolute($normalized);
    }

    /**
     * Prefix containment with a trailing separator, so that `/app2` is not
     * mistaken for a child of `/app` — the byte-prefix trap design doc 12 T-01
     * calls out explicitly.
     */
    private static function isWithin(string $path, string $root): bool
    {
        if ($root === '') {
            // Unreachable through the public API: every entry point rejects an
            // empty root. Answering false keeps "no root" meaning "nothing is
            // inside" rather than "everything is".
            return false;
        }

        $trimmedRoot = \rtrim($root, '/');

        if ($trimmedRoot === '') {
            // The POSIX root itself: every absolute path is inside it.
            return \strncmp($path, '/', 1) === 0;
        }

        if (self::pathsEqual($path, $trimmedRoot)) {
            return true;
        }

        $withSeparator = $trimmedRoot . '/';

        return self::pathsEqual(\substr($path, 0, \strlen($withSeparator)), $withSeparator);
    }

    /**
     * Compare two path fragments byte-wise, case-insensitively on Windows where
     * the filesystem itself is case-insensitive (otherwise `C:/App` would slip
     * past a confinement check against `C:/app`).
     */
    private static function pathsEqual(string $a, string $b): bool
    {
        if (self::isWindows()) {
            return \strcasecmp($a, $b) === 0;
        }

        return $a === $b;
    }

    /**
     * Normalise an allowed root, preferring its real location.
     *
     * Absoluteness is checked **before** `realpath()`: otherwise a relative root
     * such as "src" would be resolved against the process working directory and
     * silently become a real root, which is confinement decided by `getcwd()`.
     *
     * A root that does not exist yet gets the same ancestor walk as a path, so
     * that a root reached through a link (`current/newroot` where
     * `current -> proj`) is compared in the same terms as the path it is being
     * compared against — otherwise the two sides disagree and a legitimate
     * layout is refused.
     */
    private static function normalizeRoot(string $root): ?string
    {
        if ($root === '' || self::hasNul($root) || self::isWrapper($root)) {
            return null;
        }

        $normalized = self::normalize($root);

        if (!self::isAbsolute($normalized)) {
            return null;
        }

        try {
            $real = self::realpathFixpoint($normalized);

            return $real !== null
                ? self::normalize($real)
                : self::resolveThroughExistingAncestor($normalized);
        } catch (PathException $e) {
            // A root we cannot resolve is a root we cannot confine against, so
            // it is dropped rather than guessed at.
            return null;
        }
    }

    /**
     * ASCII-only letter test.
     *
     * `ctype_alpha()` is avoided on purpose: it needs `ext-ctype`, which is not
     * a declared dependency (ADR-008), and it is locale-dependent — under some
     * locales a high byte counts as alphabetic, which would let "\xC9:/x" be
     * read as a drive prefix.
     */
    private static function isAsciiAlpha(string $byte): bool
    {
        $ordinal = \ord($byte);

        return ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);
    }

    private static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR === '\\';
    }
}
