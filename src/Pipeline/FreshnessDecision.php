<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

/**
 * Why the pipeline is about to do what it is about to do (design doc 04 §4,
 * decisions CSF-A13, CCP-A15).
 *
 * Six outcomes, against `StalenessReport`'s three: the checker answers "did the
 * files change", the pipeline also has to account for a cache that is absent,
 * an environment that moved under it, and debug mode forcing a rebuild. Keeping
 * them separate is what lets the debug overlay say *why* a rebuild happened
 * rather than just that one did.
 *
 * A `final class` of constants rather than an enum: enums are PHP 8.1 and the
 * floor is 7.3 (ADR-007, design doc 14 §Forbidden constructs).
 */
final class FreshnessDecision
{
    /**
     * Nothing changed; serve the cached artefact.
     */
    public const FRESH = 'FRESH';

    /**
     * A scanned source file changed. The candidate set may or may not have
     * changed with it — if it did not, the build is a touch, not a compile
     * (design doc 04 §4.8).
     */
    public const TEMPLATES_CHANGED = 'TEMPLATES_CHANGED';

    /**
     * The entry stylesheet or one of its imports changed: always a compile.
     */
    public const INPUTS_CHANGED = 'INPUTS_CHANGED';

    /**
     * No usable manifest — first request, cleared cache, or one that failed
     * validation.
     */
    public const NO_CACHE = 'NO_CACHE';

    /**
     * The package version, config hash, driver or driver version differ from
     * what built the cache. The files may be untouched and the output still
     * wrong, which is why this is not folded into the others.
     */
    public const ENV_CHANGED = 'ENV_CHANGED';

    /**
     * `debug: true` forces a compile on every request (decision CCP-A15).
     */
    public const DEBUG = 'DEBUG';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FRESH,
            self::TEMPLATES_CHANGED,
            self::INPUTS_CHANGED,
            self::NO_CACHE,
            self::ENV_CHANGED,
            self::DEBUG,
        ];
    }

    public static function isValid(string $decision): bool
    {
        return \in_array($decision, self::all(), true);
    }

    /**
     * @throws \InvalidArgumentException When $decision is not one of the six.
     */
    public static function assert(string $decision): void
    {
        if (!self::isValid($decision)) {
            // The value is internal — it never comes from a request — so it is
            // safe to quote, and quoting it is what makes the failure findable.
            throw new \InvalidArgumentException(
                \sprintf('Unknown freshness decision "%s".', $decision)
            );
        }
    }
}
