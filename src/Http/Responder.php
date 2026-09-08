<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Pipeline\BuildResolverInterface;
use TailwindPhp\Pipeline\BuildResult;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Path;

/**
 * Turns a request into a response and puts it on the wire (design doc 04 §5,
 * doc 10; FR-PIPE-1, FR-HTTP-3/4/5/7, decisions HBC-A6/A18).
 *
 * The order of the steps is the security design, not a style choice:
 *
 * 1. **Match first.** A path that is not ours returns `null` having done *no*
 *    I/O — no stat, no manifest read, no compile. A site that mounts this
 *    library pays a `preg_match` for every request to every other URL.
 * 2. **Method next, before `resolve()`** (HBC-A6). A `DELETE` to the stylesheet
 *    route must not be able to trigger a compile; refusing it after the build
 *    would make an unauthenticated request the cheapest way to spend a site's
 *    CPU.
 * 3. **Only then resolve**, and only then touch the filesystem.
 *
 * **Threat T-02.** The path this class streams is never assembled from request
 * bytes. The request contributes exactly two things: a bundle *name* chosen by
 * {@see RouteMatcher} from the configured set, and a fingerprint that has passed
 * {@see \TailwindPhp\Cache\Fingerprint::isValid()}. The filename is built from
 * those two tokens by {@see CacheStore}, and is then put through
 * {@see Path::confine()} against the cache directory before anything opens it —
 * so even a bug in the name building cannot serve a file from outside the cache.
 */
final class Responder
{
    /**
     * The methods that get a stylesheet (doc 10 §HEAD).
     *
     * @var list<string>
     */
    private const READ_METHODS = ['GET', 'HEAD'];

    /**
     * The suffix every route carries.
     */
    private const SUFFIX = '.css';

    /**
     * @var RouteMatcher
     */
    private $matcher;

    /**
     * @var BuildResolverInterface
     */
    private $resolver;

    /**
     * @var CacheStore
     */
    private $store;

    /**
     * @var ResponseBuilder
     */
    private $builder;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var bool
     */
    private $handleOptions;

    /**
     * @var callable(string): void
     */
    private $headerSink;

    /**
     * @param bool $handleOptions From `http.handle_options`; false leaves
     *                            `OPTIONS` to the integrator's CORS layer
     *                            (HBC-A6).
     * @param callable|null $headerSink Where {@see self::emit()} sends header
     *                                  lines. `headers_list()` does not exist
     *                                  in the CLI SAPI, so the tests substitute
     *                                  a recorder here; production uses
     *                                  `header()`.
     */
    public function __construct(
        RouteMatcher $matcher,
        BuildResolverInterface $resolver,
        CacheStore $store,
        ResponseBuilder $builder,
        FilesystemInterface $fs,
        bool $handleOptions = false,
        ?callable $headerSink = null
    ) {
        $this->matcher = $matcher;
        $this->resolver = $resolver;
        $this->store = $store;
        $this->builder = $builder;
        $this->fs = $fs;
        $this->handleOptions = $handleOptions;

        if ($headerSink === null) {
            $headerSink = static function (string $line): void {
                \header($line, true);
            };
        }

        $this->headerSink = $headerSink;
    }

    /**
     * @param string $route The request path, with or without a leading slash
     *                      and query string.
     * @param array<string, mixed> $server The server variables; only the five
     *                                     keys {@see ServerRequest} reads are
     *                                     looked at.
     *
     * @return Response|null Null means "not ours" — the integrator's own
     *                       routing continues, and nothing has been touched.
     *
     * @throws \TailwindPhp\Exception\ConfigurationException When the matched
     *                                                       bundle is not
     *                                                       configured.
     * @throws \TailwindPhp\Exception\PathException When a cache path resolves
     *                                              outside the cache directory
     *                                              (T-02).
     */
    public function handle(string $route, array $server): ?Response
    {
        $match = $this->matcher->match($route);

        if ($match === null) {
            return null;
        }

        $request = ServerRequest::fromArray($server);
        $method = $request->method();

        if (!\in_array($method, self::READ_METHODS, true)) {
            if ($method === 'OPTIONS') {
                return $this->handleOptions ? $this->builder->options() : null;
            }

            return $this->builder->methodNotAllowed();
        }

        $result = $this->resolver->resolve($match->bundle());

        if ($result->isInline()) {
            // The fail-soft shapes of doc 15: an overlay or the constant
            // failure comment, served with their own status.
            return $this->builder->inline($request, $result->status(), $result->body());
        }

        $requested = $match->fingerprint();

        if ($requested !== null && $requested !== $result->fingerprint()) {
            return $this->serveOtherVersion($request, $match, $requested, $result);
        }

        return $this->serveCurrent($request, $match, $result, $requested !== null);
    }

    /**
     * Put a response on the wire.
     *
     * The only place in `src/` outside `Console/` that writes to the client.
     * `readfile()` rather than `echo file_get_contents()`: a stylesheet can be
     * hundreds of kilobytes and the second form holds all of it in memory to
     * achieve the same bytes (NFR-PERF-7).
     */
    public function emit(Response $response): void
    {
        $sink = $this->headerSink;

        \http_response_code($response->status());

        foreach ($response->headers() as $name => $value) {
            $sink($name . ': ' . $value);
        }

        // Integrators normally have exactly one buffer open, from their
        // framework. Discarding it is what stops a stray notice or a template's
        // whitespace from being prepended to the stylesheet. Exactly one level
        // is discarded: any others are someone's deliberate nesting.
        if (\ob_get_level() > 0) {
            \ob_end_clean();
        }

        $path = $response->bodyPath();

        if ($path !== null) {
            // Stat before opening. `readfile()` on a missing or unreadable file
            // emits a PHP warning, and on a host with `display_errors` on that
            // warning is written *into the stylesheet*, carrying the absolute
            // path of both the artefact and this file (threat T-06). Checking
            // first is what keeps that impossible without an `@`, which design
            // doc 18 permits only around rename/unlink/flock/mkdir.
            if ($this->fs->stat($path) !== null) {
                // A false return means the body is absent or partial. There is
                // nothing useful to send after it and nothing safe to say, so
                // the response simply ends: a short body is a broken stylesheet,
                // but an error message inside one is a disclosure.
                \readfile($path);
            }
        } else {
            $body = $response->body();

            if ($body !== null) {
                echo $body;
            }
        }

        \flush();
    }

