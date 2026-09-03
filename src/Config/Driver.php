<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

/**
 * The compiler driver names (design doc 06, ADR-002).
 *
 * A `final class` with constants rather than an enum: enums are PHP 8.1 and the
 * floor is 7.3 (ADR-007, design doc 14 §Forbidden constructs).
 */
final class Driver
{
    /**
     * Pick `cli` when it is safe and available, else `php`.
     */
    public const AUTO = 'auto';

    /**
     * The bundled pure-PHP engine — always available.
     */
    public const PHP = 'php';

    /**
     * The official standalone binary; requires `proc_open` and a verified binary.
     */
    public const CLI = 'cli';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::AUTO, self::PHP, self::CLI];
    }

    public static function isValid(string $driver): bool
    {
        return \in_array($driver, self::all(), true);
    }

    /**
     * @throws \InvalidArgumentException When $driver is not one of the three.
     */
    public static function assert(string $driver): void
    {
        if (!self::isValid($driver)) {
            // Escaped and capped: the argument is caller-supplied and the
            // message reaches a log, where a newline would forge an entry
            // (T-06). One implementation of that rule, in ConfigBuilder.
            throw new \InvalidArgumentException(
                \sprintf('Unknown compiler driver "%s".', ConfigBuilder::safe($driver))
            );
        }
    }
}
