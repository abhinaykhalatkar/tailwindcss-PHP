<?php

declare(strict_types=1);

namespace TailwindPhp\PreProcessor;

/**
 * An optional transform applied to the entry stylesheet before Tailwind ever
 * sees it (design doc 06 §Optional pre-processor stage).
 *
 * The one shipped use is SCSS: a project whose entry file is `.scss` runs it
 * through scssphp first, and Tailwind's own at-rules (`@theme`, `@utility`,
 * `@apply`) pass through untouched. The default is {@see NullPreProcessor}.
 *
 * The stage is a separate interface rather than a hook inside the compiler
 * because its side channel matters: a pre-processor pulls in files of its own
 * (`@use`, `@import` in SCSS), and those have to be reported back so they land
 * in the manifest as watched inputs — otherwise editing a partial would never
 * invalidate the cache. That is why this returns a {@see PreProcessResult} and
 * not a plain string.
 */
interface PreProcessorInterface
{
    /**
     * @param string $entryText The raw bytes of the entry file.
     * @param string $entryRelPath The entry's project-relative path (ADR-014) — used to
     *                             resolve the pre-processor's own imports and to place
     *                             them, relative, in the result.
     */
    public function process(string $entryText, string $entryRelPath): PreProcessResult;
}
