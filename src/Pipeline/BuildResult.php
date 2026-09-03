<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * What the pipeline hands back: either a file to serve or a body to inline
 * (design doc 04 §4.10 and §6, decisions CCP-A1, CCP-A16).
 *
 * Two shapes, one type, because every caller — the HTTP responder, the
 * framework bridges, the console — has to handle both and a union would only
 * move the branch somewhere less obvious. The named constructors make which
 * shape you are building explicit; {@see self::isInline()} makes it checkable.
 *
 * The **inline** shape is the fail-soft path (design doc 15): a build that threw
 * still has to produce *something* a browser can render, whether that is the
 * debug overlay or the one-line production comment. It carries the status code
 * and the cause, so a bridge can log the exception while serving the body.
 *
 * `path()` is the **fingerprinted** artefact, not the stable copy (CCP-A17):
 * the stable name may lag a build that could not replace it.
 */
final class BuildResult
{
    /**
     * The only content type this library ever produces.
     */
    private const CONTENT_TYPE = 'text/css; charset=utf-8';

    /**
     * How the result was arrived at (decision CCP-A15). Reported in the debug
     * overlay and useful in a log when a site is rebuilding more than expected.
     */
    private const SOURCES = ['cached', 'touched', 'compiled', 'stale-fallback', 'error'];

    /**
     * @var string|null
     */
    private $path;

    /**
     * @var string|null
     */
    private $gzPath;

    /**
     * @var string
     */
    private $contentHash;

    /**
     * @var string
     */
    private $fingerprint;

    /**
     * @var int
     */
    private $lastModified;

    /**
     * @var string
     */
    private $source;

    /**
     * @var DiagnosticBag
     */
    private $diagnostics;

    /**
     * @var bool
     */
    private $inline;

    /**
     * @var int
     */
    private $status;

    /**
     * @var string
     */
    private $body;

    /**
     * @var \Throwable|null
     */
    private $cause;

    private function __construct()
    {
    }

    /**
     * A build that produced an artefact on disk.
     *
     * @param string $path Absolute path of `<bundle>.<fp>.css`.
     * @param string|null $gzPath Its pre-compressed twin, when one exists.
     * @param string $contentHash Bare hex (CSF-A3).
     * @param string $fingerprint The 8–16 hex characters in the filename.
     * @param int $lastModified Artefact mtime, for `Last-Modified`.
     * @param string $source One of self::SOURCES.
     *
     * @throws \InvalidArgumentException When $source is not a known outcome.
     */
    public static function file(
        string $path,
        ?string $gzPath,
        string $contentHash,
        string $fingerprint,
        int $lastModified,
        string $source,
        DiagnosticBag $diagnostics
    ): self {
        self::assertSource($source);

        $result = new self();
        $result->inline = false;
        $result->path = $path;
        $result->gzPath = $gzPath;
        $result->contentHash = $contentHash;
        $result->fingerprint = $fingerprint;
        $result->lastModified = $lastModified;
        $result->source = $source;
        $result->diagnostics = $diagnostics;
        $result->status = 200;
        $result->body = '';
        $result->cause = null;

        return $result;
    }

    /**
     * A body to serve directly, with no artefact behind it — the fail-soft and
     * debug-overlay paths of design doc 15.
     *
     * @param int $status Response status; 200 for the overlay, 500
     *                    when the build failed outright.
     * @param string $body CSS, already rendered and escaped.
     * @param string $source One of self::SOURCES.
     * @param \Throwable|null $cause For the caller to log; never rendered into
     *                               $body by this class.
     *
     * @throws \InvalidArgumentException When $source is not a known outcome.
     */
    public static function inline(
        int $status,
        string $body,
        string $source,
        ?\Throwable $cause,
        DiagnosticBag $diagnostics
    ): self {
        self::assertSource($source);

        $result = new self();
        $result->inline = true;
        $result->status = $status;
        $result->body = $body;
        $result->source = $source;
        $result->cause = $cause;
        $result->diagnostics = $diagnostics;
        $result->path = null;
        $result->gzPath = null;
        $result->contentHash = '';
        $result->fingerprint = '';
        $result->lastModified = 0;

        return $result;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    /**
     * @return string|null Null for an inline result — there is no file.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    public function gzPath(): ?string
    {
        return $this->gzPath;
    }

    /**
     * @return string Bare hex, without the `sha256:` prefix (CSF-A3).
     */
    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * The ETag form: the fingerprint in double quotes (ADR-004).
     */
    public function etag(): string
    {
        return '"' . $this->fingerprint . '"';
    }

    public function lastModified(): int
    {
        return $this->lastModified;
    }

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function diagnostics(): DiagnosticBag
    {
        return $this->diagnostics;
    }

    /**
     * @return int 200 for a file result; the inline shape may carry 500.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return string Empty for a file result — read {@see self::path()}.
     */
    public function body(): string
    {
        return $this->body;
    }

    public function cause(): ?\Throwable
    {
        return $this->cause;
    }

    /**
     * @return list<string>
     */
    public static function sources(): array
    {
        return self::SOURCES;
    }

    private static function assertSource(string $source): void
    {
        if (!\in_array($source, self::SOURCES, true)) {
            // Internal value, never from a request, so quoting it is safe and
            // is what makes a typo findable.
            throw new \InvalidArgumentException(\sprintf('Unknown build source "%s".', $source));
        }
    }
}
