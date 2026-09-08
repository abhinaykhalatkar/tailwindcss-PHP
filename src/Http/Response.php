<?php

declare(strict_types=1);

namespace TailwindPhp\Http;

/**
 * What to send back (design doc 10 §Response value object, decision HBC-A1).
 *
 * Doc 10 sketches this with public properties; HBC-A1 settles it as an
 * immutable value object with getters, consistent with the rest of the library
 * (doc 18). Nothing between construction and `emit()` may edit a response: a
 * header set is a security surface, and one that can be mutated after
 * validation is one that has not been validated.
 *
 * A response carries **either** a file to stream **or** an inline body, never
 * both — {@see self::file()} and {@see self::body()} are the two named
 * constructors, and {@see self::none()} is the third case (304, 405, 302 and
 * `HEAD`), where the absence of a body is the point.
 */
final class Response
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @var string|null
     */
    private $bodyPath;

    /**
     * @var string|null
     */
    private $body;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(int $status, array $headers, ?string $bodyPath, ?string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->bodyPath = $bodyPath;
        $this->body = $body;
    }

    /**
     * A response whose body is a file to stream.
     *
     * @param array<string, string> $headers
     */
    public static function file(int $status, array $headers, string $bodyPath): self
    {
        return new self($status, $headers, $bodyPath, null);
    }

    /**
     * A response whose body is already in memory — the debug overlay and the
     * production failure comment (doc 15).
     *
     * @param array<string, string> $headers
     */
    public static function inline(int $status, array $headers, string $body): self
    {
        return new self($status, $headers, null, $body);
    }

    /**
     * A response with no body at all.
     *
     * @param array<string, string> $headers
     */
    public static function none(int $status, array $headers): self
    {
        return new self($status, $headers, null, null);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string> Header name => value, ready to be emitted.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return string|null Absolute path to stream, or null.
     */
    public function bodyPath(): ?string
    {
        return $this->bodyPath;
    }

    /**
     * @return string|null Inline body, or null.
     */
    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * @return string|null The value of $name, or null when it was not set.
     */
    public function headerValue(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
}
