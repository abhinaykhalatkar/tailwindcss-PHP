<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * SHA-256 helpers (design doc 17 §Support).
 *
 * SHA-256 is used for two different jobs: content fingerprints for cache
 * artefacts and URLs (ADR-004), and verifying the downloaded Tailwind binary
 * against the pinned checksum table (threat T-04). The second is why
 * {@see self::equals()} exists — a fingerprint comparison is not security
 * sensitive, but a checksum comparison is, and having one obvious constant-time
 * helper means nobody has to decide which `===` is safe.
 *
 * Digests are lowercase hex throughout, because that is the form the checksum
 * table and the manifest store.
 */
final class Hash
{
    /**
     * Read size for the streaming variant. 64 KiB keeps a large artefact off the
     * heap while staying well above the syscall overhead.
     */
    private const CHUNK_BYTES = 65536;

    private function __construct()
    {
    }

    /**
     * Lowercase hex SHA-256 of a string already in memory.
     */
    public static function sha256(string $data): string
    {
        return \hash('sha256', $data);
    }

    /**
     * Lowercase hex SHA-256 of a file, read incrementally.
     *
     * Uses the native call directly — the `Support\Filesystem` facade does not
     * exist yet and this needs no more than a readability probe.
     *
     * @throws \RuntimeException When the file cannot be read.
     */
    public static function sha256File(string $path): string
    {
        // Probing first keeps hash_file() from emitting a warning on a missing
        // file; `@` suppression is forbidden project-wide (design doc 12).
        if (!\is_file($path) || !\is_readable($path)) {
            throw new \RuntimeException(\sprintf('Cannot read file for hashing: %s', $path));
        }

        $digest = \hash_file('sha256', $path);

        if ($digest === false) {
            throw new \RuntimeException(\sprintf('Hashing failed for file: %s', $path));
        }

        return $digest;
    }

    /**
     * Lowercase hex SHA-256 of everything remaining in an open stream.
     *
     * The stream is read from its current position and is neither rewound nor
     * closed — the caller owns it.
     *
     * @param resource $resource
     *
     * @throws \RuntimeException When $resource is not a readable stream.
     */
    public static function sha256Stream($resource): string
    {
        if (!\is_resource($resource)) {
            throw new \RuntimeException('Cannot hash a stream that is not an open resource.');
        }

        // Checked up front rather than reacting to a failed read: a stream that
        // was never opened for reading is the one realistic way fread() fails
        // here, and rejecting it before the loop keeps the failure mode both
        // explicit and testable.
        if (!self::isReadable($resource)) {
            throw new \RuntimeException('Cannot hash a stream that is not open for reading.');
        }

        $context = \hash_init('sha256');

        while (!\feof($resource)) {
            $chunk = \fread($resource, self::CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            \hash_update($context, $chunk);
        }

        return \hash_final($context);
    }

    /**
     * Constant-time digest comparison.
     *
     * Always use this for a checksum or any other attacker-influenced value: a
     * short-circuiting `===` leaks how many leading bytes matched (threat T-04).
     *
     * @param string $known The trusted digest, e.g. from the pinned table.
     * @param string $user The digest computed from untrusted input.
     */
    public static function equals(string $known, string $user): bool
    {
        return \hash_equals($known, $user);
    }

    /**
     * Whether the stream's access mode permits reading.
     *
     * @param resource $resource
     */
    private static function isReadable($resource): bool
    {
        $meta = \stream_get_meta_data($resource);
        $mode = $meta['mode'];

        return \strpos($mode, 'r') !== false || \strpos($mode, '+') !== false;
    }
}
