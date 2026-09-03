<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * A path was rejected: traversal, stream wrapper, NUL byte, missing target, or
 * a target that resolved outside the allowed roots
 * (design doc 12 threats T-01, T-02, T-14).
 *
 * The offending path is redacted whenever it is absolute, in both the message
 * and the context, so that neither a log line nor a debug overlay can echo a
 * filesystem layout back to an attacker (threat T-06). Callers are expected to
 * pass the path *as the user wrote it*, which is normally already relative.
 */
final class PathException extends ConfigurationException
{
    /**
     * @var string|null
     */
    private $path;

    /**
     * @param string $problem A sentence describing why the path was rejected.
     * @param string|null $path The offending path; redacted if absolute.
     */
    public function __construct(string $problem, ?string $path = null, ?\Throwable $previous = null)
    {
        $safePath = self::redactIfAbsolute($path);

        $sentence = $safePath === null
            ? $problem
            : $problem . ' (' . $safePath . ')';

        parent::__construct([$sentence], $previous);

        $this->path = $safePath;

        $this->initContext([
            'problems' => $this->problems(),
            'path' => $safePath,
        ]);
    }

    /**
     * The offending path, already redacted if it was absolute.
     */
    public function path(): ?string
    {
        return $this->path;
    }
}
