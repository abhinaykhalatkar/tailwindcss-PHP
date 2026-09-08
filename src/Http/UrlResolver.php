<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Cache\Fingerprint;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Config\Config;
use TailwindPhp\Support\FilesystemInterface;

/**
 * The URL to put in a `<link>` (design doc 10 §URL helper, FR-HTTP-8,
 * ADR-004, decision HBC-A15).
 *
 * This runs while a **page** is being rendered, so it does the least work that
 * can produce a correct answer: one manifest read for the fingerprint, and
 * **no freshness check at all**. Checking would mean stat-ing every template on
 * every page render, and would put the CSS pipeline on the critical path of
 * every HTML response in the application.
 *
 * The consequence is deliberate and is documented in doc 10: immediately after
 * an edit, the page may link to the *previous* fingerprint. That request then
 * arrives on the fingerprinted route, which notices the version is not current
 * and redirects (ADR-018). One extra round trip, once per edit, in development.
 *
 * Invariant I5: the fingerprint returned here is the one the served response
 * carries in its `ETag`, because both come from the same manifest field.
 */
final class UrlResolver
{
    /**
     * The suffix every route carries.
     */
    private const SUFFIX = '.css';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var CacheStore
     */
    private $store;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    public function __construct(Config $config, CacheStore $store, FilesystemInterface $fs)
    {
        $this->config = $config;
        $this->store = $store;
        $this->fs = $fs;
    }

    /**
     * @param string $bundle The configured bundle name.
     * @param string $prefix Passed through untouched — `'/'` for a root-mounted
     *                       site, `'/app/'` for a sub-directory, `''` for a
     *                       relative link.
     *
     * @return string `<prefix><route with fingerprint>`, or `<prefix><route>`
     *                when there is nothing to fingerprint with.
     *
     * @throws \TailwindPhp\Exception\ConfigurationException When no such bundle
     *                                                       is configured.
     */
    public function url(string $bundle, string $prefix = ''): string
    {
        $route = $this->config->bundle($bundle)->publicRoute();

        if (!$this->config->fingerprint()) {
            // HBC-A15: the switch affects this helper and nothing else. The
            // artefacts on disk keep their fingerprints; the pages just stop
            // linking to them.
            return $prefix . $route;
        }

        $manifest = Manifest::load($this->store->manifestPath($bundle), $this->fs);

        if ($manifest === null) {
            // Before the first build there is no fingerprint to name. The plain
            // route is the right answer: requesting it is what triggers the
            // build (doc 10 §URL helper).
            return $prefix . $route;
        }

        $fingerprint = $manifest->fingerprint();

        if (!Fingerprint::isValid($fingerprint)) {
            // A manifest is our own file, so this is close to impossible — but
            // the value would go into an HTML attribute, and "close to
            // impossible" is not the standard for that.
            return $prefix . $route;
        }

        return $prefix . \substr($route, 0, \strlen($route) - \strlen(self::SUFFIX))
            . '.' . $fingerprint . self::SUFFIX;
    }
}