    /**
     * The request asked for a fingerprint that is not the current one
     * (FR-HTTP-4, ADR-018).
     *
     * If we still hold that version, serve it: a browser that loaded the page
     * moments before a deploy asked for exactly the right file, and 302-ing it
     * to the new one would be a redirect for no reason. If we do not, redirect —
     * serving *current* CSS under the *old* URL would write today's stylesheet
     * into a cache entry marked immutable for a year under yesterday's name.
     */
    private function serveOtherVersion(
        ServerRequest $request,
        RouteMatch $match,
        string $requested,
        BuildResult $result
    ): Response {
        $bundle = $match->bundle();

        if (!$this->store->has($bundle, $requested)) {
            return $this->redirectToCurrent($match, $result);
        }

        $path = $this->confine($this->store->fingerprintedCssPath($bundle, $requested));
        $stat = $this->fs->stat($path);

        if ($stat === null) {
            // It existed a moment ago and does not now — a concurrent prune.
            // Redirecting is the honest answer; a 404 would leave the page
            // unstyled until the visitor reloads.
            return $this->redirectToCurrent($match, $result);
        }

        [$gzPath, $gzLength] = $this->gzTwin($bundle, $requested);

        return $this->builder->asset(
            $request,
            $path,
            $stat['size'],
            $gzPath,
            $gzLength,
            $requested,
            $stat['mtime'],
            true
        );
    }

    /**
     * Serve what the pipeline just handed us.
     *
     * `immutable` is true only when the request arrived on a fingerprinted
     * route (invariant I6): the same bytes are served on both routes, but only
     * one of the two URLs is a promise that they will never change.
     */
    private function serveCurrent(
        ServerRequest $request,
        RouteMatch $match,
        BuildResult $result,
        bool $fingerprintedRoute
    ): Response {
        $path = $this->confine((string) $result->path());
        $stat = $this->fs->stat($path);

        if ($stat === null) {
            // The pipeline named an artefact that is not on disk — a prune or a
            // deploy that landed between the build and this line. Serving it
            // anyway is the worst option available: `Content-Length: 0` with an
            // unreadable body caches an **empty stylesheet** for a year under
            // an immutable URL, and on a host with `display_errors` on the
            // failing `readfile()` prepends a warning carrying two absolute
            // paths to the CSS (threat T-06).
            if ($fingerprintedRoute) {
                // A redirect here would point at the URL we are already on.
                return $this->builder->inline($request, 500, ErrorRenderer::productionBody());
            }

            return $this->redirectToCurrent($match, $result);
        }

        [$gzPath, $gzLength] = $this->gzTwin($match->bundle(), $result->fingerprint());

        return $this->builder->asset(
            $request,
            $path,
            $stat['size'],
            $gzPath,
            $gzLength,
            $result->fingerprint(),
            $result->lastModified(),
            $fingerprintedRoute
        );
    }

    /**
     * The pre-compressed sibling, if there is one to send.
     *
     * Its size is read here rather than taken from the build, because a
     * retained version's twin was written by an earlier request and the only
     * authority on how many bytes it holds is the file itself — and
     * `Content-Length` disagreeing with the body is a broken response.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function gzTwin(string $bundle, string $fingerprint): array
    {
        if (!$this->store->gzipEnabled()) {
            return [null, null];
        }

        $path = $this->confine($this->store->fingerprintedGzPath($bundle, $fingerprint));
        $stat = $this->fs->stat($path);

        if ($stat === null) {
            return [null, null];
        }

        return [$path, $stat['size']];
    }

    /**
     * Threat T-02, applied to a path that was already built from validated
     * tokens: two independent things must both be wrong before a byte leaves
     * the cache directory.
     */
    private function confine(string $path): string
    {
        return Path::confine($path, $this->store->cacheDir());
    }

    /**
     * Point the client at the version we do hold.
     *
     * The builder assembles and validates the target (CCP-A28); this only
     * supplies the route's own basename, so the redirect stays relative and a
     * library mounted under a sub-directory redirects within it rather than to
     * the domain root (HBC-A4).
     *
     * @throws \InvalidArgumentException When the current fingerprint is not a
     *                                   fingerprint — the manifest is ours, so
     *                                   that is a bug rather than a request.
     */
    private function redirectToCurrent(RouteMatch $match, BuildResult $result): Response
    {
        return $this->builder->redirect(self::basenameOf($match->publicRoute()), $result->fingerprint());
    }

    /**
     * The route's filename without its `.css` suffix.
     */
    private static function basenameOf(string $publicRoute): string
    {
        $base = \strrchr($publicRoute, '/');
        $base = $base === false ? $publicRoute : \substr($base, 1);

        return \substr($base, 0, \strlen($base) - \strlen(self::SUFFIX));
    }
}
