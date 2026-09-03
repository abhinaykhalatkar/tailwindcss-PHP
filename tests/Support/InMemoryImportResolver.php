<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Engine\Import\ResolvedImport;

/**
 * An `@import` resolver backed by an array instead of a disk (CCP-A8).
 *
 * The compiler owns the interesting half of importing — depth limits, cycle
 * detection, the order inlined CSS ends up in — and none of that needs files to
 * exercise. Writing a five-file import graph to a temp directory to prove that
 * `a → b → a` is caught would make a unit test slow, platform-sensitive, and no
 * more convincing. So the unit suite injects this and passes a map (design doc
 * 16 §Unit test conventions: no filesystem, no clock).
 *
 * Resolution is **lexical**: `.` and `..` are folded away by string surgery, not
 * by `realpath()`, so the map keys are the only thing that exists and the double
 * behaves identically on every platform. The consequence is that it cannot model
 * a symlink escaping the project root — a test about *confinement* belongs
 * against the real resolver, not against this.
 *
 * Anything it cannot vouch for comes back as `null`, matching the interface
 * contract: an unknown key, and a specifier that climbs above the project root.
 * A bare package name such as `tailwindcss` also returns null, because it is
 * simply not a key in the map — which is the behaviour the driver expects.
 */
final class InMemoryImportResolver implements ImportResolverInterface
{
    /**
     * @var array<string, string> Project-relative path => CSS.
     */
    private $map;

    /**
     * @var list<string> Specifiers asked for, in order, misses included.
     */
    private $requested = [];

    /**
     * @param array<string, string> $map Keys are project-relative, forward
     *                                   slashes, with the `.css` extension.
     */
    public function __construct(array $map)
    {
        $this->map = $map;
    }

    public function resolve(string $spec, string $fromRelPath): ?ResolvedImport
    {
        // Recorded before the lookup: a miss is exactly the case a test about
        // walk order wants to see.
        $this->requested[] = $spec;

        $key = $this->normalize($spec, $fromRelPath);

        if ($key === null || !\array_key_exists($key, $this->map)) {
            return null;
        }

        return new ResolvedImport($key, $this->map[$key]);
    }

    /**
     * The specifiers this resolver was asked for, in order — the compiler's walk
     * made visible, since the CSS it produces shows what was inlined but not
     * what was attempted.
     *
     * @return list<string>
     */
    public function resolved(): array
    {
        return $this->requested;
    }

    /**
     * Fold a specifier and its importer into a map key.
     *
     * @return string|null Null when the result climbs above the project root,
     *                     which the real resolver refuses as outside the
     *                     permitted roots.
     */
    private function normalize(string $spec, string $fromRelPath): ?string
    {
        $spec = \str_replace('\\', '/', $spec);

        // Extensionless specifiers are legal in CSS tooling; the map is keyed by
        // the real filename, so the extension is added here rather than the test
        // having to key the map both ways.
        if (\substr($spec, -4) !== '.css') {
            $spec .= '.css';
        }

        $base = \str_replace('\\', '/', \dirname(\str_replace('\\', '/', $fromRelPath)));

        // dirname() of a root-level file is '.', and a specifier that is already
        // root-relative has no base at all.
        $combined = ($base === '.' || $base === '' || $spec[0] === '/')
            ? $spec
            : $base . '/' . $spec;

        $segments = [];

        foreach (\explode('/', \ltrim($combined, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $segments[] = $segment;

                continue;
            }

            if ($segments === []) {
                return null;
            }

            \array_pop($segments);
        }

        return $segments === [] ? null : \implode('/', $segments);
    }
}
