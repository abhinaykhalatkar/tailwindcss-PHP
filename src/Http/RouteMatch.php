<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

/**
 * A request path recognised as one of our stylesheet routes (design doc 10
 * §Routes).
 *
 * It carries the **bundle name**, not the path it came from. That is the point
 * of the type: everything downstream builds filenames from the bundle name and
 * a validated fingerprint, so the request string itself stops here and never
 * becomes part of a path (threat T-02).
 */
final class RouteMatch
{
    /**
     * @var string
     */
    private $bundle;

    /**
     * @var string
     */
    private $publicRoute;

    /**
     * @var string|null
     */
    private $fingerprint;

    /**
     * @param string $bundle The configured bundle name.
     * @param string $publicRoute Its configured route, as configured.
     * @param string|null $fingerprint The 8-16 hex characters from a
     *                                 fingerprinted route; null for the plain
     *                                 route.
     */
    public function __construct(string $bundle, string $publicRoute, ?string $fingerprint)
    {
        $this->bundle = $bundle;
        $this->publicRoute = $publicRoute;
        $this->fingerprint = $fingerprint;
    }

    public function bundle(): string
    {
        return $this->bundle;
    }

    public function publicRoute(): string
    {
        return $this->publicRoute;
    }

    /**
     * @return string|null Null when the plain route was requested.
     */
    public function fingerprint(): ?string
    {
        return $this->fingerprint;
    }
}
