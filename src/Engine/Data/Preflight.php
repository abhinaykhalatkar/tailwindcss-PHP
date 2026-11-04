<?php

declare(strict_types=1);

// phpcs:ignoreFile -- generated data: the long literals are the point.

namespace TailwindPhp\Engine\Data;

/**
 * Tailwind CSS preflight (the base stylesheet), converted to PHP data.
 *
 * GENERATED FILE. Do not edit by hand: run `php tools/import-preflight.php` and commit the result.
 *
 * Provenance
 *   generator   tools/import-preflight.php (class TailwindPhp\Tools\DataImporter)
 *   tailwind    4.1.18 (the release pinned in tests/Conformance/golden/VERSION)
 *   input.css   @import "tailwindcss/preflight.css";
 *   candidates  none
 *   options     tailwindcss -i input.css -o output.css --cwd <temporary project>
 *   binaries    SHA-256 pinned for this release, one per platform (resources/checksums.json):
 *                 linux-arm64      7a7702db6c93718a9b6655d455304edda18600f5a4f195242342ed3b5b70ebe8
 *                 linux-x64        737becf8d4ad1115ea98df69fa94026d402ca8feb91306a035b5b004167da8dd
 *                 linux-x64-musl   23ccf6c95db9161e4dbff19b5a2d4bb3be12d40067e2d0463ba794b0d9d1bd66
 *                 macos-arm64      7f27711dceac1a580b6ad58ddac46e59202c85a6c16f2f08f6fdcdee261008e1
 *                 macos-x64        1e8a77fd796a3a4aa3d8727887de926ef9d38477aba113fd7c32c0d31a32a3ab
 *                 windows-x64.exe  55bc5a2e294520a74fe3523eaa11915ef50047e7228a545aa181ec413cf52612
 *   output      SHA-256 of the stylesheet the CLI printed (banner removed, LF):
 *                 b7e450b21c8c7e43d3686cda3450c8ae3aeb7ef65196fe9efa9ad89deb771472
 *   content     36 top-level nodes
 *
 * Upstream attribution (ADR-012). This file contains data derived from Tailwind CSS,
 * https://github.com/tailwindlabs/tailwindcss, reproduced under the licence below.
 * tailwindcss-php is independent of Tailwind Labs and is not endorsed by it.
 *
 * MIT License
 *
 * Copyright (c) Tailwind Labs, Inc.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
final class Preflight
{
    public const VERSION = '4.1.18';

    private function __construct()
    {
    }

    /**
     * The CLI's preflight, verbatim: LF line endings, banner removed.
     *
     * @return string
     */
    public static function css(): string
    {
        return '*, ::after, ::before, ::backdrop, ::file-selector-button {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  border: 0 solid;
}
html, :host {
  line-height: 1.5;
  -webkit-text-size-adjust: 100%;
  tab-size: 4;
  font-family: ui-sans-serif, system-ui, sans-serif, \'Apple Color Emoji\', \'Segoe UI Emoji\', \'Segoe UI Symbol\', \'Noto Color Emoji\';
  font-feature-settings: normal;
  font-variation-settings: normal;
  -webkit-tap-highlight-color: transparent;
}
hr {
  height: 0;
  color: inherit;
  border-top-width: 1px;
}
abbr:where([title]) {
  -webkit-text-decoration: underline dotted;
  text-decoration: underline dotted;
}
h1, h2, h3, h4, h5, h6 {
  font-size: inherit;
  font-weight: inherit;
}
a {
  color: inherit;
  -webkit-text-decoration: inherit;
  text-decoration: inherit;
}
b, strong {
  font-weight: bolder;
}
code, kbd, samp, pre {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace;
  font-feature-settings: normal;
  font-variation-settings: normal;
  font-size: 1em;
}
small {
  font-size: 80%;
}
sub, sup {
  font-size: 75%;
  line-height: 0;
  position: relative;
  vertical-align: baseline;
}
sub {
  bottom: -0.25em;
}
sup {
  top: -0.5em;
}
table {
  text-indent: 0;
  border-color: inherit;
  border-collapse: collapse;
}
:-moz-focusring {
  outline: auto;
}
progress {
  vertical-align: baseline;
}
summary {
  display: list-item;
}
ol, ul, menu {
  list-style: none;
}
img, svg, video, canvas, audio, iframe, embed, object {
  display: block;
  vertical-align: middle;
}
img, video {
  max-width: 100%;
  height: auto;
}
button, input, select, optgroup, textarea, ::file-selector-button {
  font: inherit;
  font-feature-settings: inherit;
  font-variation-settings: inherit;
  letter-spacing: inherit;
  color: inherit;
  border-radius: 0;
  background-color: transparent;
  opacity: 1;
}
:where(select:is([multiple], [size])) optgroup {
  font-weight: bolder;
}
:where(select:is([multiple], [size])) optgroup option {
  padding-inline-start: 20px;
}
::file-selector-button {
  margin-inline-end: 4px;
}
::placeholder {
  opacity: 1;
}
@supports (not (-webkit-appearance: -apple-pay-button))  or (contain-intrinsic-size: 1px) {
  ::placeholder {
    color: currentcolor;
    @supports (color: color-mix(in lab, red, red)) {
      color: color-mix(in oklab, currentcolor 50%, transparent);
    }
  }
}
textarea {
  resize: vertical;
}
::-webkit-search-decoration {
  -webkit-appearance: none;
}
::-webkit-date-and-time-value {
  min-height: 1lh;
  text-align: inherit;
}
::-webkit-datetime-edit {
  display: inline-flex;
}
::-webkit-datetime-edit-fields-wrapper {
  padding: 0;
}
::-webkit-datetime-edit, ::-webkit-datetime-edit-year-field, ::-webkit-datetime-edit-month-field, ::-webkit-datetime-edit-day-field, ::-webkit-datetime-edit-hour-field, ::-webkit-datetime-edit-minute-field, ::-webkit-datetime-edit-second-field, ::-webkit-datetime-edit-millisecond-field, ::-webkit-datetime-edit-meridiem-field {
  padding-block: 0;
}
::-webkit-calendar-picker-indicator {
  line-height: 1;
}
:-moz-ui-invalid {
  box-shadow: none;
}
button, input:where([type=\'button\'], [type=\'reset\'], [type=\'submit\']), ::file-selector-button {
  appearance: button;
}
::-webkit-inner-spin-button, ::-webkit-outer-spin-button {
  height: auto;
}
[hidden]:where(:not([hidden=\'until-found\'])) {
  display: none !important;
}
';
    }

    /**
     * The same stylesheet as plain arrays (no objects, no source positions).
     *
     * @return list<array<string, mixed>>
     */
    public static function ast(): array
    {
        return [
            [
                'type' => 'rule',
                'selector' => '*, ::after, ::before, ::backdrop, ::file-selector-button',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'box-sizing', 'value' => 'border-box', 'important' => false],
                    ['type' => 'decl', 'property' => 'margin', 'value' => '0', 'important' => false],
                    ['type' => 'decl', 'property' => 'padding', 'value' => '0', 'important' => false],
                    ['type' => 'decl', 'property' => 'border', 'value' => '0 solid', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'html, :host',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'line-height', 'value' => '1.5', 'important' => false],
                    ['type' => 'decl', 'property' => '-webkit-text-size-adjust', 'value' => '100%', 'important' => false],
                    ['type' => 'decl', 'property' => 'tab-size', 'value' => '4', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-family', 'value' => 'ui-sans-serif, system-ui, sans-serif, \'Apple Color Emoji\', \'Segoe UI Emoji\', \'Segoe UI Symbol\', \'Noto Color Emoji\'', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-feature-settings', 'value' => 'normal', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-variation-settings', 'value' => 'normal', 'important' => false],
                    ['type' => 'decl', 'property' => '-webkit-tap-highlight-color', 'value' => 'transparent', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'hr',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'height', 'value' => '0', 'important' => false],
                    ['type' => 'decl', 'property' => 'color', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'border-top-width', 'value' => '1px', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'abbr:where([title])',
                'nodes' => [
                    ['type' => 'decl', 'property' => '-webkit-text-decoration', 'value' => 'underline dotted', 'important' => false],
                    ['type' => 'decl', 'property' => 'text-decoration', 'value' => 'underline dotted', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'h1, h2, h3, h4, h5, h6',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font-size', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-weight', 'value' => 'inherit', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'a',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'color', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => '-webkit-text-decoration', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'text-decoration', 'value' => 'inherit', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'b, strong',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font-weight', 'value' => 'bolder', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'code, kbd, samp, pre',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font-family', 'value' => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-feature-settings', 'value' => 'normal', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-variation-settings', 'value' => 'normal', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-size', 'value' => '1em', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'small',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font-size', 'value' => '80%', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'sub, sup',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font-size', 'value' => '75%', 'important' => false],
                    ['type' => 'decl', 'property' => 'line-height', 'value' => '0', 'important' => false],
                    ['type' => 'decl', 'property' => 'position', 'value' => 'relative', 'important' => false],
                    ['type' => 'decl', 'property' => 'vertical-align', 'value' => 'baseline', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'sub',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'bottom', 'value' => '-0.25em', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'sup',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'top', 'value' => '-0.5em', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'table',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'text-indent', 'value' => '0', 'important' => false],
                    ['type' => 'decl', 'property' => 'border-color', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'border-collapse', 'value' => 'collapse', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => ':-moz-focusring',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'outline', 'value' => 'auto', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'progress',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'vertical-align', 'value' => 'baseline', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'summary',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'display', 'value' => 'list-item', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'ol, ul, menu',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'list-style', 'value' => 'none', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'img, svg, video, canvas, audio, iframe, embed, object',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'display', 'value' => 'block', 'important' => false],
                    ['type' => 'decl', 'property' => 'vertical-align', 'value' => 'middle', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'img, video',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'max-width', 'value' => '100%', 'important' => false],
                    ['type' => 'decl', 'property' => 'height', 'value' => 'auto', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'button, input, select, optgroup, textarea, ::file-selector-button',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-feature-settings', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'font-variation-settings', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'letter-spacing', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'color', 'value' => 'inherit', 'important' => false],
                    ['type' => 'decl', 'property' => 'border-radius', 'value' => '0', 'important' => false],
                    ['type' => 'decl', 'property' => 'background-color', 'value' => 'transparent', 'important' => false],
                    ['type' => 'decl', 'property' => 'opacity', 'value' => '1', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => ':where(select:is([multiple], [size])) optgroup',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'font-weight', 'value' => 'bolder', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => ':where(select:is([multiple], [size])) optgroup option',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'padding-inline-start', 'value' => '20px', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::file-selector-button',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'margin-inline-end', 'value' => '4px', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::placeholder',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'opacity', 'value' => '1', 'important' => false],
                ],
            ],
            [
                'type' => 'at-rule',
                'name' => 'supports',
                'params' => '(not (-webkit-appearance: -apple-pay-button))  or (contain-intrinsic-size: 1px)',
                'nodes' => [
                    [
                        'type' => 'rule',
                        'selector' => '::placeholder',
                        'nodes' => [
                            ['type' => 'decl', 'property' => 'color', 'value' => 'currentcolor', 'important' => false],
                            [
                                'type' => 'at-rule',
                                'name' => 'supports',
                                'params' => '(color: color-mix(in lab, red, red))',
                                'nodes' => [
                                    ['type' => 'decl', 'property' => 'color', 'value' => 'color-mix(in oklab, currentcolor 50%, transparent)', 'important' => false],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'textarea',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'resize', 'value' => 'vertical', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-search-decoration',
                'nodes' => [
                    ['type' => 'decl', 'property' => '-webkit-appearance', 'value' => 'none', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-date-and-time-value',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'min-height', 'value' => '1lh', 'important' => false],
                    ['type' => 'decl', 'property' => 'text-align', 'value' => 'inherit', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-datetime-edit',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'display', 'value' => 'inline-flex', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-datetime-edit-fields-wrapper',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'padding', 'value' => '0', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-datetime-edit, ::-webkit-datetime-edit-year-field, ::-webkit-datetime-edit-month-field, ::-webkit-datetime-edit-day-field, ::-webkit-datetime-edit-hour-field, ::-webkit-datetime-edit-minute-field, ::-webkit-datetime-edit-second-field, ::-webkit-datetime-edit-millisecond-field, ::-webkit-datetime-edit-meridiem-field',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'padding-block', 'value' => '0', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-calendar-picker-indicator',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'line-height', 'value' => '1', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => ':-moz-ui-invalid',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'box-shadow', 'value' => 'none', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => 'button, input:where([type=\'button\'], [type=\'reset\'], [type=\'submit\']), ::file-selector-button',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'appearance', 'value' => 'button', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '::-webkit-inner-spin-button, ::-webkit-outer-spin-button',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'height', 'value' => 'auto', 'important' => false],
                ],
            ],
            [
                'type' => 'rule',
                'selector' => '[hidden]:where(:not([hidden=\'until-found\']))',
                'nodes' => [
                    ['type' => 'decl', 'property' => 'display', 'value' => 'none', 'important' => true],
                ],
            ],
        ];
    }
}
