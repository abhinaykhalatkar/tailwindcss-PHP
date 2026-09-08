<?php

/**
 * A plain function for procedural templates (design doc 10 §URL helper,
 * decision HBC-A10).
 *
 * Templates that are not rendered by Twig or Blade have no container to reach
 * into, so they need something callable by name. The instance is an **explicit
 * argument**: a static registry would be global mutable state, which doc 18
 * forbids outright, and it would make two facades in one process — a thing the
 * tests do constantly — quietly share the wrong configuration.
 *
 * The guard is the same one every autoloaded function file needs: this file is
 * listed in `composer.json` under `autoload.files`, and a project that also
 * loads the library some other way would otherwise fatal on the redeclaration.
 */

declare(strict_types=1);

namespace TailwindPhp;

if (!\function_exists('TailwindPhp\url')) {
    /**
     * @param Tailwind $tailwind The facade for this request.
     * @param string $prefix Passed through untouched — `'/'` for a root-mounted
     *                       site, `''` for a relative link.
     *
     * @return string `<prefix>assets/styles.<fingerprint>.css`, or the plain
     *                route when there is nothing to fingerprint with.
     */
    function url(Tailwind $tailwind, string $prefix = ''): string
    {
        return $tailwind->url($prefix);
    }
}
