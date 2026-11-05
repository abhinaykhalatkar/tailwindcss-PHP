<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Data;

/**
 * The T1 variant table (design doc 07 §Phase 3c, doc 08 §Variants).
 *
 * **Hand-maintained.** Every row is written out by hand from doc 08 and checked
 * against the 4.1.18 goldens (`tests/Conformance/golden/variants/`); nothing here
 * is generated and nothing is a closure, so the table is plain data that
 * `Variant\Definition::fromRow()` validates once.
 *
 * Row keys: see {@see \TailwindPhp\Engine\Variant\Definition}. `selector` holds
 * `&` where the candidate's class selector goes; a list of selectors makes one
 * nested rule each (`marker`, `selection`).
 *
 * Not rows, on purpose:
 *
 *   - `sm md lg xl 2xl`, `max-sm …`, `min-sm …` are built from the theme's
 *     `--breakpoint-*` variables when `Variant\Registry` is constructed;
 *   - `group-*` and `peer-*` are expanded by the registry from the two
 *     compound templates below, for every pseudo-class row;
 *   - arbitrary variants (`[&>*]:`, `[@media(…)]:`) and `min-[…]`/`max-[…]`
 *     are read from the candidate; the `min` and `max` rows are their templates;
 *   - the T2 variants (`has-*`, `not-*`, `in-*`, `data-*`, `aria-*`, `nth-*`,
 *     `supports-*`, `ltr`, `rtl`, container queries `@sm …`) are M3.
 *
 * **`order`** reproduces v4's variant order, which decides where a variant's rule
 * lands in the sheet (lower sorts first). Source: one `<variant>:flex` candidate
 * for every T1 and T2 variant was compiled together by the pinned CLI and the
 * order the rules came back in was read off, so the numbers below leave room for
 * the T2 variants in their real places. The scale is `(CLI position + 1) * 1000`,
 * with two special cases:
 *
 *   - the compound templates sit at 4000 (`group`) and 5000 (`peer`); a
 *     compound variant's order is the template's plus the inner variant's order
 *     divided by 1000, so `group-first` (4085) sorts before `group-hover`
 *     (4112) and every `group-*` before every `peer-*`, as the CLI does;
 *   - `min` and `max` hold the order of their arbitrary forms (144000 and
 *     137000); the named breakpoints take their places from the theme.
 *
 * Ties (equal orders) are the sort key's business: it falls back to the raw
 * candidate text.
 */
