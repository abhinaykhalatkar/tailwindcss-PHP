<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

/**
 * The outcome of {@see DriverResolverInterface::resolve()}: the chosen
 * compiler, plus the two things the caller needs that the compiler itself
 * cannot tell it (design doc 06 §Resolution).
 *
 * The probe is returned alongside the compiler so the pipeline can write it
 * back to the manifest and let the next request skip re-hashing the CLI binary
 * (design doc 06 §Availability probe). The reason exists because `auto` mode
 * silently falling back to the PHP engine is exactly the kind of thing a
 * developer needs told — it is logged once per process and shown by `doctor`
 * as `I_DRIVER_SELECTED`.
 *
 * Immutable, and a plain carrier: it validates nothing, because the resolver
 * that built it has already done every check there is.
 */
final class DriverResolution
{
    /**
     * @var CompilerInterface
     */
    private $compiler;

    /**
     * @var array<string, mixed>|null
     */
    private $probe;

    /**
     * @var string|null
     */
    private $reason;

    /**
     * @param CompilerInterface $compiler The driver this request will use.
     * @param array<string, mixed>|null $probe `{binary, m, s, sha256, version}` for the
     *                                         manifest's `driver_probe`, or null when the
     *                                         CLI was never inspected (an explicit `php`
     *                                         driver) or the inspection produced nothing
     *                                         worth caching.
     * @param string|null $reason Why `auto` landed on this driver, in log-safe prose.
     *                            Null when the driver was not chosen automatically —
     *                            an explicit `php` or `cli` setting needs no explanation.
     */
    public function __construct(CompilerInterface $compiler, ?array $probe, ?string $reason)
    {
        $this->compiler = $compiler;
        $this->probe = $probe;
        $this->reason = $reason;
    }

    public function compiler(): CompilerInterface
    {
        return $this->compiler;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function probe(): ?array
    {
        return $this->probe;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
