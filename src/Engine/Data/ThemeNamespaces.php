<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data;

/**
 * The theme namespaces a broader namespace must not match (design doc 07
 * §Theme; TASK-0051): a lookup in `font` ignores the variables of `font-weight`
 * and `font-size`, so `font-weight-bold` is no font family and `text-shadow-sm`
 * no font size, as the CLI has it.
 *
 * Data copied from `ignoredThemeKeyMap` in the bundled source of the release
 * pinned in tests/Conformance/golden/VERSION (4.1.18): five entries. The CLI
 * writes namespaces with their leading `--`; here they are written as the
 * registry's namespaces are, without it. Update it together with the pinned
 * version.
 *
 * Provenance only (a table of names, no text copied): upstream is Tailwind CSS,
 * https://github.com/tailwindlabs/tailwindcss, MIT licence; tailwindcss-php is
 * independent of Tailwind Labs and is not endorsed by it.
 */
final class ThemeNamespaces
{
    /**
     * Namespace => the sub-namespaces a lookup in it skips.
     *
     * @var array<string, list<string>>
     */
    public const IGNORED = [
        'font' => ['font-weight', 'font-size'],
        'inset' => ['inset-shadow', 'inset-ring'],
        'text' => [
            'text-color',
            'text-decoration-color',
            'text-decoration-thickness',
            'text-indent',
            'text-shadow',
            'text-underline-offset',
        ],
        'grid-column' => ['grid-column-start', 'grid-column-end'],
        'grid-row' => ['grid-row-start', 'grid-row-end'],
    ];

    private function __construct()
    {
    }

    /**
     * Whether `--<namespace>-<key>` belongs to an ignored sub-namespace of
     * `$namespace`: its name is that sub-namespace or goes on from it with a
     * dash (`font` + `weight-bold` → `font-weight-bold`). The bare variable
     * (an empty key) is never ignored. One `strncmp` per ignored name (at
     * most six), no scan of the theme.
     */
    public static function isIgnored(string $namespace, string $key): bool
    {
        if (!isset(self::IGNORED[$namespace])) {
            return false;
        }

        // An empty key names the bare variable, `--<namespace>`: `<namespace>-` is no ignored name.
        $name = $namespace . '-' . $key;

        foreach (self::IGNORED[$namespace] as $ignored) {
            $length = \strlen($ignored);

            if (\strncmp($name, $ignored, $length) === 0 && (\strlen($name) === $length || $name[$length] === '-')) {
                return true;
            }
        }

        return false;
    }
}
