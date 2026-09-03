<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Pipeline\BuildResolverInterface;
use TailwindPhp\Pipeline\BuildResult;

/**
 * A build resolver that hands back prepared results.
 *
 * {@see BuildResolverInterface} is the seam between the two halves of the
 * library — the pipeline that produces a stylesheet and everything that serves
 * one — and it is the seam precisely so the serving half can be tested without
 * the producing half. A responder has to branch on conditional requests, on the
 * inline fail-soft shape, on a missing gzip twin; every one of those branches is
 * a property of the {@see BuildResult} it is given, so the honest way to test
 * them is to hand over the result directly rather than to arrange a compiler, a
 * cache directory and a clock that between them happen to produce it.
 *
 * Unknown bundles throw {@see ConfigurationException} rather than returning
 * null, because that is what the interface promises and a responder's handling
 * of a misconfigured bundle name is itself worth a test.
 */
final class StaticBuildResolver implements BuildResolverInterface
{
    /**
     * @var array<string, BuildResult> Bundle name => the result to return.
     */
    private $results;

    /**
     * @var list<string> Bundle names asked for, in order.
     */
    private $calls = [];

    /**
     * @param array<string, BuildResult> $results
     */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    /**
     * @throws ConfigurationException When $bundle was not prepared.
     */
    public function resolve(string $bundle): BuildResult
    {
        // Recorded before the guard: "asked for a bundle that does not exist" is
        // a call worth seeing in the log of a failing test.
        $this->calls[] = $bundle;

        if (!isset($this->results[$bundle])) {
            throw new ConfigurationException([
                \sprintf('Unknown bundle "%s".', $bundle),
            ]);
        }

        return $this->results[$bundle];
    }

    /**
     * Which bundles were requested, in order — enough to catch a responder that
     * resolves twice for one request, or resolves the default bundle when it was
     * given an explicit name.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
