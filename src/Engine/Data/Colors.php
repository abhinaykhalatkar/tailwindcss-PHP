<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data;

/**
 * Colour names a colour utility accepts without a theme variable (design doc
 * 07 §Value resolution rule 2).
 *
 * `inherit`, `current` and `transparent` are keywords v4 hard-codes for every
 * colour utility (`bg-current` → `background-color: currentcolor`); they win
 * over a theme variable of the same name. `black` and `white` are ordinary
 * theme variables of the default theme (`--color-black`, `--color-white`):
 * they resolve through the theme like any palette colour, so a theme that
 * removes `--color-*` removes them too (checked against the 4.1.18 goldens).
 */
final class Colors
{
    /**
     * Keyword name => the CSS value it emits.
     *
     * @var array<string, string>
     */
    public const SPECIAL = [
        'inherit' => 'inherit',
        'current' => 'currentcolor',
        'transparent' => 'transparent',
    ];

    /**
     * Palette names the default theme defines as variables rather than
     * keywords (documentation for the resolver's order; not consulted).
     *
     * @var list<string>
     */
    public const THEME_NAMED = ['black', 'white'];

    private function __construct()
    {
    }

    public static function special(string $name): ?string
    {
        return self::SPECIAL[$name] ?? null;
    }
}
