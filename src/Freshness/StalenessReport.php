<?php

declare(strict_types=1);

namespace TailwindPhp\Freshness;

/**
 * The verdict of {@see StalenessChecker::compare()} (design doc 05
 * §Comparison, design decision CSF-A13).
 *
 * Only the three Layer-1 kinds live here; NO_CACHE and ENV_CHANGED are
 * pipeline-level outcomes decided from other signals entirely
 * (`Pipeline\FreshnessDecision`, design decision CSF-A13).
 */
final class StalenessReport
{
    /**
     * Nothing changed: serve the cache as-is.
     */
    public const FRESH = 'fresh';

    /**
     * An input (entry CSS or `watch[]`) was added, removed or changed:
     * always recompile.
     */
    public const INPUTS_CHANGED = 'inputs_changed';

    /**
     * Only source files changed: Layer 2 decides TOUCH vs. COMPILE from the
     * candidate hash.
     */
    public const TEMPLATES_CHANGED = 'templates_changed';

    /**
     * @var string
     */
    private $kind;

    /**
     * @var list<string>
     */
    private $changedOrAdded;

    /**
     * @var list<string>
     */
    private $removed;

    /**
     * @param string $kind One of the class constants.
     * @param array<int, string> $changedOrAdded Populated only for
     *                                           self::TEMPLATES_CHANGED; any
     *                                           integer keys are discarded.
     * @param array<int, string> $removed Populated only for
     *                                    self::TEMPLATES_CHANGED; any integer
     *                                    keys are discarded.
     *
     * @throws \InvalidArgumentException When $kind is not one of the class constants.
     */
    public function __construct(string $kind, array $changedOrAdded = [], array $removed = [])
    {
        if ($kind !== self::FRESH && $kind !== self::INPUTS_CHANGED && $kind !== self::TEMPLATES_CHANGED) {
            throw new \InvalidArgumentException(\sprintf('Unknown staleness kind "%s".', $kind));
        }

        $this->kind = $kind;
        $this->changedOrAdded = \array_values($changedOrAdded);
        $this->removed = \array_values($removed);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isFresh(): bool
    {
        return $this->kind === self::FRESH;
    }

    /**
     * @return list<string>
     */
    public function changedOrAdded(): array
    {
        return $this->changedOrAdded;
    }

    /**
     * @return list<string>
     */
    public function removed(): array
    {
        return $this->removed;
    }
}