final class Variants
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function table(): array
    {
        return [
            // Child selectors.
            '*' => ['selector' => ':is(& > *)', 'order' => 1000],
            '**' => ['selector' => ':is(& *)', 'order' => 2000],

            // Compound templates: `group-hover`, `peer-checked`, named `group-hover/name`.
            'group' => ['compound' => 'group', 'selector' => '&:is({inner} *)', 'order' => 4000],
            'peer' => ['compound' => 'peer', 'selector' => '&:is({inner} ~ *)', 'order' => 5000],

            // Pseudo-elements.
            'first-letter' => ['selector' => '&::first-letter', 'order' => 75000],
            'first-line' => ['selector' => '&::first-line', 'order' => 76000],
            'marker' => [
                'selector' => ['& *::marker', '&::marker', '& *::-webkit-details-marker', '&::-webkit-details-marker'],
                'order' => 77000,
            ],
            'selection' => ['selector' => ['& *::selection', '&::selection'], 'order' => 78000],
            'file' => ['selector' => '&::file-selector-button', 'order' => 79000],
            'placeholder' => ['selector' => '&::placeholder', 'order' => 80000],
            'backdrop' => ['selector' => '&::backdrop', 'order' => 81000],
            'details-content' => ['selector' => '&::details-content', 'order' => 82000],
            'before' => [
                'selector' => '&::before',
                'inject' => ['content' => 'var(--tw-content)'],
                'order' => 83000,
            ],
            'after' => [
                'selector' => '&::after',
                'inject' => ['content' => 'var(--tw-content)'],
                'order' => 84000,
            ],

            // Pseudo-classes.
            'first' => ['selector' => '&:first-child', 'order' => 85000],
            'last' => ['selector' => '&:last-child', 'order' => 86000],
            'only' => ['selector' => '&:only-child', 'order' => 87000],
            'odd' => ['selector' => '&:nth-child(odd)', 'order' => 88000],
            'even' => ['selector' => '&:nth-child(even)', 'order' => 89000],
            'first-of-type' => ['selector' => '&:first-of-type', 'order' => 90000],
            'last-of-type' => ['selector' => '&:last-of-type', 'order' => 91000],
            'only-of-type' => ['selector' => '&:only-of-type', 'order' => 92000],
            'visited' => ['selector' => '&:visited', 'order' => 93000],
            'target' => ['selector' => '&:target', 'order' => 94000],
            'open' => ['selector' => '&:is([open], :popover-open, :open)', 'order' => 95000],
            'default' => ['selector' => '&:default', 'order' => 96000],
            'checked' => ['selector' => '&:checked', 'order' => 97000],
            'indeterminate' => ['selector' => '&:indeterminate', 'order' => 98000],
            'placeholder-shown' => ['selector' => '&:placeholder-shown', 'order' => 99000],
            'autofill' => ['selector' => '&:autofill', 'order' => 100000],
            'optional' => ['selector' => '&:optional', 'order' => 101000],
            'required' => ['selector' => '&:required', 'order' => 102000],
            'valid' => ['selector' => '&:valid', 'order' => 103000],
            'invalid' => ['selector' => '&:invalid', 'order' => 104000],
            'user-valid' => ['selector' => '&:user-valid', 'order' => 105000],
            'user-invalid' => ['selector' => '&:user-invalid', 'order' => 106000],
            'in-range' => ['selector' => '&:in-range', 'order' => 107000],
            'out-of-range' => ['selector' => '&:out-of-range', 'order' => 108000],
            'read-only' => ['selector' => '&:read-only', 'order' => 109000],
            'empty' => ['selector' => '&:empty', 'order' => 110000],
            'focus-within' => ['selector' => '&:focus-within', 'order' => 111000],
            'hover' => ['selector' => '&:hover', 'media' => '(hover: hover)', 'order' => 112000],
            'focus' => ['selector' => '&:focus', 'order' => 113000],
            'focus-visible' => ['selector' => '&:focus-visible', 'order' => 114000],
            'active' => ['selector' => '&:active', 'order' => 115000],
            'enabled' => ['selector' => '&:enabled', 'order' => 116000],
            'disabled' => ['selector' => '&:disabled', 'order' => 117000],
            'inert' => ['selector' => '&:is([inert], [inert] *)', 'order' => 118000],

            // Media preferences.
            'motion-safe' => ['media' => '(prefers-reduced-motion: no-preference)', 'order' => 133000],
            'motion-reduce' => ['media' => '(prefers-reduced-motion: reduce)', 'order' => 134000],
            'contrast-more' => ['media' => '(prefers-contrast: more)', 'order' => 135000],
            'contrast-less' => ['media' => '(prefers-contrast: less)', 'order' => 136000],

            // Arbitrary breakpoints: `max-[600px]`, `min-[40rem]`.
            'max' => [
                'functional' => 'max',
                'media' => '(width < {value})',
                'theme' => 'breakpoint',
                'order' => 137000,
            ],
            'min' => [
                'functional' => 'min',
                'media' => '(width >= {value})',
                'theme' => 'breakpoint',
                'order' => 144000,
            ],

            // Media: orientation, colour scheme, print, pointer, scripting.
            'portrait' => ['media' => '(orientation: portrait)', 'order' => 155000],
            'landscape' => ['media' => '(orientation: landscape)', 'order' => 156000],
            'dark' => ['media' => '(prefers-color-scheme: dark)', 'order' => 159000],
            'starting' => ['starting-style' => true, 'order' => 160000],
            'print' => ['media' => 'print', 'order' => 161000],
            'forced-colors' => ['media' => '(forced-colors: active)', 'order' => 162000],
            'inverted-colors' => ['media' => '(inverted-colors: inverted)', 'order' => 163000],
            'pointer-none' => ['media' => '(pointer: none)', 'order' => 164000],
            'pointer-coarse' => ['media' => '(pointer: coarse)', 'order' => 165000],
            'pointer-fine' => ['media' => '(pointer: fine)', 'order' => 166000],
            'any-pointer-none' => ['media' => '(any-pointer: none)', 'order' => 167000],
            'any-pointer-coarse' => ['media' => '(any-pointer: coarse)', 'order' => 168000],
            'any-pointer-fine' => ['media' => '(any-pointer: fine)', 'order' => 169000],
            'noscript' => ['media' => '(scripting: none)', 'order' => 170000],
        ];
    }
}
