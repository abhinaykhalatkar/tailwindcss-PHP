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
 * In practice the revision bumps with any of these, because each one changes
 * what an existing candidate compiles to: a regenerated golden whose existing
 * rules differ from the committed ones, an allow-list entry removed because the
 * engine now matches the CLI (or one added because it stopped matching), and a
 * change under `Engine\Data\*` (default theme, preflight, utility rows, variant
 * order) or in a handler that alters existing output. A new golden, a new corpus
 * line or a new row that only adds a candidate that used to emit nothing does
 * not.
 *
 * `compat-4.1.18.0` is the first value published with a real engine: the M1 stub
 * reported `stub-m1`, so there is no earlier engine revision to bump from; its
 * middle components are the version in `tests/Conformance/golden/VERSION`.
 * `TailwindPhp\Version::VERSION` (the package version) is the owner's to change
 * at tagging and is not this class.
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
