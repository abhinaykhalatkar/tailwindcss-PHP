<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * JSON encoding and decoding with the project's fixed flag set
 * (design doc 17 §Support).
 *
 * Every cache artefact this library writes is JSON, and two properties matter
 * enough to be centralised rather than left to each call site:
 *
 *  - **Deterministic bytes.** Unescaped slashes and unicode, and preserved zero
 *    fractions, mean a manifest re-encoded from the same data is byte-identical,
 *    which is what makes content fingerprints stable (ADR-004, ADR-014).
 *  - **A bounded decoder.** `decode()` defaults to depth 16 so a hostile or
 *    corrupt artefact cannot drive the parser into deep recursion (threat T-08),
 *    and only an object or array is accepted at the top level, because every
 *    document we read is one.
 *
 * Failures surface as the native `\JsonException` and nothing else. The manifest
 * layer converts that into a domain exception; down here there is no useful
 * distinction between "malformed" and "too deep" beyond the message.
 */
final class Json
{
    /**
     * Flags shared by every encode: throw instead of returning false, and keep
     * the output readable and byte-stable.
     */
    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Nesting depth allowed when reading an artefact (threat T-08).
     */
    private const DEFAULT_DEPTH = 16;

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws \JsonException When the value cannot be encoded.
     */
    public static function encode(array $data, bool $pretty = false): string
    {
        $flags = self::ENCODE_FLAGS;

        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        $encoded = \json_encode($data, $flags);

        if (!\is_string($encoded)) {
            // Unreachable with JSON_THROW_ON_ERROR, but the signature says
            // string and static analysis is right to insist.
            throw new \JsonException('Encoding produced no output.');
        }

        return $encoded;
    }

    /**
     * @param int $depth Maximum nesting; the default is the artefact limit.
     *
     * @return array<array-key, mixed>
     *
     * @throws \JsonException When the document is malformed, too deeply nested,
     *                        or not an object/array at the top level.
     */
    public static function decode(string $json, int $depth = self::DEFAULT_DEPTH): array
    {
        if ($depth < 1) {
            throw new \JsonException(\sprintf('Decoding depth must be at least 1, got %d.', $depth));
        }

        $decoded = \json_decode($json, true, $depth, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \JsonException(
                \sprintf('Expected a JSON object or array at the top level, got %s.', \get_debug_type($decoded))
            );
        }

        return $decoded;
    }
}
