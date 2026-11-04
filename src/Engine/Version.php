<?php

declare(strict_types=1);

namespace TailwindPhp\Engine;

/**
 * The engine's compatibility stamp (design doc 17, FR-FRESH-8).
 *
 * `COMPAT` is folded into every cache fingerprint, so bumping it invalidates
 * every artefact the previous engine produced. The format is
 * `compat-<tailwind major>.<minor>.<patch>.<engine revision>`:
 *
 * - **Any change to the generated CSS of an existing candidate** — a bug fix, a
 *   sort-order change, a serializer tweak — bumps the last component, the
 *   engine revision. Adding support for a candidate that used to emit nothing
 *   does not change any existing output and does not need a bump.
 * - **A bump of the pinned official CLI** replaces the three middle components
 *   with the new Tailwind version and resets the revision to `0`; that goes
 *   through the release procedure, never by hand.
 *
 * Never instantiated.
 */
final class Version
{
    public const COMPAT = 'compat-4.1.18.0';

    private function __construct()
    {
    }
}
